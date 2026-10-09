<?php
declare(strict_types=1);

final class Almacen
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array{id:int, nombre:string, orden:int}> */
    public function activos(): array
    {
        return $this->db
            ->query('SELECT id, nombre, orden FROM almacenes WHERE activo = 1 ORDER BY orden, id')
            ->fetchAll();
    }

    public function find(int $id, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, orden, activo FROM almacenes WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Almacenes activos bloqueados en modo compartido: nadie los da de baja mientras se les suma stock.
     * @param list<int> $ids
     * @return array<int, array{id:int, nombre:string}>
     */
    public function bloquearActivos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$marcas, $params] = self::marcas($ids);
        $stmt = $this->db->prepare(
            'SELECT id, nombre FROM almacenes
             WHERE activo = 1 AND id IN (' . $marcas . ')
             ORDER BY id
             LOCK IN SHARE MODE'
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    public function create(string $nombre): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO almacenes (nombre, orden)
             SELECT :nombre, COALESCE(MAX(orden), 0) + 10 FROM almacenes'
        );
        $stmt->execute(['nombre' => $nombre]);
        return (int) $this->db->lastInsertId();
    }

    public function rename(int $id, string $nombre): void
    {
        $stmt = $this->db->prepare('UPDATE almacenes SET nombre = :nombre WHERE id = :id AND activo = 1');
        $stmt->execute(['nombre' => $nombre, 'id' => $id]);
    }

    /** El nombre se libera con un sufijo para poder crear otro almacén con el mismo nombre. */
    public function darDeBaja(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE almacenes SET activo = 0, nombre = CONCAT(LEFT(nombre, 60), ' (baja #', id, ')') WHERE id = :id AND activo = 1"
        );
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('No se pudo dar de baja el almacén #%d.', $id));
        }
    }

    public function contarActivos(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM almacenes WHERE activo = 1')->fetchColumn();
    }

    public function primeroActivoDistintoDe(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre FROM almacenes WHERE activo = 1 AND id <> :id ORDER BY orden, id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int, array<int, string>>  [producto_id][almacen_id] => stock */
    public function stockPorProducto(): array
    {
        $rows = $this->db->query(
            'SELECT ia.producto_id, ia.almacen_id, ia.stock
             FROM inventario_almacenes ia
             JOIN almacenes a ON a.id = ia.almacen_id AND a.activo = 1'
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['producto_id']][(int) $r['almacen_id']] = (string) $r['stock'];
        }
        return $out;
    }

    /** Stock actual de la fila bloqueada; '0.000' si todavía no existe (el gap lock evita inserciones concurrentes). */
    public function bloquearStock(int $productoId, int $almacenId): string
    {
        $stmt = $this->db->prepare(
            'SELECT stock FROM inventario_almacenes WHERE producto_id = :producto_id AND almacen_id = :almacen_id FOR UPDATE'
        );
        $stmt->execute(['producto_id' => $productoId, 'almacen_id' => $almacenId]);
        $stock = $stmt->fetchColumn();
        return $stock === false ? '0.000' : (string) $stock;
    }

    /**
     * Delta con signo. Un resultado negativo lo rechaza ck_inv_alm_stock y revierte la transacción.
     * Las salidas van por UPDATE: MariaDB valida el CHECK sobre la fila candidata del INSERT antes de
     * detectar el duplicado, así que un upsert con delta negativo fallaría aunque hubiera stock.
     */
    public function aplicarDelta(int $productoId, int $almacenId, string $delta): void
    {
        if (str_starts_with($delta, '-')) {
            $stmt = $this->db->prepare(
                'UPDATE inventario_almacenes SET stock = stock + :delta
                 WHERE producto_id = :producto_id AND almacen_id = :almacen_id'
            );
            $stmt->execute(['delta' => $delta, 'producto_id' => $productoId, 'almacen_id' => $almacenId]);
            if ($stmt->rowCount() !== 1) {
                throw new ValidationException(['stock' => sprintf('El producto #%d no tiene stock en el almacén #%d.', $productoId, $almacenId)]);
            }
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO inventario_almacenes (producto_id, almacen_id, stock)
             VALUES (:producto_id, :almacen_id, :delta_ins)
             ON DUPLICATE KEY UPDATE stock = stock + :delta_upd'
        );
        $stmt->execute([
            'producto_id' => $productoId,
            'almacen_id'  => $almacenId,
            'delta_ins'   => $delta,
            'delta_upd'   => $delta,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException(sprintf('No se pudo ajustar el stock del producto #%d en el almacén #%d.', $productoId, $almacenId));
        }
    }

    /**
     * Descuenta una venta recorriendo los almacenes en su orden. Lanza ValidationException si no alcanza.
     * @return list<array{almacen_id:int, cantidad:string}>
     */
    public function descontarEnOrden(int $productoId, string $cantidad, string $nombreProducto): array
    {
        $stmt = $this->db->prepare(
            'SELECT ia.almacen_id, ia.stock
             FROM inventario_almacenes ia
             JOIN almacenes a ON a.id = ia.almacen_id AND a.activo = 1
             WHERE ia.producto_id = :producto_id AND ia.stock > 0
             ORDER BY a.orden, a.id
             FOR UPDATE'
        );
        $stmt->execute(['producto_id' => $productoId]);

        $restante = Producto::aMilesimas($cantidad);
        $salidas = [];
        foreach ($stmt->fetchAll() as $fila) {
            if ($restante <= 0) {
                break;
            }
            $toma = min($restante, Producto::aMilesimas((string) $fila['stock']));
            $texto = Producto::desdeMilesimas($toma);
            $this->aplicarDelta($productoId, (int) $fila['almacen_id'], '-' . $texto);
            $salidas[] = ['almacen_id' => (int) $fila['almacen_id'], 'cantidad' => $texto];
            $restante -= $toma;
        }

        if ($restante > 0) {
            throw new ValidationException(['items' => sprintf(
                'Stock por almacén insuficiente para %s: faltan %s.',
                $nombreProducto,
                num(Producto::desdeMilesimas($restante), 3)
            )]);
        }
        return $salidas;
    }

    /**
     * Pasa todo el stock de un almacén a otro sin tocar el stock global.
     * @return list<array{producto_id:int, cantidad:string}>
     */
    public function moverTodo(int $origenId, int $destinoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT producto_id, stock FROM inventario_almacenes WHERE almacen_id = :almacen_id ORDER BY producto_id FOR UPDATE'
        );
        $stmt->execute(['almacen_id' => $origenId]);

        $movidos = [];
        foreach ($stmt->fetchAll() as $fila) {
            $stock = (string) $fila['stock'];
            if (Producto::aMilesimas($stock) > 0) {
                $this->aplicarDelta((int) $fila['producto_id'], $destinoId, $stock);
                $movidos[] = ['producto_id' => (int) $fila['producto_id'], 'cantidad' => $stock];
            }
        }

        $this->db->prepare('DELETE FROM inventario_almacenes WHERE almacen_id = :almacen_id')
            ->execute(['almacen_id' => $origenId]);
        return $movidos;
    }

    /** @param list<int> $ids @return array{0:string, 1:array<string,int>} */
    private static function marcas(array $ids): array
    {
        sort($ids);
        $marcas = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $marcas[] = ':id' . $i;
            $params['id' . $i] = $id;
        }
        return [implode(', ', $marcas), $params];
    }
}
