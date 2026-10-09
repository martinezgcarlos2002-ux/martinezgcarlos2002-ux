<?php
declare(strict_types=1);

final class Productor
{
    private const CAMPOS = ['nombre', 'documento', 'telefono', 'correo', 'direccion', 'precio_litro_usd', 'credito_dias', 'dia_cierre', 'grupo_cierre', 'tolerancia_pago'];

    /** Días de gracia tras el fin del ciclo: el pago está atrasado cuando los días transcurridos los superan. */
    public const TOLERANCIAS = ['bajo' => 2, 'medio' => 5, 'alto' => 10];
    public const TOLERANCIA_DEFAULT = 'medio';

    /** Suministrado y pagado se derivan de las tablas transaccionales: nunca se guardan totales duplicados. */
    private const SQL_TOTALES = '
        COALESCE((SELECT SUM(e.subtotal_usd) FROM productores_entregas e WHERE e.productor_id = p.id), 0) AS suministrado_usd,
        COALESCE((SELECT SUM(a.monto_usd) FROM productores_abonos a WHERE a.productor_id = p.id AND a.anulado_en IS NULL), 0) AS pagado_usd';

    public function __construct(private PDO $db)
    {
    }

    /** Lista blanca de ordenamientos: la clave viene de GET, el valor es SQL fijo. */
    public const ORDENES = [
        'nombre' => 't.nombre ASC, t.id ASC',
        'id'     => 't.id ASC',
    ];

    /**
     * @param 'activos'|'inactivos'|'todos' $estado
     * @param key-of<self::ORDENES> $orden
     * @param int|null $diaCierre  1 = lunes … 7 = domingo; null = todos
     */
    public function listar(string $q, string $estado, string $orden = 'nombre', ?int $diaCierre = null): array
    {
        $where = [];
        $params = [];

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(p.nombre LIKE :q1 OR p.documento LIKE :q2)';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }
        if ($estado === 'activos') {
            $where[] = 'p.activo = 1';
        } elseif ($estado === 'inactivos') {
            $where[] = 'p.activo = 0';
        }
        if ($diaCierre !== null) {
            $where[] = 'p.dia_cierre = :dia_cierre';
            $params['dia_cierre'] = CierreSemanal::diaCierreValido($diaCierre);
        }

        $sql = 'SELECT t.*, t.suministrado_usd - t.pagado_usd AS saldo_usd
                FROM (
                    SELECT p.id, p.nombre, p.documento, p.telefono, p.correo, p.direccion,
                           p.precio_litro_usd, p.credito_dias, p.dia_cierre, p.grupo_cierre, p.tolerancia_pago, p.activo,
                           (SELECT MAX(e.fecha) FROM productores_entregas e WHERE e.productor_id = p.id) AS ultima_entrega,
                           ' . self::SQL_TOTALES . '
                    FROM productores p'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ') t ORDER BY ' . (self::ORDENES[$orden] ?? self::ORDENES['nombre']);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Indicadores históricos. El precio promedio es ponderado por litros (inversión / litros).
     * @return array{productores_activos:int, productores_total:int, litros:string, precio_promedio:?string, suministrado_usd:string, pagado_usd:string, saldo_usd:string}
     */
    public function kpis(): array
    {
        $row = $this->db->query(
            'SELECT (SELECT COUNT(*) FROM productores WHERE activo = 1) AS productores_activos,
                    (SELECT COUNT(*) FROM productores) AS productores_total,
                    e.litros, e.suministrado_usd,
                    ROUND(e.suministrado_usd / NULLIF(e.litros, 0), 4) AS precio_promedio,
                    (SELECT COALESCE(SUM(monto_usd), 0) FROM productores_abonos WHERE anulado_en IS NULL) AS pagado_usd
             FROM (SELECT COALESCE(SUM(litros), 0) AS litros, COALESCE(SUM(subtotal_usd), 0) AS suministrado_usd
                   FROM productores_entregas) e'
        )->fetch();

        return [
            'productores_activos' => (int) $row['productores_activos'],
            'productores_total'   => (int) $row['productores_total'],
            'litros'              => (string) $row['litros'],
            'precio_promedio'     => $row['precio_promedio'] === null ? null : (string) $row['precio_promedio'],
            'suministrado_usd'    => (string) $row['suministrado_usd'],
            'pagado_usd'          => (string) $row['pagado_usd'],
            'saldo_usd'           => Caja::desdeCentavos(Caja::aCentavos($row['suministrado_usd']) - Caja::aCentavos($row['pagado_usd'])),
        ];
    }

    /** @return array{litros:string, suministrado_usd:string, pagado_usd:string, saldo_usd:string} */
    public function cuenta(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE((SELECT SUM(e.litros) FROM productores_entregas e WHERE e.productor_id = p.id), 0) AS litros,
                    ' . self::SQL_TOTALES . '
             FROM productores p WHERE p.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return [
            'litros'           => (string) $row['litros'],
            'suministrado_usd' => (string) $row['suministrado_usd'],
            'pagado_usd'       => (string) $row['pagado_usd'],
            'saldo_usd'        => Caja::desdeCentavos(Caja::aCentavos($row['suministrado_usd']) - Caja::aCentavos($row['pagado_usd'])),
        ];
    }

    public function find(int $id, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM productores WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<string> etiquetas distintas de grupo_cierre */
    public function gruposCierre(): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT grupo_cierre FROM productores
             WHERE grupo_cierre IS NOT NULL AND TRIM(grupo_cierre) <> ''
             ORDER BY grupo_cierre"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /** @return list<array{id:int, nombre:string, documento:?string, precio_litro_usd:string, dia_cierre:int}> */
    public function activos(): array
    {
        return $this->db
            ->query('SELECT id, nombre, documento, precio_litro_usd, dia_cierre FROM productores WHERE activo = 1 ORDER BY nombre ASC, id ASC')
            ->fetchAll();
    }

    /**
     * Precio vigente y día de cierre bloqueando la fila, para congelar el precio en la entrega
     * y ubicar su ciclo dentro de la misma transacción.
     * @return array{precio_litro_usd:string, dia_cierre:int}|null
     */
    public function paraEntrega(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT precio_litro_usd, dia_cierre FROM productores WHERE id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'precio_litro_usd' => (string) $row['precio_litro_usd'],
            'dia_cierre'       => CierreSemanal::diaCierreValido($row['dia_cierre']),
        ];
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO productores (nombre, documento, telefono, correo, direccion, precio_litro_usd, credito_dias, dia_cierre, grupo_cierre, tolerancia_pago)
             VALUES (:nombre, :documento, :telefono, :correo, :direccion, :precio_litro_usd, :credito_dias, :dia_cierre, :grupo_cierre, :tolerancia_pago)'
        );
        $stmt->execute(array_intersect_key($data, array_flip(self::CAMPOS)));
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE productores
             SET nombre = :nombre, documento = :documento, telefono = :telefono, correo = :correo,
                 direccion = :direccion, precio_litro_usd = :precio_litro_usd, credito_dias = :credito_dias,
                 dia_cierre = :dia_cierre, grupo_cierre = :grupo_cierre, tolerancia_pago = :tolerancia_pago, activo = :activo
             WHERE id = :id'
        );
        $stmt->execute(array_intersect_key($data, array_flip([...self::CAMPOS, 'activo'])) + ['id' => $id]);
    }

    public function registrarPrecio(int $productorId, ?string $anterior, string $nuevo, string $motivo, int $usuarioId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO productores_precios (productor_id, precio_anterior_usd, precio_nuevo_usd, motivo, usuario_id)
             VALUES (:productor_id, :anterior, :nuevo, :motivo, :usuario_id)'
        );
        $stmt->execute([
            'productor_id' => $productorId,
            'anterior'     => $anterior,
            'nuevo'        => $nuevo,
            'motivo'       => $motivo,
            'usuario_id'   => $usuarioId,
        ]);
    }

    /** Entregas, pagos (incluso anulados) o cierres: cualquiera obliga a conservar el registro. */
    public function tieneHistorial(int $id): bool
    {
        $stmt = $this->db->prepare(
            'SELECT EXISTS(SELECT 1 FROM productores_entregas WHERE productor_id = :id1)
                 OR EXISTS(SELECT 1 FROM productores_abonos   WHERE productor_id = :id2)
                 OR EXISTS(SELECT 1 FROM productores_cierres  WHERE productor_id = :id3)'
        );
        $stmt->execute(['id1' => $id, 'id2' => $id, 'id3' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function archivar(int $id): void
    {
        $this->db->prepare('UPDATE productores SET activo = 0 WHERE id = :id')->execute(['id' => $id]);
    }

    /** Solo para productores sin historial operativo: su bitácora de precios es la del alta. */
    public function eliminarSinHistorial(int $id): void
    {
        $this->db->prepare('DELETE FROM productores_precios WHERE productor_id = :id')->execute(['id' => $id]);
        $this->db->prepare('DELETE FROM productores WHERE id = :id')->execute(['id' => $id]);
    }
}
