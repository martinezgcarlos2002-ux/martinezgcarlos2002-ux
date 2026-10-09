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
        view('ventas_nueva', [
            'productos' => $this->productos->disponiblesParaVenta(),
            'clientes'  => $this->ventas->clientesActivos(),
            'tasa'      => TasaCambio::vigente(get_db())['tasa'] ?? null,
            'tipos'     => Venta::TIPOS_CLIENTE,
            'entidades' => Venta::ENTIDADES,
            'metodos'   => Venta::METODOS_PAGO,
            'buscarUrl' => url('ventas_buscar_entidades'),
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
            redirect('ventas_nueva');
        }
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
     * Factura + descuento de stock + kardex + ingreso en caja + auditoría en una sola transacción.
     * Si cualquier paso falla se revierte todo y el intento fallido se audita fuera de la transacción.
     */
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

        $cajaTxt = in_array($venta['metodo_pago'], Venta::METODOS_SIN_CAJA, true)
            ? 'Sin asiento de caja (crédito, cortesía o descuento).'
            : sprintf('Ingreso asentado en caja a %s Bs/USD.', num($venta['tasa_bs_por_usd'], 4));
        flash('success', sprintf(
            'Venta %s registrada: $%s (Bs. %s). %s',
            $venta['numero'],
            num($venta['total_usd']),
            num($venta['total_bs']),
            $cajaTxt
        ));
        redirect('ventas_ticket', ['id' => (int) $venta['id']]);
    }

    /**
     * @return array{
     *   cliente_id:?int, tipo_cliente:string, entidad_tipo:?string, entidad_id:?int,
     *   fecha:string, moneda_pago:string, metodo_pago:string,
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
        $clienteId = parse_int_range($_POST['cliente_id'] ?? null, 1, PHP_INT_MAX);

        if ($tipoCliente === 'exclusivo') {
            $entidadTipo = (string) ($_POST['entidad_tipo'] ?? '');
            $entidadId = parse_int_range($_POST['entidad_id'] ?? null, 1, PHP_INT_MAX);
            if (!isset(Venta::ENTIDADES[$entidadTipo]) || $entidadId === null) {
                $errors['entidad_id'] = 'Busca y selecciona el productor, empleado o cliente exclusivo.';
            }
        } elseif ($clienteId === null) {
            $errors['cliente_id'] = 'Selecciona un cliente.';
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
        } elseif (in_array($metodo, Venta::METODOS_DEUDA, true) && $tipoCliente !== 'exclusivo') {
            $errors['metodo_pago'] = 'Crédito y mercancía a descuento requieren un cliente exclusivo (productor, empleado o CxC).';
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

                if ($productoId === null) {
                    $errors["items.$n"] = "Línea $n: selecciona un producto.";
                    continue;
                }
                if ($cantidad === null) {
                    $errors["items.$n"] = "Línea $n: cantidad inválida (mayor que 0, hasta 3 decimales).";
                    continue;
                }
                if ($precio === null) {
                    $errors["items.$n"] = "Línea $n: precio inválido (hasta 2 decimales).";
                    continue;
                }

                if (!isset($lineas[$productoId])) {
                    $lineas[$productoId] = ['milesimas' => 0, 'precio' => $precio];
                } elseif (number_format((float) $lineas[$productoId]['precio'], 2, '.', '') !== number_format((float) $precio, 2, '.', '')) {
                    $errors["items.$n"] = "Línea $n: el producto ya está en la factura con otro precio.";
                    continue;
                }
                $lineas[$productoId]['milesimas'] += Producto::aMilesimas($cantidad);
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return [
            'cliente_id'   => $clienteId,
            'tipo_cliente' => $tipoCliente,
            'entidad_tipo' => $entidadTipo,
            'entidad_id'   => $entidadId,
            'fecha'        => $fecha,
            'moneda_pago'  => $moneda,
            'metodo_pago'  => $metodo,
            'lineas'       => array_map(static fn (array $l): array => [
                'cantidad' => Producto::desdeMilesimas($l['milesimas']),
                'precio'   => $l['precio'],
            ], $lineas),
        ];
    }

    /** Corre dentro de Database::transaction(): cualquier excepción revierte factura, stock, kardex y caja. */
    private function procesar(array $pedido, int $userId): array
    {
        $momento = $pedido['fecha'] === date('Y-m-d') ? date('Y-m-d H:i:s') : $pedido['fecha'] . ' 23:59:59';

        $tasa = $this->ventas->tasaVigenteEn($momento);
        if ($tasa === null) {
            throw new ValidationException(['tasa' => 'No hay tasa de cambio vigente para esa fecha. Registra la tasa BCV antes de facturar.']);
        }
        $cliente = $this->resolverCliente($pedido);
        $this->asegurarEntidad($pedido);

        $bloqueados = $this->productos->bloquearActivos(array_keys($pedido['lineas']));
        $errors = [];
        foreach ($pedido['lineas'] as $productoId => $linea) {
            $producto = $bloqueados[$productoId] ?? null;
            if ($producto === null) {
                $errors["producto.$productoId"] = "El producto #$productoId no existe o está inactivo.";
            } elseif (Producto::aMilesimas((string) $producto['stock']) < Producto::aMilesimas($linea['cantidad'])) {
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
            'cliente_id'      => $cliente['id'],
            'tipo_cliente'    => $pedido['tipo_cliente'],
            'entidad_tipo'    => $pedido['entidad_tipo'],
            'entidad_id'      => $pedido['entidad_id'],
            'fecha'           => $momento,
            'moneda_pago'     => $pedido['moneda_pago'],
            'metodo_pago'     => $pedido['metodo_pago'],
            'tasa_id'         => $tasa['id'],
            'tasa_bs_por_usd' => $tasa['tasa'],
            'emitida_por'     => $userId,
        ]);

        $detalle = [];
        foreach ($pedido['lineas'] as $productoId => $linea) {
            $this->ventas->addItem($ventaId, $productoId, $linea['cantidad'], $linea['precio']);
            $this->productos->descontarStock($productoId, $linea['cantidad']);
            $salidas = $this->almacenes->descontarEnOrden($productoId, $linea['cantidad'], $bloqueados[$productoId]['nombre']);
            $this->productos->registrarKardexVenta($productoId, $linea['cantidad'], $ventaId, $userId);
            $detalle[] = [
                'producto_id'     => $productoId,
                'cantidad'        => $linea['cantidad'],
                'precio_unit_usd' => $linea['precio'],
                'precio_catalogo' => (string) $bloqueados[$productoId]['precio_usd'],
                'stock_previo'    => (string) $bloqueados[$productoId]['stock'],
                'almacenes'       => $salidas,
            ];
        }

        $venta = $this->ventas->finalizar($ventaId);
        if ((float) $venta['total_usd'] <= 0 || (float) $venta['total_bs'] <= 0) {
            throw new ValidationException(['items' => 'El total de la factura debe ser mayor que cero.']);
        }

        $cajaId = null;
        if (!in_array($pedido['metodo_pago'], Venta::METODOS_SIN_CAJA, true)) {
            $cajaId = $this->ventas->registrarIngresoCaja($ventaId, $userId);
        }

        $deuda = $this->asentarDeuda($venta, $pedido, $userId);

        audit_log('ventas', 'crear', [
            'numero'              => $venta['numero'],
            'cliente_id'          => $cliente['id'],
            'tipo_cliente'        => $pedido['tipo_cliente'],
            'entidad_tipo'        => $pedido['entidad_tipo'],
            'entidad_id'          => $pedido['entidad_id'],
            'metodo_pago'         => $pedido['metodo_pago'],
            'moneda_pago'         => $venta['moneda_pago'],
            'tasa_id'             => $tasa['id'],
            'tasa_bs_por_usd'     => $tasa['tasa'],
            'total_usd'           => (string) $venta['total_usd'],
            'total_bs'            => (string) $venta['total_bs'],
            'caja_movimiento_id'  => $cajaId,
            'deuda'               => $deuda,
            'items'               => $detalle,
        ], 'ventas', $ventaId);

        return $venta;
    }

    /** @param array<string, mixed> $pedido */
    private function resolverCliente(array $pedido): array
    {
        if ($pedido['tipo_cliente'] === 'exclusivo') {
            return $this->ventas->clienteContado();
        }
        $cliente = $this->ventas->clienteActivo((int) $pedido['cliente_id']);
        if ($cliente === null) {
            throw new ValidationException(['cliente_id' => 'El cliente no existe o está inactivo.']);
        }
        return $cliente;
    }

    /** @param array<string, mixed> $pedido */
    private function asegurarEntidad(array $pedido): void
    {
        if ($pedido['tipo_cliente'] !== 'exclusivo') {
            return;
        }
        $id = (int) $pedido['entidad_id'];
        $ok = match ($pedido['entidad_tipo']) {
            'productor' => (new Productor(get_db()))->find($id) !== null,
            'empleado'  => (new Empleado(get_db()))->find($id) !== null,
            'cxc'       => (new CxCService(get_db(), new CorrelativoService(get_db())))->cliente($id) !== null,
            default     => false,
        };
        if (!$ok) {
            throw new ValidationException(['entidad_id' => 'La entidad exclusiva no existe.']);
        }
    }

    /**
     * Crédito o mercancía a descuento: asienta la deuda en productor, nómina o CxC.
     * @param array<string, mixed> $venta
     * @param array<string, mixed> $pedido
     * @return array<string, mixed>|null
     */
    private function asentarDeuda(array $venta, array $pedido, int $userId): ?array
    {
        if ($pedido['tipo_cliente'] !== 'exclusivo' || !in_array($pedido['metodo_pago'], Venta::METODOS_DEUDA, true)) {
            return null;
        }
        $tipo = (string) $pedido['entidad_tipo'];
        $id = (int) $pedido['entidad_id'];
        $fecha = substr((string) $venta['fecha'], 0, 10);
        $monto = (string) $venta['total_usd'];
        $concepto = 'Venta ' . $venta['numero'];

        if ($tipo === 'productor') {
            $productor = new Productor(get_db());
            $row = $productor->find($id);
            if ($row === null) {
                throw new ValidationException(['entidad_id' => 'El productor no existe.']);
            }
            $semana = CierreSemanal::semanaDe($fecha, CierreSemanal::diaCierreValido($row['dia_cierre']));
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
                'descripcion'     => $concepto . ' (mercancía / crédito POS)',
                'cierre_id'       => null,
                'registrado_por'  => $userId,
            ]);
            return ['destino' => 'productores_abonos', 'id' => $abonoId];
        }

        if ($tipo === 'empleado') {
            $emp = (new Empleado(get_db()))->find($id);
            if ($emp === null || !(int) $emp['activo']) {
                throw new ValidationException(['entidad_id' => 'El empleado no existe o está inactivo.']);
            }
            $prestamoId = (new PrestamoEmpleado(get_db()))->create([
                'empleado_id'   => $id,
                'monto_total'   => $monto,
                'cuota_semanal' => '0.00',
                'tipo'          => 'mercancia',
                'fecha'         => $fecha,
                'concepto'      => $concepto,
            ], $userId);
            return ['destino' => 'hr_prestamos', 'id' => $prestamoId];
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
            'observaciones'     => $concepto . ' POS',
            'fecha_emision'     => $fecha,
            'fecha_vencimiento' => $vence,
            'monto_total'       => $monto,
        ], $detalles, false, $userId);
        return ['destino' => 'cxc_facturas', 'id' => $out['factura_id'], 'numero' => $out['numero']];
    }

    /** Se llama tras el rollback, así el registro del fallo sobrevive aunque la venta no. */
    private function auditarFallo(array $motivo): void
    {
        $items = $_POST['items'] ?? null;
        $moneda = $_POST['moneda_pago'] ?? null;
        try {
            audit_log('ventas', 'crear_fallida', [
                'cliente_id'  => parse_int_range($_POST['cliente_id'] ?? null, 1, PHP_INT_MAX),
                'moneda_pago' => is_string($moneda) && in_array($moneda, Venta::MONEDAS, true) ? $moneda : null,
                'lineas'      => is_array($items) ? count($items) : 0,
                'motivo'      => $motivo,
            ], 'ventas');
        } catch (Throwable $e) {
            error_log('[ADARA][ventas] No se pudo auditar el fallo: ' . $e->getMessage());
        }
    }

    /** El POS no pinta errores por campo: se muestran como flash. */
    private function fallar(array $errors): never
    {
        foreach ($errors as $mensaje) {
            flash('error', $mensaje);
        }
        redirect('ventas_nueva');
    }
}
