<?php
declare(strict_types=1);

/**
 * Detalle de las cuentas que suman las tarjetas "Deudas arrastradas" del dashboard.
 *
 * Las condiciones y las expresiones de monto son las mismas que
 * DashboardController::deudasArrastre(). Ese método no se toca: aquí solo se
 * abre el GROUP BY para listar cada cuenta. La suma en centavos de estas filas
 * tiene que coincidir con el total de la tarjeta.
 */
final class DeudasArrastreDetalle
{
    /** Sábado que abre la semana operativa (misma regla que el dashboard). */
    public static function corte(?DateTimeImmutable $hoy = null): string
    {
        $hoy ??= new DateTimeImmutable('today');
        return $hoy->modify('-' . (((int) $hoy->format('w') + 1) % 7) . ' days')->format('Y-m-d');
    }

    /**
     * @return list<array{cuenta_id:int, cuenta:string, concepto:string, desde:string, hasta:string, deuda:string}>
     */
    public static function productores(PDO $db, string $corte): array
    {
        $sql = "WITH ultimo AS (
                    SELECT productor_id, MAX(semana_inicio) AS semana_inicio
                    FROM productores_cierres
                    WHERE estado <> 'anulada'
                    GROUP BY productor_id
                )
                SELECT p.id AS cuenta_id, p.nombre AS cuenta,
                       'Saldo del último ciclo cerrado' AS concepto,
                       c.semana_inicio AS desde, c.semana_fin AS hasta,
                       (c.saldo_usd - c.pagado_usd) AS deuda
                FROM productores_cierres c
                JOIN ultimo u ON u.productor_id = c.productor_id AND u.semana_inicio = c.semana_inicio
                JOIN productores p ON p.id = c.productor_id
                WHERE c.semana_fin < :corte_p AND c.saldo_usd > c.pagado_usd
                UNION ALL
                SELECT p.id, p.nombre,
                       'Arrastre del ciclo abierto',
                       c.semana_inicio, c.semana_fin,
                       c.arrastre_anterior_usd
                FROM productores_cierres c
                JOIN ultimo u ON u.productor_id = c.productor_id AND u.semana_inicio = c.semana_inicio
                JOIN productores p ON p.id = c.productor_id
                WHERE c.semana_inicio >= :corte_a AND c.arrastre_anterior_usd > 0
                ORDER BY deuda DESC, cuenta";
        return self::filas($db, $sql, ['corte_p' => $corte, 'corte_a' => $corte]);
    }

    /**
     * @return list<array{cuenta_id:int, cuenta:string, concepto:string, desde:string, hasta:string, deuda:string}>
     */
    public static function nomina(PDO $db, string $corte): array
    {
        $sql = "SELECT e.id AS cuenta_id,
                       TRIM(CONCAT(e.nombres, ' ', e.apellidos)) AS cuenta,
                       'Nómina cerrada sin pagar' AS concepto,
                       n.semana_inicio AS desde, n.semana_fin AS hasta,
                       n.neto_usd AS deuda
                FROM nomina_semanal n
                JOIN empleados e ON e.id = n.empleado_id
                WHERE n.estado = 'cerrada' AND n.neto_usd > 0 AND n.semana_fin < :corte
                ORDER BY n.semana_fin DESC, cuenta";
        return self::filas($db, $sql, ['corte' => $corte]);
    }

    /**
     * @return list<array{cuenta_id:int, cuenta:string, concepto:string, desde:string, hasta:string, deuda:string}>
     */
    public static function transporte(PDO $db, string $corte): array
    {
        $sql = "SELECT t.id AS cuenta_id, t.nombre AS cuenta,
                       'Cierre semanal con saldo' AS concepto,
                       p.fecha_inicio AS desde, p.fecha_fin AS hasta,
                       (p.total_pagar - COALESCE(a.pagado, 0)) AS deuda
                FROM log_pagos_semanales p
                JOIN log_transportistas t ON t.id = p.transportista_id
                LEFT JOIN (
                    SELECT pago_semanal_id, SUM(monto) AS pagado
                    FROM log_pagos_transporte
                    GROUP BY pago_semanal_id
                ) a ON a.pago_semanal_id = p.id
                WHERE p.fecha_fin < :corte AND p.total_pagar > COALESCE(a.pagado, 0)
                ORDER BY p.fecha_fin DESC, cuenta";
        return self::filas($db, $sql, ['corte' => $corte]);
    }

    public static function total(array $filas): string
    {
        $centavos = 0;
        foreach ($filas as $fila) {
            $centavos += Caja::aCentavos($fila['deuda']);
        }
        return Caja::desdeCentavos($centavos);
    }

    /**
     * @param array<string, string> $params
     * @return list<array{cuenta_id:int, cuenta:string, concepto:string, desde:string, hasta:string, deuda:string}>
     */
    private static function filas(PDO $db, string $sql, array $params): array
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'cuenta_id' => (int) $row['cuenta_id'],
                'cuenta'    => (string) $row['cuenta'],
                'concepto'  => (string) $row['concepto'],
                'desde'     => (string) $row['desde'],
                'hasta'     => (string) $row['hasta'],
                'deuda'     => Caja::desdeCentavos(Caja::aCentavos($row['deuda'])),
            ];
        }
        return $out;
    }
}
