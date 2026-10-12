<?php
declare(strict_types=1);

/**
 * Control de Facturas de Clientes (CxC): pantalla y endpoints JSON.
 *
 * Seguridad (además de lo que ya hace index.php: sesión, RBAC por ruta y CSRF en todo POST):
 *   - Toda entrada se valida y normaliza aquí antes de llegar a CxCService; nada se interpola en SQL.
 *   - Respuestas JSON uniformes: {ok:true, mensaje, ...datos} o {ok:false, errores:{campo:mensaje}}.
 *   - Los errores internos se registran en el log y al cliente solo llega un mensaje genérico.
 *   - Los objetos devueltos tienen la misma forma que la carga inicial de la vista, para que el
 *     frontend reemplace sus filas por las del servidor (fuente de verdad) sin recargar.
 */
final class FacturaController
{
    /** Etiquetas que envía el formulario → clave del ENUM. También se aceptan las claves. */
    private const METODOS_ENTRADA = [
        'efectivo' => 'efectivo', 'punto' => 'punto', 'transferencia' => 'transferencia',
        'pago móvil' => 'pago_movil', 'pago movil' => 'pago_movil', 'pago_movil' => 'pago_movil',
        'saldo a favor' => 'saldo_favor', 'saldo_favor' => 'saldo_favor',
        'otro' => 'otro', '' => 'otro',
    ];
    private const DESTINOS_ENTRADA = [
        'origen' => 'origen', 'otra' => 'otra_factura', 'otra_factura' => 'otra_factura',
        'credito' => 'credito_pendiente', 'credito_pendiente' => 'credito_pendiente',
    ];
    private const ESTADOS = [
        'pagada' => 'Pagada', 'vencida' => 'Vencida', 'por_vencer' => 'Por vencer', 'vigente' => 'Vigente', 'anulada' => 'Anulada',
    ];
    private const DESTINOS = [
        'origen' => 'Misma factura', 'otra' => 'Otra factura', 'credito' => 'Crédito a favor',
    ];

    private CxCService $cxc;

    public function __construct()
    {
        $this->cxc = new CxCService(get_db(), new CorrelativoService(get_db()));
    }

    public function index(): void
    {
        $metricas = $this->cxc->obtenerMetricasDashboard();
        view('facturas', [
            'hoy'          => date('Y-m-d'),
            'metricas'     => $metricas,
            'clientes'     => array_map([self::class, 'jsonCliente'], $this->cxc->clientes()),
            'facturas'     => array_map([self::class, 'jsonFactura'], $this->cxc->facturas()),
            'pagos'        => array_map([self::class, 'jsonPago'], $this->cxc->pagos()),
            'devoluciones' => array_map([self::class, 'jsonDevolucion'], $this->cxc->devoluciones()),
            'metodos'      => array_values(array_intersect_key(CxCService::METODOS, array_flip(CxCService::METODOS_MANUALES))),
            'puedeCrear'   => has_permiso(current_user(), 'facturas', 'crear'),
            'puedeAnular'  => has_permiso(current_user(), 'facturas', 'anular'),
            'tasa'         => TasaCambio::vigente(get_db())['tasa'] ?? null,
        ]);
    }

    /** GET: número aleatorio libre (R-####). Es una sugerencia: la unicidad final la garantiza el índice. */
    public function numero(): void
    {
        $this->responder(fn (): array => ['numero' => (new CorrelativoService(get_db()))->generarAleatorio()]);
    }

    /** GET ?q=: facturas con saldo por número o cliente. */
    public function buscar(): void
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
        $this->responder(fn (): array => ['facturas' => array_map([self::class, 'jsonFactura'], $this->cxc->buscarPendientes($q))]);
    }

    public function storeCliente(): void
    {
        $this->responder(function (): array {
            $errores = [];
            $nombre = mb_strtoupper(preg_replace('/\s+/u', ' ', post_string('nombre', 140)));
            if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
                $errores['nombre'] = 'El nombre es obligatorio (2 a 120 caracteres).';
            }
            $docRaw = post_documento_compuesto('documento', 20);
            $documento = $docRaw === '' || preg_match('/^[VEJGP]-?$/i', $docRaw) ? null : normalize_documento($docRaw);
            if ($docRaw !== '' && $documento === null && !preg_match('/^[VEJGP]-?$/i', $docRaw)) {
                $errores['documento'] = 'Documento inválido. Ej.: V-12345678 o J-12345678-9.';
            }
            $telefono = preg_replace('/[^0-9+\-\s()]/', '', post_telefono_compuesto('telefono', 30));
            if (mb_strlen($telefono) > 20) {
                $errores['telefono'] = 'Teléfono demasiado largo.';
            }
            $domicilio = preg_replace('/\s+/u', ' ', post_string('domicilio', 200));
            if (mb_strlen($domicilio) > 160) {
                $errores['domicilio'] = 'El domicilio admite hasta 160 caracteres.';
            }
            if ($errores) {
                throw new ValidationException($errores);
            }
            $id = self::idOpcional('id');
            $data = ['nombre' => $nombre, 'documento' => $documento, 'telefono' => $telefono ?: null, 'domicilio' => $domicilio ?: null];
            $id = $this->cxc->guardarCliente($data, $id, self::usuario());
            return [
                'mensaje' => isset($_POST['id']) && $_POST['id'] !== '' ? 'Cliente actualizado.' : 'Cliente registrado.',
                'cliente' => self::jsonCliente($this->cxc->cliente($id)),
            ];
        }, 201);
    }

    public function destroyCliente(): void
    {
        $this->responder(function (): array {
            $id = self::idRequerido('id', 'Cliente inválido.');
            $this->cxc->eliminarCliente($id);
            return ['mensaje' => 'Cliente eliminado.', 'id' => $id];
        });
    }

    public function storeFactura(): void
    {
        $this->responder(function (): array {
            $clienteId = self::idRequerido('cliente_id', 'Selecciona un cliente.');
            $emision = self::fecha('emision', 'Fecha de emisión inválida.');
            $vence = self::fecha('vence', 'Fecha de vencimiento inválida.', false);
            $aleatorio = ($_POST['modo_numero'] ?? '') === 'aleatorio';
            $r = $this->cxc->registrarFactura([
                'cliente_id'        => $clienteId,
                'numero'            => post_string('numero', 30),
                'es_aleatorio'      => $aleatorio,
                'domicilio'         => mb_substr(preg_replace('/\s+/u', ' ', post_string('domicilio', 200)), 0, 160),
                'observaciones'     => mb_substr(preg_replace('/\s+/u', ' ', post_string('observaciones', 300)), 0, 240),
                'fecha_emision'     => $emision,
                'fecha_vencimiento' => $vence,
                'monto_total'       => post_string('monto', 30),
            ], self::renglonesPost(), ($_POST['descontar_credito'] ?? '') === '1', self::usuario());

            $mensaje = 'Factura ' . $r['numero'] . ' registrada.';
            if ((float) $r['credito_aplicado'] > 0) {
                $mensaje .= ' Se aplicaron $' . num($r['credito_aplicado']) . ' de saldo a favor.';
            }
            return [
                'mensaje' => $mensaje,
                'factura' => self::jsonFactura($this->cxc->facturas([$r['factura_id']])[0]),
                'cliente' => self::jsonCliente($this->cxc->cliente($clienteId)),
                'pagos'        => $r['pago_id'] !== null ? array_map([self::class, 'jsonPago'], $this->cxc->pagos([$r['pago_id']])) : [],
                'devoluciones' => self::jsonDevolucionesDe($this->cxc, $clienteId),
            ];
        }, 201);
    }

    public function storePago(): void
    {
        $this->responder(function (): array {
            $facturaId = self::idRequerido('factura_id', 'Selecciona una factura.');
            $tasa = self::tasaCambioPost();
            $r = $this->cxc->registrarPagoIndividual(
                $facturaId,
                post_string('monto', 30),
                self::fecha('fecha', 'Fecha de pago inválida.'),
                self::metodo(),
                self::texto('referencia', 60),
                self::texto('notas', 160),
                self::usuario(),
                $tasa['tasa'],
                $tasa['bs']
            );
            $pagos = $this->cxc->pagos([$r['pago_id']]);
            $facturas = array_map([self::class, 'jsonFactura'], $this->cxc->facturas([$facturaId]));
            $clienteId = (int) $facturas[0]['cliente_id'];
            $metodo = (string) ($pagos[0]['metodo'] ?? '');
            $mensaje = 'Pago de $' . num($pagos[0]['monto']) . ' registrado.';
            if ($metodo === 'saldo_favor') {
                $mensaje = 'Se aplicaron $' . num($pagos[0]['monto']) . ' de saldo a favor a la factura.';
            }
            return [
                'mensaje'      => $mensaje,
                'pagos'        => array_map([self::class, 'jsonPago'], $pagos),
                'facturas'     => $facturas,
                'cliente'      => self::jsonCliente($this->cxc->cliente($clienteId)),
                'devoluciones' => self::jsonDevolucionesDe($this->cxc, $clienteId),
            ];
        }, 201);
    }

    /** El reparto lo decide el servidor (FIFO); cualquier "repartos" enviado por el cliente se ignora. */
    public function storeAbono(): void
    {
        $this->responder(function (): array {
            $clienteId = self::idRequerido('cliente_id', 'Selecciona un cliente.');
            $tasa = self::tasaCambioPost();
            $r = $this->cxc->abonarAVarias(
                $clienteId,
                post_string('monto', 30),
                self::fecha('fecha', 'Fecha del abono inválida.'),
                self::metodo(),
                self::texto('referencia', 60),
                self::texto('notas', 160),
                self::usuario(),
                $tasa['tasa'],
                $tasa['bs']
            );
            $mensaje = sprintf('Abono repartido en %d factura(s).', count($r['pagos']));
            if ((float) $r['excedente'] > 0) {
                $mensaje .= ' Excedente de $' . num($r['excedente']) . ' guardado como saldo a favor.';
            }
            return [
                'mensaje'   => $mensaje,
                'excedente' => (float) $r['excedente'],
                'pagos'     => array_map([self::class, 'jsonPago'], $this->cxc->pagos(array_column($r['pagos'], 'pago_id'))),
                'facturas'  => array_map([self::class, 'jsonFactura'], $this->cxc->facturas(array_column($r['pagos'], 'factura_id'))),
                'cliente'      => self::jsonCliente($this->cxc->cliente($clienteId)),
                'devoluciones' => self::jsonDevolucionesDe($this->cxc, $clienteId),
            ];
        }, 201);
    }

    public function storeDevolucion(): void
    {
        $this->responder(function (): array {
            $clienteId = self::idRequerido('cliente_id', 'Selecciona un cliente.');
            $destino = self::DESTINOS_ENTRADA[post_string('destino', 20)] ?? null;
            if ($destino === null) {
                throw new ValidationException(['destino' => 'Indica qué hacer con el dinero de la devolución.']);
            }
            $r = $this->cxc->registrarDevolucion([
                'cliente_id'          => $clienteId,
                'destino'             => $destino,
                'factura_origen_id'   => self::idOpcional('factura_id'),
                'factura_aplicada_id' => $destino === 'otra_factura' ? self::idOpcional('factura_destino') : null,
                'monto'               => post_string('monto', 30),
                'fecha'               => self::fecha('fecha', 'Fecha de la devolución inválida.'),
                'motivo'              => self::texto('motivo', 160),
            ], self::renglonesPost(), self::usuario());

            return [
                'mensaje'    => $destino === 'credito_pendiente' ? 'Devolución registrada como saldo a favor del cliente.' : 'Devolución aplicada a la factura.',
                'devolucion' => self::jsonDevolucion($this->cxc->devoluciones([$r['devolucion_id']])[0]),
                'facturas'   => $r['factura_aplicada_id'] !== null ? array_map([self::class, 'jsonFactura'], $this->cxc->facturas([$r['factura_aplicada_id']])) : [],
                'cliente'    => self::jsonCliente($this->cxc->cliente($clienteId)),
            ];
        }, 201);
    }

    public function anularPago(): void
    {
        $this->responder(function (): array {
            $id = self::idRequerido('id', 'Pago inválido.');
            $r = $this->cxc->anularPago($id, self::usuario());
            return [
                'mensaje'  => 'Pago anulado. El saldo de la factura se restauró.',
                'pagos'    => array_map([self::class, 'jsonPago'], $this->cxc->pagos([$r['pago_id']])),
                'facturas' => array_map([self::class, 'jsonFactura'], $this->cxc->facturas([$r['factura_id']])),
                'cliente'  => self::jsonCliente($this->cxc->cliente($r['cliente_id'])),
            ];
        });
    }

    public function anularDevolucion(): void
    {
        $this->responder(function (): array {
            $id = self::idRequerido('id', 'Devolución inválida.');
            $r = $this->cxc->anularDevolucion($id, self::usuario());
            $facturas = $r['factura_aplicada_id'] !== null
                ? array_map([self::class, 'jsonFactura'], $this->cxc->facturas([$r['factura_aplicada_id']]))
                : [];
            return [
                'mensaje'     => 'Devolución anulada.',
                'devolucion'  => self::jsonDevolucion($this->cxc->devoluciones([$r['devolucion_id']])[0]),
                'facturas'    => $facturas,
                'cliente'     => self::jsonCliente($this->cxc->cliente($r['cliente_id'])),
            ];
        });
    }

    public function anularFactura(): void
    {
        $this->responder(function (): array {
            $id = self::idRequerido('id', 'Factura inválida.');
            $r = $this->cxc->anularFactura($id, self::usuario());
            return [
                'mensaje'  => 'Factura anulada.',
                'factura'  => self::jsonFactura($this->cxc->facturas([$r['factura_id']])[0]),
                'cliente'  => self::jsonCliente($this->cxc->cliente($r['cliente_id'])),
            ];
        });
    }

    /** GET ?factura_id=: renglones + historial de pagos. */
    public function detalle(): void
    {
        $this->responder(function (): array {
            $id = self::idGet('factura_id', 'Factura inválida.');
            $d = $this->cxc->obtenerDetalleFactura($id);
            return [
                'factura'   => self::jsonFactura($d['factura']),
                'renglones' => array_map(static fn (array $r): array => [
                    'id'          => (int) $r['id'],
                    'renglon'     => (int) $r['renglon'],
                    'cantidad'    => (float) $r['cantidad'],
                    'precio'      => (float) $r['precio_unitario'],
                    'subtotal'    => (float) $r['subtotal'],
                    'descripcion' => (string) ($r['descripcion'] ?? ''),
                ], $d['renglones']),
                'pagos'     => array_map([self::class, 'jsonPago'], $d['pagos']),
            ];
        });
    }

    /** GET ?id=: cabecera de devolución + productos (precio tomado de la factura origen). */
    public function detalleDevolucion(): void
    {
        $this->responder(function (): array {
            $id = self::idGet('id', 'Devolución inválida.');
            $d = $this->cxc->obtenerDetalleDevolucion($id);
            $dev = $d['devolucion'];
            $origen = $dev['factura_origen_id'] !== null ? $this->cxc->facturas([(int) $dev['factura_origen_id']]) : [];
            $aplicada = $dev['factura_aplicada_id'] !== null ? $this->cxc->facturas([(int) $dev['factura_aplicada_id']]) : [];
            return [
                'devolucion' => self::jsonDevolucion($dev),
                'cliente'    => self::jsonCliente($this->cxc->cliente((int) $dev['cliente_id'])),
                'origen'     => $origen !== [] ? self::jsonFactura($origen[0]) : null,
                'aplicada'   => $aplicada !== [] ? self::jsonFactura($aplicada[0]) : null,
                'aplicacion' => $dev['destino'] === 'credito_pendiente'
                    ? 'Quedó como saldo a favor del cliente'
                    : ('Aplicada a la factura ' . (($aplicada[0] ?? [])['numero_factura'] ?? '')),
                'renglones'  => array_map(static fn (array $r): array => [
                    'id'            => (int) $r['id'],
                    'renglon'       => (int) $r['renglon'],
                    'cantidad'      => (float) $r['cantidad'],
                    'precio'        => (float) $r['precio_unitario'],
                    'precio_origen' => $r['precio_origen'] !== null ? (float) $r['precio_origen'] : (float) $r['precio_unitario'],
                    'subtotal'      => (float) $r['subtotal'],
                    'descripcion'   => (string) ($r['descripcion'] ?? ''),
                ], $d['renglones']),
            ];
        });
    }

    /** GET: facturas vencidas / por vencer con saldo, para la campana. */
    public function alertasCampana(): void
    {
        $this->responder(function (): array {
            $a = $this->cxc->alertasCampana();
            return [
                'total' => $a['total'],
                'lista' => array_map([self::class, 'jsonAlertaCampana'], $a['lista']),
            ];
        });
    }

    /** GET ?factura_id=: factura individual imprimible (A4 vertical) con firmas y sello. */
    public function imprimirFactura(): void
    {
        try {
            $id = self::idGet('factura_id', 'Factura inválida.');
            $d = $this->cxc->obtenerDetalleFactura($id);
            $cli = $this->cxc->cliente((int) $d['factura']['cliente_id']);
            header('Content-Disposition: inline; filename="factura_' . $d['factura']['numero_factura'] . '.html"');
            view('facturas_imprimir', [
                'hoy'           => date('Y-m-d'),
                'empresa'       => APP_EMPRESA,
                'firmante'      => self::firmante(),
                'factura'       => self::jsonFactura($d['factura']),
                'observaciones' => (string) ($d['factura']['observaciones'] ?? ''),
                'cliente'       => self::jsonCliente($cli),
                'renglones'     => $d['renglones'],
                'pagos'         => array_map([self::class, 'jsonPago'], $d['pagos']),
            ]);
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('facturas');
        }
    }

    /** GET ?cliente_id=: estado de cuenta vertical (libro mayor). */
    public function estadoCuentaPdf(): void
    {
        try {
            $id = self::idGet('cliente_id', 'Cliente inválido.');
            $cliente = $this->cxc->cliente($id);
            if ($cliente === null) {
                throw new ValidationException(['cliente_id' => 'El cliente no existe.']);
            }
            header('Content-Disposition: inline; filename="estado_cuenta_' . $id . '.html"');
            view('facturas_estado_cuenta', [
                'hoy'      => date('Y-m-d'),
                'empresa'  => APP_EMPRESA,
                'firmante' => self::firmante(),
                'cliente'  => self::jsonCliente($cliente),
                'asientos' => $this->cxc->libroMayorCliente($id),
            ]);
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('facturas');
        }
    }

    /** Reporte general .xlsx: resumen, prioridad, facturas, pagos, devoluciones y clientes. */
    public function excel(): void
    {
        $r = $this->datosReporte();
        $usd = XlsxWriter::S_USD;
        $txt = XlsxWriter::S_TEXTO;
        $tot = XlsxWriter::S_TOTAL_USD;
        $ttx = XlsxWriter::S_TOTAL_TEXTO;
        $titulo = APP_EMPRESA . ' — Control de Facturas · ' . self::dmy($r['hoy']);

        $resumen = [];
        foreach ([
            ['Clientes registrados', (int) $r['metricas']['clientes'], $txt],
            ['Facturas por cobrar', (int) $r['metricas']['por_cobrar'], $txt],
            ['Facturas pagadas', (int) $r['metricas']['pagadas'], $txt],
            ['Total facturado histórico', (float) $r['metricas']['facturado'], $usd],
            ['Total a cobrar', (float) $r['metricas']['a_cobrar'], $usd],
            ['Total cobrado', (float) $r['metricas']['cobrado'], $usd],
            ['Vencido (crítico)', (float) $r['metricas']['vencido'], $usd],
            ['Facturas vencidas', (int) $r['metricas']['vencidas'], $txt],
            ['Por vencer (≤3 días)', (int) $r['metricas']['por_vencer'], $txt],
            ['Crédito devoluciones sin aplicar', (float) $r['metricas']['credito'], $usd],
            ['Total devoluciones', (float) $r['metricas']['total_devoluciones'], $usd],
            ['Cartera vigente', (float) $r['metricas']['cartera_vigente'], $usd],
            ['Cartera vencida', (float) $r['metricas']['cartera_vencida'], $usd],
        ] as $fila) {
            $resumen[] = [[$fila[0], $txt], [$fila[1], $fila[2]]];
        }

        $prioridad = [];
        foreach ($r['prioridad'] as $p) {
            $prioridad[] = [
                [$p['cliente'], $txt],
                [(float) $p['saldo_total'], $usd],
                [(float) $p['vencido'], $usd],
                [(int) $p['facturas_vencidas'], $txt],
                [(int) $p['dias_atraso'], $txt],
            ];
        }

        $facturas = [];
        $tf = ['monto' => 0.0, 'pagado' => 0.0, 'devuelto' => 0.0, 'credito' => 0.0, 'saldo' => 0.0];
        foreach ($r['facturas'] as $f) {
            foreach (['monto', 'pagado', 'devuelto', 'credito', 'saldo'] as $k) {
                $tf[$k] += (float) $f[$k];
            }
            $facturas[] = [
                [$f['numero'], $txt],
                [$f['cliente'], $txt],
                [self::dmy($f['emision']), $txt],
                [self::dmy($f['vence']), $txt],
                [self::ESTADOS[$f['estado']] ?? $f['estado'], $txt],
                [(float) $f['monto'], $usd],
                [(float) $f['pagado'], $usd],
                [(float) $f['devuelto'], $usd],
                [(float) $f['credito'], $usd],
                [(float) $f['saldo'], $usd],
                [$f['domicilio'], $txt],
            ];
        }
        $facturas[] = [
            ['TOTALES', $ttx], ['', $ttx], ['', $ttx], ['', $ttx], ['', $ttx],
            [$tf['monto'], $tot], [$tf['pagado'], $tot], [$tf['devuelto'], $tot],
            [$tf['credito'], $tot], [$tf['saldo'], $tot], ['', $ttx],
        ];

        $pagos = [];
        $tp = 0.0;
        foreach ($r['pagos'] as $p) {
            $tp += (float) $p['monto'];
            $fac = $r['facturaPorId'][$p['factura_id']] ?? null;
            $pagos[] = [
                [self::dmy($p['fecha']), $txt],
                [$fac['numero'] ?? '', $txt],
                [$fac['cliente'] ?? '', $txt],
                [$p['metodo'], $txt],
                [$p['referencia'], $txt],
                [$p['notas'], $txt],
                [(float) $p['monto'], $usd],
            ];
        }
        $pagos[] = [['TOTALES', $ttx], ['', $ttx], ['', $ttx], ['', $ttx], ['', $ttx], ['', $ttx], [$tp, $tot]];

        $devs = [];
        $td = 0.0;
        foreach ($r['devoluciones'] as $d) {
            $td += (float) $d['monto'];
            $ori = $d['factura_id'] !== null ? ($r['facturaPorId'][$d['factura_id']]['numero'] ?? '') : '';
            $dst = $d['factura_destino'] !== null ? ($r['facturaPorId'][$d['factura_destino']]['numero'] ?? '') : '';
            $devs[] = [
                [self::dmy($d['fecha']), $txt],
                [$r['clientePorId'][$d['cliente_id']]['nombre'] ?? '', $txt],
                [(float) $d['monto'], $usd],
                [$d['motivo'], $txt],
                [$ori, $txt],
                [self::DESTINOS[$d['destino']] ?? $d['destino'], $txt],
                [$dst, $txt],
                [$d['estado'] === 'pendiente' ? 'Pendiente' : 'Aplicada', $txt],
            ];
        }
        $devs[] = [['TOTALES', $ttx], ['', $ttx], [$td, $tot], ['', $ttx], ['', $ttx], ['', $ttx], ['', $ttx], ['', $ttx]];

        $clientes = [];
        foreach ($r['clientes'] as $c) {
            $clientes[] = [
                [$c['nombre'], $txt],
                [$c['documento'], $txt],
                [$c['telefono'], $txt],
                [$c['domicilio'], $txt],
                [(float) $c['credito'], $usd],
            ];
        }

        (new XlsxWriter())
            ->hoja('Resumen', $titulo, ['Indicador', 'Valor'], $resumen)
            ->hoja('Cobrar primero', $titulo . ' — A quiénes cobrar primero',
                ['Cliente', 'Saldo total', 'Vencido', 'Fact. vencidas', 'Días de atraso'], $prioridad)
            ->hoja('Facturas', $titulo . ' — Facturas',
                ['N° factura', 'Cliente', 'Emisión', 'Vence', 'Estado', 'Monto', 'Pagado', 'Devuelto', 'Crédito', 'Saldo', 'Domicilio'], $facturas)
            ->hoja('Pagos', $titulo . ' — Pagos',
                ['Fecha', 'Factura', 'Cliente', 'Método', 'Referencia', 'Notas', 'Monto'], $pagos)
            ->hoja('Devoluciones', $titulo . ' — Devoluciones',
                ['Fecha', 'Cliente', 'Monto', 'Motivo', 'Factura origen', 'Destino', 'Aplicada a', 'Estado'], $devs)
            ->hoja('Clientes', $titulo . ' — Clientes',
                ['Nombre', 'Documento', 'Teléfono', 'Domicilio', 'Saldo a favor'], $clientes)
            ->enviar('cxc_facturas_' . $r['hoy'] . '.xlsx');
    }

    /** HTML imprimible (A4 horizontal). Sin atributos style="": CSP style-src no permite inline. */
    public function pdf(): void
    {
        $r = $this->datosReporte();
        header('Content-Disposition: inline; filename="cxc_facturas_' . $r['hoy'] . '.html"');
        view('facturas_pdf', $r);
    }

    /**
     * @return array{
     *   hoy:string, empresa:string, metricas:array<string, mixed>,
     *   prioridad:list<array<string, mixed>>,
     *   clientes:list<array<string, mixed>>, facturas:list<array<string, mixed>>,
     *   pagos:list<array<string, mixed>>, devoluciones:list<array<string, mixed>>,
     *   clientePorId:array<int, array<string, mixed>>, facturaPorId:array<int, array<string, mixed>>
     * }
     */
    private function datosReporte(): array
    {
        $metricas = $this->cxc->obtenerMetricasDashboard();
        $clientes = array_map([self::class, 'jsonCliente'], $this->cxc->clientes());
        $facturas = array_map([self::class, 'jsonFactura'], $this->cxc->facturas());
        $clientePorId = [];
        foreach ($clientes as $c) {
            if ($c !== null) {
                $clientePorId[$c['id']] = $c;
            }
        }
        $facturaPorId = [];
        foreach ($facturas as $f) {
            $facturaPorId[$f['id']] = $f;
        }
        return [
            'hoy'          => date('Y-m-d'),
            'empresa'      => APP_EMPRESA,
            'metricas'     => $metricas,
            'prioridad'    => $metricas['prioridad'],
            'clientes'     => array_values(array_filter($clientes)),
            'facturas'     => $facturas,
            'pagos'        => array_map([self::class, 'jsonPago'], $this->cxc->pagos()),
            'devoluciones' => array_map([self::class, 'jsonDevolucion'], $this->cxc->devoluciones()),
            'clientePorId' => $clientePorId,
            'facturaPorId' => $facturaPorId,
        ];
    }

    // ----------------------------------------------------------------------
    // Respuesta y validación
    // ----------------------------------------------------------------------

    /** Ejecuta $accion y responde JSON; traduce cada tipo de fallo a su código HTTP. */
    private function responder(callable $accion, int $status = 200): never
    {
        try {
            json_response($status, ['ok' => true] + $accion());
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            $msg = trigger_error_message($e);
            if ($msg !== null) {
                json_response(409, ['ok' => false, 'errores' => ['general' => $msg]]);
            }
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                json_response(409, ['ok' => false, 'errores' => ['general' => 'Otra operación estaba usando estos datos. Intenta de nuevo.']]);
            }
            error_log('[ADARA][CxC] ' . $e);
            json_response(500, ['ok' => false, 'errores' => ['general' => 'Error interno al guardar. No se aplicaron cambios.']]);
        } catch (DatabaseException $e) {
            json_response(503, ['ok' => false, 'errores' => ['general' => $e->getMessage()]]);
        } catch (Throwable $e) {
            error_log('[ADARA][CxC] ' . $e);
            json_response(500, ['ok' => false, 'errores' => ['general' => 'Error interno. No se aplicaron cambios.']]);
        }
    }

    private static function usuario(): int
    {
        return (int) current_user()['id'];
    }

    private static function idRequerido(string $campo, string $mensaje): int
    {
        return self::idOpcional($campo) ?? throw new ValidationException([$campo => $mensaje]);
    }

    private static function idOpcional(string $campo): ?int
    {
        $v = $_POST[$campo] ?? '';
        return $v === '' || $v === null ? null : parse_int_range($v, 1, 4294967295);
    }

    private static function idGet(string $campo, string $mensaje): int
    {
        $v = $_GET[$campo] ?? $_POST[$campo] ?? '';
        return parse_int_range($v, 1, 4294967295) ?? throw new ValidationException([$campo => $mensaje]);
    }

    /** Fecha Y-m-d; con $noFutura rechaza fechas posteriores a hoy (cobros y emisión no pueden ser futuros). */
    private static function fecha(string $campo, string $mensaje, bool $noFutura = true): string
    {
        $f = parse_date($_POST[$campo] ?? null);
        if ($f === null || $f < '2000-01-01' || ($noFutura && $f > date('Y-m-d'))) {
            throw new ValidationException([$campo => $noFutura && $f !== null && $f > date('Y-m-d') ? 'La fecha no puede ser futura.' : $mensaje]);
        }
        return $f;
    }

    private static function metodo(): string
    {
        $raw = mb_strtolower(post_string('metodo', 30));
        return self::METODOS_ENTRADA[$raw] ?? throw new ValidationException(['metodo' => 'Método de pago inválido.']);
    }

    private static function texto(string $campo, int $max): ?string
    {
        $v = preg_replace('/\s+/u', ' ', post_string($campo, $max + 20));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** renglones llega como JSON [{cantidad, precio, descripcion}]; vacío o ausente = monto manual. */
    private static function renglonesPost(): array
    {
        $raw = $_POST['renglones'] ?? '';
        if (!is_string($raw) || trim($raw) === '' || $raw === '[]') {
            return [];
        }
        if (strlen($raw) > 60000) {
            throw new ValidationException(['renglones' => 'Demasiados renglones.']);
        }
        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationException(['renglones' => 'Renglones con formato inválido.']);
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new ValidationException(['renglones' => 'Renglones con formato inválido.']);
        }
        return array_map(static fn (mixed $r): array => is_array($r) ? [
            'cantidad'    => is_int($r['cantidad'] ?? null) || is_float($r['cantidad'] ?? null) ? (float) $r['cantidad'] : (string) ($r['cantidad'] ?? ''),
            'precio'      => is_int($r['precio'] ?? null) || is_float($r['precio'] ?? null) ? (float) $r['precio'] : (string) ($r['precio'] ?? ''),
            'descripcion' => is_string($r['descripcion'] ?? null) ? $r['descripcion'] : null,
        ] : [], $data);
    }

    // ----------------------------------------------------------------------
    // Forma JSON para la vista (misma que la carga inicial)
    // ----------------------------------------------------------------------

    private static function firmante(): string
    {
        $u = current_user();
        return is_array($u) && ($u['nombre'] ?? '') !== '' ? (string) $u['nombre'] : APP_EMPRESA;
    }

    private static function dmy(string $iso): string
    {
        return $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
    }

    /** @param array<string, mixed> $a */
    private static function jsonAlertaCampana(array $a): array
    {
        $vencida = $a['estado'] === 'vencida';
        $dias = (int) $a['dias'];
        $detalle = $vencida
            ? ($dias === 0 ? 'Vence hoy · saldo $' . num($a['saldo']) : $dias . ' día' . ($dias === 1 ? '' : 's') . ' de atraso · saldo $' . num($a['saldo']))
            : ($dias === 0 ? 'Vence hoy · saldo $' . num($a['saldo']) : 'Vence en ' . $dias . ' día' . ($dias === 1 ? '' : 's') . ' · saldo $' . num($a['saldo']));
        return [
            'cliente_id'     => (int) $a['cliente_id'],
            'cliente'        => (string) $a['cliente'],
            'factura_id'     => (int) $a['factura_id'],
            'numero'         => (string) $a['numero'],
            'estado'         => (string) $a['estado'],
            'saldo'          => (float) $a['saldo'],
            'dias_atraso'    => (int) $a['dias_atraso'],
            'dias_restantes' => (int) $a['dias_restantes'],
            'dias'           => $dias,
            'detalle'        => $detalle,
            'url'            => url('facturas', ['cliente_id' => (int) $a['cliente_id'], 'factura_id' => (int) $a['factura_id']]),
        ];
    }

    private static function jsonCliente(?array $c): ?array
    {
        return $c === null ? null : [
            'id'        => (int) $c['id'],
            'nombre'    => (string) $c['nombre'],
            'documento' => (string) ($c['documento'] ?? ''),
            'telefono'  => (string) ($c['telefono'] ?? ''),
            'domicilio' => (string) ($c['domicilio'] ?? ''),
            'credito'   => (float) $c['saldo_favor'],
        ];
    }

    /** pagado = dinero recibido; credito = parte cubierta con saldo a favor (ambos suman total_pagado). */
    private static function jsonFactura(array $f): array
    {
        $credito = (float) $f['credito_aplicado'];
        return [
            'id'         => (int) $f['id'],
            'numero'     => (string) $f['numero_factura'],
            'cliente_id' => (int) $f['cliente_id'],
            'cliente'    => (string) $f['cliente'],
            'domicilio'  => (string) ($f['domicilio'] ?? ''),
            'emision'    => (string) $f['fecha_emision'],
            'vence'      => (string) $f['fecha_vencimiento'],
            'monto'      => (float) $f['monto_total'],
            'pagado'     => round((float) $f['total_pagado'] - $credito, 2),
            'devuelto'   => (float) $f['total_devuelto'],
            'credito'    => $credito,
            'saldo'      => (float) $f['saldo_pendiente'],
            'estado'     => (string) $f['estado'],
        ];
    }

    private static function jsonPago(array $p): array
    {
        return [
            'id'         => (int) $p['id'],
            'factura_id' => (int) $p['factura_id'],
            'fecha'      => (string) $p['fecha'],
            'monto'      => (float) $p['monto'],
            'metodo'     => CxCService::METODOS[$p['metodo']] ?? (string) $p['metodo'],
            'referencia' => (string) ($p['referencia'] ?? ''),
            'notas'      => (string) ($p['notas'] ?? ''),
            'tasa'       => $p['tasa_cambio'] !== null && $p['tasa_cambio'] !== '' ? (float) $p['tasa_cambio'] : null,
            'monto_bs'   => $p['monto_bs'] !== null && $p['monto_bs'] !== '' ? (float) $p['monto_bs'] : null,
            'estado'     => (string) ($p['estado'] ?? 'aplicado'),
        ];
    }

    /** @return array{tasa:?string, bs:?string} */
    private static function tasaCambioPost(): array
    {
        $tasaRaw = trim((string) ($_POST['tasa_cambio'] ?? ''));
        $bsRaw = trim((string) ($_POST['monto_bs'] ?? ''));
        if ($tasaRaw === '' && $bsRaw === '') {
            return ['tasa' => null, 'bs' => null];
        }
        $tasa = parse_decimal($tasaRaw, 4, 99999999.9999);
        if ($tasa === null) {
            throw new ValidationException(['tasa_cambio' => 'Tasa inválida: número mayor que 0 con hasta 4 decimales (Bs por 1 USD).']);
        }
        $bs = $bsRaw === '' ? null : parse_monto($bsRaw, 2, 9999999999.99);
        if ($bsRaw !== '' && $bs === null) {
            throw new ValidationException(['monto_bs' => 'Monto en bolívares inválido.']);
        }
        return ['tasa' => $tasa, 'bs' => $bs];
    }

    /** @return list<array<string, mixed>> */
    private static function jsonDevolucionesDe(CxCService $cxc, int $clienteId): array
    {
        $out = [];
        foreach ($cxc->devoluciones() as $d) {
            if ((int) $d['cliente_id'] === $clienteId) {
                $out[] = self::jsonDevolucion($d);
            }
        }
        return $out;
    }

    private static function jsonDevolucion(array $d): array
    {
        return [
            'id'              => (int) $d['id'],
            'fecha'           => (string) $d['fecha'],
            'cliente_id'      => (int) $d['cliente_id'],
            'monto'           => (float) $d['monto'],
            'motivo'          => (string) ($d['motivo'] ?? ''),
            'factura_id'      => $d['factura_origen_id'] !== null ? (int) $d['factura_origen_id'] : null,
            'destino'         => ['origen' => 'origen', 'otra_factura' => 'otra', 'credito_pendiente' => 'credito'][$d['destino']],
            'factura_destino' => $d['factura_aplicada_id'] !== null ? (int) $d['factura_aplicada_id'] : null,
            'estado'          => (string) $d['estado'],
        ];
    }
}
