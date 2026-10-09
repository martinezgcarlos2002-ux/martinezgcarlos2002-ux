<?php
declare(strict_types=1);

final class ProduccionController
{
    private const MAX_LINEAS = 500;

    private Produccion $produccion;
    private Producto $productos;
    private Almacen $almacenes;
    private ProduccionCatalogoService $catalogo;

    public function __construct()
    {
        $this->produccion = new Produccion(get_db());
        $this->productos = new Producto(get_db());
        $this->almacenes = new Almacen(get_db());
        $this->catalogo = new ProduccionCatalogoService(get_db());
    }

    public function index(): void
    {
        $hoy = date('Y-m-d');
        $fecha = parse_date($_GET['fecha'] ?? '') ?? $hoy;
        if ($fecha > $hoy) {
            $fecha = $hoy;
        }

        $filas = array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'nombre'        => $r['nombre'],
            'unidad'        => $r['unidad'],
            'unidad_medida' => Producto::uom((string) ($r['unidad_medida'] ?? 'KG')),
            'produccion_id' => $r['produccion_id'] === null ? null : (int) $r['produccion_id'],
            'precio'        => (string) ($r['precio_unit_usd'] ?? $r['precio_usd']),
            'cantidad'      => $r['cantidad'] === null ? '' : rtrim(rtrim((string) $r['cantidad'], '0'), '.'),
            'distribuido'   => (string) ($r['distribuido'] ?? '0'),
            'pendiente'     => (string) ($r['pendiente'] ?? '0'),
            'inventario'    => (string) ($r['inventario'] ?? $r['nombre']),
        ], $this->produccion->planillaDelDia($fecha));

        view('produccion_diaria', [
            'filas'           => $filas,
            'fecha'           => $fecha,
            'hoy'             => $hoy,
            'almacenes'       => $this->almacenes->activos(),
            'stockPorAlmacen' => $this->almacenes->stockPorProducto(),
            'puedeCrear'      => has_permiso(current_user(), 'produccion', 'crear'),
        ]);
    }

    /** Paso 1: cantidades elaboradas del día. No mueve stock: el stock entra al distribuir. */
    public function guardarDia(): void
    {
        $fecha = $this->fechaPost();
        $lineas = $this->validarPlanilla();
        $userId = current_user()['id'];

        try {
            $resumen = Database::transaction(function () use ($fecha, $lineas, $userId): array {
                $existentes = $this->produccion->bloquearDia($fecha);
                $productos = $this->productos->bloquearActivos(array_keys(array_filter($lineas, static fn ($c) => $c !== null)));
                $cambios = [];
                $errors = [];

                foreach ($lineas as $productoId => $cantidad) {
                    $actual = $existentes[$productoId] ?? null;
                    $distribuido = $actual === null ? 0 : Producto::aMilesimas((string) $actual['distribuido']);

                    if ($cantidad === null) {
                        if ($actual === null) {
                            continue;
                        }
                        if ($distribuido > 0) {
                            $errors["p$productoId"] = sprintf('No puedes borrar la producción del producto #%d: ya se distribuyó una parte.', $productoId);
                            continue;
                        }
                        $this->produccion->eliminarLineaSinDistribuir((int) $actual['id']);
                        $cambios[] = ['producto_id' => $productoId, 'antes' => (string) $actual['cantidad'], 'despues' => null];
                        continue;
                    }

                    if ($actual === null) {
                        $producto = $productos[$productoId] ?? null;
                        if ($producto === null) {
                            $errors["p$productoId"] = sprintf('El producto #%d no existe o está inactivo.', $productoId);
                            continue;
                        }
                        $id = $this->produccion->crearLinea($fecha, $productoId, $cantidad, (string) $producto['precio_usd'], $userId);
                        $cambios[] = ['producto_id' => $productoId, 'produccion_id' => $id, 'antes' => null, 'despues' => $cantidad];
                        continue;
                    }

                    if (Producto::aMilesimas($cantidad) === Producto::aMilesimas((string) $actual['cantidad'])) {
                        continue;
                    }
                    if (Producto::aMilesimas($cantidad) < $distribuido) {
                        $errors["p$productoId"] = sprintf(
                            'Producto #%d: la cantidad no puede ser menor a lo ya distribuido (%s).',
                            $productoId,
                            num($actual['distribuido'], 3)
                        );
                        continue;
                    }
                    $this->produccion->actualizarCantidad((int) $actual['id'], $cantidad);
                    $cambios[] = ['producto_id' => $productoId, 'produccion_id' => (int) $actual['id'], 'antes' => (string) $actual['cantidad'], 'despues' => $cantidad];
                }

                if ($errors) {
                    throw new ValidationException($errors);
                }
                if ($cambios !== []) {
                    audit_log('produccion', 'guardar_dia', ['fecha' => $fecha, 'cambios' => $cambios], 'produccion_diaria');
                }
                return $cambios;
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors, $fecha);
        } catch (PDOException $e) {
            if (Producto::violaCheck($e, 'ck_prod_diaria_dist')) {
                $this->fallar(['cantidad' => 'La cantidad no puede ser menor a lo ya distribuido.'], $fecha);
            }
            throw $e;
        }

        flash($resumen === [] ? 'info' : 'success', $resumen === []
            ? 'No hubo cambios en la producción del día.'
            : sprintf('Producción del %s guardada (%d cambios). Ahora distribúyela a los almacenes.', $fecha, count($resumen)));
        redirect('produccion', ['fecha' => $fecha]);
    }

    /**
     * Paso 2, todo o nada: por cada (producción, almacén) resta del pendiente, suma al stock global,
     * suma al stock del almacén y deja kardex. Si una sola operación falla, Database::transaction() hace rollBack().
     */
    public function distribuir(): void
    {
        $fecha = $this->fechaPost();
        $userId = current_user()['id'];

        try {
            $plan = $this->validarDistribucion();

            $detalle = Database::transaction(function () use ($fecha, $plan, $userId): array {
                $producciones = $this->produccion->bloquearParaDistribuir($fecha, array_keys($plan));
                $almacenIds = array_values(array_unique(array_merge(...array_map('array_keys', array_values($plan)))));
                $almacenes = $this->almacenes->bloquearActivos($almacenIds);

                $errors = [];
                foreach ($plan as $produccionId => $porAlmacen) {
                    $prod = $producciones[$produccionId] ?? null;
                    if ($prod === null) {
                        $errors["pd$produccionId"] = sprintf('La producción #%d no pertenece al %s.', $produccionId, $fecha);
                        continue;
                    }
                    foreach (array_keys($porAlmacen) as $almacenId) {
                        if (!isset($almacenes[$almacenId])) {
                            $errors["a$almacenId"] = sprintf('El almacén #%d no existe o está dado de baja.', $almacenId);
                        }
                    }
                    $solicitado = array_sum(array_map([Producto::class, 'aMilesimas'], $porAlmacen));
                    if ($solicitado > Producto::aMilesimas((string) $prod['pendiente'])) {
                        $errors["pd$produccionId"] = sprintf(
                            'Producción #%d: quieres distribuir %s pero solo quedan %s pendientes.',
                            $produccionId,
                            num(Producto::desdeMilesimas($solicitado), 3),
                            num($prod['pendiente'], 3)
                        );
                    }
                }
                if ($errors) {
                    throw new ValidationException($errors);
                }

                $productoIds = array_values(array_unique(array_map(
                    static fn (int $id): int => (int) $producciones[$id]['producto_id'],
                    array_keys($plan)
                )));
                $productos = $this->productos->bloquearActivos($productoIds);

                $detalle = [];
                foreach ($plan as $produccionId => $porAlmacen) {
                    $productoId = (int) $producciones[$produccionId]['producto_id'];
                    if (!isset($productos[$productoId])) {
                        throw new ValidationException(["p$productoId" => sprintf('El producto #%d está archivado: no puede recibir stock.', $productoId)]);
                    }
                    foreach ($porAlmacen as $almacenId => $cantidad) {
                        $distId = $this->produccion->registrarDistribucion($produccionId, $almacenId, $cantidad, $userId);
                        $this->productos->sumarStockGlobal($productoId, $cantidad);
                        $this->almacenes->aplicarDelta($productoId, $almacenId, $cantidad);
                        $this->productos->registrarKardex([
                            'producto_id'          => $productoId,
                            'almacen_id'           => $almacenId,
                            'tipo'                 => 'produccion',
                            'cantidad'             => $cantidad,
                            'produccion_diaria_id' => $produccionId,
                            'usuario_id'           => $userId,
                        ]);
                        $detalle[] = [
                            'distribucion_id' => $distId,
                            'produccion_id'   => $produccionId,
                            'producto_id'     => $productoId,
                            'almacen_id'      => $almacenId,
                            'cantidad'        => $cantidad,
                        ];
                    }
                }

                audit_log('produccion', 'distribuir', ['fecha' => $fecha, 'movimientos' => $detalle], 'produccion_diaria');
                return $detalle;
            });
        } catch (ValidationException $e) {
            $this->auditarFallo('distribuir_fallida', $fecha, $e->errors);
            $this->fallar($e->errors, $fecha);
        } catch (PDOException $e) {
            if (Producto::violaCheck($e, 'ck_prod_diaria_dist', 'ck_productos_stock', 'ck_inv_alm_stock')) {
                $errors = ['dist' => 'Otro usuario distribuyó esta producción al mismo tiempo. Revisa los pendientes.'];
                $this->auditarFallo('distribuir_fallida', $fecha, $errors);
                $this->fallar($errors, $fecha);
            }
            $this->auditarFallo('distribuir_fallida', $fecha, ['sqlstate' => (string) ($e->errorInfo[0] ?? $e->getCode())]);
            throw $e;
        } catch (Throwable $e) {
            $this->auditarFallo('distribuir_fallida', $fecha, ['error' => $e::class]);
            throw $e;
        }

        $total = array_sum(array_map(static fn (array $d): int => Producto::aMilesimas($d['cantidad']), $detalle));
        flash('success', sprintf(
            'Distribución registrada: %s unidades en %d movimientos. Stock global y por almacén actualizados.',
            num(Producto::desdeMilesimas($total), 3),
            count($detalle)
        ));
        redirect('produccion', ['fecha' => $fecha]);
    }

    public function resumen(): void
    {
        $mes = $_GET['mes'] ?? date('Y-m');
        view('produccion_resumen', ['mes' => is_string($mes) ? $mes : date('Y-m')]);
    }

    public function catalogo(): void
    {
        $user = current_user();
        view('produccion_catalogo', [
            'items'       => array_map([self::class, 'jsonCatalogo'], $this->catalogo->listar()),
            'puedeCrear'  => has_permiso($user, 'produccion', 'crear'),
            'puedeEditar' => has_permiso($user, 'produccion', 'editar') || has_permiso($user, 'produccion', 'crear'),
            'endpoints'   => [
                'guardar'  => url('produccion_catalogo_guardar'),
                'eliminar' => url('produccion_catalogo_eliminar'),
            ],
        ]);
    }

    /** Alta/edición JSON (POST). Sin id crea; con id actualiza. */
    public function catalogoGuardar(): void
    {
        $this->responderJson(function (): array {
            $data = $this->validarCatalogo();
            $id = parse_int_range($_POST['id'] ?? '', 1, PHP_INT_MAX);
            $userId = (int) current_user()['id'];

            try {
                if ($id === null) {
                    if (!has_permiso(current_user(), 'produccion', 'crear')) {
                        throw new ValidationException(['general' => 'No tienes permiso para crear productos.']);
                    }
                    $fila = $this->catalogo->crear($data, $userId);
                    audit_log('produccion', 'catalogo_crear', $data, 'prod_catalogo', (int) $fila['id']);
                    return ['mensaje' => 'Producto creado y sincronizado con inventario.', 'item' => self::jsonCatalogo($fila)];
                }

                if (!has_permiso(current_user(), 'produccion', 'editar') && !has_permiso(current_user(), 'produccion', 'crear')) {
                    throw new ValidationException(['general' => 'No tienes permiso para editar el catálogo.']);
                }
                $fila = $this->catalogo->actualizar($id, $data);
                audit_log('produccion', 'catalogo_editar', $data, 'prod_catalogo', $id);
                return ['mensaje' => 'Producto actualizado.', 'item' => self::jsonCatalogo($fila)];
            } catch (PDOException $e) {
                $clave = duplicate_key($e);
                throw match ($clave) {
                    'uq_prod_cat_nombre', 'uq_productos_nombre' => new ValidationException(['nombre' => 'Ya existe un producto con ese nombre.']),
                    'uq_productos_codigo' => new ValidationException(['nombre' => 'No se pudo generar un código único en inventario.']),
                    'uq_prod_cat_producto' => new ValidationException(['nombre' => 'Ese producto de inventario ya está en el catálogo.']),
                    default => $e,
                };
            }
        });
    }

    public function catalogoEliminar(): void
    {
        $this->responderJson(function (): array {
            $id = parse_int_range($_POST['id'] ?? '', 1, PHP_INT_MAX)
                ?? throw new ValidationException(['id' => 'Producto inválido.']);
            $this->catalogo->eliminar($id);
            audit_log('produccion', 'catalogo_inactivar', ['id' => $id], 'prod_catalogo', $id);
            return [
                'mensaje' => 'Producto inactivado en el catálogo de producción. El registro de inventario se conserva.',
                'accion'  => 'archivado',
                'id'      => $id,
            ];
        });
    }

    /** HTML imprimible (CSP-safe, sin style=""). */
    public function diariaPdf(): void
    {
        $hoy = date('Y-m-d');
        $fecha = parse_date($_GET['fecha'] ?? '') ?? $hoy;
        if ($fecha > $hoy) {
            $fecha = $hoy;
        }
        $semana = ProduccionCatalogoService::semanaDe($fecha);
        header('Content-Disposition: inline; filename="produccion_diaria_' . $fecha . '.html"');
        view('produccion_diaria_pdf', [
            'fecha'   => $fecha,
            'semana'  => $semana,
            'empresa' => APP_EMPRESA,
            'filas'   => $this->catalogo->reporteDiario($fecha),
        ]);
    }

    public function store(): void
    {
        try {
            $errors = [];
            $fecha = parse_date($_POST['fecha'] ?? '');
            $productoId = parse_int_range($_POST['producto_id'] ?? null, 1, PHP_INT_MAX);
            $litros = parse_decimal($_POST['litros_leche'] ?? null, 3, 100000);
            $factor = parse_decimal($_POST['factor_l_kg'] ?? null, 4, 50);
            $kgReales = ($_POST['kg_reales'] ?? '') === '' ? null : parse_decimal($_POST['kg_reales'], 3, 100000, true);
            $cuajadaRaw = $_POST['cuajada_kg'] ?? '';
            $observaciones = post_string('observaciones', 255);

            if ($fecha === null) {
                $errors['fecha'] = 'Fecha inválida.';
            } elseif ($fecha > date('Y-m-d')) {
                $errors['fecha'] = 'No se puede registrar producción en fechas futuras.';
            }
            $formula = $productoId === null ? null : $this->produccion->formula($productoId);
            if ($formula === null) {
                $errors['producto_id'] = 'Selecciona un tipo de queso válido.';
            }
            if ($litros === null) {
                $errors['litros_leche'] = 'Litros inválidos: mayor que 0, hasta 3 decimales.';
            }
            if ($factor === null) {
                $errors['factor_l_kg'] = 'Factor inválido: litros por kg, mayor que 0 y hasta 50.';
            }
            if (($_POST['kg_reales'] ?? '') !== '' && $kgReales === null) {
                $errors['kg_reales'] = 'Kg reales inválidos (hasta 3 decimales).';
            }

            $esCuajada = ($formula['base'] ?? '') === 'cuajada';
            $cuajada = null;
            if ($esCuajada) {
                $cuajada = parse_decimal($cuajadaRaw, 3, 100000);
                if ($cuajada === null) {
                    $errors['cuajada_kg'] = 'Indica los kg de cuajada utilizados (mayor que 0).';
                }
            }
            if ($errors) {
                throw new ValidationException($errors);
            }

            $data = [
                'fecha'                 => $fecha,
                'producto_id'           => $productoId,
                'litros_leche'          => $litros,
                'factor_l_kg'           => $factor,
                'cuajada_kg'            => $cuajada,
                'rend_cuajada_ref_kg'   => $esCuajada ? $formula['cuajada_ref_kg'] : null,
                'rend_masa_min_kg'      => $esCuajada ? $formula['masa_min_kg'] : null,
                'rend_masa_max_kg'      => $esCuajada ? $formula['masa_max_kg'] : null,
                'kg_reales'             => $kgReales,
                'precio_litro_prom_usd' => $this->produccion->precioPromedioLitro($fecha),
                'observaciones'         => $observaciones === '' ? null : $observaciones,
                'registrado_por'        => current_user()['id'],
            ];

            $registro = Database::transaction(function () use ($data): array {
                $id = $this->produccion->create($data);
                $registro = $this->produccion->find($id);
                audit_log('produccion', 'registrar', $data + [
                    'kg_esperados' => $registro['kg_esperados'],
                    'masa_min_kg'  => $registro['masa_min_kg'],
                    'masa_max_kg'  => $registro['masa_max_kg'],
                ], 'producciones', $id);
                return $registro;
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'produccion');
        }

        flash('success', sprintf('Producción registrada: %s kg esperados de %s.', num($registro['kg_esperados'], 3), $formula['nombre']));

        if ($esCuajada && $registro['kg_reales'] !== null
            && ((float) $registro['kg_reales'] < (float) $registro['masa_min_kg'] || (float) $registro['kg_reales'] > (float) $registro['masa_max_kg'])
        ) {
            flash('error', sprintf(
                'Atención: %s kg de masa reales están fuera del rango técnico (%s – %s kg) para %s kg de cuajada.',
                num($registro['kg_reales'], 3),
                num($registro['masa_min_kg'], 3),
                num($registro['masa_max_kg'], 3),
                num($registro['cuajada_kg'], 3)
            ));
        }
        if ($registro['precio_litro_prom_usd'] === null) {
            flash('info', 'No hubo entregas de leche en esa fecha: el costo de la producción queda sin calcular.');
        }

        redirect('produccion');
    }

    // ----- Internos --------------------------------------------------------

    private function fechaPost(): string
    {
        $fecha = parse_date($_POST['fecha'] ?? '');
        if ($fecha === null || $fecha > date('Y-m-d')) {
            $this->fallar(['fecha' => 'Fecha inválida: no se registra producción en fechas futuras.'], date('Y-m-d'));
        }
        return $fecha;
    }

    /** @return array<int, ?string>  producto_id => cantidad (null = borrar la línea del día) */
    private function validarPlanilla(): array
    {
        $items = $_POST['items'] ?? null;
        $fecha = parse_date($_POST['fecha'] ?? '') ?? date('Y-m-d');
        if (!is_array($items) || $items === [] || count($items) > self::MAX_LINEAS) {
            $this->fallar(['items' => 'No se recibió la planilla de producción.'], $fecha);
        }

        $lineas = [];
        $errors = [];
        foreach ($items as $productoId => $item) {
            $pid = parse_int_range($productoId, 1, PHP_INT_MAX);
            $raw = is_array($item) ? ($item['cantidad'] ?? '') : null;
            if ($pid === null || !is_string($raw)) {
                $errors['items'] = 'Planilla con datos inválidos.';
                continue;
            }
            $raw = trim($raw);
            if ($raw === '' || preg_match('/^0+([.,]0*)?$/', $raw)) {
                $lineas[$pid] = null;
                continue;
            }
            $cantidad = parse_decimal($raw, 3, 100000);
            if ($cantidad === null) {
                $errors["p$pid"] = sprintf('Producto #%d: cantidad inválida (mayor que 0, hasta 3 decimales).', $pid);
                continue;
            }
            $lineas[$pid] = $cantidad;
        }
        if ($errors) {
            $this->fallar($errors, $fecha);
        }
        return $lineas;
    }

    /** @return array<int, array<int, string>>  produccion_id => [almacen_id => cantidad] */
    private function validarDistribucion(): array
    {
        $dist = $_POST['dist'] ?? null;
        if (!is_array($dist) || count($dist) > self::MAX_LINEAS) {
            throw new ValidationException(['dist' => 'No se recibió la distribución.']);
        }

        $plan = [];
        $errors = [];
        foreach ($dist as $produccionId => $porAlmacen) {
            $pdId = parse_int_range($produccionId, 1, PHP_INT_MAX);
            if ($pdId === null || !is_array($porAlmacen)) {
                $errors['dist'] = 'Distribución con datos inválidos.';
                continue;
            }
            foreach ($porAlmacen as $almacenId => $raw) {
                $aId = parse_int_range($almacenId, 1, PHP_INT_MAX);
                if ($aId === null || !is_string($raw)) {
                    $errors['dist'] = 'Distribución con datos inválidos.';
                    continue;
                }
                $raw = trim($raw);
                if ($raw === '' || preg_match('/^0+([.,]0*)?$/', $raw)) {
                    continue;
                }
                $cantidad = parse_decimal($raw, 3, 100000);
                if ($cantidad === null) {
                    $errors["pd$pdId.$aId"] = sprintf('Producción #%d: cantidad inválida para el almacén #%d.', $pdId, $aId);
                    continue;
                }
                $plan[$pdId][$aId] = $cantidad;
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        if ($plan === []) {
            throw new ValidationException(['dist' => 'Indica al menos una cantidad mayor que cero para distribuir.']);
        }
        return $plan;
    }

    /** Se llama tras el rollBack(), así el intento fallido queda en la auditoría aunque la operación no. */
    private function auditarFallo(string $accion, string $fecha, array $motivo): void
    {
        try {
            audit_log('produccion', $accion, ['fecha' => $fecha, 'motivo' => $motivo], 'produccion_diaria');
        } catch (Throwable $e) {
            error_log('[ADARA][produccion] No se pudo auditar el fallo: ' . $e->getMessage());
        }
    }

    private function fallar(array $errors, string $fecha): never
    {
        foreach ($errors as $mensaje) {
            flash('error', $mensaje);
        }
        redirect('produccion', ['fecha' => $fecha]);
    }

    /**
     * @return array{nombre:string, descripcion:?string, unidad_medida:string, orden:int, precio_produccion:string, precio_detal:string}
     */
    private function validarCatalogo(): array
    {
        $errors = [];
        $nombre = preg_replace('/\s+/u', ' ', post_string('nombre', 200));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            $errors['nombre'] = 'El nombre debe tener entre 2 y 100 caracteres.';
        }

        $descripcion = preg_replace('/\s+/u', ' ', post_string('descripcion', 2000));
        $descripcion = $descripcion === '' ? null : $descripcion;

        $uom = Producto::uom(post_string('unidad_medida', 3) !== '' ? post_string('unidad_medida', 3) : 'KG');
        if (!isset(Producto::UOM[$uom])) {
            $errors['unidad_medida'] = 'Unidad de medida inválida (KG, LTS o UND).';
        }

        $orden = parse_int_range($_POST['orden'] ?? '0', 0, 9999);
        if ($orden === null) {
            $errors['orden'] = 'El orden debe ser un entero entre 0 y 9999.';
        }

        $precioProd = parse_monto(post_string('precio_produccion', 20) !== '' ? post_string('precio_produccion', 20) : '0', 2, 100000000, true);
        if ($precioProd === null) {
            $errors['precio_produccion'] = 'Precio de producción inválido (hasta 2 decimales).';
        }

        $precioDet = parse_monto(post_string('precio_detal', 20) !== '' ? post_string('precio_detal', 20) : '0', 2, 100000000, true);
        if ($precioDet === null) {
            $errors['precio_detal'] = 'Precio al detal inválido (hasta 2 decimales).';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return [
            'nombre'             => $nombre,
            'descripcion'        => $descripcion,
            'unidad_medida'      => $uom,
            'orden'              => $orden,
            'precio_produccion'  => $precioProd,
            'precio_detal'       => $precioDet,
        ];
    }

    private function responderJson(callable $accion, int $status = 200): never
    {
        try {
            json_response($status, ['ok' => true] + $accion());
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            error_log('[ADARA][Produccion] ' . $e);
            json_response(500, ['ok' => false, 'errores' => ['general' => 'Error interno. No se aplicaron cambios.']]);
        } catch (Throwable $e) {
            error_log('[ADARA][Produccion] ' . $e);
            json_response(500, ['ok' => false, 'errores' => ['general' => 'Error interno. No se aplicaron cambios.']]);
        }
    }

    /** @param array<string, mixed> $r */
    private static function jsonCatalogo(array $r): array
    {
        return [
            'id'                => (int) $r['id'],
            'producto_id'       => $r['producto_id'] !== null ? (int) $r['producto_id'] : null,
            'nombre'            => (string) $r['nombre'],
            'descripcion'       => (string) ($r['descripcion'] ?? ''),
            'unidad_medida'     => Producto::uom((string) ($r['unidad_medida'] ?? 'KG')),
            'orden'             => (int) ($r['orden'] ?? 0),
            'precio_produccion' => (float) ($r['precio_produccion'] ?? 0),
            'precio_detal'      => (float) ($r['precio_detal'] ?? 0),
            'visible'           => (int) ($r['visible_tv'] ?? 1) === 1,
            'inventario'        => (string) ($r['inventario'] ?? $r['nombre'] ?? ''),
            'activo'            => (int) ($r['activo'] ?? 1) === 1,
        ];
    }
}
