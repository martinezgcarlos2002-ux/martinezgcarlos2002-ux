<?php
declare(strict_types=1);

final class VentasController
{
    private const MAX_LINEAS = 100;

    private Venta $ventas;
    private Producto $productos;
    private Almacen $almacenes;

    public function __construct()
    {
        $this->ventas = new Venta(get_db());
        $this->productos = new Producto(get_db());
        $this->almacenes = new Almacen(get_db());
    }

    public function create(): void
    {
        view('ventas_nueva', $this->varsFormulario());
    }

    public function historial(): void
    {
        view('ventas_historial', [
            'filas'   => $this->ventas->historial(),
            'tipos'   => Venta::TIPOS_CLIENTE,
            'metodos' => Venta::METODOS_PAGO,
        ]);
    }

    public function buscarEntidades(): void
    {
        $tipo = (string) ($_GET['tipo'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));
        if (!isset(Venta::ENTIDADES[$tipo])) {
            json_response(422, ['ok' => false, 'errores' => ['tipo' => 'Tipo de entidad inválido.']]);
        }
        if (mb_strlen($q) < 1) {
            json_response(200, ['ok' => true, 'items' => []]);
        }
        json_response(200, ['ok' => true, 'items' => $this->ventas->buscarEntidades($tipo, $q)]);
    }

    public function clienteStore(): void
    {
        try {
            $nombre = trim(post_string('nombre', 120));
            if (mb_strlen($nombre) < 2) {
                throw new ValidationException(['nombre' => 'Indica el nombre del cliente (mínimo 2 caracteres).']);
            }
            $docRaw = trim(post_documento_compuesto('documento', 20));
            $documento = null;
            if ($docRaw !== '') {
                $documento = normalize_documento($docRaw);
                if ($documento === null) {
                    throw new ValidationException(['documento' => 'Documento inválido. Ej.: V-12345678.']);
                }
            }
            $telefono = post_telefono_compuesto('telefono', 30);
            if ($telefono !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $telefono)) {
                throw new ValidationException(['telefono' => 'Teléfono inválido (7 a 20 dígitos).']);
            }
            $direccion = preg_replace('/\s+/u', ' ', post_string('direccion', 200));
            if (mb_strlen($direccion) > 160) {
                throw new ValidationException(['direccion' => 'La dirección admite hasta 160 caracteres.']);
            }
            $id = $this->ventas->crearClientePos($nombre, $documento, 'frecuente', $telefono !== '' ? $telefono : null, $direccion !== '' ? $direccion : null);
            audit_log('ventas', 'crear_cliente_pos', ['nombre' => $nombre, 'documento' => $documento, 'telefono' => $telefono, 'direccion' => $direccion], 'ventas_clientes', $id);
            $fila = $this->ventas->clientePos($id);
            json_response(201, ['ok' => true, 'cliente' => $fila, 'frecuentes' => $this->ventas->clientesFrecuentes()]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        }
    }

    public function ascenderExclusivo(): void
    {
        try {
            $id = parse_int_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
            if ($id === null) {
                throw new ValidationException(['id' => 'Selecciona un cliente frecuente.']);
            }
            $out = Database::transaction(function () use ($id): array {
                $cli = $this->ventas->clientePos($id);
                if ($cli === null) {
                    throw new ValidationException(['id' => 'El cliente no existe.']);
                }
                $cxcId = (int) ($cli['cxc_cliente_id'] ?? 0);
                if ($cxcId < 1) {
                    $cxc = new CxCService(get_db(), new CorrelativoService(get_db()));
                    $cxcId = $cxc->guardarCliente([
                        'nombre'    => $cli['nombre'],
                        'documento' => $cli['documento'] !== null && $cli['documento'] !== '' ? $cli['documento'] : null,
                        'telefono'  => $cli['telefono'] !== null && $cli['telefono'] !== '' ? $cli['telefono'] : null,
                        'domicilio' => $cli['direccion'] !== null && $cli['direccion'] !== '' ? $cli['direccion'] : null,
                    ], null, current_user()['id']);
                }
                $this->ventas->marcarExclusivo($id, $cxcId);
                audit_log('ventas', 'ascender_exclusivo', [
                    'ventas_cliente_id' => $id,
                    'cxc_cliente_id'    => $cxcId,
                ], 'ventas_clientes', $id);
                return ['cxc_cliente_id' => $cxcId, 'cliente' => $this->ventas->clientePos($id)];
            });
            json_response(200, ['ok' => true] + $out + ['frecuentes' => $this->ventas->clientesFrecuentes()]);
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        }
    }

    public function ticket(): void
    {
        try {
            $id = parse_int_range($_GET['id'] ?? null, 1, PHP_INT_MAX);
            if ($id === null) {
                throw new ValidationException(['venta' => 'Factura inválida.']);
            }
            $doc = $this->ventas->ticket($id);
            header('Content-Disposition: inline; filename="' . $doc['numero'] . '.html"');
            view('ventas_ticket', [
                'venta'   => $doc,
                'empresa' => APP_EMPRESA,
                'rif'     => APP_RIF,
                'logo'    => $this->logoDataUri(),
                'metodos' => Venta::METODOS_PAGO,
            ]);
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('ventas_historial');
        }
    }

    public function store(): void
    {
        $userId = current_user()['id'];

        try {
            $pedido = $this->validarPedido();
            $venta = Database::transaction(fn (): array => $this->procesar($pedido, $userId));
        } catch (ValidationException $e) {
            $this->auditarFallo($e->errors);
            $this->fallar($e->errors);
        } catch (PDOException $e) {
            if (Producto::violaStockNoNegativo($e)) {
                $errors = ['items' => 'Stock insuficiente: otro usuario vendió estas existencias. Revisa las cantidades.'];
                $this->auditarFallo($errors + ['constraint' => 'ck_productos_stock']);
                $this->fallar($errors);
            }
            $this->auditarFallo(['sqlstate' => (string) ($e->errorInfo[0] ?? $e->getCode())]);
            throw $e;
        } catch (Throwable $e) {
            $this->auditarFallo(['error' => $e::class]);
            throw $e;
        }

        $cajaTxt = match (true) {
            $venta['metodo_pago'] === 'mercancia_descuento' && ($venta['entidad_tipo'] ?? '') === 'empleado'
                => 'Descuento cargado a la nómina del empleado.',
            in_array($venta['metodo_pago'], Venta::METODOS_SIN_CAJA, true)
                => 'Sin asiento en Finanzas (crédito, cortesía o descuento).',
            default => 'Ingreso asentado en Finanzas.',
        };
        flash('success', sprintf(
            'Venta %s registrada: $%s (Bs. %s). %s',
            $venta['numero'],
            num($venta['total_usd']),
            num($venta['total_bs']),
            $cajaTxt
        ));
        redirect('ventas_ticket', ['id' => (int) $venta['id']]);
    }

    /** @return array<string, mixed> */
    private function varsFormulario(): array
    {
        return [
            'productos'   => $this->productos->disponiblesParaVenta(),
            'frecuentes'  => $this->ventas->clientesFrecuentes(),
            'tasa'        => TasaCambio::vigente(get_db())['tasa'] ?? null,
            'tipos'       => Venta::TIPOS_CLIENTE,
            'entidades'   => Venta::ENTIDADES,
            'metodos'     => Venta::METODOS_PAGO,
            'semanas'     => self::semanasDescuento(),
            'buscarUrl'   => url('ventas_buscar_entidades'),
            'nuevoUrl'    => url('ventas_cliente_nuevo'),
            'ascenderUrl' => url('ventas_ascender_exclusivo'),
        ];
    }

    private function logoDataUri(): string
    {
        $path = ADARA_ROOT . '/assets/' . LOGO_EMPRESA;
        if (!is_file($path)) {
            return '';
        }
        return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($path));
    }

    /**
     * @return array{
     *   cliente_id:int, ventas_cliente_id:?int, cliente_nombre:string, tipo_cliente:string,
     *   entidad_tipo:?string, entidad_id:?int, fecha:string, moneda_pago:string, metodo_pago:string,
     *   referencia:?string, descripcion_pago:?string, semana_aplicacion:?string,
     *   lineas:array<int, array{cantidad:string, precio:string}>
     * }
     */
    private function validarPedido(): array
    {
        $errors = [];

        $tipoCliente = (string) ($_POST['tipo_cliente'] ?? '');
        if (!isset(Venta::TIPOS_CLIENTE[$tipoCliente])) {
            $errors['tipo_cliente'] = 'Selecciona el tipo de cliente.';
        }

        $entidadTipo = null;
        $entidadId = null;
        $ventasClienteId = null;
        $clienteNombre = trim(post_string('cliente_nombre', 120));

        if ($tipoCliente === 'generico') {
            if (mb_strlen($clienteNombre) < 2) {
                $errors['cliente_nombre'] = 'Escribe el nombre del cliente genérico.';
            }
        } elseif ($tipoCliente === 'frecuente') {
            $ventasClienteId = parse_int_range($_POST['ventas_cliente_id'] ?? null, 1, PHP_INT_MAX);
            if ($ventasClienteId === null) {
                $errors['ventas_cliente_id'] = 'Selecciona un cliente frecuente.';
            }
        } elseif ($tipoCliente === 'exclusivo') {
            $entidadTipo = (string) ($_POST['entidad_tipo'] ?? '');
            $entidadId = parse_int_range($_POST['entidad_id'] ?? null, 1, PHP_INT_MAX);
            if (!isset(Venta::ENTIDADES[$entidadTipo]) || $entidadId === null) {
                $errors['entidad_id'] = 'Busca y selecciona productor, empleado, transportista o cliente CxC.';
            }
        }

        $hoy = date('Y-m-d');
        $fecha = parse_date($_POST['fecha'] ?? '');
        if ($fecha === null) {
            $errors['fecha'] = 'Fecha inválida.';
        } elseif ($fecha > $hoy) {
            $errors['fecha'] = 'No se pueden facturar ventas en fechas futuras.';
        }

        $moneda = $_POST['moneda_pago'] ?? '';
        if (!is_string($moneda) || !in_array($moneda, Venta::MONEDAS, true)) {
            $errors['moneda_pago'] = 'Moneda de cobro inválida.';
        }

        $metodo = (string) ($_POST['metodo_pago'] ?? '');
        if (!isset(Venta::METODOS_PAGO[$metodo])) {
            $errors['metodo_pago'] = 'Método de pago inválido.';
        }

        $referencia = trim(post_string('referencia', 40));
        $descripcion = trim(post_string('descripcion_pago', 255));
        if (in_array($metodo, ['pago_movil', 'transferencia'], true)) {
            if ($referencia === '') {
                $errors['referencia'] = 'La referencia es obligatoria para Pago Móvil y Transferencia.';
            }
            if ($descripcion === '') {
                $errors['descripcion_pago'] = 'La descripción es obligatoria para Pago Móvil y Transferencia.';
            }
        }

        $semana = $this->parseSemana($_POST['semana_aplicacion'] ?? null);
        $deudaInterna = in_array($metodo, Venta::METODOS_DEUDA, true)
            && in_array($entidadTipo, ['productor', 'empleado', 'transportista'], true);
        if ($metodo === 'mercancia_descuento' && $deudaInterna) {
            $permitidas = array_column(self::semanasDescuento(), 'value');
            if ($semana === null || !in_array($semana, $permitidas, true)) {
                $errors['semana_aplicacion'] = 'Selecciona una semana de trabajo ya iniciada. No se permiten semanas futuras.';
            }
        } else {
            $semana = null;
        }
        if (in_array($metodo, Venta::METODOS_DEUDA, true) && $tipoCliente !== 'exclusivo') {
            $errors['metodo_pago'] = 'Crédito y mercancía a descuento requieren un cliente exclusivo.';
        }

        $items = $_POST['items'] ?? null;
        $lineas = [];
        if (!is_array($items) || $items === []) {
            $errors['items'] = 'Agrega al menos un producto.';
        } elseif (count($items) > self::MAX_LINEAS) {
            $errors['items'] = sprintf('Máximo %d líneas por factura.', self::MAX_LINEAS);
        } else {
            $n = 0;
            foreach ($items as $item) {
                $n++;
                $productoId = is_array($item) ? parse_int_range($item['producto_id'] ?? null, 1, PHP_INT_MAX) : null;
                $cantidad = is_array($item) ? parse_decimal($item['cantidad'] ?? null, 3, 100000) : null;
                $precio = is_array($item) ? parse_decimal($item['precio_unit_usd'] ?? null, 2, 1000000, true) : null;
                $empaques = is_array($item) ? (parse_int_range($item['empaques'] ?? '0', 0, 100000) ?? 0) : 0;
                $bruto = is_array($item) ? parse_decimal($item['peso_bruto'] ?? null, 3, 100000) : null;
                $taraRaw = is_array($item) ? ($item['tara_unitaria'] ?? '') : '';

                if ($productoId === null) {
                    $errors["items.$n"] = "Línea $n: selecciona un producto.";
                    continue;
                }
                if ($cantidad === null && $bruto === null) {
                    $errors["items.$n"] = "Línea $n: indica la cantidad o el peso bruto de balanza.";
                    continue;
                }
                if ($precio === null) {
                    $errors["items.$n"] = "Línea $n: precio inválido (hasta 2 decimales).";
                    continue;
                }

                if (!isset($lineas[$productoId])) {
                    $lineas[$productoId] = [
                        'milesimas'      => 0,
                        'precio'         => $precio,
                        'empaques'       => 0,
                        'bruto_milesimas'=> 0,
                        'tara_unitaria'  => is_string($taraRaw) ? $taraRaw : '',
                    ];
                } elseif (number_format((float) $lineas[$productoId]['precio'], 2, '.', '') !== number_format((float) $precio, 2, '.', '')) {
                    $errors["items.$n"] = "Línea $n: el producto ya está en la factura con otro precio.";
                    continue;
                }
                $lineas[$productoId]['milesimas'] += $cantidad !== null ? Producto::aMilesimas($cantidad) : 0;
                $lineas[$productoId]['empaques'] += $empaques;
                $lineas[$productoId]['bruto_milesimas'] += $bruto !== null ? Producto::aMilesimas($bruto) : 0;
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return [
            'cliente_id'        => 0,
            'ventas_cliente_id' => $ventasClienteId,
            'cliente_nombre'    => $clienteNombre,
            'tipo_cliente'      => $tipoCliente,
            'entidad_tipo'      => $entidadTipo,
            'entidad_id'        => $entidadId,
            'fecha'             => $fecha,
            'moneda_pago'       => $moneda,
            'metodo_pago'       => $metodo,
            'referencia'        => $referencia !== '' ? $referencia : null,
            'descripcion_pago'  => $descripcion !== '' ? $descripcion : null,
            'semana_aplicacion' => $semana,
            'lineas'            => array_map(static fn (array $l): array => [
                'cantidad'       => Producto::desdeMilesimas($l['milesimas']),
                'precio'         => $l['precio'],
                'empaques'       => (int) ($l['empaques'] ?? 0),
                'bruto_milesimas'=> (int) ($l['bruto_milesimas'] ?? 0),
                'tara_unitaria'  => (string) ($l['tara_unitaria'] ?? ''),
            ], $lineas),
        ];
    }

    private function parseSemana(mixed $raw): ?string
    {
        $fecha = parse_date(is_string($raw) ? $raw : '');
        if ($fecha === null) {
            return null;
        }
        $dia = new DateTimeImmutable($fecha);
        $lunes = $dia->modify('-' . ((int) $dia->format('N') - 1) . ' days')->format('Y-m-d');
        return $lunes > date('Y-m-d') ? null : $lunes;
    }

    /**
     * Últimas semanas lunes–domingo ya iniciadas. El lunes de la semana en curso es el tope:
     * ninguna opción tiene fecha de inicio posterior a hoy.
     *
     * @return list<array{value:string, label:string}>
     */
    private static function semanasDescuento(int $cantidad = 5): array
    {
        $cantidad = max(4, min(5, $cantidad));
        $hoy = new DateTimeImmutable('today');
        $lunes = $hoy->modify('-' . ((int) $hoy->format('N') - 1) . ' days');
        $out = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $ini = $lunes->modify("-{$i} weeks");
            if ($ini->format('Y-m-d') > $hoy->format('Y-m-d')) {
                continue;
            }
            $fin = $ini->modify('+6 days');
            $out[] = [
                'value' => $ini->format('Y-m-d'),
                'label' => 'Semana del Lunes ' . $ini->format('d/m/Y') . ' al Domingo ' . $fin->format('d/m/Y'),
            ];
        }
        return $out;
    }

    /** Corre dentro de Database::transaction(): venta + stock + Finanzas + deuda se revierten juntas. */
    private function procesar(array $pedido, int $userId): array
    {
        $momento = $pedido['fecha'] === date('Y-m-d') ? date('Y-m-d H:i:s') : $pedido['fecha'] . ' 23:59:59';

        $tasa = $this->ventas->tasaVigenteEn($momento);
        if ($tasa === null) {
            throw new ValidationException(['tasa' => 'No hay tasa de cambio vigente para esa fecha. Registra la tasa BCV antes de facturar.']);
        }
        [$pos, $nombre] = $this->resolverCliente($pedido);
        $this->asegurarEntidad($pedido);

        $bloqueados = $this->productos->bloquearActivos(array_keys($pedido['lineas']));
        $errors = [];
        foreach ($pedido['lineas'] as $productoId => $linea) {
            $producto = $bloqueados[$productoId] ?? null;
            if ($producto === null) {
                $errors["producto.$productoId"] = "El producto #$productoId no existe o está inactivo.";
                continue;
            }
            $uSec = Producto::unidadSecundaria(isset($producto['unidad_secundaria']) ? (string) $producto['unidad_secundaria'] : null);
            if ($uSec !== null) {
                $emp = (int) ($linea['empaques'] ?? 0);
                $brutoM = (int) ($linea['bruto_milesimas'] ?? 0);
                if ($emp < 1 || $brutoM <= 0) {
                    $errors["producto.$productoId"] = sprintf('Pesa el %s de %s: indica %s y el peso bruto de balanza.', mb_strtolower($uSec), $producto['nombre'], $uSec);
                    continue;
                }
                $taraU = Producto::taraUnitaria($uSec, $linea['tara_unitaria'] ?? null);
                $neto = Producto::pesoNeto(Producto::desdeMilesimas($brutoM), $emp, $taraU);
                if ($neto === null) {
                    $errors["producto.$productoId"] = sprintf('El peso bruto de %s no cubre la tara de los envases.', $producto['nombre']);
                    continue;
                }
                $pedido['lineas'][$productoId]['cantidad'] = $neto;
                $pedido['lineas'][$productoId]['peso_bruto'] = Producto::desdeMilesimas($brutoM);
                $pedido['lineas'][$productoId]['tara_unitaria'] = $taraU;
                $pedido['lineas'][$productoId]['tara_total'] = Producto::taraTotal($emp, $taraU);
                if (Producto::aMilesimas((string) $producto['stock']) < Producto::aMilesimas($neto)) {
                    $errors["producto.$productoId"] = sprintf(
                        'Stock neto insuficiente de %s: disponible %s kg, neto %s kg.',
                        $producto['nombre'],
                        num($producto['stock'], 3),
                        num($neto, 3)
                    );
                }
                continue;
            }
            if (Producto::aMilesimas((string) $producto['stock']) < Producto::aMilesimas($linea['cantidad'])) {
                $errors["producto.$productoId"] = sprintf(
                    'Stock insuficiente de %s: disponible %s, solicitado %s.',
                    $producto['nombre'],
                    num($producto['stock'], 3),
                    num($linea['cantidad'], 3)
                );
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $ventaId = $this->ventas->create([
            'cliente_id'        => $pos['id'],
            'ventas_cliente_id' => $pedido['ventas_cliente_id'],
            'cliente_nombre'    => $nombre,
            'tipo_cliente'      => $pedido['tipo_cliente'],
            'entidad_tipo'      => $pedido['entidad_tipo'],
            'entidad_id'        => $pedido['entidad_id'],
            'fecha'             => $momento,
            'moneda_pago'       => $pedido['moneda_pago'],
            'metodo_pago'       => $pedido['metodo_pago'],
            'referencia'        => $pedido['referencia'],
            'descripcion_pago'  => $pedido['descripcion_pago'],
            'semana_aplicacion' => $pedido['semana_aplicacion'],
            'tasa_id'           => $tasa['id'],
            'tasa_bs_por_usd'   => $tasa['tasa'],
            'emitida_por'       => $userId,
        ]);

        $detalle = [];
        foreach ($pedido['lineas'] as $productoId => $linea) {
            $emp = (int) ($linea['empaques'] ?? 0);
            $this->ventas->addItem(
                $ventaId,
                $productoId,
                $linea['cantidad'],
                $linea['precio'],
                $emp,
                $linea['peso_bruto'] ?? null,
                $linea['tara_unitaria'] ?? '0.000',
                $linea['tara_total'] ?? '0.000'
            );
            $this->productos->descontarStock($productoId, $linea['cantidad']);
            $salidas = $this->almacenes->descontarEnOrden($productoId, $linea['cantidad'], $bloqueados[$productoId]['nombre']);
            $this->productos->registrarKardexVenta($productoId, $linea['cantidad'], $ventaId, $userId);
            $detalle[] = [
                'producto_id'     => $productoId,
                'cantidad'        => $linea['cantidad'],
                'precio_unit_usd' => $linea['precio'],
                'almacenes'       => $salidas,
            ];
        }

        $venta = $this->ventas->finalizar($ventaId);
        if ((float) $venta['total_usd'] <= 0 || (float) $venta['total_bs'] <= 0) {
            throw new ValidationException(['items' => 'El total de la factura debe ser mayor que cero.']);
        }

        $cajaId = null;
        if (!in_array($pedido['metodo_pago'], Venta::METODOS_SIN_CAJA, true)) {
            $cajaId = $this->ventas->registrarIngresoCaja($ventaId, $userId, $nombre);
        }

        $deuda = $this->asentarDeuda($venta, $pedido, $userId, $nombre);

        audit_log('ventas', 'crear', [
            'numero'              => $venta['numero'],
            'tipo_cliente'        => $pedido['tipo_cliente'],
            'cliente'             => $nombre,
            'entidad_tipo'        => $pedido['entidad_tipo'],
            'entidad_id'          => $pedido['entidad_id'],
            'metodo_pago'         => $pedido['metodo_pago'],
            'referencia'          => $pedido['referencia'],
            'semana_aplicacion'   => $pedido['semana_aplicacion'],
            'total_usd'           => (string) $venta['total_usd'],
            'caja_movimiento_id'  => $cajaId,
            'deuda'               => $deuda,
            'items'               => $detalle,
        ], 'ventas', $ventaId);

        return $venta;
    }

    /** @param array<string, mixed> $pedido @return array{0:array{id:int,nombre:string},1:string} */
    private function resolverCliente(array $pedido): array
    {
        $pos = $this->ventas->clienteContado();
        if ($pedido['tipo_cliente'] === 'generico') {
            return [$pos, $pedido['cliente_nombre']];
        }
        if ($pedido['tipo_cliente'] === 'frecuente') {
            $cli = $this->ventas->clientePos((int) $pedido['ventas_cliente_id']);
            if ($cli === null || $cli['tipo'] !== 'frecuente') {
                throw new ValidationException(['ventas_cliente_id' => 'El cliente frecuente no existe.']);
            }
            return [$pos, (string) $cli['nombre']];
        }
        $nombre = $this->ventas->nombreEntidad([
            'entidad_tipo'   => $pedido['entidad_tipo'],
            'entidad_id'     => $pedido['entidad_id'],
            'cliente_nombre' => '',
        ]);
        if ($nombre === '') {
            throw new ValidationException(['entidad_id' => 'No se encontró el nombre de la entidad exclusiva.']);
        }
        return [$pos, $nombre];
    }

    /** @param array<string, mixed> $pedido */
    private function asegurarEntidad(array $pedido): void
    {
        if ($pedido['tipo_cliente'] !== 'exclusivo') {
            return;
        }
        $id = (int) $pedido['entidad_id'];
        $ok = match ($pedido['entidad_tipo']) {
            'productor'     => (new Productor(get_db()))->find($id) !== null,
            'empleado'      => ($empVenta = (new Empleado(get_db()))->find($id)) !== null && $empVenta['activo'],
            'transportista' => (new TransporteService(get_db()))->existeActivo($id),
            'cxc'           => (new CxCService(get_db(), new CorrelativoService(get_db())))->cliente($id) !== null,
            default         => false,
        };
        if (!$ok) {
            throw new ValidationException(['entidad_id' => 'La entidad exclusiva no existe.']);
        }
    }

    /**
     * @param array<string, mixed> $venta
     * @param array<string, mixed> $pedido
     * @return array<string, mixed>|null
     */
    private function asentarDeuda(array $venta, array $pedido, int $userId, string $nombreCliente): ?array
    {
        if ($pedido['tipo_cliente'] !== 'exclusivo' || !in_array($pedido['metodo_pago'], Venta::METODOS_DEUDA, true)) {
            return null;
        }
        $tipo = (string) $pedido['entidad_tipo'];
        $id = (int) $pedido['entidad_id'];
        $fecha = substr((string) $venta['fecha'], 0, 10);
        $monto = (string) $venta['total_usd'];
        $factura = 'Factura Nro: ' . $venta['numero'];
        $semanaRef = $pedido['semana_aplicacion'] ?? $fecha;

        if ($tipo === 'productor') {
            $row = (new Productor(get_db()))->find($id);
            if ($row === null) {
                throw new ValidationException(['entidad_id' => 'El productor no existe.']);
            }
            $semana = CierreSemanal::semanaDe($semanaRef, CierreSemanal::diaCierreValido($row['dia_cierre']));
            $abonoId = (new PagoProductor(get_db()))->create([
                'productor_id'    => $id,
                'fecha'           => $fecha,
                'semana_inicio'   => $semana,
                'tipo'            => 'mercancia',
                'moneda'          => 'USD',
                'monto'           => $monto,
                'tasa_bs_por_usd' => null,
                'monto_usd'       => $monto,
                'referencia'      => $venta['numero'],
                'descripcion'     => $factura . ' — ' . $nombreCliente,
                'cierre_id'       => null,
                'registrado_por'  => $userId,
            ]);
            return ['destino' => 'productores_abonos', 'id' => $abonoId, 'semana' => $semana];
        }

        if ($tipo === 'empleado') {
            $emp = (new Empleado(get_db()))->find($id);
            if ($emp === null || !(int) $emp['activo']) {
                throw new ValidationException(['entidad_id' => 'El empleado no existe o está inactivo.']);
            }
            // La factura ya quedó en ventas. Aquí se carga el mismo total USD a la nómina:
            // cuota_semanal = monto para que el cierre semanal lo descuente completo.
            $prestamoId = (new PrestamoEmpleado(get_db()))->create([
                'empleado_id'   => (int) $emp['id'],
                'monto_total'   => $monto,
                'cuota_semanal' => $monto,
                'tipo'          => 'mercancia',
                'fecha'         => $semanaRef,
                'concepto'      => mb_substr('Mercancía - Factura Nro: ' . $venta['numero'], 0, 120),
            ], $userId);
            return ['destino' => 'hr_prestamos', 'id' => $prestamoId];
        }

        if ($tipo === 'transportista') {
            $log = new TransporteService(get_db());
            $semana = CierreSemanal::semanaDe($semanaRef, CierreSemanal::DIA_CIERRE_DEFAULT);
            $cierreId = $log->cierreParaSemana($id, $semana, $userId);
            $pagoId = $log->registrarPagoCierre($cierreId, [
                'fecha'                 => $fecha,
                'monto'                 => $monto,
                'tipo'                  => 'mercancia',
                'descripcion_mercancia' => $factura . ' — semana ' . $semana,
            ], $userId);
            return ['destino' => 'log_pagos_transporte', 'id' => $pagoId, 'semana' => $semana];
        }

        $cxc = new CxCService(get_db(), new CorrelativoService(get_db()));
        $detalles = [];
        foreach ($this->ventas->items((int) $venta['id']) as $item) {
            $detalles[] = [
                'cantidad'    => $item['cantidad'],
                'precio'      => $item['precio_unit_usd'],
                'descripcion' => $item['nombre'],
            ];
        }
        $vence = (new DateTimeImmutable($fecha))->modify('+7 days')->format('Y-m-d');
        $out = $cxc->registrarFactura([
            'cliente_id'        => $id,
            'numero'            => $venta['numero'],
            'es_aleatorio'      => false,
            'domicilio'         => null,
            'observaciones'     => $factura . ' POS',
            'fecha_emision'     => $fecha,
            'fecha_vencimiento' => $vence,
            'monto_total'       => $monto,
        ], $detalles, false, $userId);
        return ['destino' => 'cxc_facturas', 'id' => $out['factura_id'], 'numero' => $out['numero']];
    }

    private function auditarFallo(array $motivo): void
    {
        try {
            audit_log('ventas', 'crear_fallida', [
                'tipo_cliente' => $_POST['tipo_cliente'] ?? null,
                'metodo_pago'  => $_POST['metodo_pago'] ?? null,
                'motivo'       => $motivo,
            ], 'ventas');
        } catch (Throwable $e) {
            error_log('[ADARA][ventas] No se pudo auditar el fallo: ' . $e->getMessage());
        }
    }

    private function fallar(array $errors): never
    {
        foreach ($errors as $mensaje) {
            flash('error', $mensaje);
        }
        redirect('ventas_nueva');
    }
}
