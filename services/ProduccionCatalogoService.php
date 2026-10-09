<?php
declare(strict_types=1);

/**
 * Catálogo de producción (`prod_catalogo`) sincronizado con Inventario (`productos`).
 * Alta y edición van en transacción ACID: o se graban ambos lados, o ninguno.
 */
final class ProduccionCatalogoService
{
    public function __construct(private PDO $db)
    {
        $this->forzarUtf8();
    }

    /** Evita mojibake (ej. «Guayan├®s») aunque el DSN ya lleve charset=utf8mb4. */
    public function forzarUtf8(): void
    {
        $this->db->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->db->query(
            'SELECT c.id, c.producto_id, c.nombre, c.descripcion, c.unidad_medida, c.orden, c.precio_produccion, c.activo,
                    p.nombre AS inventario, p.stock, p.unidad, p.unidad_medida AS inv_uom, p.precio_usd,
                    COALESCE(v.precio_usd, c.precio_produccion) AS precio_detal,
                    COALESCE(v.mostrar_pantalla, 1) AS visible_tv
             FROM prod_catalogo c
             LEFT JOIN productos p ON p.id = c.producto_id
             LEFT JOIN ven_catalogo_publicidad v ON v.producto_id = p.id
             ORDER BY c.orden, c.nombre, c.id'
        )->fetchAll();
    }

    /** @return array<string, mixed> */
    public function fila(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.producto_id, c.nombre, c.descripcion, c.unidad_medida, c.orden, c.precio_produccion, c.activo,
                    p.nombre AS inventario, p.stock, p.unidad,
                    COALESCE(v.precio_usd, c.precio_produccion) AS precio_detal,
                    COALESCE(v.mostrar_pantalla, 1) AS visible_tv
             FROM prod_catalogo c
             LEFT JOIN productos p ON p.id = c.producto_id
             LEFT JOIN ven_catalogo_publicidad v ON v.producto_id = p.id
             WHERE c.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['id' => 'El producto del catálogo no existe.']);
        }
        return $row;
    }

    /**
     * Inserta en Producción, captura el ID y crea (o vincula) el mismo nombre en Inventario.
     *
     * @param array{nombre:string, descripcion:?string, unidad_medida:string, orden:int, precio_produccion:string, precio_detal:string} $data
     * @return array<string, mixed>
     */
    public function crear(array $data, int $usuarioId): array
    {
        $this->forzarUtf8();
        return $this->enTransaccion(function () use ($data, $usuarioId): array {
            return $this->crearDentroDeTransaccion($data, $usuarioId);
        });
    }

    /**
     * @param array{nombre:string, descripcion:?string, unidad_medida:string, orden:int, precio_produccion:string, precio_detal:string} $data
     * @return array<string, mixed>
     */
    public function actualizar(int $id, array $data): array
    {
        $this->forzarUtf8();
        return $this->enTransaccion(function () use ($id, $data): array {
            $actual = $this->bloquear($id);
            $this->db->prepare(
                'UPDATE prod_catalogo
                 SET nombre = :nombre, descripcion = :descripcion, unidad_medida = :uom, orden = :orden,
                     precio_produccion = :precio
                 WHERE id = :id'
            )->execute([
                'nombre'      => $data['nombre'],
                'descripcion' => $data['descripcion'],
                'uom'         => $data['unidad_medida'],
                'orden'       => $data['orden'],
                'precio'      => $data['precio_produccion'],
                'id'          => $id,
            ]);

            $productoId = (int) ($actual['producto_id'] ?? 0);
            if ($productoId > 0) {
                $this->sincronizarInventario($productoId, $data['nombre'], $data['descripcion'], $data['unidad_medida'], $data['precio_produccion']);
                $this->sincronizarDetal($productoId, $data['precio_detal']);
            }

            return $this->fila($id);
        });
    }

    /** Baja lógica: nunca DELETE. El producto sigue existiendo en inventario. */
    public function eliminar(int $id): string
    {
        $this->forzarUtf8();
        return $this->enTransaccion(function () use ($id): string {
            $this->bloquear($id);
            $arch = $this->db->prepare('UPDATE prod_catalogo SET activo = 0 WHERE id = :id');
            $arch->execute(['id' => $id]);
            if ($arch->rowCount() !== 1) {
                $sigue = $this->db->prepare('SELECT activo FROM prod_catalogo WHERE id = :id');
                $sigue->execute(['id' => $id]);
                if ((int) $sigue->fetchColumn() !== 0) {
                    throw new RuntimeException(sprintf('No se pudo inactivar el catálogo #%d.', $id));
                }
            }
            return 'archivado';
        });
    }

    /**
     * Lunes–domingo según NOMINA_DIA_INICIO (1 = lunes).
     * @return array{inicio:string, fin:string}
     */
    public static function semanaDe(string $fecha): array
    {
        $dia = new DateTimeImmutable($fecha);
        $atras = ((int) $dia->format('N') - NOMINA_DIA_INICIO + 7) % 7;
        $inicio = $dia->modify(sprintf('-%d days', $atras));
        return [
            'inicio' => $inicio->format('Y-m-d'),
            'fin'    => $inicio->modify('+6 days')->format('Y-m-d'),
        ];
    }

    /**
     * @return list<array{nombre:string, prod_hoy:string, prod_semana:string, stock:string, unidad:string, unidad_medida:string}>
     */
    public function reporteDiario(string $fecha): array
    {
        $semana = self::semanaDe($fecha);
        $stmt = $this->db->prepare(
            'SELECT c.nombre,
                    COALESCE(hoy.cantidad, 0) AS prod_hoy,
                    COALESCE(sem.cantidad, 0) AS prod_semana,
                    COALESCE(stk.stock, p.stock, 0) AS stock,
                    COALESCE(p.unidad, \'kg\') AS unidad,
                    COALESCE(c.unidad_medida, p.unidad_medida, \'KG\') AS unidad_medida
             FROM prod_catalogo c
             LEFT JOIN productos p ON p.id = c.producto_id
             LEFT JOIN (
                 SELECT producto_id, SUM(cantidad) AS cantidad
                 FROM produccion_diaria
                 WHERE fecha = :fecha
                 GROUP BY producto_id
             ) hoy ON hoy.producto_id = c.producto_id
             LEFT JOIN (
                 SELECT producto_id, SUM(cantidad) AS cantidad
                 FROM produccion_diaria
                 WHERE fecha BETWEEN :sem_ini AND :sem_fin
                 GROUP BY producto_id
             ) sem ON sem.producto_id = c.producto_id
             LEFT JOIN (
                 SELECT producto_id, SUM(stock) AS stock
                 FROM inventario_almacenes
                 GROUP BY producto_id
             ) stk ON stk.producto_id = c.producto_id
             WHERE c.activo = 1
             ORDER BY c.orden, c.nombre, c.id'
        );
        $stmt->execute([
            'fecha'   => $fecha,
            'sem_ini' => $semana['inicio'],
            'sem_fin' => $semana['fin'],
        ]);
        return $stmt->fetchAll();
    }

    /**
     * @param array{nombre:string, descripcion:?string, unidad_medida:string, orden:int, precio_produccion:string, precio_detal:string} $data
     * @return array<string, mixed>
     */
    private function crearDentroDeTransaccion(array $data, int $usuarioId): array
    {
        unset($usuarioId);

        $ins = $this->db->prepare(
            'INSERT INTO prod_catalogo (nombre, descripcion, unidad_medida, orden, precio_produccion, activo)
             VALUES (:nombre, :descripcion, :uom, :orden, :precio, 1)'
        );
        $ins->execute([
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'],
            'uom'         => $data['unidad_medida'],
            'orden'       => $data['orden'],
            'precio'      => $data['precio_produccion'],
        ]);
        $catalogoId = (int) $this->db->lastInsertId();

        $productoId = $this->insertarOVincularInventario(
            $catalogoId,
            $data['nombre'],
            $data['descripcion'],
            $data['unidad_medida'],
            $data['precio_produccion']
        );
        $this->sincronizarDetal($productoId, $data['precio_detal']);

        return $this->fila($catalogoId);
    }

    private function insertarOVincularInventario(int $catalogoId, string $nombre, ?string $descripcion, string $uom, string $precio): int
    {
        $busca = $this->db->prepare('SELECT id FROM productos WHERE nombre = :nombre LIMIT 1 FOR UPDATE');
        $busca->execute(['nombre' => $nombre]);
        $existente = $busca->fetchColumn();

        if ($existente !== false) {
            $productoId = (int) $existente;
            $ocupado = $this->db->prepare(
                'SELECT id FROM prod_catalogo WHERE producto_id = :pid AND id <> :id LIMIT 1'
            );
            $ocupado->execute(['pid' => $productoId, 'id' => $catalogoId]);
            if ($ocupado->fetchColumn() !== false) {
                throw new ValidationException([
                    'nombre' => 'Ese producto ya está vinculado a otro ítem del catálogo de producción.',
                ]);
            }
            $this->sincronizarInventario($productoId, $nombre, $descripcion, $uom, $precio);
        } else {
            $codigo = sprintf('PRD-%06d', $catalogoId);
            $unidad = Producto::UOM_A_UNIDAD[$uom] ?? 'kg';
            $this->db->prepare(
                'INSERT INTO productos (codigo, nombre, descripcion, unidad, unidad_medida, precio_usd, umbral_alerta)
                 VALUES (:codigo, :nombre, :descripcion, :unidad, :uom, :precio, 0)'
            )->execute([
                'codigo'      => $codigo,
                'nombre'      => $nombre,
                'descripcion' => $descripcion,
                'unidad'      => $unidad,
                'uom'         => $uom,
                'precio'      => $precio,
            ]);
            $productoId = (int) $this->db->lastInsertId();
        }

        $this->db->prepare('UPDATE prod_catalogo SET producto_id = :pid WHERE id = :id')
            ->execute(['pid' => $productoId, 'id' => $catalogoId]);

        return $productoId;
    }

    private function sincronizarInventario(int $productoId, string $nombre, ?string $descripcion, string $uom, string $precio): void
    {
        $unidad = Producto::UOM_A_UNIDAD[$uom] ?? 'kg';
        $this->db->prepare(
            'UPDATE productos
             SET nombre = :nombre, descripcion = :descripcion, unidad = :unidad, unidad_medida = :uom,
                 precio_usd = :precio, activo = 1
             WHERE id = :id'
        )->execute([
            'nombre'      => $nombre,
            'descripcion' => $descripcion,
            'unidad'      => $unidad,
            'uom'         => $uom,
            'precio'      => $precio,
            'id'          => $productoId,
        ]);
    }

    /** @param callable(): mixed $work */
    private function enTransaccion(callable $work): mixed
    {
        $pdo = $this->db;
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $resultado = $work();
            $pdo->commit();
            return $resultado;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function sincronizarDetal(int $productoId, string $precioDetal): void
    {
        $this->db->prepare(
            'INSERT INTO ven_catalogo_publicidad (producto_id, precio_usd, mostrar_pantalla)
             VALUES (:id, :p, 1)
             ON DUPLICATE KEY UPDATE precio_usd = VALUES(precio_usd)'
        )->execute(['id' => $productoId, 'p' => $precioDetal]);
    }

    /** @return array<string, mixed> */
    private function bloquear(int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, producto_id, nombre, activo FROM prod_catalogo WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['id' => 'El producto del catálogo no existe.']);
        }
        return $row;
    }
}
