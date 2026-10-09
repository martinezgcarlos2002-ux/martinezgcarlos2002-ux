<?php
declare(strict_types=1);

final class Produccion
{
    public function __construct(private PDO $db)
    {
    }

    /** Productos activos con su fórmula (base leche si no tienen una registrada). */
    public function productosConFormula(): array
    {
        return $this->db->query(
            "SELECT p.id, p.codigo, p.nombre, p.precio_usd, p.unidad,
                    COALESCE(f.base, 'leche') AS base, f.factor_l_kg_ref,
                    f.cuajada_ref_kg, f.masa_min_kg, f.masa_max_kg, f.notas
             FROM productos p
             LEFT JOIN formulas_produccion f ON f.producto_id = p.id
             WHERE p.activo = 1
             ORDER BY p.nombre"
        )->fetchAll();
    }

    public function formula(int $productoId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.nombre, COALESCE(f.base, 'leche') AS base,
                    f.cuajada_ref_kg, f.masa_min_kg, f.masa_max_kg
             FROM productos p
             LEFT JOIN formulas_produccion f ON f.producto_id = p.id
             WHERE p.id = :id AND p.activo = 1"
        );
        $stmt->execute(['id' => $productoId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Precio promedio ponderado de la leche recibida en la fecha (SUM subtotal / SUM litros). */
    public function precioPromedioLitro(string $fecha): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT ROUND(SUM(subtotal_usd) / NULLIF(SUM(litros), 0), 4)
             FROM productores_entregas WHERE fecha = :fecha'
        );
        $stmt->execute(['fecha' => $fecha]);
        $precio = $stmt->fetchColumn();
        return $precio === null || $precio === false ? null : (string) $precio;
    }

    /** kg_esperados, masa_min/max_kg y costo_leche_usd los calcula MySQL (columnas generadas). */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO producciones
                 (fecha, producto_id, litros_leche, factor_l_kg, cuajada_kg, rend_cuajada_ref_kg,
                  rend_masa_min_kg, rend_masa_max_kg, kg_reales, precio_litro_prom_usd, observaciones, registrado_por)
             VALUES
                 (:fecha, :producto_id, :litros_leche, :factor_l_kg, :cuajada_kg, :rend_cuajada_ref_kg,
                  :rend_masa_min_kg, :rend_masa_max_kg, :kg_reales, :precio_litro_prom_usd, :observaciones, :registrado_por)'
        );
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM producciones WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    // ----- Planilla diaria (produccion_diaria) -----------------------------

    /** Productos activos con su registro del día, más los archivados que ya tenían producción esa fecha. */
    public function planillaDelDia(string $fecha): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.nombre, p.unidad, COALESCE(c.unidad_medida, p.unidad_medida, \'KG\') AS unidad_medida,
                    COALESCE(c.precio_produccion, p.precio_usd) AS precio_usd,
                    p.nombre AS inventario,
                    pd.id AS produccion_id, pd.cantidad, pd.precio_unit_usd, pd.total_usd, pd.distribuido, pd.pendiente
             FROM productos p
             LEFT JOIN prod_catalogo c ON c.producto_id = p.id
             LEFT JOIN produccion_diaria pd ON pd.producto_id = p.id AND pd.fecha = :fecha
             WHERE (c.id IS NOT NULL AND c.activo = 1) OR pd.id IS NOT NULL
             ORDER BY COALESCE(c.orden, 9999), p.nombre'
        );
        $stmt->execute(['fecha' => $fecha]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array{id:int, producto_id:int, cantidad:string, distribuido:string}>  por producto_id */
    public function bloquearDia(string $fecha): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, producto_id, cantidad, distribuido FROM produccion_diaria WHERE fecha = :fecha ORDER BY id FOR UPDATE'
        );
        $stmt->execute(['fecha' => $fecha]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['producto_id']] = $row;
        }
        return $out;
    }

    /** El precio se congela al registrar: cambiarlo en inventario no altera el total de días pasados. */
    public function crearLinea(string $fecha, int $productoId, string $cantidad, string $precioUnitUsd, int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO produccion_diaria (fecha, producto_id, cantidad, precio_unit_usd, registrado_por)
             VALUES (:fecha, :producto_id, :cantidad, :precio, :usuario_id)'
        );
        $stmt->execute([
            'fecha'       => $fecha,
            'producto_id' => $productoId,
            'cantidad'    => $cantidad,
            'precio'      => $precioUnitUsd,
            'usuario_id'  => $usuarioId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** ck_prod_diaria_dist impide bajar la cantidad por debajo de lo ya distribuido. */
    public function actualizarCantidad(int $id, string $cantidad): void
    {
        $this->db->prepare('UPDATE produccion_diaria SET cantidad = :cantidad WHERE id = :id')
            ->execute(['cantidad' => $cantidad, 'id' => $id]);
    }

    public function eliminarLineaSinDistribuir(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM produccion_diaria WHERE id = :id AND distribuido = 0');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('La producción #%d ya tiene distribuciones y no se puede borrar.', $id));
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{id:int, producto_id:int, cantidad:string, distribuido:string, pendiente:string}>
     */
    public function bloquearParaDistribuir(string $fecha, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        sort($ids);
        $marcas = [];
        $params = ['fecha' => $fecha];
        foreach (array_values($ids) as $i => $id) {
            $marcas[] = ':id' . $i;
            $params['id' . $i] = $id;
        }

        $stmt = $this->db->prepare(
            'SELECT id, producto_id, cantidad, distribuido, pendiente
             FROM produccion_diaria
             WHERE fecha = :fecha AND id IN (' . implode(', ', $marcas) . ')
             ORDER BY id
             FOR UPDATE'
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /** Resta del pendiente (suma a distribuido) y deja el rastro inmutable en produccion_distribuciones. */
    public function registrarDistribucion(int $produccionId, int $almacenId, string $cantidad, int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE produccion_diaria
             SET distribuido = distribuido + :cantidad
             WHERE id = :id AND pendiente >= :minimo'
        );
        $stmt->execute(['cantidad' => $cantidad, 'id' => $produccionId, 'minimo' => $cantidad]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('La producción #%d no tiene pendiente suficiente.', $produccionId));
        }

        $ins = $this->db->prepare(
            'INSERT INTO produccion_distribuciones (produccion_diaria_id, almacen_id, cantidad, usuario_id)
             VALUES (:produccion_id, :almacen_id, :cantidad, :usuario_id)'
        );
        $ins->execute([
            'produccion_id' => $produccionId,
            'almacen_id'    => $almacenId,
            'cantidad'      => $cantidad,
            'usuario_id'    => $usuarioId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function recientes(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT pr.id, pr.fecha, p.nombre AS producto, pr.litros_leche, pr.factor_l_kg, pr.kg_esperados,
                    pr.cuajada_kg, pr.masa_min_kg, pr.masa_max_kg, pr.kg_reales, pr.costo_leche_usd,
                    ROUND(pr.costo_leche_usd / NULLIF(pr.kg_reales, 0), 4) AS costo_kg_usd
             FROM producciones pr
             JOIN productos p ON p.id = pr.producto_id
             ORDER BY pr.fecha DESC, pr.id DESC
             LIMIT :limite'
        );
        $stmt->bindValue('limite', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
