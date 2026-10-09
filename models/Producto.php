<?php
declare(strict_types=1);

final class Producto
{
    public const UNIDADES = ['kg', 'unidad', 'litro'];
    public const UOM = [
        'KG'  => 'Kilogramos (KG)',
        'LTS' => 'Litros (LTS)',
        'UND' => 'Unidades (UND)',
    ];
    public const UOM_A_UNIDAD = ['KG' => 'kg', 'LTS' => 'litro', 'UND' => 'unidad'];
    public const UNIDAD_A_UOM = ['kg' => 'KG', 'litro' => 'LTS', 'unidad' => 'UND'];

    public static function uom(string $codigo): string
    {
        $c = strtoupper($codigo);
        return isset(self::UOM[$c]) ? $c : 'KG';
    }

    public function __construct(private PDO $db)
    {
    }

    public function listar(string $q): array
    {
        $sql = 'SELECT id, codigo, nombre, unidad, unidad_medida, precio_usd, stock, umbral_alerta, activo
                FROM productos
                WHERE activo = 1';
        $params = [];

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $sql .= ' AND (nombre LIKE :q1 OR codigo LIKE :q2)';
            $params = ['q1' => $like, 'q2' => $like];
        }

        $stmt = $this->db->prepare($sql . ' ORDER BY nombre');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array{id:int, nombre:string, unidad:string, precio_usd:string, stock:string}> */
    public function disponiblesParaVenta(): array
    {
        return $this->db
            ->query('SELECT id, nombre, unidad, precio_usd, stock FROM productos WHERE activo = 1 AND stock > 0 ORDER BY nombre')
            ->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO productos (codigo, nombre, unidad, unidad_medida, precio_usd, umbral_alerta)
             VALUES (:codigo, :nombre, :unidad, :unidad_medida, :precio_usd, :umbral_alerta)'
        );
        $stmt->execute([
            'codigo'         => $data['codigo'],
            'nombre'         => $data['nombre'],
            'unidad'         => $data['unidad'],
            'unidad_medida'  => $data['unidad_medida'] ?? (self::UNIDAD_A_UOM[$data['unidad']] ?? 'KG'),
            'precio_usd'     => $data['precio_usd'],
            'umbral_alerta'  => $data['umbral_alerta'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Bloquea (FOR UPDATE) productos activos en orden de id: ventas, producción y ajustes
     * toman los candados en el mismo orden y así no se provocan deadlocks.
     * @param list<int> $ids
     * @return array<int, array{id:int, nombre:string, precio_usd:string, stock:string}>
     */
    public function bloquearActivos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        sort($ids);

        $marcas = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $marcas[] = ':id' . $i;
            $params['id' . $i] = $id;
        }

        $stmt = $this->db->prepare(
            'SELECT id, nombre, precio_usd, stock
             FROM productos
             WHERE activo = 1 AND id IN (' . implode(', ', $marcas) . ')
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

    /** Incluye productos archivados: eliminar necesita verlos. */
    public function bloquear(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, codigo, nombre, unidad, precio_usd, stock, activo FROM productos WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Sin guarda en el WHERE a propósito: ck_productos_stock (stock >= 0) es la última barrera en la BD. */
    public function descontarStock(int $productoId, string $cantidad): void
    {
        $this->sumarStockGlobal($productoId, '-' . $cantidad);
    }

    /** Delta con signo sobre productos.stock. */
    public function sumarStockGlobal(int $productoId, string $delta): void
    {
        $stmt = $this->db->prepare('UPDATE productos SET stock = stock + :delta WHERE id = :id AND activo = 1');
        $stmt->execute(['delta' => $delta, 'id' => $productoId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('No se pudo ajustar el stock global del producto #%d.', $productoId));
        }
    }

    /** Kardex: toma stock_final del producto ya descontado, en la misma transacción. */
    public function registrarKardexVenta(int $productoId, string $cantidad, int $ventaId, int $usuarioId): void
    {
        $this->registrarKardex([
            'producto_id' => $productoId,
            'tipo'        => 'venta',
            'cantidad'    => '-' . $cantidad,
            'venta_id'    => $ventaId,
            'usuario_id'  => $usuarioId,
        ]);
    }

    /**
     * @param array{producto_id:int, tipo:string, cantidad:string, usuario_id:int, almacen_id?:?int,
     *              venta_id?:?int, produccion_diaria_id?:?int, motivo?:?string} $mov
     */
    public function registrarKardex(array $mov): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO inventario_movimientos
                 (producto_id, almacen_id, tipo, cantidad, stock_final, venta_id, produccion_diaria_id, motivo, usuario_id)
             SELECT id, :almacen_id, :tipo, :cantidad, stock, :venta_id, :produccion_diaria_id, :motivo, :usuario_id
             FROM productos WHERE id = :id'
        );
        $stmt->execute([
            'almacen_id'           => $mov['almacen_id'] ?? null,
            'tipo'                 => $mov['tipo'],
            'cantidad'             => $mov['cantidad'],
            'venta_id'             => $mov['venta_id'] ?? null,
            'produccion_diaria_id' => $mov['produccion_diaria_id'] ?? null,
            'motivo'               => $mov['motivo'] ?? null,
            'usuario_id'           => $mov['usuario_id'],
            'id'                   => $mov['producto_id'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('No se pudo registrar el kardex del producto #%d.', $mov['producto_id']));
        }
        return (int) $this->db->lastInsertId();
    }

    /** Con historial el producto se archiva: las FK RESTRICT protegen ventas, kardex y producción. */
    public function tieneHistorial(int $id): bool
    {
        $stmt = $this->db->prepare(
            'SELECT EXISTS (SELECT 1 FROM venta_items WHERE producto_id = :a)
                 OR EXISTS (SELECT 1 FROM inventario_movimientos WHERE producto_id = :b)
                 OR EXISTS (SELECT 1 FROM producciones WHERE producto_id = :c)
                 OR EXISTS (SELECT 1 FROM produccion_diaria WHERE producto_id = :d)
                 OR EXISTS (SELECT 1 FROM lotes WHERE producto_id = :e)'
        );
        $stmt->execute(['a' => $id, 'b' => $id, 'c' => $id, 'd' => $id, 'e' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function archivar(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE productos SET activo = 0 WHERE id = :id AND activo = 1');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('No se pudo archivar el producto #%d.', $id));
        }
    }

    public function eliminar(int $id): void
    {
        $this->db->prepare('DELETE FROM inventario_almacenes WHERE producto_id = :id')->execute(['id' => $id]);
        $this->db->prepare('DELETE FROM formulas_produccion WHERE producto_id = :id')->execute(['id' => $id]);
        $stmt = $this->db->prepare('DELETE FROM productos WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('No se pudo eliminar el producto #%d.', $id));
        }
    }

    /** MySQL 8 lanza 3819 y MariaDB 4025 cuando se viola un CHECK. */
    public static function violaCheck(PDOException $e, string ...$constraints): bool
    {
        if (!in_array($e->errorInfo[1] ?? null, [3819, 4025], true)) {
            return false;
        }
        $mensaje = (string) ($e->errorInfo[2] ?? '');
        foreach ($constraints as $nombre) {
            if (str_contains($mensaje, $nombre)) {
                return true;
            }
        }
        return false;
    }

    public static function violaStockNoNegativo(PDOException $e): bool
    {
        return self::violaCheck($e, 'ck_productos_stock', 'ck_inv_alm_stock');
    }

    /** Cantidades DECIMAL(12,3) como enteros de milésimas: comparar y sumar stock sin pasar por float. */
    public static function aMilesimas(string $decimal): int
    {
        $negativo = str_starts_with($decimal, '-');
        [$entero, $fraccion] = explode('.', ltrim($decimal, '-') . '.');
        $valor = (int) $entero * 1000 + (int) str_pad(substr($fraccion, 0, 3), 3, '0');
        return $negativo ? -$valor : $valor;
    }

    public static function desdeMilesimas(int $milesimas): string
    {
        $abs = abs($milesimas);
        return ($milesimas < 0 ? '-' : '') . sprintf('%d.%03d', intdiv($abs, 1000), $abs % 1000);
    }
}
