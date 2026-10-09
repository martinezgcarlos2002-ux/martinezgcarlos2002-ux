<?php
declare(strict_types=1);

/**
 * Extensión publicitaria de productos de producción: precio al detal, foto y TV.
 * No crea ni elimina filas de `productos`.
 */
final class CatalogoService
{
    public const FOTO_DIR_REL = 'img/catalogo';
    private const FOTO_MAX = 3_145_728;
    private const MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listarAdmin(): array
    {
        return $this->db->query(
            'SELECT p.id, p.nombre, p.unidad, p.precio_usd AS precio_produccion,
                    COALESCE(c.precio_usd, p.precio_usd) AS precio_usd,
                    c.foto_ruta,
                    COALESCE(c.mostrar_pantalla, 1) AS mostrar_pantalla,
                    c.updated_at
             FROM productos p
             LEFT JOIN ven_catalogo_publicidad c ON c.producto_id = p.id
             WHERE p.activo = 1
             ORDER BY p.nombre'
        )->fetchAll();
    }

    /**
     * Ítems visibles en la pantalla pública.
     * @return list<array<string, mixed>>
     */
    public function listarPantalla(): array
    {
        return $this->db->query(
            'SELECT p.id, p.nombre,
                    COALESCE(c.precio_usd, p.precio_usd) AS precio_usd,
                    c.foto_ruta,
                    COALESCE(c.updated_at, p.actualizado_en, p.creado_en) AS updated_at
             FROM productos p
             LEFT JOIN ven_catalogo_publicidad c ON c.producto_id = p.id
             WHERE p.activo = 1 AND COALESCE(c.mostrar_pantalla, 1) = 1
               AND COALESCE(c.precio_usd, p.precio_usd) > 0
             ORDER BY p.nombre'
        )->fetchAll();
    }

    public function tasa(): ?string
    {
        $v = $this->db->query('SELECT tasa_bs FROM ven_catalogo_tasa WHERE id = 1')->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function actualizadoEn(): string
    {
        $cat = $this->db->query('SELECT MAX(updated_at) FROM ven_catalogo_publicidad')->fetchColumn();
        $tasa = $this->db->query('SELECT actualizado_en FROM ven_catalogo_tasa WHERE id = 1')->fetchColumn();
        $stamps = array_filter([(string) $cat, (string) $tasa], static fn (string $s): bool => $s !== '');
        return $stamps === [] ? '0' : max($stamps);
    }

    public function actualizarPrecio(int $productoId, string $precio): array
    {
        $this->asegurarProducto($productoId);
        return Database::transaction(function () use ($productoId, $precio): array {
            $this->asegurarFila($productoId);
            $this->db->prepare(
                'UPDATE ven_catalogo_publicidad SET precio_usd = :p, updated_at = CURRENT_TIMESTAMP WHERE producto_id = :id'
            )->execute(['p' => $precio, 'id' => $productoId]);
            return $this->fila($productoId);
        });
    }

    public function togglePantalla(int $productoId): array
    {
        $this->asegurarProducto($productoId);
        return Database::transaction(function () use ($productoId): array {
            $this->asegurarFila($productoId);
            $this->db->prepare(
                'UPDATE ven_catalogo_publicidad
                 SET mostrar_pantalla = IF(mostrar_pantalla = 1, 0, 1), updated_at = CURRENT_TIMESTAMP
                 WHERE producto_id = :id'
            )->execute(['id' => $productoId]);
            return $this->fila($productoId);
        });
    }

    public function actualizarTasa(string $tasa, int $userId): string
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ven_catalogo_tasa (id, tasa_bs, actualizado_por) VALUES (1, :tasa, :u)
             ON DUPLICATE KEY UPDATE tasa_bs = VALUES(tasa_bs), actualizado_por = VALUES(actualizado_por)'
        );
        $stmt->execute(['tasa' => $tasa, 'u' => $userId]);
        return $this->tasa() ?? $tasa;
    }

    /** @param array{tmp_name:string, error:int, size:int, name:string} $archivo */
    public function subirFoto(int $productoId, array $archivo): array
    {
        $this->asegurarProducto($productoId);
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['foto' => 'No se recibió la imagen.']);
        }
        if (($archivo['size'] ?? 0) <= 0 || $archivo['size'] > self::FOTO_MAX) {
            throw new ValidationException(['foto' => 'La imagen debe pesar entre 1 byte y 3 MB.']);
        }
        $tmp = (string) $archivo['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            throw new ValidationException(['foto' => 'Archivo de carga inválido.']);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $ext = self::MIMES[$mime] ?? null;
        if ($ext === null) {
            throw new ValidationException(['foto' => 'Solo se aceptan imágenes JPG, PNG o WEBP.']);
        }

        $dir = ADARA_ROOT . '/assets/' . self::FOTO_DIR_REL;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ValidationException(['foto' => 'No se pudo crear la carpeta de imágenes.']);
        }

        $nombre = bin2hex(random_bytes(8)) . '.' . $ext;
        $rel = self::FOTO_DIR_REL . '/' . $nombre;
        $destino = $dir . '/' . $nombre;
        if (!move_uploaded_file($tmp, $destino)) {
            throw new ValidationException(['foto' => 'No se pudo guardar la imagen.']);
        }

        return Database::transaction(function () use ($productoId, $rel, $destino): array {
            $this->asegurarFila($productoId);
            $prev = $this->db->prepare('SELECT foto_ruta FROM ven_catalogo_publicidad WHERE producto_id = :id FOR UPDATE');
            $prev->execute(['id' => $productoId]);
            $vieja = $prev->fetchColumn();
            $this->db->prepare(
                'UPDATE ven_catalogo_publicidad SET foto_ruta = :f, updated_at = CURRENT_TIMESTAMP WHERE producto_id = :id'
            )->execute(['f' => $rel, 'id' => $productoId]);
            if (is_string($vieja) && $vieja !== '' && $vieja !== $rel) {
                $this->borrarFoto($vieja);
            }
            if (!is_file($destino)) {
                throw new ValidationException(['foto' => 'La imagen no quedó en disco.']);
            }
            return $this->fila($productoId);
        });
    }

    /** @return array<string, mixed> */
    public function fila(int $productoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.nombre, p.unidad, p.precio_usd AS precio_produccion,
                    COALESCE(c.precio_usd, p.precio_usd) AS precio_usd,
                    c.foto_ruta,
                    COALESCE(c.mostrar_pantalla, 1) AS mostrar_pantalla,
                    c.updated_at
             FROM productos p
             LEFT JOIN ven_catalogo_publicidad c ON c.producto_id = p.id
             WHERE p.id = :id AND p.activo = 1'
        );
        $stmt->execute(['id' => $productoId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ValidationException(['producto_id' => 'El producto no existe o está inactivo.']);
        }
        return $row;
    }

    private function asegurarProducto(int $productoId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM productos WHERE id = :id AND activo = 1');
        $stmt->execute(['id' => $productoId]);
        if ($stmt->fetchColumn() === false) {
            throw new ValidationException(['producto_id' => 'El producto no existe o está inactivo.']);
        }
    }

    private function asegurarFila(int $productoId): void
    {
        $precio = $this->db->prepare('SELECT precio_usd FROM productos WHERE id = :id');
        $precio->execute(['id' => $productoId]);
        $base = $precio->fetchColumn();
        $this->db->prepare(
            'INSERT INTO ven_catalogo_publicidad (producto_id, precio_usd, mostrar_pantalla)
             VALUES (:id, :p, 1)
             ON DUPLICATE KEY UPDATE producto_id = producto_id'
        )->execute(['id' => $productoId, 'p' => $base !== false ? $base : '0.00']);
    }

    private function borrarFoto(string $rel): void
    {
        $rel = str_replace('\\', '/', $rel);
        if (!preg_match('#^' . preg_quote(self::FOTO_DIR_REL, '#') . '/[a-f0-9]{16}\.(jpg|png|webp)$#', $rel)) {
            return;
        }
        $abs = ADARA_ROOT . '/assets/' . $rel;
        if (is_file($abs)) {
            unlink($abs);
        }
    }
}
