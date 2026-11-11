<?php
declare(strict_types=1);

final class DashboardController
{
    private const DIAS_SEMANA = 7;

    private PDO $db;
    private ?float $tasa;

    public function __construct()
    {
        $this->db = get_db();
        $this->tasa = TasaCambio::vigente($this->db)['tasa'] ?? null;
    }

    public function index(): void
    {
        $user = current_user();

        // Semana operativa: sábado a viernes.
        $hoy = new DateTimeImmutable('today');
        $semanaInicio = $hoy->modify('-' . (((int) $hoy->format('w') + 1) % 7) . ' days');
        $semanaFin = $semanaInicio->modify('+6 days');
        $ayer = $hoy->modify('-1 day');

        $puede = static fn (string $modulo): bool => has_permiso($user, $modulo);

        $empleados = $puede('empleados')
            ? $this->row(
                'SELECT COUNT(*) AS n, COALESCE(SUM(salario_semanal_usd), 0) AS usd FROM empleados WHERE activo = :activo',
                ['activo' => 1],
                ['n' => 0, 'usd' => 0]
            )
            : null;

        $operacion = [];
        if ($puede('inventario')) {
            $operacion[] = $this->tile('Productos', 'bi-box-seam', 'inventario',
                $this->count('SELECT COUNT(*) FROM productos WHERE activo = :activo', ['activo' => 1]));
            $operacion[] = $this->tile('Lotes', 'bi-stack', 'inventario',
                $this->count('SELECT COUNT(*) FROM lotes'));
        }
        if ($puede('ventas')) {
            $operacion[] = $this->tile('Ventas', 'bi-receipt', 'ventas',
                $this->count('SELECT COUNT(*) FROM ventas WHERE estado = :estado', ['estado' => 'emitida']));
        }
        if ($empleados !== null) {
            $operacion[] = $this->tile('Recursos Humanos', 'bi-people-fill', 'empleados', (int) $empleados['n']);
        }
        if ($puede('productores')) {
            // La tabla proveedores llega con su propio módulo; mientras no exista, la tarjeta muestra 0.
            $operacion[] = $this->tile('Proveedores', 'bi-bus-front', 'productores',
                $this->count('SELECT COUNT(*) FROM proveedores WHERE activo = :activo', ['activo' => 1]));
            $operacion[] = $this->tile('Productores', 'bi-droplet-fill', 'productores',
                $this->count('SELECT COUNT(*) FROM productores WHERE activo = :activo', ['activo' => 1]));
        }

        $finanzas = null;
        if ($puede('finanzas')) {
            $caja = $this->row(
                'SELECT COALESCE(SUM(CASE WHEN m.tipo = :ing THEN m.monto_usd END), 0) AS ingresos,
                        COALESCE(SUM(CASE WHEN m.tipo = :egr THEN m.monto_usd END), 0) AS egresos
                 FROM caja_movimientos m
                 WHERE m.revierte_id IS NULL
                   AND NOT EXISTS (SELECT 1 FROM caja_movimientos r WHERE r.revierte_id = m.id)',
                ['ing' => 'ingreso', 'egr' => 'egreso'],
                ['ingresos' => 0, 'egresos' => 0]
            );
            $libro = new Caja($this->db);
            $cobrado = $libro->cobradoClientes();
            $salidas = $libro->desembolsosOperativos();
            $ingresos = (float) $caja['ingresos'] + (float) $cobrado['usd'];
            $egresos = (float) $caja['egresos'] + (float) $salidas['usd'];
            $finanzas = [
                'ingresos' => $this->money($ingresos),
                'egresos'  => $this->money($egresos),
                'balance'  => $this->money($ingresos - $egresos),
            ];
        }

        $indicadores = [];
        if ($puede('facturas')) {
            // Misma cifra que "Total a cobrar" de Control de Facturas (CxCService::obtenerMetricasDashboard):
            // suma de saldo_pendiente de toda la cartera no anulada (vigente, por vencer y vencida).
            $fact = $this->row(
                "SELECT COALESCE(SUM(saldo_pendiente > 0), 0) AS n,
                        COALESCE(SUM(saldo_pendiente), 0) AS usd
                 FROM cxc_facturas
                 WHERE estado <> 'anulada'",
                [],
                ['n' => 0, 'usd' => 0]
            );
            $n = (int) $fact['n'];
            $indicadores['facturas'] = [
                'monto' => $this->money((float) $fact['usd']),
                'nota'  => $n . ($n === 1 ? ' factura con saldo' : ' facturas con saldo'),
            ];
        }
        if ($puede('control_leche')) {
            $leche = $this->row(
                'SELECT COUNT(*) AS n, COALESCE(SUM(litros), 0) AS litros FROM productores_entregas WHERE fecha = :fecha',
                ['fecha' => $ayer->format('Y-m-d')],
                ['n' => 0, 'litros' => 0]
            );
            $n = (int) $leche['n'];
            $indicadores['leche_ayer'] = [
                'litros' => (float) $leche['litros'],
                'nota'   => ($n === 0 ? 'Sin entregas registradas' : $n . ($n === 1 ? ' entrega' : ' entregas'))
                    . ' · ' . $ayer->format('d/m/Y'),
            ];
        }
        if ($puede('productores')) {
            $gasto = $this->row(
                'SELECT COUNT(*) AS n, COALESCE(SUM(litros), 0) AS litros, COALESCE(SUM(subtotal_usd), 0) AS usd
                 FROM productores_entregas
                 WHERE fecha BETWEEN :ini AND :fin',
                ['ini' => $semanaInicio->format('Y-m-d'), 'fin' => $semanaFin->format('Y-m-d')],
                ['n' => 0, 'litros' => 0, 'usd' => 0]
            );
            $n = (int) $gasto['n'];
            $usd = (float) $gasto['usd'];
            $diasTranscurridos = (int) $semanaInicio->diff($hoy)->days + 1;
            $indicadores['gasto_leche'] = [
                'monto' => $this->money($usd),
                'nota'  => sprintf(
                    '%s L acumulados · promedio %s $/día · %s',
                    num((float) $gasto['litros'], 1),
                    num($usd / $diasTranscurridos),
                    $n === 0 ? 'Sin entregas' : $n . ($n === 1 ? ' entrega' : ' entregas')
                ),
            ];
        }
        if ($empleados !== null) {
            $n = (int) $empleados['n'];
            $semanal = (float) $empleados['usd'];
            $indicadores['presupuesto'] = [
                'monto'  => $this->money($semanal),
                'nota'   => $n . ($n === 1 ? ' empleado activo' : ' empleados activos') . ' · salarios semanales',
                'diario' => $this->money($semanal / self::DIAS_SEMANA),
            ];
        }

        view('dashboard', [
            'semanaInicio'    => $semanaInicio,
            'semanaFin'       => $semanaFin,
            'operacion'       => $operacion,
            'finanzas'        => $finanzas,
            'indicadores'     => $indicadores,
            'deudasArrastre'  => $this->deudasArrastre($semanaInicio, $puede),
        ]);
    }

    /**
     * Deuda viva de semanas ya cerradas, por semana operativa (sábado a viernes) y categoría.
     * No existe una tabla cuentas_por_pagar: cada categoría vive en su módulo.
     *
     * Productores: el pendiente de una semana arrastrada ya está dentro del saldo de la
     * siguiente, así que solo se toma el último cierre de cada productor. Si ese cierre ya
     * venció, cuenta su saldo menos lo pagado. Si el ciclo abierto arrastra saldo anterior,
     * ese arrastre se atribuye al día anterior al corte (la última semana cerrada).
     *
     * @return array{categorias:list<string>, semanas:list<array>, totales:array<string, array{cuentas:int, usd:float}>, total:array{cuentas:int, usd:float}}|null
     */
    private function deudasArrastre(DateTimeImmutable $corte, callable $puede): ?array
    {
        $partes = [];
        if ($puede('productores')) {
            $partes[] = "SELECT 'Productores' AS categoria, c.semana_fin AS vencimiento,
                                COUNT(*) AS cuentas, SUM(c.saldo_usd - c.pagado_usd) AS deuda
                         FROM productores_cierres c
                         JOIN ultimo u ON u.productor_id = c.productor_id AND u.semana_inicio = c.semana_inicio
                         WHERE c.semana_fin < :corte_p AND c.saldo_usd > c.pagado_usd
                         GROUP BY c.semana_fin
                         UNION ALL
                         SELECT 'Productores',
                                LEAST(DATE_SUB(c.semana_inicio, INTERVAL 1 DAY), DATE_SUB(:corte_a, INTERVAL 1 DAY)),
                                COUNT(*), SUM(c.arrastre_anterior_usd)
                         FROM productores_cierres c
                         JOIN ultimo u ON u.productor_id = c.productor_id AND u.semana_inicio = c.semana_inicio
                         WHERE c.semana_inicio >= :corte_b AND c.arrastre_anterior_usd > 0
                         GROUP BY c.semana_inicio";
        }
        if ($puede('empleados')) {
            $partes[] = "SELECT 'Nómina', n.semana_fin, COUNT(*), SUM(n.neto_usd)
                         FROM nomina_semanal n
                         WHERE n.estado = 'cerrada' AND n.neto_usd > 0 AND n.semana_fin < :corte_n
                         GROUP BY n.semana_fin";
        }
        if ($puede('transportistas')) {
            $partes[] = "SELECT 'Transporte', p.fecha_fin, COUNT(*),
                                SUM(p.total_pagar - COALESCE(a.pagado, 0))
                         FROM log_pagos_semanales p
                         LEFT JOIN (
                             SELECT pago_semanal_id, SUM(monto) AS pagado
                             FROM log_pagos_transporte
                             GROUP BY pago_semanal_id
                         ) a ON a.pago_semanal_id = p.id
                         WHERE p.fecha_fin < :corte_t AND p.total_pagar > COALESCE(a.pagado, 0)
                         GROUP BY p.fecha_fin";
        }
        if ($partes === []) {
            return null;
        }

        $cte = $puede('productores')
            ? "WITH ultimo AS (
                   SELECT productor_id, MAX(semana_inicio) AS semana_inicio
                   FROM productores_cierres
                   WHERE estado <> 'anulada'
                   GROUP BY productor_id
               ) "
            : '';
        $sql = $cte . 'SELECT categoria, vencimiento, cuentas, deuda FROM ('
            . implode(' UNION ALL ', $partes) . ') d ORDER BY vencimiento DESC';

        $params = [];
        foreach (['corte_p', 'corte_a', 'corte_b', 'corte_n', 'corte_t'] as $nombre) {
            if (str_contains($sql, ':' . $nombre)) {
                $params[$nombre] = $corte->format('Y-m-d');
            }
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $filas = $stmt->fetchAll();
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
            $filas = [];
        }

        $categorias = [];
        if ($puede('productores')) {
            $categorias[] = 'Productores';
        }
        if ($puede('empleados')) {
            $categorias[] = 'Nómina';
        }
        if ($puede('transportistas')) {
            $categorias[] = 'Transporte';
        }

        $vacia = static fn (): array => ['cuentas' => 0, 'usd' => 0.0];
        $totales = array_fill_keys($categorias, $vacia());
        $semanas = [];
        foreach ($filas as $f) {
            $categoria = (string) $f['categoria'];
            if (!isset($totales[$categoria])) {
                continue;
            }
            $inicio = self::inicioSemana((string) $f['vencimiento']);
            $semanas[$inicio] ??= array_fill_keys($categorias, $vacia());
            $usd = round((float) $f['deuda'], 2);
            $n = (int) $f['cuentas'];
            $semanas[$inicio][$categoria]['cuentas'] += $n;
            $semanas[$inicio][$categoria]['usd'] = round($semanas[$inicio][$categoria]['usd'] + $usd, 2);
            $totales[$categoria]['cuentas'] += $n;
            $totales[$categoria]['usd'] = round($totales[$categoria]['usd'] + $usd, 2);
        }
        krsort($semanas);

        $lista = [];
        foreach ($semanas as $inicio => $celdas) {
            $fin = (new DateTimeImmutable($inicio))->modify('+6 days');
            $totalSemana = 0.0;
            $cuentasSemana = 0;
            foreach ($celdas as $celda) {
                $totalSemana += $celda['usd'];
                $cuentasSemana += $celda['cuentas'];
            }
            $lista[] = [
                'inicio'  => $inicio,
                'fin'     => $fin->format('Y-m-d'),
                'etiqueta'=> (new DateTimeImmutable($inicio))->format('d/m') . ' – ' . $fin->format('d/m/Y'),
                'celdas'  => $celdas,
                'cuentas' => $cuentasSemana,
                'usd'     => round($totalSemana, 2),
            ];
        }

        $total = $vacia();
        foreach ($totales as $t) {
            $total['cuentas'] += $t['cuentas'];
            $total['usd'] = round($total['usd'] + $t['usd'], 2);
        }

        return [
            'categorias' => $categorias,
            'semanas'    => $lista,
            'totales'    => $totales,
            'total'      => $total,
        ];
    }

    /** Sábado que abre la semana operativa que contiene $fecha (misma regla que el dashboard). */
    private static function inicioSemana(string $fecha): string
    {
        $dia = new DateTimeImmutable($fecha);
        return $dia->modify('-' . (((int) $dia->format('w') + 1) % 7) . ' days')->format('Y-m-d');
    }

    /** @return array{label:string, icon:string, p:string, valor:int} */
    private function tile(string $label, string $icon, string $modulo, int $valor): array
    {
        return ['label' => $label, 'icon' => $icon, 'p' => modulos()[$modulo]['p'], 'valor' => $valor];
    }

    /** Monto en USD y su equivalente en Bs a la tasa vigente (null si aún no hay tasa registrada). */
    private function money(float $usd): array
    {
        $usd = round($usd, 2);
        return ['usd' => $usd, 'bs' => $this->tasa === null ? null : round($usd * $this->tasa, 2)];
    }

    private function count(string $sql, array $params = []): int
    {
        return (int) ($this->row($sql, $params, [0])[0] ?? 0);
    }

    /**
     * Ejecuta una consulta preparada de una sola fila. Si la tabla aún no existe
     * (módulo sin migrar, SQLSTATE 42S02) devuelve $default; cualquier otro error se propaga.
     */
    private function row(string $sql, array $params, array $default): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(array_is_list($default) ? PDO::FETCH_NUM : PDO::FETCH_ASSOC);
            return $row === false ? $default : $row;
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
            return $default;
        }
    }
}
