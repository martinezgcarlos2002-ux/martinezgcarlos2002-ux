<?php
declare(strict_types=1);

/**
 * Resumen gerencial por rango de fechas.
 * Tablas reales: productores_entregas, produccion_diaria, productos, cxc_*, log_fletes_diarios.
 */
final class ResumenService
{
    public const MAX_DIAS = 366;

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array{
     *   inicio:string, fin:string,
     *   kpis:array<string, mixed>,
     *   pl:array<string, mixed>,
     *   balances:array<string, mixed>,
     *   produccion:list<array<string, mixed>>,
     *   cobranzas:list<array<string, mixed>>
     * }
     */
    public function porRango(string $inicio, string $fin): array
    {
        $rango = ['inicio' => $inicio, 'fin' => $fin];

        $leche = $this->escalar(
            'SELECT COALESCE(SUM(litros), 0) FROM productores_entregas
             WHERE fecha BETWEEN :inicio AND :fin',
            $rango
        );
        $costoLeche = $this->escalar(
            'SELECT COALESCE(SUM(subtotal_usd), 0) FROM productores_entregas
             WHERE fecha BETWEEN :inicio AND :fin',
            $rango
        );

        $prod = $this->db->prepare(
            'SELECT COALESCE(p.nombre, CONCAT(\'Producto #\', pd.producto_id)) AS nombre,
                    COALESCE(c.unidad_medida, p.unidad_medida, \'KG\') AS unidad_medida,
                    COALESCE(SUM(pd.cantidad), 0) AS cantidad,
                    COALESCE(SUM(pd.total_usd), 0) AS total_usd
             FROM produccion_diaria pd
             LEFT JOIN productos p ON p.id = pd.producto_id
             LEFT JOIN prod_catalogo c ON c.producto_id = pd.producto_id
             WHERE pd.fecha BETWEEN :inicio AND :fin
             GROUP BY pd.producto_id, p.nombre, c.unidad_medida, p.unidad_medida
             ORDER BY cantidad DESC, nombre'
        );
        $prod->execute($rango);
        $porProducto = $prod->fetchAll();

        $porUom = ['KG' => '0', 'LTS' => '0', 'UND' => '0'];
        $valorProd = '0.00';
        $produccion = [];
        foreach ($porProducto as $fila) {
            $uom = Producto::uom((string) ($fila['unidad_medida'] ?? 'KG'));
            $cantidad = $this->num((string) ($fila['cantidad'] ?? '0'));
            $total = $this->num((string) ($fila['total_usd'] ?? '0'));
            $porUom[$uom] = $this->sumarDec($porUom[$uom], $cantidad);
            $valorProd = $this->sumarUsd($valorProd, $total);
            $produccion[] = [
                'nombre' => (string) ($fila['nombre'] ?? ''),
                'cantidad' => $cantidad,
                'unidad_medida' => $uom,
                'total_usd' => $this->usd($total),
            ];
        }

        $kg = $porUom['KG'];
        $rendimiento = $this->dividir((float) $leche, (float) $kg, 4);

        $facturado = $this->escalar(
            "SELECT COALESCE(SUM(monto_total), 0) FROM cxc_facturas
             WHERE fecha_emision BETWEEN :inicio AND :fin AND estado <> 'anulada'",
            $rango
        );
        $cobrado = $this->escalar(
            "SELECT COALESCE(SUM(monto), 0) FROM cxc_pagos
             WHERE fecha BETWEEN :inicio AND :fin AND estado = 'aplicado'",
            $rango
        );
        $devoluciones = $this->escalar(
            "SELECT COALESCE(SUM(monto), 0) FROM cxc_devoluciones
             WHERE fecha BETWEEN :inicio AND :fin AND estado <> 'anulada'",
            $rango
        );

        $metodos = $this->db->prepare(
            "SELECT metodo, COALESCE(SUM(monto), 0) AS total, COUNT(*) AS n
             FROM cxc_pagos
             WHERE fecha BETWEEN :inicio AND :fin AND estado = 'aplicado'
             GROUP BY metodo
             ORDER BY total DESC"
        );
        $metodos->execute($rango);

        $fletes = $this->escalar(
            'SELECT COALESCE(SUM(costo_flete), 0) FROM log_fletes_diarios
             WHERE fecha BETWEEN :inicio AND :fin',
            $rango
        );
        $litrosFlete = $this->escalar(
            'SELECT COALESCE(SUM(litros_recolectados), 0) FROM log_fletes_diarios
             WHERE fecha BETWEEN :inicio AND :fin',
            $rango
        );

        $ingresosNetos = $this->restarUsd($facturado, $devoluciones);
        $costosOp = $this->sumarUsd($costoLeche, $fletes);
        $utilidad = $this->restarUsd($ingresosNetos, $costosOp);
        $margenPct = $this->porcentaje((float) $utilidad, (float) $ingresosNetos);

        $cxcCartera = $this->escalar(
            "SELECT COALESCE(SUM(saldo_pendiente), 0) FROM cxc_facturas
             WHERE estado <> 'anulada' AND fecha_emision <= :corte AND saldo_pendiente > 0",
            ['corte' => $fin]
        );
        $cxcVencida = $this->escalar(
            "SELECT COALESCE(SUM(saldo_pendiente), 0) FROM cxc_facturas
             WHERE estado <> 'anulada'
               AND fecha_emision <= :corte_emision
               AND fecha_vencimiento < :corte_venc
               AND saldo_pendiente > 0",
            ['corte_emision' => $fin, 'corte_venc' => $fin]
        );

        $cxpProductores = $this->escalar(
            "SELECT COALESCE(SUM(CASE
                    WHEN saldo_usd > pagado_usd THEN saldo_usd - pagado_usd
                    ELSE 0
                 END), 0)
             FROM productores_cierres
             WHERE estado IN ('cerrada', 'parcial') AND semana_fin <= :corte",
            ['corte' => $fin]
        );
        $cxpFletes = $this->escalar(
            'SELECT COALESCE(SUM(costo_flete), 0) FROM log_fletes_diarios
             WHERE fecha <= :corte AND pago_id IS NULL',
            ['corte' => $fin]
        );
        $cxpTotal = $this->sumarUsd($cxpProductores, $cxpFletes);

        $cobranzas = [];
        foreach ($metodos->fetchAll() as $m) {
            $clave = (string) ($m['metodo'] ?? '');
            $cobranzas[] = [
                'metodo' => $clave,
                'etiqueta' => CxCService::METODOS[$clave] ?? $clave,
                'total' => $this->usd((string) ($m['total'] ?? '0')),
                'n' => (int) ($m['n'] ?? 0),
            ];
        }

        $pl = [
            'ingresos_brutos' => $this->usd($facturado),
            'devoluciones' => $this->usd($devoluciones),
            'ingresos_netos' => $this->usd($ingresosNetos),
            'costo_leche' => $this->usd($costoLeche),
            'costo_fletes' => $this->usd($fletes),
            'costos_operativos' => $this->usd($costosOp),
            'utilidad' => $this->usd($utilidad),
            'margen_pct' => $margenPct,
        ];
        $balances = [
            'cxc_cartera' => $this->usd($cxcCartera),
            'cxc_vencida' => $this->usd($cxcVencida),
            'cxc_cobrado_periodo' => $this->usd($cobrado),
            'cxp_productores' => $this->usd($cxpProductores),
            'cxp_fletes' => $this->usd($cxpFletes),
            'cxp_total' => $this->usd($cxpTotal),
        ];

        return [
            'inicio' => $inicio,
            'fin' => $fin,
            'kpis' => [
                'litros' => $this->num($leche),
                'kg' => $this->num($kg),
                'lts' => $this->num($porUom['LTS']),
                'und' => $this->num($porUom['UND']),
                'rendimiento' => $rendimiento,
                'valor_produccion' => $this->usd($valorProd),
                'facturado' => $this->usd($facturado),
                'cobrado' => $this->usd($cobrado),
                'devoluciones' => $this->usd($devoluciones),
                'transporte' => $this->usd($fletes),
                'litros_flete' => $this->num($litrosFlete),
                'costo_leche' => $this->usd($costoLeche),
                'ingresos_netos' => $this->usd($ingresosNetos),
                'utilidad' => $this->usd($utilidad),
                'margen_pct' => $margenPct,
                'cxc_cartera' => $this->usd($cxcCartera),
                'cxc_vencida' => $this->usd($cxcVencida),
                'cxp_total' => $this->usd($cxpTotal),
            ],
            'pl' => $pl,
            'balances' => $balances,
            'produccion' => $produccion,
            'cobranzas' => $cobranzas,
        ];
    }

    /** Lunes–domingo según NOMINA_DIA_INICIO. */
    public static function semanaDe(string $fecha): array
    {
        $dia = new DateTimeImmutable($fecha);
        $atras = ((int) $dia->format('N') - NOMINA_DIA_INICIO + 7) % 7;
        $inicio = $dia->modify(sprintf('-%d days', $atras));
        return [
            'inicio' => $inicio->format('Y-m-d'),
            'fin' => $inicio->modify('+6 days')->format('Y-m-d'),
        ];
    }

    public static function mesDe(string $fecha): array
    {
        $d = new DateTimeImmutable($fecha);
        return [
            'inicio' => $d->modify('first day of this month')->format('Y-m-d'),
            'fin' => $d->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    /** @param array<string, string> $params */
    private function escalar(string $sql, array $params): string
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return '0';
        }
        return $this->num((string) $v);
    }

    /** Nunca divide si el denominador es 0 o no es finito. */
    private function dividir(float $numerador, float $denominador, int $decimales): float
    {
        if (!is_finite($numerador) || !is_finite($denominador) || $denominador <= 0.0) {
            return 0.0;
        }
        return round($numerador / $denominador, $decimales);
    }

    /** (utilidad / ingresos) * 100. Si no hay ingresos, el margen es 0. */
    private function porcentaje(float $parte, float $total): float
    {
        return $this->dividir($parte * 100.0, $total, 2);
    }

    private function num(?string $v): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '0';
        }
        return $v;
    }

    private function usd(?string $v): string
    {
        return Caja::desdeCentavos(Caja::aCentavos($this->num($v)));
    }

    private function sumarDec(string $a, string $b): string
    {
        return (string) round(((float) $this->num($a)) + ((float) $this->num($b)), 3);
    }

    private function sumarUsd(string $a, string $b): string
    {
        return Caja::desdeCentavos(Caja::aCentavos($this->num($a)) + Caja::aCentavos($this->num($b)));
    }

    private function restarUsd(string $a, string $b): string
    {
        return Caja::desdeCentavos(Caja::aCentavos($this->num($a)) - Caja::aCentavos($this->num($b)));
    }
}
