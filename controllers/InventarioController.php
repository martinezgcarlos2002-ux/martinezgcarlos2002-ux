<?php
declare(strict_types=1);

final class InventarioController
{
    private Producto $productos;
    private Almacen $almacenes;

    public function __construct()
    {
        $this->productos = new Producto(get_db());
        $this->almacenes = new Almacen(get_db());
    }

    public function index(): void
    {
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 60) : '';
        $user = current_user();

        view('inventario_lista', [
            'productos'       => $this->productos->listar($q),
            'almacenes'       => $this->almacenes->activos(),
            'stockPorAlmacen' => $this->almacenes->stockPorProducto(),
            'q'               => $q,
            'tasa'            => TasaCambio::vigente(get_db())['tasa'] ?? null,
            'puedeCrear'      => has_permiso($user, 'inventario', 'crear'),
            'puedeEditar'     => has_permiso($user, 'inventario', 'editar'),
            'esAdmin'         => self::esAdmin($user),
        ]);
    }

    // ----- Productos -------------------------------------------------------

    /** La ruta 'inventario' solo exige 'ver': el permiso de alta se valida aquí. Stock inicial > 0 solo para admin. */
    public function store(): void
    {
        $user = current_user();
        if (!has_permiso($user, 'inventario', 'crear')) {
            $this->denegar('crear');
        }

        try {
            $data = $this->validarProducto();
            if ($data['stock_inicial'] !== null && !self::esAdmin($user)) {
                $this->denegar('stock_inicial');
            }

            $id = Database::transaction(function () use ($data, $user): int {
                $id = $this->productos->create($data);
                $inicial = null;
                if ($data['stock_inicial'] !== null) {
                    $destino = $this->almacenes->activos()[0] ?? null;
                    if ($destino === null) {
                        throw new ValidationException(['stock' => 'Crea un almacén antes de cargar stock inicial.']);
                    }
                    $this->moverStockManual($id, (int) $destino['id'], $data['stock_inicial'], 'Stock inicial al crear el producto', (int) $user['id']);
                    $inicial = ['almacen_id' => (int) $destino['id'], 'cantidad' => $data['stock_inicial']];
                }
                audit_log('inventario', 'crear', $data + ['stock_inicial_en' => $inicial], 'productos', $id);
                return $id;
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors);
        } catch (PDOException $e) {
            $this->fallar(match (duplicate_key($e)) {
                'uq_productos_nombre' => ['nombre' => 'Ya existe un producto con ese nombre.'],
                'uq_productos_codigo' => ['codigo' => 'Ya existe un producto con ese código.'],
                default               => throw $e,
            });
        }

        flash('success', $data['stock_inicial'] === null
            ? sprintf('Producto "%s" creado con stock 0. El stock entra por Producción.', $data['nombre'])
            : sprintf('Producto "%s" creado con %s de stock inicial.', $data['nombre'], num($data['stock_inicial'], 3)));
        redirect('inventario', ['q' => $data['nombre']]);
    }

    /** Solo administrador. Fija el stock de un producto en un almacén; la diferencia va al kardex como 'ajuste'. */
    public function ajustarStock(): void
    {
        $user = current_user();
        if (!self::esAdmin($user)) {
            $this->denegar('ajustar_stock');
        }

        try {
            $errors = [];
            $productoId = parse_int_range($_POST['producto_id'] ?? null, 1, PHP_INT_MAX);
            $almacenId = parse_int_range($_POST['almacen_id'] ?? null, 1, PHP_INT_MAX);
            $nuevo = parse_decimal($_POST['stock_nuevo'] ?? null, 3, 1000000, true);
            $motivo = preg_replace('/\s+/u', ' ', post_string('motivo', 255));

            if ($productoId === null) {
                $errors['producto_id'] = 'Producto inválido.';
            }
            if ($almacenId === null) {
                $errors['almacen_id'] = 'Selecciona un almacén.';
            }
            if ($nuevo === null) {
                $errors['stock_nuevo'] = 'Stock inválido: número positivo o cero, hasta 3 decimales.';
            }
            if (mb_strlen($motivo) < 5) {
                $errors['motivo'] = 'Indica el motivo del ajuste (mínimo 5 caracteres).';
            }
            if ($errors) {
                throw new ValidationException($errors);
            }

            $ajuste = Database::transaction(function () use ($productoId, $almacenId, $nuevo, $motivo, $user): array {
                $producto = $this->productos->bloquearActivos([$productoId])[$productoId] ?? null;
                if ($producto === null) {
                    throw new ValidationException(['producto_id' => 'El producto no existe o está archivado.']);
                }
                if ($this->almacenes->bloquearActivos([$almacenId]) === []) {
                    throw new ValidationException(['almacen_id' => 'El almacén no existe o está dado de baja.']);
                }

                $anterior = $this->almacenes->bloquearStock($productoId, $almacenId);
                $delta = Producto::aMilesimas($nuevo) - Producto::aMilesimas($anterior);
                if ($delta === 0) {
                    throw new ValidationException(['stock_nuevo' => 'El stock indicado es igual al actual: no hay nada que ajustar.']);
                }

                $deltaTexto = Producto::desdeMilesimas($delta);
                $kardexId = $this->moverStockManual($productoId, $almacenId, $deltaTexto, $motivo, (int) $user['id']);

                $detalle = [
                    'producto'       => $producto['nombre'],
                    'almacen_id'     => $almacenId,
                    'stock_anterior' => $anterior,
                    'stock_nuevo'    => $nuevo,
                    'delta'          => $deltaTexto,
                    'global_antes'   => (string) $producto['stock'],
                    'motivo'         => $motivo,
                    'kardex_id'      => $kardexId,
                ];
                audit_log('inventario', 'ajuste_stock', $detalle, 'productos', $productoId);
                return $detalle;
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors);
        } catch (PDOException $e) {
            if (Producto::violaStockNoNegativo($e)) {
                $this->fallar(['stock_nuevo' => 'El ajuste dejaría stock negativo.']);
            }
            throw $e;
        }

        flash('success', sprintf(
            'Stock de %s ajustado de %s a %s (%s).',
            $ajuste['producto'],
            num($ajuste['stock_anterior'], 3),
            num($ajuste['stock_nuevo'], 3),
            $ajuste['delta']
        ));
        redirect('inventario');
    }

    /** Solo administrador. Sin historial se borra; con historial se archiva. Exige stock 0 para no perder inventario. */
    public function destroy(): void
    {
        $user = current_user();
        if (!self::esAdmin($user)) {
            $this->denegar('eliminar');
        }

        $id = parse_int_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
        if ($id === null) {
            $this->fallar(['id' => 'Producto inválido.']);
        }

        try {
            [$accion, $producto] = Database::transaction(function () use ($id): array {
                $producto = $this->productos->bloquear($id);
                if ($producto === null || (int) $producto['activo'] !== 1) {
                    throw new ValidationException(['id' => 'El producto no existe o ya está archivado.']);
                }
                if (Producto::aMilesimas((string) $producto['stock']) !== 0) {
                    throw new ValidationException(['id' => sprintf(
                        '%s tiene %s en stock. Ajusta su stock a 0 antes de eliminarlo.',
                        $producto['nombre'],
                        num($producto['stock'], 3)
                    )]);
                }

                $accion = $this->productos->tieneHistorial($id) ? 'archivar' : 'eliminar';
                if ($accion === 'archivar') {
                    $this->productos->archivar($id);
                } else {
                    $this->productos->eliminar($id);
                }

                audit_log('inventario', $accion, [
                    'codigo'     => $producto['codigo'],
                    'nombre'     => $producto['nombre'],
                    'unidad'     => $producto['unidad'],
                    'precio_usd' => (string) $producto['precio_usd'],
                ], 'productos', $id);
                return [$accion, $producto];
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors);
        }

        flash('success', $accion === 'archivar'
            ? sprintf('%s tiene ventas o producción registradas: se archivó para conservar el historial.', $producto['nombre'])
            : sprintf('%s eliminado.', $producto['nombre']));
        redirect('inventario');
    }

    // ----- Almacenes -------------------------------------------------------

    public function almacenStore(): void
    {
        $nombre = $this->validarNombreAlmacen(post_string('nombre_nuevo', 120), 'nombre_nuevo');

        try {
            Database::transaction(function () use ($nombre): void {
                $id = $this->almacenes->create($nombre);
                audit_log('inventario', 'almacen_crear', ['nombre' => $nombre], 'almacenes', $id);
            });
        } catch (PDOException $e) {
            if (duplicate_key($e) === 'uq_almacenes_nombre') {
                $this->fallar(['nombre_nuevo' => sprintf('Ya existe un almacén llamado "%s".', $nombre)]);
            }
            throw $e;
        }

        flash('success', sprintf('Almacén "%s" creado.', $nombre));
        redirect('inventario');
    }

    public function almacenRename(): void
    {
        $nombres = $_POST['nombre'] ?? null;
        if (!is_array($nombres) || $nombres === [] || count($nombres) > 100) {
            $this->fallar(['nombre' => 'No se recibieron nombres de almacén.']);
        }

        $pedidos = [];
        foreach ($nombres as $id => $nombre) {
            $aid = parse_int_range($id, 1, PHP_INT_MAX);
            if ($aid === null || !is_string($nombre)) {
                $this->fallar(['nombre' => 'Datos de almacén inválidos.']);
            }
            $pedidos[$aid] = $this->validarNombreAlmacen(mb_substr(trim($nombre), 0, 120), "nombre.$aid");
        }
        if (count(array_unique(array_map('mb_strtolower', $pedidos))) !== count($pedidos)) {
            $this->fallar(['nombre' => 'Dos almacenes no pueden llamarse igual.']);
        }

        try {
            $cambios = Database::transaction(function () use ($pedidos): array {
                $cambios = [];
                foreach ($pedidos as $id => $nombre) {
                    $actual = $this->almacenes->find($id, true);
                    if ($actual === null || (int) $actual['activo'] !== 1) {
                        throw new ValidationException(['nombre' => sprintf('El almacén #%d no existe o está dado de baja.', $id)]);
                    }
                    if ($actual['nombre'] !== $nombre) {
                        $this->almacenes->rename($id, $nombre);
                        $cambios[$id] = ['antes' => $actual['nombre'], 'despues' => $nombre];
                    }
                }
                if ($cambios !== []) {
                    audit_log('inventario', 'almacen_renombrar', $cambios, 'almacenes');
                }
                return $cambios;
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors);
        } catch (PDOException $e) {
            if (duplicate_key($e) === 'uq_almacenes_nombre') {
                $this->fallar(['nombre' => 'Ya existe otro almacén con uno de esos nombres.']);
            }
            throw $e;
        }

        flash($cambios === [] ? 'info' : 'success', $cambios === [] ? 'Sin cambios en los nombres.' : 'Almacenes actualizados.');
        redirect('inventario');
    }

    /** Solo administrador. El stock pasa al primer almacén restante y el almacén queda inactivo (su historial se conserva). */
    public function almacenDestroy(): void
    {
        if (!self::esAdmin(current_user())) {
            $this->denegar('almacen_eliminar');
        }

        $id = parse_int_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
        if ($id === null) {
            $this->fallar(['id' => 'Almacén inválido.']);
        }

        try {
            [$origen, $destino, $movidos] = Database::transaction(function () use ($id): array {
                $origen = $this->almacenes->find($id, true);
                if ($origen === null || (int) $origen['activo'] !== 1) {
                    throw new ValidationException(['id' => 'Ese almacén ya no existe.']);
                }
                if ($this->almacenes->contarActivos() <= 1) {
                    throw new ValidationException(['id' => 'No puedes eliminar el último almacén.']);
                }
                $destino = $this->almacenes->primeroActivoDistintoDe($id);
                if ($destino === null) {
                    throw new ValidationException(['id' => 'No hay otro almacén que reciba el stock.']);
                }

                $movidos = $this->almacenes->moverTodo($id, (int) $destino['id']);
                $this->almacenes->darDeBaja($id);

                audit_log('inventario', 'almacen_eliminar', [
                    'nombre'          => $origen['nombre'],
                    'destino_id'      => (int) $destino['id'],
                    'destino_nombre'  => $destino['nombre'],
                    'stock_trasladado' => $movidos,
                ], 'almacenes', $id);
                return [$origen, $destino, $movidos];
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors);
        }

        flash('success', sprintf(
            'Almacén "%s" eliminado. %d productos con stock pasaron a "%s".',
            $origen['nombre'],
            count($movidos),
            $destino['nombre']
        ));
        redirect('inventario');
    }

    // ----- Internos --------------------------------------------------------

    /** Mueve stock (delta con signo) en almacén + global + kardex 'ajuste'. Debe correr dentro de una transacción. */
    private function moverStockManual(int $productoId, int $almacenId, string $delta, string $motivo, int $usuarioId): int
    {
        $this->almacenes->aplicarDelta($productoId, $almacenId, $delta);
        $this->productos->sumarStockGlobal($productoId, $delta);
        return $this->productos->registrarKardex([
            'producto_id' => $productoId,
            'almacen_id'  => $almacenId,
            'tipo'        => 'ajuste',
            'cantidad'    => $delta,
            'motivo'      => $motivo,
            'usuario_id'  => $usuarioId,
        ]);
    }

    /** @return array{codigo:?string, nombre:string, unidad:string, precio_usd:string, umbral_alerta:string, stock_inicial:?string} */
    private function validarProducto(): array
    {
        $errors = [];

        $nombre = preg_replace('/\s+/u', ' ', post_string('nombre', 200));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            $errors['nombre'] = 'El nombre debe tener entre 2 y 100 caracteres.';
        }

        $codigo = mb_strtoupper(post_string('codigo', 60));
        if ($codigo !== '' && !preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/', $codigo)) {
            $errors['codigo'] = 'Código inválido: hasta 30 caracteres (letras, números, guion).';
        }

        $unidad = post_string('unidad', 10);
        $uom = strtoupper(post_string('unidad_medida', 3));
        if ($uom !== '' && isset(Producto::UOM[$uom])) {
            $unidad = Producto::UOM_A_UNIDAD[$uom];
        } elseif (!in_array($unidad, Producto::UNIDADES, true)) {
            $errors['unidad'] = 'Unidad de medida inválida.';
            $uom = 'KG';
        } else {
            $uom = Producto::UNIDAD_A_UOM[$unidad] ?? 'KG';
        }

        $moneda = post_string('moneda', 3) ?: 'USD';
        if (!in_array($moneda, ['USD', 'VES'], true)) {
            $errors['moneda'] = 'Moneda inválida.';
        }

        $precio = parse_decimal($_POST['precio_usd'] ?? null, 2, 100000000, true);
        if ($precio === null) {
            $errors['precio_usd'] = 'Precio inválido: número positivo con hasta 2 decimales.';
        } elseif ($moneda === 'VES') {
            $tasa = TasaCambio::vigente(get_db())['tasa'] ?? null;
            if ($tasa === null) {
                $errors['moneda'] = 'No hay tasa vigente para convertir el precio en Bs a USD.';
            } else {
                $precio = number_format(round((float) $precio / $tasa, 2), 2, '.', '');
            }
        }

        $umbralRaw = $_POST['umbral_alerta'] ?? '';
        $umbral = $umbralRaw === '' ? '0' : parse_decimal($umbralRaw, 3, 1000000, true);
        if ($umbral === null) {
            $errors['umbral_alerta'] = 'Alerta mínima inválida (hasta 3 decimales).';
        }

        $stockRaw = $_POST['stock'] ?? '';
        $stock = null;
        if (is_string($stockRaw) && trim($stockRaw) !== '' && !preg_match('/^0+([.,]0*)?$/', trim($stockRaw))) {
            $stock = parse_decimal($stockRaw, 3, 1000000);
            if ($stock === null) {
                $errors['stock'] = 'Stock inicial inválido (hasta 3 decimales).';
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return [
            'codigo'        => $codigo === '' ? null : $codigo,
            'nombre'        => $nombre,
            'unidad'         => $unidad,
            'unidad_medida'  => $uom,
            'precio_usd'     => $precio,
            'umbral_alerta' => $umbral,
            'stock_inicial' => $stock,
        ];
    }

    private function validarNombreAlmacen(string $nombre, string $campo): string
    {
        $nombre = preg_replace('/\s+/u', ' ', trim($nombre));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60) {
            $this->fallar([$campo => 'El nombre del almacén debe tener entre 2 y 60 caracteres.']);
        }
        return $nombre;
    }

    private static function esAdmin(?array $user): bool
    {
        return ($user['rol'] ?? null) === 'admin';
    }

    private function denegar(string $accion): never
    {
        audit_log('seguridad', 'acceso_denegado', ['p' => (string) ($_GET['p'] ?? ''), 'accion' => $accion]);
        render_forbidden();
        exit;
    }

    /** Los formularios viven en modales sin errores por campo: se muestran como flash. */
    private function fallar(array $errors): never
    {
        foreach ($errors as $mensaje) {
            flash('error', $mensaje);
        }
        redirect('inventario');
    }
}
