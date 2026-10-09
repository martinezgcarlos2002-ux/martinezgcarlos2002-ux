<?php
declare(strict_types=1);

/**
 * Nómina semanal. Todo el cálculo se hace en centavos enteros.
 *   salario diario  = semanal / 7
 *   días no trab.   = ausencias + libres que exceden el cupo del mes + días fuera de contrato
 *   desc. días      = días no trabajados × salario diario
 *   neto            = (semanal − desc. días) + pagos extras − vales − cuota de deuda interna
 *   pendiente       = deuda del empleado con la empresa que queda después de la cuota
 *   resto (caja)    = neto − cobranzas a terceros marcadas para descontar esta semana
 * El neto es la nómina oficial del negocio; las cobranzas a terceros solo afectan el pago en caja.
 */
final class Nomina
{
    public const ESTADOS = ['borrador' => 'Abierta', 'cerrada' => 'Cerrada', 'pagada' => 'Pagada', 'mixta' => 'Mixta'];
    private const DIAS_CICLO = 7;

    private Empleado $empleados;
    private DiaLibre $dias;
    private PrestamoEmpleado $prestamos;

    public function __construct(private PDO $db)
    {
        $this->empleados = new Empleado($db);
        $this->dias = new DiaLibre($db);
        $this->prestamos = new PrestamoEmpleado($db);
    }

    /** @return array{0:string, 1:string} inicio y fin del ciclo que contiene $fecha */
    public static function periodo(string $fecha): array
    {
        $dia = new DateTimeImmutable($fecha);
        $atras = ((int) $dia->format('N') - NOMINA_DIA_INICIO + self::DIAS_CICLO) % self::DIAS_CICLO;
        $inicio = $dia->modify('-' . $atras . ' days');
        return [$inicio->format('Y-m-d'), $inicio->modify('+' . (self::DIAS_CICLO - 1) . ' days')->format('Y-m-d')];
    }

    /**
     * @param array<string, mixed> $emp
     * @param array<string, string> $marcas Y-m-d => tipo
     * @param array<string, true> $excedidos Y-m-d de libres fuera de cupo
     * @return array<string, mixed>
     */
    public static function calcular(
        array $emp,
        string $inicio,
        string $fin,
        array $marcas,
        array $excedidos,
        string $extras,
        string $vales,
        string $deudaSaldo,
        string $cuota,
        string $cobranzas,
        ?string $tasa
    ): array {
        $conteo = NominaService::contarDiasNoTrabajados($emp, $inicio, $fin, $marcas, $excedidos);
        $salC = Caja::aCentavos($emp['salario_semanal']);
        $liq = NominaService::liquidar(
            $salC,
            $conteo['dias'],
            Caja::aCentavos($extras),
            Caja::aCentavos($vales),
            Caja::aCentavos($deudaSaldo),
            Caja::aCentavos($cuota),
            Caja::aCentavos($cobranzas),
            $tasa
        );

        return [
            'salario_semanal'       => Caja::desdeCentavos($salC),
            'salario_diario'        => $liq['diario'],
            'dias_no_trabajados'    => number_format($conteo['dias'], 1, '.', ''),
            'dias_ausencia'         => number_format($conteo['ausencia'], 1, '.', ''),
            'dias_libres_excedidos' => number_format($conteo['excedido'], 1, '.', ''),
            'dias_fuera_contrato'   => number_format($conteo['fuera'], 1, '.', ''),
            'pagos_extras'          => Caja::desdeCentavos(Caja::aCentavos($extras)),
            'salario_mas_extras'    => Caja::desdeCentavos($salC + Caja::aCentavos($extras)),
            'vales'                 => Caja::desdeCentavos(Caja::aCentavos($vales)),
            'desc_dias'             => Caja::desdeCentavos($liq['desc_c']),
            'deuda_saldo'           => Caja::desdeCentavos(Caja::aCentavos($deudaSaldo)),
            'cuota_deuda'           => Caja::desdeCentavos(Caja::aCentavos($cuota)),
            'pendiente'             => Caja::desdeCentavos(Caja::aCentavos($deudaSaldo) - Caja::aCentavos($cuota)),
            'neto'                  => Caja::desdeCentavos($liq['neto_c']),
            'cobranzas'             => Caja::desdeCentavos(Caja::aCentavos($cobranzas)),
            'resto_usd'             => Caja::desdeCentavos($liq['resto_c']),
            'tasa'                  => $tasa,
            'monto_bs'              => $liq['monto_bs'],
            'resto_bs'              => $liq['resto_bs'],
        ];
    }

    /**
     * Nómina calculada del ciclo. Las filas cerradas o pagadas muestran su fotografía guardada.
     * @return array{inicio:string, fin:string, tasa:?string, filas:list<array<string, mixed>>, totales:array<string, string>, estado:string}
     */
    public function semana(string $inicio, ?string $tasaVigente): array
    {
        [$inicio, $fin] = self::periodo($inicio);
        $guardadas = $this->guardadas($inicio, false);
        $vigentes = $this->empleados->vigentesEntre($inicio, $fin);

        $porId = [];
        foreach ($vigentes as $emp) {
            $porId[$emp['id']] = $emp;
        }
        foreach (array_keys($guardadas) as $empId) {
            if (!isset($porId[$empId]) && ($emp = $this->empleados->find($empId)) !== null) {
                $porId[$empId] = $emp;
            }
        }

        $marcas = $this->dias->rango($this->inicioMarcas($inicio), $fin);
        $excedidos = $this->dias->excedidos(array_map(static fn (array $e): int => $e['dias_libres_mes'], $porId), $inicio, $fin, $marcas);
        $saldos = $this->saldosDeuda($inicio, $fin);
        $cobros = $this->cobranzasSemana($inicio);

        $filas = [];
        foreach ($porId as $empId => $emp) {
            $g = $guardadas[$empId] ?? null;
            if ($g !== null && $g['estado'] !== 'borrador') {
                $calc = self::desdeFoto($g);
            } else {
                $calc = self::calcular(
                    $emp, $inicio, $fin,
                    $marcas[$empId] ?? [], $excedidos[$empId] ?? [],
                    (string) ($g['pagos_extras_usd'] ?? '0'),
                    (string) ($g['vales_mercancia_usd'] ?? '0'),
                    $saldos[$empId] ?? '0.00',
                    $this->cuotaFila($empId, $g, $saldos[$empId] ?? '0.00', null),
                    $cobros[$empId] ?? '0.00',
                    $tasaVigente
                );
            }
            $filas[] = $emp + $calc + ['estado' => $g['estado'] ?? 'borrador', 'nomina_id' => $g !== null ? (int) $g['id'] : null];
        }
        usort($filas, static fn (array $a, array $b): int => [$a['apellidos'], $a['nombres']] <=> [$b['apellidos'], $b['nombres']]);

        $estados = array_unique(array_column($filas, 'estado'));
        return [
            'inicio'  => $inicio,
            'fin'     => $fin,
            'tasa'    => $tasaVigente,
            'filas'   => $filas,
            'totales' => self::totales($filas),
            'estado'  => count($estados) === 1 ? (string) reset($estados) : ($filas === [] ? 'borrador' : 'mixta'),
        ];
    }

    /**
     * Recalcula y guarda en borrador. Las filas cerradas o pagadas no se tocan.
     * cuota = null conserva la cuota ya guardada (solo el administrador la modifica).
     * @param array<int, array{extras:string, vales:string, cuota:?string}> $entradas
     * @return array{guardadas:int, omitidas:int}
     */
    public function guardar(string $inicio, array $entradas, string $tasa, int $userId, bool $cerrar = false): array
    {
        [$inicio, $fin] = self::periodo($inicio);
        $guardadas = $this->guardadas($inicio, true);
        $vigentes = $this->empleados->vigentesEntre($inicio, $fin);
        $cupos = [];
        foreach ($vigentes as $emp) {
            $cupos[$emp['id']] = $emp['dias_libres_mes'];
        }
        $marcas = $this->dias->rango($this->inicioMarcas($inicio), $fin, true);
        $excedidos = $this->dias->excedidos($cupos, $inicio, $fin, $marcas);
        $saldos = $this->saldosDeuda($inicio, $fin, true);
        $cobros = $this->cobranzasSemana($inicio, true);

        $stmt = $this->db->prepare(
            'INSERT INTO nomina_semanal
                 (empleado_id, semana_inicio, semana_fin, salario_base_usd, salario_diario_usd, dias_no_trabajados,
                  dias_ausencia, dias_libres_excedidos, dias_fuera_contrato, desc_dias_usd, pagos_extras_usd,
                  vales_mercancia_usd, tasa_bs_por_usd, neto_usd, deuda_saldo_usd, cuota_deuda_usd, cobranzas_usd,
                  estado, registrado_por)
             VALUES
                 (:empleado_id, :inicio, :fin, :salario, :diario, :dias, :ausencia, :excedidos, :fuera, :desc, :extras,
                  :vales, :tasa, :neto, :deuda, :cuota, :cobranzas, :estado, :usuario)
             ON DUPLICATE KEY UPDATE
                 semana_fin = VALUES(semana_fin), salario_base_usd = VALUES(salario_base_usd),
                 salario_diario_usd = VALUES(salario_diario_usd), dias_no_trabajados = VALUES(dias_no_trabajados),
                 dias_ausencia = VALUES(dias_ausencia), dias_libres_excedidos = VALUES(dias_libres_excedidos),
                 dias_fuera_contrato = VALUES(dias_fuera_contrato), desc_dias_usd = VALUES(desc_dias_usd),
                 pagos_extras_usd = VALUES(pagos_extras_usd), vales_mercancia_usd = VALUES(vales_mercancia_usd),
                 tasa_bs_por_usd = VALUES(tasa_bs_por_usd), neto_usd = VALUES(neto_usd),
                 deuda_saldo_usd = VALUES(deuda_saldo_usd), cuota_deuda_usd = VALUES(cuota_deuda_usd),
                 cobranzas_usd = VALUES(cobranzas_usd), estado = VALUES(estado), registrado_por = VALUES(registrado_por)'
        );

        $filas = [];
        $errores = [];
        $omitidas = 0;
        foreach ($vigentes as $emp) {
            $g = $guardadas[$emp['id']] ?? null;
            if ($g !== null && $g['estado'] !== 'borrador') {
                $omitidas++;
                continue;
            }
            $in = $entradas[$emp['id']] ?? [];
            $saldo = $saldos[$emp['id']] ?? '0.00';
            $cuota = $this->cuotaFila($emp['id'], $g, $saldo, $in['cuota'] ?? null);
            if (Caja::aCentavos($cuota) > Caja::aCentavos($saldo)) {
                $errores['cuota.' . $emp['id']] = sprintf('%s %s: la cuota %s supera la deuda %s.',
                    $emp['nombres'], $emp['apellidos'], num($cuota), num($saldo));
                continue;
            }
            $filas[] = [$emp, self::calcular(
                $emp, $inicio, $fin, $marcas[$emp['id']] ?? [], $excedidos[$emp['id']] ?? [],
                $in['extras'] ?? (string) ($g['pagos_extras_usd'] ?? '0'),
                $in['vales'] ?? (string) ($g['vales_mercancia_usd'] ?? '0'),
                $saldo, $cuota, $cobros[$emp['id']] ?? '0.00', $tasa
            )];
        }
        if ($errores) {
            throw new ValidationException($errores);
        }

        $idFila = $this->db->prepare(
            'SELECT id FROM nomina_semanal WHERE empleado_id = :e AND semana_inicio = :s'
        );
        foreach ($filas as [$emp, $c]) {
            $stmt->execute([
                'empleado_id' => $emp['id'],
                'inicio'      => $inicio,
                'fin'         => $fin,
                'salario'     => $c['salario_semanal'],
                'diario'      => $c['salario_diario'],
                'dias'        => $c['dias_no_trabajados'],
                'ausencia'    => $c['dias_ausencia'],
                'excedidos'   => $c['dias_libres_excedidos'],
                'fuera'       => $c['dias_fuera_contrato'],
                'desc'        => $c['desc_dias'],
                'extras'      => $c['pagos_extras'],
                'vales'       => $c['vales'],
                'tasa'        => $tasa,
                'neto'        => $c['neto'],
                'deuda'       => $c['deuda_saldo'],
                'cuota'       => $c['cuota_deuda'],
                'cobranzas'   => $c['cobranzas'],
                'estado'      => $cerrar ? 'cerrada' : 'borrador',
                'usuario'     => $userId,
            ]);
            if ($cerrar && Caja::aCentavos($c['cuota_deuda']) > 0) {
                $idFila->execute(['e' => $emp['id'], 's' => $inicio]);
                $nominaId = (int) $idFila->fetchColumn();
                if ($nominaId > 0) {
                    $this->prestamos->registrarCuotasDeNomina($emp['id'], $nominaId, $c['cuota_deuda']);
                }
            }
        }
        return ['guardadas' => count($filas), 'omitidas' => $omitidas];
    }

    /**
     * Vuelve a 'borrador' las filas cerradas del ciclo. Las pagadas no se reabren.
     * @return array{reabiertas:int, pagadas:int}
     */
    public function reabrir(string $inicio): array
    {
        [$inicio] = self::periodo($inicio);
        $estados = ['borrador' => 0, 'cerrada' => 0, 'pagada' => 0];
        $ids = [];
        foreach ($this->guardadas($inicio, true) as $g) {
            $estados[$g['estado']]++;
            if ($g['estado'] === 'cerrada') {
                $ids[] = (int) $g['id'];
            }
        }
        if ($estados['cerrada'] === 0) {
            return ['reabiertas' => 0, 'pagadas' => $estados['pagada']];
        }
        $this->prestamos->revertirCuotasDeNominas($ids);
        $stmt = $this->db->prepare("UPDATE nomina_semanal SET estado = 'borrador' WHERE semana_inicio = :inicio AND estado = 'cerrada'");
        $stmt->execute(['inicio' => $inicio]);
        return ['reabiertas' => $stmt->rowCount(), 'pagadas' => $estados['pagada']];
    }

    /**
     * Deuda interna por empleado: préstamos hasta $hasta menos cuotas de las demás semanas.
     * Sin argumentos devuelve el saldo actual (todos los préstamos menos todas las cuotas).
     * @return array<int, string>
     */
    public function saldosDeuda(?string $excluirSemana = null, ?string $hasta = null, bool $bloquear = false): array
    {
        return $this->prestamos->saldos($excluirSemana, $hasta, $bloquear);
    }

    /** @return array<int, string> cobranzas a terceros retenidas en el pago del ciclo */
    public function cobranzasSemana(string $inicio, bool $bloquear = false): array
    {
        $stmt = $this->db->prepare(
            "SELECT empleado_id, SUM(monto_usd) AS usd FROM cobranzas_terceros
             WHERE estado IN ('descontado', 'pendiente') AND semana_descuento = :inicio
             GROUP BY empleado_id" . ($bloquear ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['inicio' => $inicio]);
        $out = [];
        foreach ($stmt as $row) {
            $out[(int) $row['empleado_id']] = (string) $row['usd'];
        }
        return $out;
    }

    public function estadoFila(int $empleadoId, string $inicio): ?string
    {
        $stmt = $this->db->prepare('SELECT estado FROM nomina_semanal WHERE empleado_id = :id AND semana_inicio = :inicio');
        $stmt->execute(['id' => $empleadoId, 'inicio' => $inicio]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** @param list<array<string, mixed>> $filas */
    public static function totales(array $filas): array
    {
        $campos = ['salario_semanal', 'pagos_extras', 'salario_mas_extras', 'vales', 'desc_dias', 'deuda_saldo',
                   'cuota_deuda', 'pendiente', 'neto', 'cobranzas', 'resto_usd', 'monto_bs', 'resto_bs'];
        $out = [];
        foreach ($campos as $campo) {
            $out[$campo] = Caja::desdeCentavos(array_sum(array_map(static fn (array $f): int => Caja::aCentavos($f[$campo] ?? 0), $filas)));
        }
        $out['salario_diario'] = number_format(array_sum(array_map(static fn (array $f): float => (float) $f['salario_diario'], $filas)), 4, '.', '');
        $out['dias_no_trabajados'] = number_format(array_sum(array_map(static fn (array $f): float => (float) $f['dias_no_trabajados'], $filas)), 1, '.', '');
        return $out;
    }

    /** Si la deuda bajó (préstamo corregido, cuotas en otras semanas), la cuota guardada no puede superarla. */
    private static function cuotaAjustada(string $cuota, string $saldo): string
    {
        return Caja::desdeCentavos(min(Caja::aCentavos($cuota), Caja::aCentavos($saldo)));
    }

    /**
     * @param array<string, mixed>|null $g
     */
    private function cuotaFila(int $empleadoId, ?array $g, string $saldo, ?string $entrada): string
    {
        if ($entrada !== null && $entrada !== '') {
            return self::cuotaAjustada($entrada, $saldo);
        }
        if ($g !== null && isset($g['cuota_deuda_usd']) && Caja::aCentavos((string) $g['cuota_deuda_usd']) > 0) {
            return self::cuotaAjustada((string) $g['cuota_deuda_usd'], $saldo);
        }
        return $this->prestamos->cuotaAutomatica($empleadoId, $saldo);
    }

    private function inicioMarcas(string $inicio): string
    {
        return substr($inicio, 0, 8) . '01';
    }

    /** @return array<int, array<string, mixed>> */
    private function guardadas(string $inicio, bool $bloquear): array
    {
        $stmt = $this->db->prepare('SELECT * FROM nomina_semanal WHERE semana_inicio = :inicio' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute(['inicio' => $inicio]);
        $out = [];
        foreach ($stmt as $row) {
            $out[(int) $row['empleado_id']] = $row;
        }
        return $out;
    }

    /** @param array<string, mixed> $g */
    private static function desdeFoto(array $g): array
    {
        $tasa = $g['tasa_bs_por_usd'] !== null ? (string) $g['tasa_bs_por_usd'] : null;
        $netoC = Caja::aCentavos($g['neto_usd']);
        $restoC = $netoC - Caja::aCentavos($g['cobranzas_usd']);
        return [
            'salario_semanal'       => (string) $g['salario_base_usd'],
            'salario_diario'        => (string) $g['salario_diario_usd'],
            'dias_no_trabajados'    => (string) $g['dias_no_trabajados'],
            'dias_ausencia'         => (string) $g['dias_ausencia'],
            'dias_libres_excedidos' => (string) $g['dias_libres_excedidos'],
            'dias_fuera_contrato'   => (string) $g['dias_fuera_contrato'],
            'pagos_extras'          => (string) $g['pagos_extras_usd'],
            'salario_mas_extras'    => Caja::desdeCentavos(Caja::aCentavos($g['salario_base_usd']) + Caja::aCentavos($g['pagos_extras_usd'])),
            'vales'                 => (string) $g['vales_mercancia_usd'],
            'desc_dias'             => (string) $g['desc_dias_usd'],
            'deuda_saldo'           => (string) $g['deuda_saldo_usd'],
            'cuota_deuda'           => (string) $g['cuota_deuda_usd'],
            'pendiente'             => Caja::desdeCentavos(Caja::aCentavos($g['deuda_saldo_usd']) - Caja::aCentavos($g['cuota_deuda_usd'])),
            'neto'                  => (string) $g['neto_usd'],
            'cobranzas'             => (string) $g['cobranzas_usd'],
            'resto_usd'             => Caja::desdeCentavos($restoC),
            'tasa'                  => $tasa,
            'monto_bs'              => $tasa === null ? null : NominaService::aBs($netoC, $tasa),
            'resto_bs'              => $tasa === null ? null : NominaService::restoBsDesdeDeudaUsd($netoC, Caja::aCentavos($g['cobranzas_usd']), $tasa),
        ];
    }
}
