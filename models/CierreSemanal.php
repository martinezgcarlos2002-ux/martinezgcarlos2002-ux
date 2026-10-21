<?php
declare(strict_types=1);

/**
 * Cierres de ciclo semanal por productor. Cada productor cierra su ciclo en productores.dia_cierre
 * (1 = lunes … 7 = domingo); el ciclo abre al día siguiente y dura 7 días.
 *
 *   bruto      = SUM(entregas.subtotal_usd)            (precio congelado en cada entrega)
 *   arrastre   = pendiente del cierre vigente anterior (negativo = saldo a favor del productor)
 *   saldo_usd  = bruto + arrastre - descuentos (mercancía y vale)
 *   pagado_usd = liquidación (efectivo, pago móvil, transferencia; pm_trans histórico)
 *   pendiente  = saldo_usd - pagado_usd
 *   arrastre   = pendiente de la semana anterior (cada pago se resta una sola vez)
 *
 * Toda la aritmética se hace en centavos enteros para no acumular errores de coma flotante.
 */
final class CierreSemanal
{
    private PagoProductor $pagos;

    public function __construct(private PDO $db)
    {
        $this->pagos = new PagoProductor($db);
    }

    /** Ciclo sábado-viernes: valor por defecto de la columna y de las planillas calendario globales. */
    public const DIA_CIERRE_DEFAULT = 5;

    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /**
     * Inicio del ciclo del productor calculado en SQL. Requiere el alias p de productores.
     * WEEKDAY: lunes = 0 … domingo = 6; el ciclo abre en WEEKDAY = dia_cierre % 7.
     * El +7 va antes de restar: dia_cierre es UNSIGNED y una resta negativa desborda en modo estricto.
     */
    public static function sqlInicioCiclo(string $columnaFecha, string $columnaFecha2 = ''): string
    {
        $f2 = $columnaFecha2 === '' ? $columnaFecha : $columnaFecha2;
        return "DATE_SUB($columnaFecha, INTERVAL MOD(WEEKDAY($f2) + 7 - MOD(p.dia_cierre, 7), 7) DAY)";
    }

    public static function diaCierreValido(mixed $dia): int
    {
        $dia = (int) $dia;
        if ($dia < 1 || $dia > 7) {
            throw new DomainException("Día de cierre fuera de rango: $dia");
        }
        return $dia;
    }

    /** Primer día del ciclo de 7 días que contiene $fecha para un productor que cierra en $diaCierre. */
    public static function semanaDe(string $fecha, int $diaCierre): string
    {
        $apertura = self::diaCierreValido($diaCierre) % 7 + 1;
        $dia = new DateTimeImmutable($fecha);
        $atras = ((int) $dia->format('N') - $apertura + 7) % 7;
        return $dia->modify("-{$atras} days")->format('Y-m-d');
    }

    public static function finDe(string $semanaInicio): string
    {
        return (new DateTimeImmutable($semanaInicio))->modify('+6 days')->format('Y-m-d');
    }

    public static function esInicioDeCiclo(string $fecha, int $diaCierre): bool
    {
        return self::semanaDe($fecha, $diaCierre) === $fecha;
    }

    public function diaCierreDe(int $productorId): int
    {
        $stmt = $this->db->prepare('SELECT dia_cierre FROM productores WHERE id = :id');
        $stmt->execute(['id' => $productorId]);
        $dia = $stmt->fetchColumn();
        if ($dia === false) {
            throw new ValidationException(['productor_id' => 'El productor no existe.']);
        }
        return self::diaCierreValido($dia);
    }

    /** @return array{inicio:string, fin:string, dia_cierre:int} */
    public function cicloDe(int $productorId, string $fecha): array
    {
        $dia = $this->diaCierreDe($productorId);
        $inicio = self::semanaDe($fecha, $dia);
        return ['inicio' => $inicio, 'fin' => self::finDe($inicio), 'dia_cierre' => $dia];
    }

    /**
     * ¿Algún cierre vigente cubre la fecha? Tras cambiar el día de cierre, el primer ciclo nuevo
     * se solapa con el último cierre viejo: se decide por rango, no por semana_inicio.
     */
    public function cubreFecha(int $productorId, string $fecha): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM productores_cierres
             WHERE productor_id = :productor_id AND :fecha1 >= semana_inicio AND :fecha2 <= semana_fin AND estado <> 'anulada'
             LIMIT 1"
        );
        $stmt->execute(['productor_id' => $productorId, 'fecha1' => $fecha, 'fecha2' => $fecha]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Consolidación de las entregas libres (sin cierre) del ciclo. El precio promedio es ponderado por litros
     * (bruto / litros): un promedio simple de los precios diarios falsearía la factura.
     * Excluir las ya ligadas evita contar dos veces los días solapados tras un cambio de día de cierre.
     * @return array{litros:string, bruto_usd:string, traslado_usd:string, precio_promedio:?string, dias:int}
     */
    public function consolidar(int $productorId, string $semanaInicio): array
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(litros), 0)                         AS litros,
                    COALESCE(SUM(subtotal_usd), 0)                   AS bruto_usd,
                    COALESCE(SUM(traslado_usd), 0)                   AS traslado_usd,
                    ROUND(SUM(subtotal_usd) / NULLIF(SUM(litros), 0), 4) AS precio_promedio,
                    COUNT(*)                                         AS dias
             FROM productores_entregas
             WHERE productor_id = :productor_id AND fecha BETWEEN :desde AND :hasta AND cierre_id IS NULL'
        );
        $stmt->execute(['productor_id' => $productorId, 'desde' => $semanaInicio, 'hasta' => self::finDe($semanaInicio)]);
        $row = $stmt->fetch();
        return [
            'litros'          => (string) $row['litros'],
            'bruto_usd'       => (string) $row['bruto_usd'],
            'traslado_usd'    => (string) $row['traslado_usd'],
            'precio_promedio' => $row['precio_promedio'] === null ? null : (string) $row['precio_promedio'],
            'dias'            => (int) $row['dias'],
        ];
    }

    public function find(int $id, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM productores_cierres WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findPorSemana(int $productorId, string $semanaInicio, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM productores_cierres
             WHERE productor_id = :productor_id AND semana_inicio = :semana AND estado <> 'anulada'"
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['productor_id' => $productorId, 'semana' => $semanaInicio]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Pendiente que arrastra hacia $semanaInicio el último cierre vigente anterior. */
    public function pendienteAntes(int $productorId, string $semanaInicio): string
    {
        $stmt = $this->db->prepare(
            "SELECT saldo_usd - pagado_usd
             FROM productores_cierres
             WHERE productor_id = :productor_id AND semana_inicio < :semana AND estado <> 'anulada'
             ORDER BY semana_inicio DESC
             LIMIT 1"
        );
        $stmt->execute(['productor_id' => $productorId, 'semana' => $semanaInicio]);
        $valor = $stmt->fetchColumn();
        return $valor === false ? '0.00' : (string) $valor;
    }

    /**
     * Pendiente que cada productor arrastra hacia el ciclo que contiene $fechaRef (su propio ciclo).
     * @return array<int, string>  indexado por productor_id
     */
    public function pendientesAntesTodos(string $fechaRef): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.productor_id, c.saldo_usd - c.pagado_usd AS pendiente
             FROM productores_cierres c
             JOIN (SELECT c2.productor_id, MAX(c2.semana_inicio) AS semana
                   FROM productores_cierres c2
                   JOIN productores p ON p.id = c2.productor_id
                   WHERE c2.estado <> 'anulada' AND c2.semana_inicio < " . self::sqlInicioCiclo(':ref1', ':ref2') . "
                   GROUP BY c2.productor_id) u
               ON u.productor_id = c.productor_id AND u.semana = c.semana_inicio"
        );
        $stmt->execute(['ref1' => $fechaRef, 'ref2' => $fechaRef]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Ciclos ya terminados (fin < $hoy) con entregas o pagos sin cierre, en orden cronológico.
     * El ciclo de cada entrega se deriva del dia_cierre de su productor.
     * @return list<array{productor_id:int, semana_inicio:string}>
     */
    public function pendientesDeCierre(string $hoy, ?int $productorId = null): array
    {
        $sql = "SELECT t.productor_id, t.semana_inicio
                FROM (
                    SELECT e.productor_id, " . self::sqlInicioCiclo('e.fecha') . " AS semana_inicio
                    FROM productores_entregas e
                    JOIN productores p ON p.id = e.productor_id
                    WHERE e.cierre_id IS NULL AND e.fecha < :hoy_e
                    UNION
                    SELECT a.productor_id, a.semana_inicio
                    FROM productores_abonos a
                    WHERE a.cierre_id IS NULL AND a.anulado_en IS NULL AND a.semana_inicio < :hoy_a
                ) t
                WHERE t.semana_inicio + INTERVAL 6 DAY < :hoy_t
                  AND NOT EXISTS (
                    SELECT 1 FROM productores_cierres c
                    WHERE c.productor_id = t.productor_id AND c.semana_inicio = t.semana_inicio AND c.estado <> 'anulada'
                )";
        $params = ['hoy_e' => $hoy, 'hoy_a' => $hoy, 'hoy_t' => $hoy];
        if ($productorId !== null) {
            $sql .= ' AND t.productor_id = :productor_id';
            $params['productor_id'] = $productorId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY t.productor_id, t.semana_inicio');
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'productor_id'  => (int) $r['productor_id'],
            'semana_inicio' => (string) $r['semana_inicio'],
        ], $stmt->fetchAll());
    }

    /**
     * Congela la semana: snapshot de entregas, liga entregas y pagos al cierre y recalcula la cadena de saldos.
     * El llamador debe haber bloqueado la fila del productor (serializa con entregas y pagos concurrentes).
     */
    public function cerrar(int $productorId, string $semanaInicio, int $usuarioId, ?string $notas = null): int
    {
        $dia = $this->diaCierreDe($productorId);
        if (!self::esInicioDeCiclo($semanaInicio, $dia)) {
            throw new ValidationException(['semana' => sprintf(
                'El ciclo de este productor inicia el %s (cierra el %s).',
                mb_strtolower(self::DIAS[$dia % 7 + 1]),
                mb_strtolower(self::DIAS[$dia])
            )]);
        }
        if (self::finDe($semanaInicio) >= date('Y-m-d')) {
            throw new ValidationException(['semana' => 'Solo se pueden cerrar ciclos ya terminados (el día de cierre debe haber pasado).']);
        }

        $total = $this->consolidar($productorId, $semanaInicio);
        $snapshot = [
            'litros'       => $total['litros'],
            'bruto_usd'    => $total['bruto_usd'],
            'traslado_usd' => $total['traslado_usd'],
            'notas'        => $notas,
            'cerrado_por'  => $usuarioId,
        ];

        $anulado = $this->anuladoPorSemana($productorId, $semanaInicio);
        if ($anulado !== null) {
            // Semana reabierta: se reutiliza la fila para conservar las FK de caja que apuntan a ella.
            $cierreId = $anulado;
            $stmt = $this->db->prepare(
                "UPDATE productores_cierres
                 SET litros = :litros, bruto_usd = :bruto_usd, traslado_usd = :traslado_usd,
                     abonos_usd = 0, arrastre_anterior_usd = 0, saldo_usd = 0, pagado_usd = 0,
                     estado = 'cerrada', notas = :notas, cerrado_por = :cerrado_por, cerrado_en = NOW(), pagado_en = NULL
                 WHERE id = :id"
            );
            $stmt->execute($snapshot + ['id' => $cierreId]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO productores_cierres
                     (productor_id, semana_inicio, semana_fin, litros, bruto_usd, traslado_usd,
                      abonos_usd, arrastre_anterior_usd, saldo_usd, pagado_usd, estado, notas, cerrado_por)
                 VALUES
                     (:productor_id, :semana_inicio, :semana_fin, :litros, :bruto_usd, :traslado_usd,
                      0, 0, 0, 0, 'cerrada', :notas, :cerrado_por)"
            );
            $stmt->execute($snapshot + [
                'productor_id'  => $productorId,
                'semana_inicio' => $semanaInicio,
                'semana_fin'    => self::finDe($semanaInicio),
            ]);
            $cierreId = (int) $this->db->lastInsertId();
        }

        $stmt = $this->db->prepare(
            'UPDATE productores_entregas
             SET cierre_id = :cierre_id
             WHERE productor_id = :productor_id AND fecha BETWEEN :desde AND :hasta AND cierre_id IS NULL'
        );
        $stmt->execute([
            'cierre_id'    => $cierreId,
            'productor_id' => $productorId,
            'desde'        => $semanaInicio,
            'hasta'        => self::finDe($semanaInicio),
        ]);

        $this->pagos->vincularCierre($productorId, $semanaInicio, $cierreId);
        $this->recalcular($productorId);

        return $cierreId;
    }

    /**
     * Reabre la semana: el cierre pasa a 'anulada' (la semana vuelve a estar abierta), se liberan sus entregas
     * y pagos vigentes y se recalcula la cadena, con lo que el arrastre que generaba desaparece de las semanas siguientes.
     * El llamador debe haber bloqueado la fila del productor.
     * No reescribe precio_litro_usd: una reapertura de auditoría conserva el precio histórico.
     * El precio base solo se propaga en EntregaLeche::aplicarPrecioEnSemanasAbiertas, al editar el perfil.
     */
    public function reabrir(int $id): void
    {
        $cierre = $this->find($id, true);
        if ($cierre === null || $cierre['estado'] === 'anulada') {
            throw new ValidationException(['cierre_id' => 'La semana ya está abierta.']);
        }

        // El estado cambia primero: los triggers solo liberan entregas de cierres anulados.
        $this->db->prepare("UPDATE productores_cierres SET estado = 'anulada', pagado_en = NULL WHERE id = :id")
            ->execute(['id' => $id]);
        $this->db->prepare('UPDATE productores_entregas SET cierre_id = NULL WHERE cierre_id = :id')
            ->execute(['id' => $id]);
        $this->db->prepare('UPDATE productores_abonos SET cierre_id = NULL WHERE cierre_id = :id AND anulado_en IS NULL')
            ->execute(['id' => $id]);

        $this->recalcular((int) $cierre['productor_id']);
    }

    /** @return array<int, true>  productores cuyo ciclo que contiene $fechaRef ya está cerrado */
    public function productoresCerrados(string $fechaRef): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.productor_id
             FROM productores_cierres c
             JOIN productores p ON p.id = c.productor_id
             WHERE c.semana_inicio = " . self::sqlInicioCiclo(':ref1', ':ref2') . " AND c.estado <> 'anulada'"
        );
        $stmt->execute(['ref1' => $fechaRef, 'ref2' => $fechaRef]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function anuladoPorSemana(int $productorId, string $semanaInicio): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM productores_cierres
             WHERE productor_id = :productor_id AND semana_inicio = :semana AND estado = 'anulada'
             FOR UPDATE"
        );
        $stmt->execute(['productor_id' => $productorId, 'semana' => $semanaInicio]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Corrección administrativa del snapshot. Las entregas no se tocan: son el histórico inmutable. */
    public function editar(int $id, string $litros, string $brutoUsd, ?string $notas): void
    {
        $stmt = $this->db->prepare(
            'UPDATE productores_cierres SET litros = :litros, bruto_usd = :bruto_usd, notas = :notas WHERE id = :id'
        );
        $stmt->execute(['litros' => $litros, 'bruto_usd' => $brutoUsd, 'notas' => $notas, 'id' => $id]);
    }

    /**
     * Recalcula en orden cronológico arrastre, abonos, pagado, saldo y estado de todos los cierres vigentes
     * del productor. Idempotente: se invoca tras cerrar, pagar, anular un pago o editar un cierre.
     */
    public function recalcular(int $productorId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, semana_inicio, bruto_usd, abonos_usd, arrastre_anterior_usd, saldo_usd, pagado_usd, estado, pagado_en
             FROM productores_cierres
             WHERE productor_id = :productor_id AND estado <> 'anulada'
             ORDER BY semana_inicio
             FOR UPDATE"
        );
        $stmt->execute(['productor_id' => $productorId]);
        $cierres = $stmt->fetchAll();
        if ($cierres === []) {
            return;
        }

        $pagos = $this->pagos->totalesPorSemana($productorId);
        $upd = $this->db->prepare(
            'UPDATE productores_cierres
             SET arrastre_anterior_usd = :arrastre, abonos_usd = :abonos, pagado_usd = :pagado,
                 saldo_usd = :saldo, estado = :estado, pagado_en = :pagado_en
             WHERE id = :id'
        );

        $ultimo = array_key_last($cierres);
        $arrastre = 0;
        foreach ($cierres as $i => $c) {
            $descuentos = Caja::aCentavos($pagos[$c['semana_inicio']]['mercancia_usd'] ?? 0);
            $liquidacion = Caja::aCentavos($pagos[$c['semana_inicio']]['dinero_usd'] ?? 0);
            $saldo = Caja::aCentavos($c['bruto_usd']) + $arrastre - $descuentos;
            $pendiente = $saldo - $liquidacion;

            $estado = match (true) {
                $pendiente <= 0               => 'pagada',
                $i !== $ultimo                => 'arrastrada',
                $descuentos + $liquidacion > 0 => 'parcial',
                default                  => 'cerrada',
            };

            $nuevo = [
                'arrastre'  => Caja::desdeCentavos($arrastre),
                'abonos'    => Caja::desdeCentavos($descuentos),
                'pagado'    => Caja::desdeCentavos($liquidacion),
                'saldo'     => Caja::desdeCentavos($saldo),
                'estado'    => $estado,
                'pagado_en' => $estado === 'pagada' ? ($c['pagado_en'] ?? date('Y-m-d H:i:s')) : null,
            ];
            $actual = [
                'arrastre'  => Caja::desdeCentavos(Caja::aCentavos($c['arrastre_anterior_usd'])),
                'abonos'    => Caja::desdeCentavos(Caja::aCentavos($c['abonos_usd'])),
                'pagado'    => Caja::desdeCentavos(Caja::aCentavos($c['pagado_usd'])),
                'saldo'     => Caja::desdeCentavos(Caja::aCentavos($c['saldo_usd'])),
                'estado'    => $c['estado'],
                'pagado_en' => $c['pagado_en'],
            ];
            if ($nuevo !== $actual) {
                $upd->execute($nuevo + ['id' => (int) $c['id']]);
            }

            $arrastre = $pendiente;
        }
    }

    public function listarPorProductor(int $productorId, ?string $desde = null, ?string $hasta = null): array
    {
        $sql = "SELECT c.id, c.semana_inicio, c.semana_fin, c.litros, c.bruto_usd, c.traslado_usd,
                       ROUND(c.bruto_usd / NULLIF(c.litros, 0), 4) AS precio_promedio,
                       c.arrastre_anterior_usd, c.abonos_usd, c.pagado_usd, c.saldo_usd,
                       c.saldo_usd - c.pagado_usd AS pendiente_usd,
                       c.estado, c.notas, c.cerrado_en, c.pagado_en
                FROM productores_cierres c
                WHERE c.productor_id = :productor_id AND c.estado <> 'anulada'";
        $params = ['productor_id' => $productorId];
        if ($desde !== null) {
            $sql .= ' AND c.semana_inicio >= :desde';
            $params['desde'] = $desde;
        }
        if ($hasta !== null) {
            $sql .= ' AND c.semana_inicio <= :hasta';
            $params['hasta'] = $hasta;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY c.semana_inicio DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
