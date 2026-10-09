<?php
declare(strict_types=1);

/**
 * Asignación de días libres. Sin cupo rígido: se pueden asignar N días y los que exceden
 * empleados.dias_libres_mes se descuentan en la nómina. La falta de cobertura es alerta, no bloqueo.
 */
final class DiasLibresController
{
    private DiaLibre $dias;
    private Empleado $empleados;

    public function __construct()
    {
        $this->dias = new DiaLibre(get_db());
        $this->empleados = new Empleado(get_db());
    }

    /** Vista previa de una marca: devuelve alertas_cobertura y el estado del cupo sin guardar. */
    public function verificar(): void
    {
        $empId = parse_int_range($_POST['empleado_id'] ?? null, 1, PHP_INT_MAX);
        $fecha = parse_date($_POST['fecha'] ?? null);
        $tipo = $this->tipo($_POST['tipo'] ?? '');
        $emp = $empId !== null ? $this->empleados->find($empId) : null;
        if ($emp === null || $fecha === null || $tipo === false) {
            json_response(422, ['ok' => false, 'errores' => ['dia' => 'Empleado, fecha o tipo inválido.']]);
        }

        $alertas = $emp['cargo'] === '' ? [] : $this->dias->alertasCobertura(
            $fecha,
            $fecha,
            [$emp['id'] => [$fecha => $tipo]],
            [['fecha' => $fecha, 'cargo' => $emp['cargo']]]
        );
        json_response(200, [
            'ok'                 => true,
            'empleado_id'        => $emp['id'],
            'fecha'              => $fecha,
            'tipo'               => $tipo,
            'alertas_cobertura'  => $alertas,
            'cupo'               => $this->dias->cupoMes($emp, $fecha, $tipo),
        ]);
    }

    /**
     * Guarda el mes para los empleados enviados en emps[]. Solo viajan las celdas marcadas:
     * dias[empleado_id][día] = libre|no_trabajado. Las celdas ausentes quedan sin marca.
     */
    public function guardar(): void
    {
        $anio = parse_int_range($_POST['anio'] ?? null, 2000, 2100);
        $mes = parse_int_range($_POST['mes'] ?? null, 1, 12);
        if ($anio === null || $mes === null) {
            $this->fallar(['mes' => 'Mes inválido.'], []);
        }
        $volver = ['tab' => 'libres', 'anio' => $anio, 'mes' => $mes];
        $diasMes = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->format('t');

        $empIds = [];
        foreach ((array) ($_POST['emps'] ?? []) as $raw) {
            $id = parse_int_range($raw, 1, PHP_INT_MAX);
            if ($id !== null) {
                $empIds[$id] = $id;
            }
        }
        $deseado = [];
        foreach ((array) ($_POST['dias'] ?? []) as $empId => $celdas) {
            $empId = parse_int_range($empId, 1, PHP_INT_MAX);
            if ($empId === null || !isset($empIds[$empId]) || !is_array($celdas)) {
                continue;
            }
            foreach ($celdas as $dia => $tipo) {
                $dia = parse_int_range($dia, 1, $diasMes);
                $tipo = $this->tipo($tipo);
                if ($dia !== null && is_string($tipo)) {
                    $deseado[$empId][sprintf('%04d-%02d-%02d', $anio, $mes, $dia)] = $tipo;
                }
            }
        }

        $userId = current_user()['id'];
        try {
            $cambios = Database::transaction(function () use ($anio, $mes, $empIds, $deseado, $userId): array {
                $cambios = $this->dias->guardarMes($anio, $mes, array_values($empIds), $deseado, $userId);
                if ($cambios !== []) {
                    audit_log('empleados', 'guardar_dias_libres', [
                        'mes'     => sprintf('%04d-%02d', $anio, $mes),
                        'cambios' => $cambios,
                    ], 'dias_libres');
                }
                return $cambios;
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors, $volver);
        }

        [$alertas, $cupos] = $this->resultado($cambios);
        if (wants_json()) {
            json_response(200, [
                'ok'                => true,
                'cambios'           => count($cambios, COUNT_RECURSIVE) - count($cambios),
                'alertas_cobertura' => $alertas,
                'cupos'             => $cupos,
            ]);
        }

        flash('success', $cambios === [] ? 'Sin cambios en los días libres.' : 'Días libres guardados.');
        foreach ($alertas as $a) {
            flash('warning', $a['mensaje'] . '.');
        }
        foreach ($cupos as $c) {
            flash('info', $c);
        }
        redirect('empleados', $volver);
    }

    /**
     * Borra las marcas (libres y faltas) del mes. Las semanas con nómina cerrada o pagada no se tocan.
     * Las celdas no guardadas en pantalla se descartan solas al recargar.
     */
    public function limpiarMes(?int $mes = null, ?int $anio = null): void
    {
        [$anio, $mes] = $this->mesPost($anio, $mes);
        $volver = ['tab' => 'libres', 'anio' => $anio, 'mes' => $mes];
        [$desde, $hasta] = DiaLibre::mesRango($anio, $mes);
        $userId = current_user()['id'];

        try {
            [$cambios, $conservadas] = Database::transaction(function () use ($anio, $mes, $desde, $hasta, $userId): array {
                $empleados = $this->empleados->vigentesEntre($desde, $hasta);
                $plan = $this->dias->planLimpiar($anio, $mes, $empleados);
                $cambios = $this->dias->guardarMes($anio, $mes, array_column($empleados, 'id'), $plan['deseado'], $userId);
                if ($cambios !== []) {
                    audit_log('empleados', 'limpiar_dias_libres', [
                        'mes'         => sprintf('%04d-%02d', $anio, $mes),
                        'borradas'    => self::contar($cambios),
                        'conservadas' => $plan['conservadas'],
                    ], 'dias_libres');
                }
                return [$cambios, $plan['conservadas']];
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors, $volver);
        }

        $borradas = self::contar($cambios);
        if (wants_json()) {
            json_response(200, ['ok' => true, 'borradas' => $borradas, 'conservadas' => $conservadas]);
        }
        flash('success', $borradas === 0 ? 'No había marcas que limpiar en semanas abiertas.' : "Mes limpiado: $borradas marca" . ($borradas === 1 ? '' : 's') . ' eliminada' . ($borradas === 1 ? '' : 's') . '.');
        if ($conservadas > 0) {
            flash('info', "$conservadas marca" . ($conservadas === 1 ? '' : 's') . ' en semanas con nómina cerrada se conservaron.');
        }
        redirect('empleados', $volver);
    }

    /**
     * Asigna a cada empleado vigente sus dias_libres_mes sin dejar ningún cargo por debajo de su
     * cobertura_minima en ningún día. Si un cupo no cabe, se asigna lo posible y se informa el faltante.
     */
    public function generarAleatorio(?int $mes = null, ?int $anio = null): void
    {
        [$anio, $mes] = $this->mesPost($anio, $mes);
        $volver = ['tab' => 'libres', 'anio' => $anio, 'mes' => $mes];
        [$desde, $hasta] = DiaLibre::mesRango($anio, $mes);
        $userId = current_user()['id'];

        try {
            [$plan, $cambios] = Database::transaction(function () use ($anio, $mes, $desde, $hasta, $userId): array {
                $empleados = $this->empleados->vigentesEntre($desde, $hasta);
                $plan = $this->dias->planAleatorio($anio, $mes, $empleados);
                $cambios = $this->dias->guardarMes($anio, $mes, array_column($empleados, 'id'), $plan['deseado'], $userId);
                audit_log('empleados', 'aleatorio_dias_libres', [
                    'mes'       => sprintf('%04d-%02d', $anio, $mes),
                    'asignados' => $plan['asignados'],
                    'cambios'   => self::contar($cambios),
                    'faltantes' => $plan['faltantes'],
                ], 'dias_libres');
                return [$plan, $cambios];
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors, $volver);
        }

        $alertas = $this->dias->alertasCobertura($desde, $hasta);
        if (wants_json()) {
            json_response(200, [
                'ok'                => true,
                'asignados'         => $plan['asignados'],
                'cambios'           => self::contar($cambios),
                'faltantes'         => $plan['faltantes'],
                'alertas_cobertura' => $alertas,
            ]);
        }
        flash('success', sprintf('Asignación aleatoria: %d libre%s repartido%s sin romper la cobertura mínima.',
            $plan['asignados'], $plan['asignados'] === 1 ? '' : 's', $plan['asignados'] === 1 ? '' : 's'));
        foreach ($plan['faltantes'] as $f) {
            flash('warning', sprintf('%s: faltan %d libre%s; %s no tiene personal suficiente para cubrirlos.',
                $f['empleado'], $f['faltan'], $f['faltan'] === 1 ? '' : 's', $f['cargo'] !== '' ? $f['cargo'] : 'el calendario'));
        }
        if ($alertas !== []) {
            flash('info', 'Hay ' . count($alertas) . ' día(s) con cobertura baja por faltas o semanas cerradas ya registradas.');
        }
        redirect('empleados', $volver);
    }

    /** @return array{0:int, 1:int} */
    private function mesPost(?int $anio, ?int $mes): array
    {
        $anio ??= parse_int_range($_POST['anio'] ?? null, 2000, 2100);
        $mes ??= parse_int_range($_POST['mes'] ?? null, 1, 12);
        if ($anio === null || $mes === null || $mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            $this->fallar(['mes' => 'Mes inválido.'], []);
        }
        return [$anio, $mes];
    }

    /** @param array<int, array<string, ?string>> $cambios */
    private static function contar(array $cambios): int
    {
        return array_sum(array_map('count', $cambios));
    }

    /**
     * Alertas de cobertura solo para los pares fecha/cargo que cambiaron hacia una ausencia,
     * y avisos de cupo para los empleados que quedaron por encima.
     * @param array<int, array<string, ?string>> $cambios
     * @return array{0:list<array<string, mixed>>, 1:list<string>}
     */
    private function resultado(array $cambios): array
    {
        $foco = [];
        $cupos = [];
        $desde = null;
        $hasta = null;
        foreach ($cambios as $empId => $dias) {
            $emp = $this->empleados->find($empId);
            if ($emp === null) {
                continue;
            }
            $revisarCupo = null;
            foreach ($dias as $fecha => $tipo) {
                if ($tipo === null) {
                    continue;
                }
                if ($emp['cargo'] !== '') {
                    $foco[] = ['fecha' => $fecha, 'cargo' => $emp['cargo']];
                }
                $desde = $desde === null || $fecha < $desde ? $fecha : $desde;
                $hasta = $hasta === null || $fecha > $hasta ? $fecha : $hasta;
                if ($tipo === 'libre') {
                    $revisarCupo = $fecha;
                }
            }
            if ($revisarCupo !== null) {
                $cupo = $this->dias->cupoMes($emp, $revisarCupo, 'libre');
                if ($cupo['mensaje'] !== null) {
                    $cupos[] = $cupo['mensaje'];
                }
            }
        }
        $alertas = $foco === [] ? [] : $this->dias->alertasCobertura($desde, $hasta, [], $foco);
        return [$alertas, $cupos];
    }

    private function tipo(mixed $raw): string|false|null
    {
        if ($raw === '' || $raw === null) {
            return null;
        }
        return is_string($raw) && isset(DiaLibre::TIPOS[$raw]) ? $raw : false;
    }

    /** @param array<string, string> $errors */
    private function fallar(array $errors, array $volver): never
    {
        if (wants_json()) {
            json_response(422, ['ok' => false, 'errores' => $errors]);
        }
        flash('error', implode(' ', $errors));
        redirect('empleados', $volver + ['tab' => 'libres']);
    }
}
