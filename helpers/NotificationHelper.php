<?php
declare(strict_types=1);

/**
 * Alertas de la campana del layout.
 *
 *   Próximos cierres: productores activos cuyo dia_cierre cae exactamente mañana o pasado mañana.
 *   Pagos atrasados : productores cuyo último cierre vigente tiene pendiente > 0 (menos pagos aún sin cierre).
 *                     El atraso se cuenta desde el fin del ciclo más antiguo de la cadena impaga
 *                     (ciclos 'arrastrada' contiguos antes del último) y dispara si supera
 *                     estrictamente la tolerancia del productor (Productor::TOLERANCIAS).
 */
final class NotificationHelper
{
    private const DIAS_AVISO = [1, 2];
    private const DIA_CORTO = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    /**
     * @return array{
     *   cierres:list<array{titulo:string, detalle:string, url:string, productor_id:int, fecha_cierre:string, dias:int, monto_usd:string}>,
     *   atrasados:list<array{titulo:string, detalle:string, url:string, productor_id:int, pendiente_usd:string, dias_atraso:int, tolerancia_dias:int}>,
     *   cxc:list<array{titulo:string, detalle:string, url:string, cliente_id:int, factura_id:int, estado:string, dias:int}>
     * }
     */
    public static function getAlertas(?array $user = null, ?string $hoy = null): array
    {
        static $cache = [];
        $user ??= current_user();
        $vacio = ['cierres' => [], 'atrasados' => [], 'cxc' => []];
        if ($user === null) {
            return $vacio;
        }
        $hoy ??= date('Y-m-d');
        return $cache[$user['id'] . '|' . $hoy] ??= [
            'cierres'   => has_permiso($user, 'productores') ? self::proximosCierres(get_db(), $hoy) : [],
            'atrasados' => has_permiso($user, 'productores') ? self::pagosAtrasados(get_db(), $hoy) : [],
            'cxc'       => has_permiso($user, 'facturas') ? self::facturasCxC() : [],
        ];
    }

    /** @return list<array{titulo:string, detalle:string, url:string, cliente_id:int, factura_id:int, estado:string, dias:int}> */
    private static function facturasCxC(): array
    {
        $cxc = new CxCService(get_db(), new CorrelativoService(get_db()));
        $out = [];
        foreach ($cxc->alertasCampana()['lista'] as $a) {
            $vencida = $a['estado'] === 'vencida';
            $dias = (int) $a['dias'];
            $out[] = [
                'titulo'     => $a['cliente'] . ' · ' . $a['numero'],
                'detalle'    => $vencida
                    ? ($dias <= 0 ? 'Vence hoy' : $dias . ' día' . ($dias === 1 ? '' : 's') . ' de atraso') . ' · $' . num($a['saldo'])
                    : ($dias <= 0 ? 'Vence hoy' : 'Vence en ' . $dias . ' día' . ($dias === 1 ? '' : 's')) . ' · $' . num($a['saldo']),
                'url'        => url('facturas', ['cliente_id' => $a['cliente_id'], 'factura_id' => $a['factura_id']]),
                'cliente_id' => (int) $a['cliente_id'],
                'factura_id' => (int) $a['factura_id'],
                'estado'     => (string) $a['estado'],
                'dias'       => $dias,
            ];
        }
        return $out;
    }

    private static function proximosCierres(PDO $db, string $hoy): array
    {
        $base = new DateTimeImmutable($hoy);
        $fechas = [];
        foreach (self::DIAS_AVISO as $n) {
            $f = $base->modify("+$n days");
            $fechas[(int) $f->format('N')] = ['fecha' => $f->format('Y-m-d'), 'dias' => $n];
        }
        [$d1, $d2] = array_keys($fechas);

        // Litros y monto acumulados en el ciclo que está por cerrar (entregas aún sin cierre).
        $stmt = $db->prepare(
            'SELECT p.id, p.nombre, p.dia_cierre,
                    COALESCE(SUM(e.litros), 0) AS litros, COALESCE(SUM(e.subtotal_usd), 0) AS monto_usd, COUNT(e.id) AS entregas
             FROM productores p
             LEFT JOIN productores_entregas e
               ON e.productor_id = p.id AND e.cierre_id IS NULL
              AND e.fecha BETWEEN (CASE p.dia_cierre WHEN :da1 THEN :fa1 ELSE :fb1 END) - INTERVAL 6 DAY
                              AND (CASE p.dia_cierre WHEN :da2 THEN :fa2 ELSE :fb2 END)
             WHERE p.activo = 1 AND p.dia_cierre IN (:d1, :d2)
             GROUP BY p.id, p.nombre, p.dia_cierre
             ORDER BY p.nombre'
        );
        $stmt->execute([
            'da1' => $d1, 'fa1' => $fechas[$d1]['fecha'], 'fb1' => $fechas[$d2]['fecha'],
            'da2' => $d1, 'fa2' => $fechas[$d1]['fecha'], 'fb2' => $fechas[$d2]['fecha'],
            'd1'  => $d1, 'd2' => $d2,
        ]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $cierre = $fechas[(int) $r['dia_cierre']];
            $fecha = new DateTimeImmutable($cierre['fecha']);
            $out[] = [
                'titulo'       => (string) $r['nombre'],
                'detalle'      => sprintf(
                    'Cierra %s (%s %s) · %s L · $%s',
                    $cierre['dias'] === 1 ? 'mañana' : 'en 2 días',
                    self::DIA_CORTO[(int) $fecha->format('N')],
                    $fecha->format('d/m'),
                    num($r['litros'], 1),
                    num($r['monto_usd'])
                ),
                'url'          => url('productor_control', [
                    'id'     => (int) $r['id'],
                    'semana' => CierreSemanal::semanaDe($cierre['fecha'], (int) $r['dia_cierre']),
                ]),
                'productor_id' => (int) $r['id'],
                'fecha_cierre' => $cierre['fecha'],
                'dias'         => $cierre['dias'],
                'monto_usd'    => (string) $r['monto_usd'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['dias'], $a['titulo']] <=> [$b['dias'], $b['titulo']]);
        return $out;
    }

    private static function pagosAtrasados(PDO $db, string $hoy): array
    {
        $rows = $db->query(
            "SELECT c.productor_id, c.semana_inicio, c.semana_fin, c.estado, c.saldo_usd - c.pagado_usd AS pendiente,
                    p.nombre, p.tolerancia_pago
             FROM productores_cierres c
             JOIN productores p ON p.id = c.productor_id
             WHERE c.estado <> 'anulada'
               AND c.productor_id IN (
                   SELECT productor_id FROM productores_cierres
                   WHERE estado IN ('cerrada','parcial') AND saldo_usd - pagado_usd > 0
               )
             ORDER BY c.productor_id, c.semana_inicio"
        )->fetchAll();

        $porProductor = [];
        foreach ($rows as $r) {
            $porProductor[(int) $r['productor_id']][] = $r;
        }
        $abiertos = (new PagoProductor($db))->abiertosPorProductor();
        $hoyDt = new DateTimeImmutable($hoy);

        $out = [];
        foreach ($porProductor as $pid => $cadena) {
            $ultimo = end($cadena);
            $pendiente = Caja::aCentavos($ultimo['pendiente']) - Caja::aCentavos($abiertos[$pid] ?? 0);
            if (!in_array($ultimo['estado'], ['cerrada', 'parcial'], true) || $pendiente <= 0) {
                continue;
            }

            $origen = $ultimo;
            for ($i = count($cadena) - 2; $i >= 0 && $cadena[$i]['estado'] === 'arrastrada'; $i--) {
                $origen = $cadena[$i];
            }

            $atraso = (int) (new DateTimeImmutable((string) $origen['semana_fin']))->diff($hoyDt)->format('%r%a');
            $tolerancia = (string) $ultimo['tolerancia_pago'];
            $limite = Productor::TOLERANCIAS[$tolerancia] ?? Productor::TOLERANCIAS[Productor::TOLERANCIA_DEFAULT];
            if ($atraso <= $limite) {
                continue;
            }

            $ciclos = count($cadena) - array_search($origen, $cadena, true);
            $out[] = [
                'titulo'          => (string) $ultimo['nombre'],
                'detalle'         => sprintf(
                    '$%s pendiente · desde ciclo %s–%s%s · %d días de atraso (tolerancia %s: %d)',
                    num($pendiente / 100),
                    (new DateTimeImmutable((string) $origen['semana_inicio']))->format('d/m'),
                    (new DateTimeImmutable((string) $origen['semana_fin']))->format('d/m'),
                    $ciclos > 1 ? " ($ciclos ciclos)" : '',
                    $atraso,
                    $tolerancia,
                    $limite
                ),
                'url'             => url('productor_control', ['id' => $pid, 'tab' => 'cierres']),
                'productor_id'    => $pid,
                'pendiente_usd'   => Caja::desdeCentavos($pendiente),
                'dias_atraso'     => $atraso,
                'tolerancia_dias' => $limite,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['dias_atraso'] <=> $a['dias_atraso']);
        return $out;
    }
}
