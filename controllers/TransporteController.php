<?php
declare(strict_types=1);

/** Logística y transporte: ficha del conductor, rutas, fletes y cierres. */
final class TransporteController
{
    private TransporteService $log;

    public function __construct()
    {
        $this->log = new TransporteService(get_db());
    }

    public function index(): void
    {
        view('transporte', [
            'hoy'          => date('Y-m-d'),
            'metricas'     => $this->log->obtenerMetricas(),
            'transportistas' => array_map([self::class, 'jsonTransportista'], $this->log->transportistas()),
            'productores'  => array_map([self::class, 'jsonProductor'], $this->log->productoresDisponibles()),
            'vehiculos'    => TransporteService::VEHICULOS,
            'tiposPago'    => TransporteService::TIPOS_PAGO,
            'puedeCrear'   => has_permiso(current_user(), 'transportistas', 'crear'),
            'puedeEditar'  => has_permiso(current_user(), 'transportistas', 'editar'),
        ]);
    }

    public function storeTransportista(): void
    {
        $this->responder(function (): array {
            $nombre = mb_strtoupper(preg_replace('/\s+/u', ' ', post_string('nombre', 140)));
            if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
                throw new ValidationException(['nombre' => 'El nombre es obligatorio (2 a 120 caracteres).']);
            }
            $docRaw = post_documento_compuesto('documento', 20);
            $documento = $docRaw === '' ? null : normalize_documento($docRaw);
            if ($documento === null) {
                throw new ValidationException(['documento' => 'El documento es obligatorio y único. Ej.: V-12345678.']);
            }
            $vehiculos = self::vehiculosPost();
            $id = $this->log->guardarTransportista([
                'nombre'     => $nombre,
                'documento'  => $documento,
                'telefono'   => post_telefono_compuesto('telefono', 20) ?: null,
                'modalidad'  => post_string('modalidad', 20),
                'vehiculos'  => $vehiculos,
            ], self::idOpcional('id'), (int) current_user()['id']);
            return [
                'mensaje'       => 'Transportista guardado.',
                'transportista' => self::jsonTransportista($this->log->transportista($id)),
                'metricas'      => $this->log->obtenerMetricas(),
            ];
        }, 201);
    }

    public function guardarRuta(): void
    {
        $this->responder(function (): array {
            $trId = self::idReq('transportista_id', 'Selecciona un transportista.');
            $zona = preg_replace('/\s+/u', ' ', post_string('nombre_zona', 80));
            if (mb_strlen($zona) < 2) {
                throw new ValidationException(['nombre_zona' => 'Indica el nombre de la zona (mínimo 2 caracteres).']);
            }
            $precio = parse_monto(post_string('precio_viaje', 20), 2, 999999.99, true);
            if ($precio === null) {
                throw new ValidationException(['precio_viaje' => 'Precio de viaje inválido.']);
            }
            $nVeh = parse_int_range($_POST['vehiculos_asignados'] ?? '1', 1, 99)
                ?? throw new ValidationException(['vehiculos_asignados' => 'Indica cuántos vehículos se asignan a la ruta.']);
            $ids = self::idsLista('productor_ids');
            $rutaId = $this->log->guardarRuta(
                $trId,
                self::idOpcional('ruta_id'),
                $zona,
                $precio,
                $nVeh,
                $ids,
                (int) current_user()['id']
            );
            return [
                'mensaje'       => 'Ruta armada.',
                'ruta_id'       => $rutaId,
                'transportista' => self::jsonTransportista($this->log->transportista($trId)),
                'metricas'      => $this->log->obtenerMetricas(),
            ];
        }, 201);
    }

    public function registrarFlete(): void
    {
        $this->responder(function (): array {
            $rutaId = self::idReq('ruta_id', 'Selecciona una ruta.');
            $fecha = parse_date($_POST['fecha'] ?? '');
            if ($fecha === null || $fecha > date('Y-m-d')) {
                throw new ValidationException(['fecha' => 'Fecha inválida (no puede ser futura).']);
            }
            $res = $this->log->registrarFlete($rutaId, $fecha, (int) current_user()['id']);
            $fletes = $this->log->fletes();
            $flete = null;
            foreach ($fletes as $f) {
                if ((int) $f['id'] === $res['id']) {
                    $flete = $f;
                    break;
                }
            }
            $trId = (int) ($flete['transportista_id'] ?? 0);
            $out = [
                'mensaje'       => 'Viaje del día registrado. Litros tomados de Control de Leche: ' . num($res['litros']) . ' L.',
                'flete'         => $flete !== null ? self::jsonFlete($flete) : null,
                'litros'        => (float) $res['litros'],
                'costo_diario'  => (float) $res['costo_diario'],
                'costo_litro'   => (float) $res['costo_litro'],
                'transportista' => self::jsonTransportista($this->log->transportista($trId)),
                'metricas'      => $this->log->obtenerMetricas(),
            ];
            if ($res['warning'] !== null) {
                $out['warning'] = $res['warning'];
            }
            return $out;
        }, 201);
    }

    public function cerrarSemana(): void
    {
        $this->responder(function (): array {
            $trId = self::idReq('transportista_id', 'Selecciona un transportista.');
            $pagoId = $this->log->cerrarSemana($trId, (int) current_user()['id']);
            return [
                'mensaje'       => 'Cierre semanal generado.',
                'pago_id'       => $pagoId,
                'transportista' => self::jsonTransportista($this->log->transportista($trId)),
                'metricas'      => $this->log->obtenerMetricas(),
            ];
        }, 201);
    }

    public function registrarPago(): void
    {
        $this->responder(function (): array {
            $pagoId = self::idReq('pago_id', 'Selecciona un cierre.');
            $fecha = parse_date($_POST['fecha'] ?? '');
            if ($fecha === null || $fecha > date('Y-m-d')) {
                throw new ValidationException(['fecha' => 'Fecha inválida (no puede ser futura).']);
            }
            $monto = parse_monto(post_string('monto', 20), 2, 999999.99);
            if ($monto === null) {
                throw new ValidationException(['monto' => 'Indica el monto del pago o adelanto.']);
            }
            $tipo = post_string('tipo', 20);
            $desc = preg_replace('/\s+/u', ' ', post_string('descripcion_mercancia', 255));
            $this->log->registrarPagoCierre($pagoId, [
                'fecha'                   => $fecha,
                'monto'                   => $monto,
                'tipo'                    => $tipo,
                'descripcion_mercancia'   => $desc === '' ? null : $desc,
            ], (int) current_user()['id']);
            $d = $this->log->detallePago($pagoId);
            $trId = (int) $d['pago']['transportista_id'];
            return [
                'mensaje'       => 'Pago registrado.',
                'detalle'       => self::jsonDetalle($d),
                'transportista' => self::jsonTransportista($this->log->transportista($trId)),
                'metricas'      => $this->log->obtenerMetricas(),
            ];
        }, 201);
    }

    public function detalleCierre(): void
    {
        $this->responder(function (): array {
            $pagoId = parse_int_range($_GET['pago_id'] ?? ($_POST['pago_id'] ?? ''), 1, 4294967295)
                ?? throw new ValidationException(['pago_id' => 'Cierre inválido.']);
            return [
                'detalle' => self::jsonDetalle($this->log->detallePago($pagoId)),
            ];
        });
    }

    public function analizarRuta(): void
    {
        $this->responder(function (): array {
            $trId = self::idReq('transportista_id', 'Selecciona un transportista.');
            $ids = self::idsLista('productor_ids');
            return [
                'analisis' => $this->log->analizarRuta($trId, $ids),
            ];
        });
    }

    public function reabrirSemana(): void
    {
        $this->responder(function (): array {
            $pagoId = parse_int_range($_GET['pago_id'] ?? ($_POST['pago_id'] ?? ''), 1, 4294967295)
                ?? throw new ValidationException(['pago_id' => 'Cierre inválido.']);
            $trId = $this->log->reabrirSemana($pagoId, (int) current_user()['id']);
            return [
                'mensaje'       => 'Semana reabierta. Los viajes volvieron a pendiente.',
                'transportista' => self::jsonTransportista($this->log->transportista($trId)),
                'metricas'      => $this->log->obtenerMetricas(),
            ];
        });
    }

    public function facturaPdf(): void
    {
        try {
            $id = parse_int_range($_GET['pago_id'] ?? '', 1, 4294967295)
                ?? throw new ValidationException(['pago_id' => 'Cierre inválido.']);
            $d = $this->log->detallePago($id);
            header('Content-Disposition: inline; filename="factura_transporte_' . $id . '.html"');
            view('transporte_factura', [
                'hoy'           => date('Y-m-d'),
                'empresa'       => APP_EMPRESA,
                'pago'          => $d['pago'],
                'transportista' => $d['transportista'],
                'fletes'        => $d['fletes'],
                'rutas'         => $d['rutas'],
                'adelantos'     => $d['adelantos'],
                'bruto'         => $d['bruto'],
                'pagado'        => $d['pagado'],
                'neto'          => $d['neto'],
                'tiposPago'     => TransporteService::TIPOS_PAGO,
            ]);
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('transportistas');
        }
    }

    public function cambiarEstado(): void
    {
        $this->responder(function (): array {
            $id = self::idReq('id', 'Transportista inválido.');
            $estado = post_string('estado', 12);
            $this->log->cambiarEstado($id, $estado);
            return [
                'mensaje'       => $estado === 'activo' ? 'Transportista activado.' : 'Transportista desactivado.',
                'transportista' => self::jsonTransportista($this->log->transportista($id)),
                'metricas'      => $this->log->obtenerMetricas(),
            ];
        });
    }

    public function notariaPdf(): void
    {
        $fecha = parse_date($_GET['semana'] ?? null) ?? date('Y-m-d');
        $semana = CierreSemanal::semanaDe($fecha, CierreSemanal::DIA_CIERRE_DEFAULT);
        $personas = [];
        foreach ($this->log->transportistas() as $t) {
            if (($t['estado'] ?? 'activo') !== 'inactivo') {
                $personas[] = ['nombre' => (string) $t['nombre']];
            }
        }
        usort($personas, static fn (array $a, array $b): int => $a['nombre'] <=> $b['nombre']);
        $dias = [];
        $d = new DateTimeImmutable($semana);
        for ($i = 0; $i < 7; $i++) {
            $dias[] = $d->modify('+' . $i . ' days')->format('Y-m-d');
        }
        header('Content-Disposition: inline; filename="notaria_transportistas_' . $semana . '.html"');
        view('notaria_hoja', [
            'titulo'    => 'NOTARÍA — TRANSPORTISTAS',
            'semana'    => $semana,
            'semanaFin' => CierreSemanal::finDe($semana),
            'dias'      => $dias,
            'personas'  => $personas,
            'impreso'   => date('Y-m-d'),
            'volver'    => url('transportistas'),
            'rutaBase'  => 'transporte_notaria',
        ]);
    }

    public function directorioPdf(): void
    {
        header('Content-Disposition: inline; filename="directorio_transportistas.html"');
        view('transporte_directorio', [
            'hoy'            => date('Y-m-d'),
            'empresa'        => APP_EMPRESA,
            'transportistas' => array_map([self::class, 'jsonTransportista'], $this->log->transportistas()),
        ]);
    }

    public function cxpPdf(): void
    {
        $rep = $this->log->reporteCxp();
        header('Content-Disposition: inline; filename="cxp_transporte.html"');
        view('transporte_cxp', [
            'hoy'     => date('Y-m-d'),
            'empresa' => APP_EMPRESA,
            'filas'   => $rep['filas'],
            'totales' => $rep['totales'],
        ]);
    }

    private function responder(callable $accion, int $status = 200): never
    {
        try {
            json_response($status, ['ok' => true] + $accion());
        } catch (ValidationException $e) {
            json_response(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            error_log('[ADARA][Transporte] ' . $e);
            json_response(500, ['ok' => false, 'errores' => ['general' => 'Error interno. No se aplicaron cambios.']]);
        } catch (Throwable $e) {
            error_log('[ADARA][Transporte] ' . $e);
            json_response(500, ['ok' => false, 'errores' => ['general' => 'Error interno. No se aplicaron cambios.']]);
        }
    }

    private static function idReq(string $campo, string $msg): int
    {
        return parse_int_range($_POST[$campo] ?? '', 1, 4294967295)
            ?? throw new ValidationException([$campo => $msg]);
    }

    private static function idOpcional(string $campo): ?int
    {
        $v = $_POST[$campo] ?? '';
        return $v === '' || $v === null ? null : parse_int_range($v, 1, 4294967295);
    }

    /** @return list<int> */
    private static function idsLista(string $campo): array
    {
        $raw = $_POST[$campo] ?? '';
        if (is_array($raw)) {
            $data = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            try {
                $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $data = preg_split('/\s*,\s*/', $raw) ?: [];
            }
        } else {
            $data = [];
        }
        if (!is_array($data)) {
            throw new ValidationException(['productores' => 'Lista de productores inválida.']);
        }
        $ids = [];
        foreach ($data as $v) {
            $n = parse_int_range($v, 1, 4294967295);
            if ($n !== null) {
                $ids[] = $n;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return list<array<string, mixed>> */
    private static function vehiculosPost(): array
    {
        $raw = $_POST['vehiculos'] ?? [];
        if (is_string($raw) && $raw !== '') {
            try {
                $raw = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ValidationException(['vehiculos' => 'Lista de vehículos inválida.']);
            }
        }
        if (!is_array($raw)) {
            throw new ValidationException(['vehiculos' => 'Registra al menos un vehículo.']);
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_array($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $t */
    private static function jsonTransportista(array $t): array
    {
        $vehiculos = array_map([self::class, 'jsonVehiculo'], $t['vehiculos'] ?? []);
        $n = count($vehiculos);
        $cap = 0.0;
        $capMax = 0.0;
        foreach ($vehiculos as $v) {
            $cap += (float) $v['limite_carga_kg'];
            if ((float) $v['limite_carga_kg'] > $capMax) {
                $capMax = (float) $v['limite_carga_kg'];
            }
        }
        $primero = $vehiculos[0] ?? null;
        return [
            'id'                 => (int) $t['id'],
            'nombre'             => (string) $t['nombre'],
            'documento'          => (string) ($t['documento'] ?? ''),
            'telefono'           => (string) ($t['telefono'] ?? ''),
            'modalidad'          => (string) ($t['modalidad'] ?? ($n > 1 ? 'cooperativa' : 'individual')),
            'estado'             => (string) $t['estado'],
            'cantidad_vehiculos' => $n,
            'capacidad_total'    => $cap,
            'capacidad_max'      => $capMax,
            'placa'              => (string) ($primero['placa'] ?? ''),
            'tipo_vehiculo'      => (string) ($primero['tipo_vehiculo'] ?? ''),
            'vehiculos'          => $vehiculos,
            'rutas'              => array_map([self::class, 'jsonRuta'], $t['rutas'] ?? []),
            'fletes'             => array_map([self::class, 'jsonFlete'], $t['fletes'] ?? []),
            'pagos'              => array_map([self::class, 'jsonPago'], $t['pagos'] ?? []),
        ];
    }

    /** @param array<string, mixed> $v */
    private static function jsonVehiculo(array $v): array
    {
        return [
            'id'              => (int) ($v['id'] ?? 0),
            'tipo_vehiculo'   => (string) ($v['tipo_vehiculo'] ?? 'otro'),
            'marca'           => (string) ($v['marca'] ?? ''),
            'modelo'          => (string) ($v['modelo'] ?? ''),
            'color'           => (string) ($v['color'] ?? ''),
            'placa'           => (string) ($v['placa'] ?? ''),
            'limite_carga_kg' => (float) ($v['limite_carga_kg'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $r */
    private static function jsonRuta(array $r): array
    {
        return [
            'id'               => (int) $r['id'],
            'transportista_id' => (int) $r['transportista_id'],
            'nombre_zona'      => (string) $r['nombre_zona'],
            'precio_viaje'        => (float) $r['precio_viaje'],
            'vehiculos_asignados' => max(1, (int) ($r['vehiculos_asignados'] ?? 1)),
            'costo_semanal'       => (float) Caja::desdeCentavos(
                TransporteService::costoSemanalCentavos((string) $r['precio_viaje'], (int) ($r['vehiculos_asignados'] ?? 1))
            ),
            'costo_diario'        => (float) Caja::desdeCentavos(
                TransporteService::costoDiarioCentavos((string) $r['precio_viaje'], (int) ($r['vehiculos_asignados'] ?? 1))
            ),
            'estado'           => (string) $r['estado'],
            'productores'      => array_map(static fn (array $p): array => [
                'id'     => (int) $p['id'],
                'nombre' => (string) $p['nombre'],
            ], $r['productores'] ?? []),
        ];
    }

    /** @param array<string, mixed> $f */
    private static function jsonFlete(array $f): array
    {
        $litros = (float) $f['litros_recolectados'];
        $diario = (float) $f['costo_flete'];
        $porL = $litros > 0 ? $diario / $litros : 0.0;
        return [
            'id'               => (int) $f['id'],
            'transportista_id' => (int) $f['transportista_id'],
            'ruta_id'          => (int) $f['ruta_id'],
            'nombre_zona'      => (string) ($f['nombre_zona'] ?? ''),
            'fecha'            => (string) $f['fecha'],
            'litros'           => $litros,
            'costo'            => $diario,
            'costo_diario'     => $diario,
            'costo_litro'      => $porL,
            'alerta_flete'     => $porL > TransporteService::COSTO_LITRO_MAX,
            'pago_id'          => $f['pago_id'] !== null ? (int) $f['pago_id'] : null,
        ];
    }

    /** @param array<string, mixed> $p */
    private static function jsonPago(array $p): array
    {
        return [
            'id'               => (int) $p['id'],
            'transportista_id' => (int) $p['transportista_id'],
            'fecha_inicio'     => (string) $p['fecha_inicio'],
            'fecha_fin'        => (string) $p['fecha_fin'],
            'total_litros'     => (float) $p['total_litros'],
            'total_pagar'      => (float) $p['total_pagar'],
            'estado'           => (string) $p['estado'],
            'pagado_usd'       => (float) ($p['pagado_usd'] ?? 0),
            'neto_usd'         => (float) Caja::desdeCentavos(
                Caja::aCentavos($p['total_pagar'] ?? 0) - Caja::aCentavos($p['pagado_usd'] ?? 0)
            ),
        ];
    }

    /** @param array<string, mixed> $d */
    private static function jsonDetalle(array $d): array
    {
        $p = $d['pago'];
        return [
            'pago'          => self::jsonPago($p + ['pagado_usd' => $d['pagado']]),
            'transportista' => [
                'id'                 => (int) ($d['transportista']['id'] ?? 0),
                'nombre'             => (string) ($d['transportista']['nombre'] ?? ''),
                'documento'          => (string) ($d['transportista']['documento'] ?? ''),
                'cantidad_vehiculos' => count($d['transportista']['vehiculos'] ?? []),
                'vehiculos'          => array_map([self::class, 'jsonVehiculo'], $d['transportista']['vehiculos'] ?? []),
                'modalidad'          => (string) ($d['transportista']['modalidad'] ?? 'individual'),
                'placa'              => (string) (($d['transportista']['vehiculos'][0]['placa'] ?? '')),
            ],
            'rutas'     => array_map(static fn (array $r): array => [
                'nombre_zona'         => (string) $r['nombre_zona'],
                'precio_viaje'        => (float) $r['precio_viaje'],
                'vehiculos_asignados' => (int) ($r['vehiculos_asignados'] ?? 1),
                'costo_semanal'       => (float) ($r['costo_semanal'] ?? $r['precio_viaje']),
                'litros'              => (float) $r['litros'],
            ], $d['rutas'] ?? []),
            'adelantos' => array_map(static fn (array $a): array => [
                'id'                      => (int) $a['id'],
                'fecha'                   => (string) $a['fecha'],
                'monto'                   => (float) $a['monto'],
                'tipo'                    => (string) $a['tipo'],
                'descripcion_mercancia'   => (string) ($a['descripcion_mercancia'] ?? ''),
            ], $d['adelantos'] ?? []),
            'bruto'  => (float) $d['bruto'],
            'pagado' => (float) $d['pagado'],
            'neto'   => (float) $d['neto'],
        ];
    }

    /** @param array<string, mixed> $p */
    private static function jsonProductor(array $p): array
    {
        return [
            'id'        => (int) $p['id'],
            'nombre'    => (string) $p['nombre'],
            'documento' => (string) ($p['documento'] ?? ''),
        ];
    }
}
