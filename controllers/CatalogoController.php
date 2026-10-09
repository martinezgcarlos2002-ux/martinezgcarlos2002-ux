<?php
declare(strict_types=1);

/**
 * Catálogo y publicidad: precios al detal, fotos y pantalla de TV.
 * No crea ni elimina productos.
 */
final class CatalogoController
{
    private CatalogoService $cat;

    public function __construct()
    {
        $this->cat = new CatalogoService(get_db());
    }

    public function index(): void
    {
        view('catalogo', [
            'productos'    => array_map([self::class, 'jsonProducto'], $this->cat->listarAdmin()),
            'tasa'         => $this->cat->tasa(),
            'puedeEditar'  => has_permiso(current_user(), 'catalogo', 'editar')
                || has_permiso(current_user(), 'catalogo', 'crear'),
            'endpoints'    => [
                'precio'   => url('catalogo_precio'),
                'pantalla' => url('catalogo_toggle'),
                'tasa'     => url('catalogo_tasa'),
                'foto'     => url('catalogo_foto'),
                'pdf'      => url('catalogo_lista_precios_pdf'),
                'tv'       => url('catalogo_pantalla'),
            ],
        ]);
    }

    public function actualizarPrecio(): void
    {
        $this->responder(function (): array {
            $id = self::idProducto();
            $precio = parse_monto(post_string('precio', 20), 2, 9999999999.99, true);
            if ($precio === null) {
                throw new ValidationException(['precio' => 'Precio inválido: número mayor o igual a 0, hasta 2 decimales.']);
            }
            $fila = $this->cat->actualizarPrecio($id, $precio);
            audit_log('catalogo', 'precio', ['producto_id' => $id, 'precio_usd' => $precio], 'ven_catalogo_publicidad', $id);
            return ['mensaje' => 'Precio actualizado.', 'producto' => self::jsonProducto($fila)];
        });
    }

    public function togglePantalla(): void
    {
        $this->responder(function (): array {
            $id = self::idProducto();
            $fila = $this->cat->togglePantalla($id);
            audit_log('catalogo', 'toggle_tv', [
                'producto_id' => $id,
                'mostrar'     => (int) $fila['mostrar_pantalla'],
            ], 'ven_catalogo_publicidad', $id);
            return [
                'mensaje'  => ((int) $fila['mostrar_pantalla'] === 1) ? 'Visible en pantalla.' : 'Oculto de la pantalla.',
                'producto' => self::jsonProducto($fila),
            ];
        });
    }

    public function actualizarTasa(): void
    {
        $this->responder(function (): array {
            $tasa = parse_decimal(post_string('tasa', 20), 4, 99999999.9999);
            if ($tasa === null) {
                throw new ValidationException(['tasa' => 'Tasa inválida: número mayor que 0 con hasta 4 decimales (Bs por 1 USD).']);
            }
            $guardada = $this->cat->actualizarTasa($tasa, (int) current_user()['id']);
            audit_log('catalogo', 'tasa', ['tasa_bs' => $guardada], 'ven_catalogo_tasa', 1);
            return ['mensaje' => 'Tasa del catálogo actualizada.', 'tasa' => (float) $guardada];
        });
    }

    public function subirFoto(): void
    {
        $this->responder(function (): array {
            $id = self::idProducto();
            $archivo = $_FILES['foto'] ?? null;
            if (!is_array($archivo)) {
                throw new ValidationException(['foto' => 'Selecciona una imagen JPG, PNG o WEBP.']);
            }
            $fila = $this->cat->subirFoto($id, $archivo);
            audit_log('catalogo', 'foto', ['producto_id' => $id, 'foto' => $fila['foto_ruta']], 'ven_catalogo_publicidad', $id);
            return ['mensaje' => 'Foto actualizada.', 'producto' => self::jsonProducto($fila)];
        });
    }

    /** Lista de precios imprimible (cuadrícula, CSP-safe). */
    public function listaPreciosPdf(): void
    {
        $tasa = $this->cat->tasa();
        $items = array_map(fn (array $p): array => self::jsonPantalla($p, $tasa), $this->cat->listarPantalla());
        header('Content-Disposition: inline; filename="lista_precios_catalogo.html"');
        view('catalogo_lista_precios', [
            'hoy'     => date('Y-m-d'),
            'empresa' => APP_EMPRESA,
            'tasa'    => $tasa,
            'items'   => $items,
        ]);
    }

    public function pantalla(): void
    {
        $tasa = $this->cat->tasa();
        view('catalogo_pantalla', [
            'empresa'    => APP_EMPRESA,
            'tasa'       => $tasa,
            'revision'   => $this->cat->actualizadoEn(),
            'items'      => array_map(fn (array $p): array => self::jsonPantalla($p, $tasa), $this->cat->listarPantalla()),
            'poll_url'   => url('catalogo_pantalla_datos'),
        ]);
    }

    /** GET ligero para el polling de la TV (sin recargar la página). */
    public function pantallaDatos(): void
    {
        $tasa = $this->cat->tasa();
        json_response(200, [
            'ok'       => true,
            'revision' => $this->cat->actualizadoEn(),
            'tasa'     => $tasa !== null ? (float) $tasa : null,
            'items'    => array_map(fn (array $p): array => self::jsonPantalla($p, $tasa), $this->cat->listarPantalla()),
        ]);
    }

    private function responder(callable $accion): never
    {
        try {
            json_response(200, ['ok' => true] + $accion());
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            error_log('[ADARA][Catalogo] ' . $e);
            json_response(500, ['ok' => false, 'errores' => ['general' => 'Error interno. No se aplicaron cambios.']]);
        } catch (Throwable $e) {
            error_log('[ADARA][Catalogo] ' . $e);
            json_response(500, ['ok' => false, 'errores' => ['general' => 'Error interno. No se aplicaron cambios.']]);
        }
    }

    private static function idProducto(): int
    {
        $id = parse_int_range($_POST['producto_id'] ?? '', 1, 4294967295);
        return $id ?? throw new ValidationException(['producto_id' => 'Producto inválido.']);
    }

    /** @param array<string, mixed> $p */
    private static function jsonProducto(array $p): array
    {
        $foto = (string) ($p['foto_ruta'] ?? '');
        return [
            'id'                 => (int) $p['id'],
            'nombre'             => (string) $p['nombre'],
            'unidad'             => (string) ($p['unidad'] ?? ''),
            'precio_produccion'  => (float) ($p['precio_produccion'] ?? 0),
            'precio_usd'         => (float) $p['precio_usd'],
            'foto'               => $foto,
            'foto_url'           => $foto !== '' ? asset_v($foto) : '',
            'mostrar_pantalla'   => (int) $p['mostrar_pantalla'] === 1,
        ];
    }

    /** @param array<string, mixed> $p */
    private static function jsonPantalla(array $p, ?string $tasa): array
    {
        $usd = (float) $p['precio_usd'];
        $foto = (string) ($p['foto_ruta'] ?? '');
        return [
            'id'         => (int) $p['id'],
            'nombre'     => (string) $p['nombre'],
            'precio_usd' => $usd,
            'precio_bs'  => $tasa !== null ? round($usd * (float) $tasa, 2) : null,
            'foto'       => $foto,
            'foto_url'   => $foto !== '' ? asset_v($foto) : '',
        ];
    }
}
