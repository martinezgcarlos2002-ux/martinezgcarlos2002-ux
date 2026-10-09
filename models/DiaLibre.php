<?php
declare(strict_types=1);

/**
 * Días libres y ausencias. El cupo mensual (empleados.dias_libres_mes) no limita la asignación:
 * los libres que lo exceden se descuentan en la nómina. La cobertura por cargo genera alertas, nunca bloqueos.
 */
final class DiaLibre
{
    public const TIPOS = ['libre' => 'Libre', 'no_trabajado' => 'No trabajado'];
    private const DIAS_SEMANA = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public function __construct(private PDO $db)
    {
    }

    /** @return array<int, array<string, string>> empleado_id => [Y-m-d => tipo] */
    public function rango(string $desde, string $hasta, bool $bloquear = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT empleado_id, fecha, tipo FROM dias_libres
             WHERE fecha BETWEEN :desde AND :hasta
             ORDER BY fecha' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['desde' => $desde, 'hasta' => $hasta]);
        $out = [];
        foreach ($stmt as $row) {
            $out[(int) $row['empleado_id']][(string) $row['fecha']] = (string) $row['tipo'];
        }
        return $out;
    }

    /**
     * Libres del rango que superan el cupo del mes calendario al que pertenecen.
     * El orden es cronológico: los primeros N libres del mes son pagados.
     * @param array<int, int> $cupos empleado_id => dias_libres_mes
     * @param array<int, array<string, string>>|null $marcas si se pasa, se usa en lugar de la BD (simulación)
     * @return array<int, array<string, true>>
     */
    public function excedidos(array $cupos, string $desde, string $hasta, ?array $marcas = null): array
    {
        if ($cupos === []) {
            return [];
        }
        $inicioMes = substr($desde, 0, 8) . '01';
        $marcas ??= $this->rango($inicioMes, $hasta);

        $out = [];
        foreach ($marcas as $empId => $dias) {
            if (!isset($cupos[$empId])) {
                continue;
            }
            ksort($dias);
            $usadosPorMes = [];
            foreach ($dias as $fecha => $tipo) {
                if ($tipo !== 'libre' || $fecha < $inicioMes || $fecha > $hasta) {
                    continue;
                }
                $mes = substr($fecha, 0, 7);
                $usadosPorMes[$mes] = ($usadosPorMes[$mes] ?? 0) + 1;
                if ($fecha >= $desde && $usadosPorMes[$mes] > $cupos[$empId]) {
                    $out[$empId][$fecha] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Reemplaza las marcas del mes para los empleados indicados. Rechaza cambios en semanas con nómina cerrada.
     * @param list<int> $empleadoIds
     * @param array<int, array<string, string>> $deseado empleado_id => [Y-m-d => tipo]
     * @return array<int, array<string, ?string>> cambios aplicados: null = marca eliminada
     */
    public function guardarMes(int $anio, int $mes, array $empleadoIds, array $deseado, int $userId): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = (new DateTimeImmutable($desde))->format('Y-m-t');
        $actual = $this->rango($desde, $hasta, true);

        $cambios = [];
        foreach ($empleadoIds as $empId) {
            $antes = $actual[$empId] ?? [];
            $despues = $deseado[$empId] ?? [];
            foreach ($antes + $despues as $fecha => $_) {
                $nuevo = $despues[$fecha] ?? null;
                if (($antes[$fecha] ?? null) !== $nuevo) {
                    $cambios[$empId][$fecha] = $nuevo;
                }
            }
        }
        if ($cambios === []) {
            return [];
        }

        $cerradas = $this->fechasConNominaCerrada($cambios);
        if ($cerradas !== []) {
            throw new ValidationException(['dias' => 'Hay días en semanas con nómina cerrada: ' . implode(', ', $cerradas) . '. Reabre la nómina para cambiarlos.']);
        }

        $upsert = $this->db->prepare(
            'INSERT INTO dias_libres (empleado_id, fecha, tipo, registrado_por)
             VALUES (:empleado_id, :fecha, :tipo, :usuario)
             ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), registrado_por = VALUES(registrado_por)'
        );
        $borrar = $this->db->prepare('DELETE FROM dias_libres WHERE empleado_id = :empleado_id AND fecha = :fecha');
        foreach ($cambios as $empId => $dias) {
            foreach ($dias as $fecha => $tipo) {
                if ($tipo === null) {
                    $borrar->execute(['empleado_id' => $empId, 'fecha' => $fecha]);
                } else {
                    $upsert->execute(['empleado_id' => $empId, 'fecha' => $fecha, 'tipo' => $tipo, 'usuario' => $userId]);
                }
            }
        }
        return $cambios;
    }

    /**
     * Fechas del rango que caen en semanas con nómina cerrada o pagada, por empleado.
     * @param list<int> $empleadoIds
     * @return array<int, array<string, true>>
     */
    public function fechasBloqueadas(array $empleadoIds, string $desde, string $hasta): array
    {
        if ($empleadoIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $empleadoIds));
        $stmt = $this->db->prepare(
            "SELECT empleado_id, semana_inicio, semana_fin FROM nomina_semanal
             WHERE estado <> 'borrador' AND semana_inicio <= :hasta AND semana_fin >= :desde
               AND empleado_id IN ($in)"
        );
        $stmt->execute(['desde' => $desde, 'hasta' => $hasta]);
        $out = [];
        foreach ($stmt as $row) {
            $d = new DateTimeImmutable(max((string) $row['semana_inicio'], $desde));
            $fin = new DateTimeImmutable(min((string) $row['semana_fin'], $hasta));
            for (; $d <= $fin; $d = $d->modify('+1 day')) {
                $out[(int) $row['empleado_id']][$d->format('Y-m-d')] = true;
            }
        }
        return $out;
    }

    /**
     * Estado objetivo del mes al limpiar: solo sobreviven las marcas de semanas cerradas.
     * @param list<array<string, mixed>> $empleados
     * @return array{deseado:array<int, array<string, string>>, conservadas:int}
     */
    public function planLimpiar(int $anio, int $mes, array $empleados): array
    {
        [$desde, $hasta] = self::mesRango($anio, $mes);
        $ids = array_column($empleados, 'id');
        $actual = $this->rango($desde, $hasta, true);
        $bloqueadas = $this->fechasBloqueadas($ids, $desde, $hasta);
        $deseado = [];
        $conservadas = 0;
        foreach ($ids as $id) {
            foreach ($actual[$id] ?? [] as $fecha => $tipo) {
                if (isset($bloqueadas[$id][$fecha])) {
                    $deseado[$id][$fecha] = $tipo;
                    $conservadas++;
                }
            }
        }
        return ['deseado' => $deseado, 'conservadas' => $conservadas];
    }

    /**
     * Reparte los libres del cupo mensual (dias_libres_mes) de cada empleado sin dejar ningún cargo
     * por debajo de cobertura_minima en ningún día. Conserva las faltas y todo lo que esté en semanas
     * cerradas; los libres de semanas abiertas se regeneran. Reparto por rondas (un libre por empleado
     * en cada vuelta) para que los días escasos se compartan, prefiriendo semanas donde el empleado
     * aún no tiene libre y días con más personal disponible.
     *
     * @param list<array<string, mixed>> $empleados vigentes del mes
     * @return array{deseado:array<int, array<string, string>>, asignados:int, faltantes:list<array{empleado:string, cargo:string, faltan:int}>}
     */
    public function planAleatorio(int $anio, int $mes, array $empleados): array
    {
        [$desde, $hasta] = self::mesRango($anio, $mes);
        $ids = array_column($empleados, 'id');
        $minimos = (new Empleado($this->db))->cargos();
        $actual = $this->rango($desde, $hasta, true);
        $bloqueadas = $this->fechasBloqueadas($ids, $desde, $hasta);

        $dias = [];
        for ($d = new DateTimeImmutable($desde); $d->format('Y-m-d') <= $hasta; $d = $d->modify('+1 day')) {
            $dias[] = $d->format('Y-m-d');
        }

        $marcas = [];
        $necesita = [];
        foreach ($empleados as $emp) {
            $id = $emp['id'];
            $libresFijos = 0;
            foreach ($actual[$id] ?? [] as $fecha => $tipo) {
                if (isset($bloqueadas[$id][$fecha]) || $tipo === 'no_trabajado') {
                    $marcas[$id][$fecha] = $tipo;
                    $libresFijos += $tipo === 'libre' ? 1 : 0;
                }
            }
            $necesita[$id] = max(0, (int) $emp['dias_libres_mes'] - $libresFijos);
        }

        $presentes = [];
        foreach ($empleados as $emp) {
            if ($emp['cargo'] === '') {
                continue;
            }
            foreach ($dias as $fecha) {
                $presentes[$emp['cargo']][$fecha] ??= 0;
                if (self::enContrato($emp, $fecha) && !isset($marcas[$emp['id']][$fecha])) {
                    $presentes[$emp['cargo']][$fecha]++;
                }
            }
        }

        $porId = array_column($empleados, null, 'id');
        $orden = $ids;
        shuffle($orden);
        $asignados = 0;
        do {
            $avanzo = false;
            foreach ($orden as $id) {
                if ($necesita[$id] <= 0) {
                    continue;
                }
                $emp = $porId[$id];
                $cargo = $emp['cargo'];
                $minimo = $minimos[$cargo] ?? 1;
                $porSemana = [];
                foreach ($marcas[$id] ?? [] as $fecha => $tipo) {
                    if ($tipo === 'libre') {
                        $sem = (new DateTimeImmutable($fecha))->format('oW');
                        $porSemana[$sem] = ($porSemana[$sem] ?? 0) + 1;
                    }
                }
                $candidatos = [];
                foreach ($dias as $fecha) {
                    if (isset($marcas[$id][$fecha]) || isset($bloqueadas[$id][$fecha]) || !self::enContrato($emp, $fecha)) {
                        continue;
                    }
                    if ($cargo !== '' && $presentes[$cargo][$fecha] - 1 < $minimo) {
                        continue;
                    }
                    $candidatos[] = [
                        $porSemana[(new DateTimeImmutable($fecha))->format('oW')] ?? 0,
                        $cargo !== '' ? -$presentes[$cargo][$fecha] : 0,
                        random_int(0, PHP_INT_MAX),
                        $fecha,
                    ];
                }
                if ($candidatos === []) {
                    continue;
                }
                sort($candidatos);
                $fecha = $candidatos[0][3];
                $marcas[$id][$fecha] = 'libre';
                if ($cargo !== '') {
                    $presentes[$cargo][$fecha]--;
                }
                $necesita[$id]--;
                $asignados++;
                $avanzo = true;
            }
        } while ($avanzo);

        $faltantes = [];
        foreach ($necesita as $id => $n) {
            if ($n > 0) {
                $faltantes[] = [
                    'empleado' => $porId[$id]['nombres'] . ' ' . $porId[$id]['apellidos'],
                    'cargo'    => $porId[$id]['cargo'],
                    'faltan'   => $n,
                ];
            }
        }
        return ['deseado' => $marcas, 'asignados' => $asignados, 'faltantes' => $faltantes];
    }

    /** @return array{0:string, 1:string} */
    public static function mesRango(int $anio, int $mes): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        return [$desde, (new DateTimeImmutable($desde))->format('Y-m-t')];
    }

    /**
     * Cobertura por cargo y día. $override simula marcas sin persistirlas (null = sin marca).
     * Solo se evalúan los pares fecha/cargo de $foco cuando se indica.
     *
     * @param array<int, array<string, ?string>> $override
     * @param list<array{fecha:string, cargo:string}>|null $foco
     * @return list<array{fecha:string, cargo:string, presentes:int, vigentes:int, minimo:int, nivel:string, mensaje:string}>
     */
    public function alertasCobertura(string $desde, string $hasta, array $override = [], ?array $foco = null): array
    {
        $empleados = (new Empleado($this->db))->vigentesEntre($desde, $hasta);
        $minimos = (new Empleado($this->db))->cargos();
        $marcas = $this->rango($desde, $hasta);
        foreach ($override as $empId => $dias) {
            foreach ($dias as $fecha => $tipo) {
                if ($tipo === null) {
                    unset($marcas[$empId][$fecha]);
                } else {
                    $marcas[$empId][$fecha] = $tipo;
                }
            }
        }

        $porCargo = [];
        foreach ($empleados as $emp) {
            if ($emp['cargo'] !== '') {
                $porCargo[$emp['cargo']][] = $emp;
            }
        }
        $focoSet = null;
        if ($foco !== null) {
            $focoSet = [];
            foreach ($foco as $f) {
                $focoSet[$f['fecha'] . '|' . $f['cargo']] = true;
            }
        }

        $alertas = [];
        $dia = new DateTimeImmutable($desde);
        $fin = new DateTimeImmutable($hasta);
        for (; $dia <= $fin; $dia = $dia->modify('+1 day')) {
            $fecha = $dia->format('Y-m-d');
            foreach ($porCargo as $cargo => $grupo) {
                if ($focoSet !== null && !isset($focoSet[$fecha . '|' . $cargo])) {
                    continue;
                }
                $vigentes = 0;
                $presentes = 0;
                foreach ($grupo as $emp) {
                    if (!self::enContrato($emp, $fecha)) {
                        continue;
                    }
                    $vigentes++;
                    if (!isset($marcas[$emp['id']][$fecha])) {
                        $presentes++;
                    }
                }
                $minimo = $minimos[$cargo] ?? 1;
                if ($vigentes === 0 || $presentes >= $minimo) {
                    continue;
                }
                $alertas[] = [
                    'fecha'     => $fecha,
                    'cargo'     => $cargo,
                    'presentes' => $presentes,
                    'vigentes'  => $vigentes,
                    'minimo'    => $minimo,
                    'nivel'     => $presentes === 0 ? 'critica' : 'baja',
                    'mensaje'   => self::mensaje($dia, $cargo, $presentes, $minimo),
                ];
            }
        }
        return $alertas;
    }

    /**
     * Libres del mes frente al cupo del empleado, con la marca simulada aplicada.
     * @param array<string, mixed> $empleado
     * @return array{usados:int, cupo:int, excedidos:int, mensaje:?string}
     */
    public function cupoMes(array $empleado, string $fecha, ?string $tipo): array
    {
        $desde = substr($fecha, 0, 8) . '01';
        $hasta = (new DateTimeImmutable($desde))->format('Y-m-t');
        $marcas = $this->rango($desde, $hasta)[$empleado['id']] ?? [];
        if ($tipo === null) {
            unset($marcas[$fecha]);
        } else {
            $marcas[$fecha] = $tipo;
        }
        $usados = count(array_filter($marcas, static fn (string $t): bool => $t === 'libre'));
        $cupo = (int) $empleado['dias_libres_mes'];
        $excedidos = max(0, $usados - $cupo);
        return [
            'usados'    => $usados,
            'cupo'      => $cupo,
            'excedidos' => $excedidos,
            'mensaje'   => $excedidos > 0
                ? sprintf('%s lleva %d libres de %d este mes: %d se descontará%s en la nómina.',
                    $empleado['nombres'], $usados, $cupo, $excedidos, $excedidos === 1 ? '' : 'n')
                : null,
        ];
    }

    /** @param array<string, mixed> $emp */
    public static function enContrato(array $emp, string $fecha): bool
    {
        return ($emp['fecha_ingreso'] === '' || $emp['fecha_ingreso'] <= $fecha)
            && ($emp['fecha_salida'] === '' || $emp['fecha_salida'] >= $fecha);
    }

    /**
     * @param array<int, array<string, ?string>> $cambios
     * @return list<string> fechas d/m/Y afectadas
     */
    private function fechasConNominaCerrada(array $cambios): array
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM nomina_semanal
             WHERE empleado_id = :empleado_id AND :fecha BETWEEN semana_inicio AND semana_fin AND estado <> 'borrador'
             LIMIT 1"
        );
        $out = [];
        foreach ($cambios as $empId => $dias) {
            foreach (array_keys($dias) as $fecha) {
                $stmt->execute(['empleado_id' => $empId, 'fecha' => $fecha]);
                if ($stmt->fetchColumn() !== false) {
                    $out[$fecha] = (new DateTimeImmutable($fecha))->format('d/m/Y');
                }
            }
        }
        ksort($out);
        return array_values($out);
    }

    private static function mensaje(DateTimeImmutable $dia, string $cargo, int $presentes, int $minimo): string
    {
        $cuando = self::DIAS_SEMANA[(int) $dia->format('N')] . ' ' . $dia->format('j');
        $nombre = mb_convert_case($cargo, MB_CASE_TITLE, 'UTF-8');
        if ($presentes === 0) {
            return sprintf('Quedas sin nadie de %s el %s', $nombre, $cuando);
        }
        return sprintf('Queda%s solo %d de %s el %s (mínimo %d)', $presentes === 1 ? '' : 'n', $presentes, $nombre, $cuando, $minimo);
    }
}
