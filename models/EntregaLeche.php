<?php
declare(strict_types=1);

final class EntregaLeche
{
    public function __construct(private PDO $db)
    {
    }

    public function existe(int $productorId, string $fecha): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM productores_entregas WHERE productor_id = :productor_id AND fecha = :fecha LIMIT 1'
        );
        $stmt->execute(['productor_id' => $productorId, 'fecha' => $fecha]);
        return $stmt->fetchColumn() !== false;
    }

    public function findPorFecha(int $productorId, string $fecha, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM productores_entregas WHERE productor_id = :productor_id AND fecha = :fecha'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['productor_id' => $productorId, 'fecha' => $fecha]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Sobrescribe una entrega de semana abierta. El precio NO se toca: quedó congelado al registrarla.
     * El trigger trg_entregas_semana_abierta_upd rechaza el cambio si la entrega pertenece a un cierre vigente.
     * @param array{litros:string, traslado_usd?:string, cuajada_kg?:string, observaciones?:?string} $data
     */
    public function actualizar(int $id, array $data): void
    {
        $sets = ['litros = :litros'];
        foreach (['traslado_usd', 'cuajada_kg', 'observaciones'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "$campo = :$campo";
            }
        }
        $stmt = $this->db->prepare('UPDATE productores_entregas SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($data + ['id' => $id]);
    }

    /** subtotal_usd lo calcula MySQL (columna generada): no se recibe del cliente. */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO productores_entregas
                 (productor_id, fecha, litros, precio_litro_usd, traslado_usd, cuajada_kg, observaciones, registrado_por)
             VALUES
                 (:productor_id, :fecha, :litros, :precio_litro_usd, :traslado_usd, :cuajada_kg, :observaciones, :registrado_por)'
        );
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function delDia(string $fecha): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.productor_id, p.nombre, e.litros, e.precio_litro_usd, e.subtotal_usd,
                    e.traslado_usd, e.cuajada_kg, e.observaciones
             FROM productores_entregas e
             JOIN productores p ON p.id = e.productor_id
             WHERE e.fecha = :fecha
             ORDER BY p.nombre'
        );
        $stmt->execute(['fecha' => $fecha]);
        return $stmt->fetchAll();
    }

    /** @return array{entregas:int, litros:string, subtotal_usd:string} */
    public function totalesDia(string $fecha): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS entregas, COALESCE(SUM(litros), 0) AS litros, COALESCE(SUM(subtotal_usd), 0) AS subtotal_usd
             FROM productores_entregas WHERE fecha = :fecha'
        );
        $stmt->execute(['fecha' => $fecha]);
        return $stmt->fetch();
    }

    /** @return array<string, array>  entregas del productor en el rango, indexadas por fecha */
    public function deProductorEnRango(int $productorId, string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            'SELECT fecha, id, litros, precio_litro_usd, subtotal_usd, traslado_usd, cuajada_kg, cierre_id
             FROM productores_entregas
             WHERE productor_id = :productor_id AND fecha BETWEEN :desde AND :hasta
             ORDER BY fecha DESC'
        );
        $stmt->execute(['productor_id' => $productorId, 'desde' => $desde, 'hasta' => $hasta]);
        return $stmt->fetchAll(PDO::FETCH_UNIQUE);
    }

    /** @return array<int, array<string, array{litros:string, subtotal_usd:string}>>  [productor_id][fecha] */
    public function matrizSemana(string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            'SELECT productor_id, fecha, litros, subtotal_usd
             FROM productores_entregas
             WHERE fecha BETWEEN :desde AND :hasta'
        );
        $stmt->execute(['desde' => $desde, 'hasta' => $hasta]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['productor_id']][$row['fecha']] = [
                'litros'       => (string) $row['litros'],
                'subtotal_usd' => (string) $row['subtotal_usd'],
            ];
        }
        return $out;
    }

    /** Totales por ciclo del productor de los últimos $semanas ciclos terminados, para la proyección. */
    public function semanasRecientes(int $productorId, string $antesDe, int $semanas = 8): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . CierreSemanal::sqlInicioCiclo('e.fecha') . ' AS semana_inicio,
                    SUM(e.litros) AS litros, SUM(e.subtotal_usd) AS monto_usd, COUNT(*) AS dias
             FROM productores_entregas e
             JOIN productores p ON p.id = e.productor_id
             WHERE e.productor_id = :productor_id AND e.fecha < :antes AND e.fecha >= :desde
             GROUP BY semana_inicio
             ORDER BY semana_inicio DESC'
        );
        $stmt->execute([
            'productor_id' => $productorId,
            'antes'        => $antesDe,
            'desde'        => (new DateTimeImmutable($antesDe))->modify('-' . ($semanas * 7) . ' days')->format('Y-m-d'),
        ]);
        return $stmt->fetchAll();
    }

    /** Entregas del período en orden cronológico, para la liquidación. */
    public function detallePeriodo(int $productorId, string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            'SELECT fecha, litros, precio_litro_usd, subtotal_usd
             FROM productores_entregas
             WHERE productor_id = :productor_id AND fecha BETWEEN :desde AND :hasta
             ORDER BY fecha'
        );
        $stmt->execute(['productor_id' => $productorId, 'desde' => $desde, 'hasta' => $hasta]);
        return $stmt->fetchAll();
    }

    /**
     * Entregas de todos los productores (o de uno) en el rango, para el detalle de Control de Leche.
     * @return list<array<string, mixed>>
     */
    public function detalleRango(string $desde, string $hasta, ?int $productorId = null): array
    {
        $sql = 'SELECT e.id, e.fecha, e.productor_id, p.nombre AS productor,
                       e.litros, e.precio_litro_usd AS precio, e.subtotal_usd AS subtotal,
                       e.cuajada_kg AS cuajada, e.traslado_usd AS traslado, e.observaciones, e.cierre_id
                FROM productores_entregas e
                JOIN productores p ON p.id = e.productor_id
                WHERE e.fecha BETWEEN :desde AND :hasta';
        $params = ['desde' => $desde, 'hasta' => $hasta];
        if ($productorId !== null) {
            $sql .= ' AND e.productor_id = :productor_id';
            $params['productor_id'] = $productorId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY e.fecha DESC, p.nombre ASC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Consolidado global por día, independiente del ciclo de cada productor.
     * @return list<array{fecha:string, litros:string, monto_usd:string, cuajada_kg:string, traslado_usd:string, productores:int, entregas:int}>
     */
    public function consolidadoPorDia(string $desde, string $hasta, ?int $productorId = null): array
    {
        $sql = 'SELECT fecha, SUM(litros) AS litros, SUM(subtotal_usd) AS monto_usd, SUM(cuajada_kg) AS cuajada_kg,
                       SUM(traslado_usd) AS traslado_usd, COUNT(DISTINCT productor_id) AS productores, COUNT(*) AS entregas
                FROM productores_entregas
                WHERE fecha BETWEEN :desde AND :hasta';
        $params = ['desde' => $desde, 'hasta' => $hasta];
        if ($productorId !== null) {
            $sql .= ' AND productor_id = :productor_id';
            $params['productor_id'] = $productorId;
        }
        $stmt = $this->db->prepare($sql . ' GROUP BY fecha ORDER BY fecha DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Consolidado global por semana calendario ISO (lunes a domingo), independiente del ciclo de cada productor.
     * @return list<array{semana_inicio:string, semana_fin:string, anio:int, semana:int, litros:string, monto_usd:string, cuajada_kg:string, traslado_usd:string, productores:int, entregas:int, dias:int}>
     */
    public function consolidadoPorSemana(string $desde, string $hasta, ?int $productorId = null): array
    {
        $sql = 'SELECT DATE_SUB(fecha, INTERVAL WEEKDAY(fecha) DAY) AS semana_inicio,
                       DATE_ADD(DATE_SUB(fecha, INTERVAL WEEKDAY(fecha) DAY), INTERVAL 6 DAY) AS semana_fin,
                       YEARWEEK(fecha, 3) AS anio_semana,
                       SUM(litros) AS litros, SUM(subtotal_usd) AS monto_usd, SUM(cuajada_kg) AS cuajada_kg,
                       SUM(traslado_usd) AS traslado_usd, COUNT(DISTINCT productor_id) AS productores,
                       COUNT(*) AS entregas, COUNT(DISTINCT fecha) AS dias
                FROM productores_entregas
                WHERE fecha BETWEEN :desde AND :hasta';
        $params = ['desde' => $desde, 'hasta' => $hasta];
        if ($productorId !== null) {
            $sql .= ' AND productor_id = :productor_id';
            $params['productor_id'] = $productorId;
        }
        $stmt = $this->db->prepare($sql . ' GROUP BY semana_inicio, semana_fin, anio_semana ORDER BY semana_inicio DESC');
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'semana_inicio' => (string) $r['semana_inicio'],
            'semana_fin'    => (string) $r['semana_fin'],
            'anio'          => intdiv((int) $r['anio_semana'], 100),
            'semana'        => (int) $r['anio_semana'] % 100,
            'litros'        => (string) $r['litros'],
            'monto_usd'     => (string) $r['monto_usd'],
            'cuajada_kg'    => (string) $r['cuajada_kg'],
            'traslado_usd'  => (string) $r['traslado_usd'],
            'productores'   => (int) $r['productores'],
            'entregas'      => (int) $r['entregas'],
            'dias'          => (int) $r['dias'],
        ], $stmt->fetchAll());
    }

    /** @return array{litros:string, costo_usd:string, traslado_usd:string, entregas:int} */
    public function totalesCostoDia(string $fecha): array
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(litros), 0) AS litros, COALESCE(SUM(subtotal_usd), 0) AS costo_usd,
                    COALESCE(SUM(traslado_usd), 0) AS traslado_usd, COUNT(*) AS entregas
             FROM productores_entregas WHERE fecha = :fecha'
        );
        $stmt->execute(['fecha' => $fecha]);
        $row = $stmt->fetch();
        return [
            'litros'       => (string) $row['litros'],
            'costo_usd'    => (string) $row['costo_usd'],
            'traslado_usd' => (string) $row['traslado_usd'],
            'entregas'     => (int) $row['entregas'],
        ];
    }

    /**
     * Totales por productor en un período, para las facturas masivas.
     * $grupo = null → todos; '' → sin etiqueta; otro → coincidencia exacta.
     */
    public function resumenPeriodo(string $desde, string $hasta, ?string $grupo = null): array
    {
        $sql = 'SELECT p.id, p.nombre, p.documento, p.grupo_cierre,
                       SUM(e.litros) AS litros, SUM(e.subtotal_usd) AS monto_usd,
                       ROUND(SUM(e.subtotal_usd) / NULLIF(SUM(e.litros), 0), 4) AS precio_promedio,
                       COUNT(*) AS dias
                FROM productores_entregas e
                JOIN productores p ON p.id = e.productor_id
                WHERE e.fecha BETWEEN :desde AND :hasta';
        $params = ['desde' => $desde, 'hasta' => $hasta];
        if ($grupo === '') {
            $sql .= ' AND (p.grupo_cierre IS NULL OR TRIM(p.grupo_cierre) = \'\')';
        } elseif ($grupo !== null) {
            $sql .= ' AND p.grupo_cierre = :grupo';
            $params['grupo'] = $grupo;
        }
        $sql .= ' GROUP BY p.id, p.nombre, p.documento, p.grupo_cierre ORDER BY p.nombre';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
