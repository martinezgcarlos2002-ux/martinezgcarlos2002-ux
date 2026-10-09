<?php
declare(strict_types=1);

/**
 * Motor de costos de producción diaria y su fotografía persistida (produccion_costos + produccion_costos_quesos).
 *
 *   precio_litro   = costo de la leche recibida el día / litros recibidos el día   (ponderado por litros)
 *   costo_leche    = litros procesados × precio_litro
 *   gasto_total    = costo_leche + nómina + personal extra + transporte + operativo + otros
 *   kg_dia(i)      = litros_dia(i) × kg_ref(i) / litros_ref(i)
 *   costo_total(i) = gasto_total × kg_dia(i) / Σ kg_dia   (prorrateo por kg, residuo por mayor resto)
 *                    + sal(i) + empaque(i) + otros(i)
 *   costo_kg(i)    = costo_total(i) / kg_dia(i)
 *   precio_kg(i)   = costo_kg(i) × (1 + margen / 100)
 *
 * Dinero en centavos y litros/kg en milésimas enteras: el prorrateo suma exactamente gasto_total.
 */
final class CostoProduccion
{
    public const GASTOS = [
        'nomina'         => 'Nómina',
        'personal_extra' => 'Costo personal extra',
        'transporte'     => 'Transporte',
        'operativo'      => 'Costo operativo',
        'otros'          => 'Otros gastos',
    ];
    public const EXTRAS = ['sal', 'empaque', 'otros'];
    public const MAX_FORMULAS = 30;
    public const MARGEN_MAX = 500;

    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{fecha:string, litros_procesados:string, margen:string, gastos:array<string,string>,
     *              formulas:list<array{nombre:string, litros_ref:string, kg_ref:string, litros_dia:?string, sal:string, empaque:string, otros:string}>} $in
     *        Valores ya validados como decimales en texto.
     * @param array{litros:string, costo_usd:string} $lecheDia  totales de productores_entregas de la fecha
     */
    public static function calcular(array $in, array $lecheDia): array
    {
        $recibidosMil = self::aMilesimas($lecheDia['litros']);
        $costoRecibidaCent = Caja::aCentavos($lecheDia['costo_usd']);
        if ($recibidosMil <= 0) {
            throw new ValidationException(['fecha' => 'No hay leche recibida en esa fecha: sin entregas no hay costo base de la leche.']);
        }
        $procesadosMil = self::aMilesimas($in['litros_procesados']);

        $costoLecheCent = self::dividirRedondeo($costoRecibidaCent * $procesadosMil, $recibidosMil);
        $gastosCent = [];
        foreach (array_keys(self::GASTOS) as $clave) {
            $gastosCent[$clave] = Caja::aCentavos($in['gastos'][$clave] ?? '0');
        }
        $gastoTotalCent = $costoLecheCent + array_sum($gastosCent);

        $litrosDia = self::repartirLitros($in['formulas'], $procesadosMil);

        $lineas = [];
        $kgTotalMil = 0;
        foreach ($in['formulas'] as $i => $f) {
            $litrosRefMil = self::aMilesimas($f['litros_ref']);
            $kgRefMil = self::aMilesimas($f['kg_ref']);
            $kgMil = self::dividirRedondeo($litrosDia[$i] * $kgRefMil, $litrosRefMil);
            if ($kgMil <= 0) {
                throw new ValidationException(["formulas.$i" => sprintf('%s: el rendimiento del día da 0 kg.', $f['nombre'])]);
            }
            $kgTotalMil += $kgMil;
            $lineas[$i] = [
                'litros_ref_mil' => $litrosRefMil,
                'kg_ref_mil'     => $kgRefMil,
                'litros_dia_mil' => $litrosDia[$i],
                'kg_mil'         => $kgMil,
                'extras_cent'    => array_sum(array_map(static fn (string $k): int => Caja::aCentavos($f[$k]), self::EXTRAS)),
            ];
        }

        $prorrateo = self::prorratear($gastoTotalCent, array_column($lineas, 'kg_mil'));
        $margenCentesimas = (int) round((float) $in['margen'] * 100);

        $quesos = [];
        foreach ($in['formulas'] as $i => $f) {
            $l = $lineas[$i];
            $costoTotalCent = $prorrateo[$i] + $l['extras_cent'];
            $costoKgDiezmil = self::dividirRedondeo($costoTotalCent * 1000 * 100, $l['kg_mil']);
            $precioKgDiezmil = self::dividirRedondeo($costoKgDiezmil * (10000 + $margenCentesimas), 10000);
            $quesos[] = [
                'nombre'              => $f['nombre'],
                'litros_ref'          => self::desdeMilesimas($l['litros_ref_mil']),
                'kg_ref'              => self::desdeMilesimas($l['kg_ref_mil']),
                'litros_dia'          => self::desdeMilesimas($l['litros_dia_mil']),
                'kg_dia'              => self::desdeMilesimas($l['kg_mil']),
                'sal_usd'             => Caja::desdeCentavos(Caja::aCentavos($f['sal'])),
                'empaque_usd'         => Caja::desdeCentavos(Caja::aCentavos($f['empaque'])),
                'otros_usd'           => Caja::desdeCentavos(Caja::aCentavos($f['otros'])),
                'costo_prorrateado_usd' => Caja::desdeCentavos($prorrateo[$i]),
                'costo_total_usd'     => Caja::desdeCentavos($costoTotalCent),
                'costo_kg_usd'        => self::desdeDiezmilesimas($costoKgDiezmil),
                'precio_venta_kg_usd' => self::desdeDiezmilesimas($precioKgDiezmil),
            ];
        }

        return [
            'fecha'              => $in['fecha'],
            'litros_recibidos'   => self::desdeMilesimas($recibidosMil),
            'litros_procesados'  => self::desdeMilesimas($procesadosMil),
            'precio_litro_usd'   => self::desdeDiezmilesimas(self::dividirRedondeo($costoRecibidaCent * 1000 * 100, $recibidosMil)),
            'costo_leche_usd'    => Caja::desdeCentavos($costoLecheCent),
            'gastos'             => array_map([Caja::class, 'desdeCentavos'], $gastosCent),
            'gasto_total_usd'    => Caja::desdeCentavos($gastoTotalCent),
            'kg_total'           => self::desdeMilesimas($kgTotalMil),
            'costo_comun_kg_usd' => self::desdeDiezmilesimas(self::dividirRedondeo($gastoTotalCent * 1000 * 100, $kgTotalMil)),
            'margen_pct'         => number_format($margenCentesimas / 100, 2, '.', ''),
            'quesos'             => $quesos,
        ];
    }

    /** Guarda (o reemplaza) la fotografía del día. Corre dentro de Database::transaction(). */
    public function guardar(array $r, int $usuarioId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM produccion_costos WHERE fecha = :fecha FOR UPDATE');
        $stmt->execute(['fecha' => $r['fecha']]);
        $id = $stmt->fetchColumn();

        $cabecera = [
            'litros_recibidos'   => $r['litros_recibidos'],
            'litros_procesados'  => $r['litros_procesados'],
            'precio_litro_usd'   => $r['precio_litro_usd'],
            'costo_leche_usd'    => $r['costo_leche_usd'],
            'nomina_usd'         => $r['gastos']['nomina'],
            'personal_extra_usd' => $r['gastos']['personal_extra'],
            'transporte_usd'     => $r['gastos']['transporte'],
            'operativo_usd'      => $r['gastos']['operativo'],
            'otros_usd'          => $r['gastos']['otros'],
            'gasto_total_usd'    => $r['gasto_total_usd'],
            'kg_total'           => $r['kg_total'],
            'costo_comun_kg_usd' => $r['costo_comun_kg_usd'],
            'margen_pct'         => $r['margen_pct'],
            'registrado_por'     => $usuarioId,
        ];

        if ($id === false) {
            $stmt = $this->db->prepare(
                'INSERT INTO produccion_costos
                     (fecha, litros_recibidos, litros_procesados, precio_litro_usd, costo_leche_usd, nomina_usd,
                      personal_extra_usd, transporte_usd, operativo_usd, otros_usd, gasto_total_usd, kg_total,
                      costo_comun_kg_usd, margen_pct, registrado_por)
                 VALUES
                     (:fecha, :litros_recibidos, :litros_procesados, :precio_litro_usd, :costo_leche_usd, :nomina_usd,
                      :personal_extra_usd, :transporte_usd, :operativo_usd, :otros_usd, :gasto_total_usd, :kg_total,
                      :costo_comun_kg_usd, :margen_pct, :registrado_por)'
            );
            $stmt->execute($cabecera + ['fecha' => $r['fecha']]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $id = (int) $id;
            $stmt = $this->db->prepare(
                'UPDATE produccion_costos
                 SET litros_recibidos = :litros_recibidos, litros_procesados = :litros_procesados,
                     precio_litro_usd = :precio_litro_usd, costo_leche_usd = :costo_leche_usd, nomina_usd = :nomina_usd,
                     personal_extra_usd = :personal_extra_usd, transporte_usd = :transporte_usd,
                     operativo_usd = :operativo_usd, otros_usd = :otros_usd, gasto_total_usd = :gasto_total_usd,
                     kg_total = :kg_total, costo_comun_kg_usd = :costo_comun_kg_usd, margen_pct = :margen_pct,
                     registrado_por = :registrado_por
                 WHERE id = :id'
            );
            $stmt->execute($cabecera + ['id' => $id]);
            $this->db->prepare('DELETE FROM produccion_costos_quesos WHERE costo_id = :id')->execute(['id' => $id]);
        }

        $ins = $this->db->prepare(
            'INSERT INTO produccion_costos_quesos
                 (costo_id, orden, nombre, litros_ref, kg_ref, litros_dia, kg_dia, sal_usd, empaque_usd, otros_usd,
                  costo_total_usd, costo_kg_usd, precio_venta_kg_usd)
             VALUES
                 (:costo_id, :orden, :nombre, :litros_ref, :kg_ref, :litros_dia, :kg_dia, :sal_usd, :empaque_usd, :otros_usd,
                  :costo_total_usd, :costo_kg_usd, :precio_venta_kg_usd)'
        );
        foreach ($r['quesos'] as $orden => $q) {
            $ins->execute([
                'costo_id'            => $id,
                'orden'               => $orden + 1,
                'nombre'              => $q['nombre'],
                'litros_ref'          => $q['litros_ref'],
                'kg_ref'              => $q['kg_ref'],
                'litros_dia'          => $q['litros_dia'],
                'kg_dia'              => $q['kg_dia'],
                'sal_usd'             => $q['sal_usd'],
                'empaque_usd'         => $q['empaque_usd'],
                'otros_usd'           => $q['otros_usd'],
                'costo_total_usd'     => $q['costo_total_usd'],
                'costo_kg_usd'        => $q['costo_kg_usd'],
                'precio_venta_kg_usd' => $q['precio_venta_kg_usd'],
            ]);
        }
        return $id;
    }

    /** @return array{cabecera:array, quesos:list<array>}|null */
    public function findPorFecha(string $fecha): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM produccion_costos WHERE fecha = :fecha');
        $stmt->execute(['fecha' => $fecha]);
        $cabecera = $stmt->fetch();
        return $cabecera === false ? null : ['cabecera' => $cabecera, 'quesos' => $this->quesosDe((int) $cabecera['id'])];
    }

    /** Última fotografía anterior a $fecha: sus fórmulas y margen precargan un día sin datos. */
    public function ultimaAntesDe(string $fecha): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM produccion_costos WHERE fecha < :fecha ORDER BY fecha DESC LIMIT 1');
        $stmt->execute(['fecha' => $fecha]);
        $cabecera = $stmt->fetch();
        return $cabecera === false ? null : ['cabecera' => $cabecera, 'quesos' => $this->quesosDe((int) $cabecera['id'])];
    }

    /** @return list<array> */
    private function quesosDe(int $costoId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM produccion_costos_quesos WHERE costo_id = :id ORDER BY orden');
        $stmt->execute(['id' => $costoId]);
        return $stmt->fetchAll();
    }

    /**
     * Litros del día por fórmula. Sin litros_dia en ninguna se reparten por igual los procesados;
     * si se indican, deben venir en todas y sumar exactamente los procesados.
     * @return list<int>  milésimas de litro, mismo orden que $formulas
     */
    private static function repartirLitros(array $formulas, int $procesadosMil): array
    {
        $indicados = array_filter($formulas, static fn (array $f): bool => $f['litros_dia'] !== null);
        if ($indicados === []) {
            return self::prorratear($procesadosMil, array_fill(0, count($formulas), 1));
        }
        if (count($indicados) !== count($formulas)) {
            throw new ValidationException(['formulas' => 'Indica los litros del día en todas las fórmulas o en ninguna (reparto parejo).']);
        }
        $litros = array_map(static fn (array $f): int => self::aMilesimas((string) $f['litros_dia']), $formulas);
        if (array_sum($litros) !== $procesadosMil) {
            throw new ValidationException(['formulas' => sprintf(
                'Los litros asignados a los quesos (%s L) deben sumar la leche procesada (%s L).',
                num(array_sum($litros) / 1000, 3),
                num($procesadosMil / 1000, 3)
            )]);
        }
        return array_values($litros);
    }

    /**
     * Reparte $total (entero) proporcional a $pesos por mayor resto: la suma del resultado es exactamente $total.
     * @param list<int> $pesos
     * @return list<int>
     */
    private static function prorratear(int $total, array $pesos): array
    {
        $suma = array_sum($pesos);
        $partes = [];
        $restos = [];
        foreach ($pesos as $i => $peso) {
            $partes[$i] = intdiv($total * $peso, $suma);
            $restos[$i] = ($total * $peso) % $suma;
        }
        arsort($restos);
        $faltante = $total - array_sum($partes);
        foreach (array_keys($restos) as $i) {
            if ($faltante-- <= 0) {
                break;
            }
            $partes[$i]++;
        }
        ksort($partes);
        return array_values($partes);
    }

    /** División entera redondeada a la mitad hacia arriba (operandos no negativos). */
    private static function dividirRedondeo(int $a, int $b): int
    {
        return intdiv(2 * $a + $b, 2 * $b);
    }

    private static function aMilesimas(string $valor): int
    {
        return (int) round((float) $valor * 1000);
    }

    private static function desdeMilesimas(int $valor): string
    {
        return number_format($valor / 1000, 3, '.', '');
    }

    private static function desdeDiezmilesimas(int $valor): string
    {
        return number_format($valor / 10000, 4, '.', '');
    }
}
