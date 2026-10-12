<?php
declare(strict_types=1);

final class ProductoresController
{
    private const DEFAULTS = [
        'nombre' => '', 'documento' => '', 'telefono' => '', 'correo' => '', 'direccion' => '',
        'precio_litro_usd' => '', 'credito_dias' => '7', 'dia_cierre' => '5', 'grupo_cierre' => '', 'tolerancia_pago' => 'medio', 'activo' => '1', 'motivo_precio' => '',
    ];

    /** Páginas a las que puede volver el registro de entregas; el valor nunca se usa sin pasar por esta lista. */
    private const RETORNOS_ENTREGA = ['productores_entrega', 'productor_control', 'productores_control_semanal', 'control_leche'];

    private const MAX_CELDAS_SEMANA = 3500;
    private const MAX_DIAS_PERIODO = 366;

    private Productor $productores;
    private EntregaLeche $entregas;
    private CierreSemanal $cierres;
    private PagoProductor $pagos;
    private Caja $caja;

    public function __construct()
    {
        $db = get_db();
        $this->productores = new Productor($db);
        $this->entregas = new EntregaLeche($db);
        $this->cierres = new CierreSemanal($db);
        $this->pagos = new PagoProductor($db);
        $this->caja = new Caja($db);
    }

    // ----- Lista -----------------------------------------------------------

    public function index(): void
    {
        $q = self::queryString('q', 60);
        $estado = match ($_GET['estado'] ?? '') {
            'activo'   => 'activos',
            'inactivo' => 'inactivos',
            default    => 'todos',
        };
        $orden = self::queryString('orden', 10);
        if (!isset(Productor::ORDENES[$orden])) {
            $orden = 'nombre';
        }
        $diaCierre = parse_int_range($_GET['dia_cierre'] ?? null, 1, 7);
        $user = current_user();

        view('productores_lista', [
            'productores'   => $this->productores->listar($q, $estado, $orden, $diaCierre),
            'kpis'          => $this->productores->kpis(),
            'q'             => $q,
            'estado'        => $estado,
            'orden'         => $orden,
            'diaCierre'     => $diaCierre,
            'puedeCrear'    => has_permiso($user, 'productores', 'crear'),
            'puedeEditar'   => has_permiso($user, 'productores', 'editar'),
            'puedeArchivar' => has_permiso($user, 'productores', 'anular'),
        ]);
    }

    // ----- Cuenta individual -----------------------------------------------

    public function control(): void
    {
        $productor = $this->findOr404();
        $id = (int) $productor['id'];
        [$old, $errors] = form_state();

        $hoy = date('Y-m-d');
        $diaCierre = CierreSemanal::diaCierreValido($productor['dia_cierre']);
        $semanaActual = CierreSemanal::semanaDe($hoy, $diaCierre);
        $semana = self::semanaSolicitada($_GET['semana'] ?? null, $diaCierre);
        $semanaFin = CierreSemanal::finDe($semana);

        [$histDesde, $histHasta] = self::rango($_GET['desde'] ?? null, $_GET['hasta'] ?? null, date('Y-m-01'));
        $cierreDesde = parse_date(self::queryString('cierre_desde', 10));
        $cierreHasta = parse_date(self::queryString('cierre_hasta', 10));

        view('productor_control', [
            'productor'         => $productor,
            'cuenta'            => $this->productores->cuenta($id),
            'hoy'               => $hoy,
            'semana'            => $semana,
            'semanaFin'         => $semanaFin,
            'semanaActual'      => $semanaActual,
            'entregasSemana'    => $this->entregas->deProductorEnRango($id, $semana, $semanaFin),
            'cierreSemana'      => $this->cierres->findPorSemana($id, $semana),
            'resumenSemana'     => $this->resumenSemana($id, $semana),
            'pagosSemana'       => $this->pagos->deSemana($id, $semana),
            'semanasPago'       => self::semanasPago($semanaActual, 8),
            'tiposPago'         => PagoProductor::ETIQUETAS,
            'proyeccion'        => $this->proyeccion($id, $semanaActual, (string) $productor['precio_litro_usd']),
            'cierres'           => $this->cierres->listarPorProductor($id, $cierreDesde, $cierreHasta),
            'historialEntregas' => $this->entregas->deProductorEnRango($id, $histDesde, $histHasta),
            'historialPagos'    => $this->pagos->historial($id, $histDesde, $histHasta),
            'histDesde'         => $histDesde,
            'histHasta'         => $histHasta,
            'esAdmin'           => self::esAdmin(),
            'tasaVigente'       => TasaCambio::vigente(get_db()),
            'old'               => $old,
            'errors'            => $errors,
        ]);
    }

    // ----- Planilla semanal masiva -----------------------------------------

    /**
     * La planilla es una grilla calendario sábado-viernes. Saldo previo, pagos y estado de cierre
     * de cada fila salen del ciclo propio del productor que contiene el primer día de la grilla.
     */
    public function controlSemanal(): void
    {
        $semana = self::semanaSolicitada($_GET['semana'] ?? null, CierreSemanal::DIA_CIERRE_DEFAULT);
        $semanaFin = CierreSemanal::finDe($semana);
        $dias = self::diasDeSemana($semana);

        $matriz = $this->entregas->matrizSemana($semana, $semanaFin);
        $saldosPrevios = $this->cierres->pendientesAntesTodos($semana);
        $pagosSemana = $this->pagos->totalesSemanaTodos($semana);
        $cerrados = $this->cierres->productoresCerrados($semana);
        $vacio = array_fill_keys(PagoProductor::RESUMEN, '0.00');

        $filas = [];
        foreach ($this->productores->activos() as $p) {
            $pid = (int) $p['id'];
            $litrosDia = [];
            $litros = 0;
            $bruto = 0;
            foreach ($dias as $fecha) {
                $celda = $matriz[$pid][$fecha] ?? null;
                $litrosDia[$fecha] = $celda['litros'] ?? null;
                $litros += (int) round((float) ($celda['litros'] ?? 0) * 1000);
                $bruto += Caja::aCentavos($celda['subtotal_usd'] ?? 0);
            }
            $pagos = $pagosSemana[$pid] ?? $vacio;
            $abonos = Caja::aCentavos($pagos['mercancia']) + Caja::aCentavos($pagos['pm_trans']) + Caja::aCentavos($pagos['efectivo']);
            $saldoPrevio = $saldosPrevios[$pid] ?? null;

            $filas[] = [
                'id'            => $pid,
                'nombre'        => $p['nombre'],
                'documento'     => $p['documento'],
                'precio'        => (string) $p['precio_litro_usd'],
                'dia_cierre'    => (int) $p['dia_cierre'],
                'ciclo_inicio'  => CierreSemanal::semanaDe($semana, (int) $p['dia_cierre']),
                'cerrada'       => isset($cerrados[$pid]),
                'litros_dia'    => $litrosDia,
                'total_litros'  => number_format($litros / 1000, 3, '.', ''),
                'total_pagar'   => Caja::desdeCentavos($bruto),
                'saldo_previo'  => $saldoPrevio,
                'mercancia'     => $pagos['mercancia'],
                'pm_trans'      => $pagos['pm_trans'],
                'efectivo'      => $pagos['efectivo'],
                'total_abonos'  => Caja::desdeCentavos($abonos),
                'pendiente'     => Caja::desdeCentavos($bruto + Caja::aCentavos($saldoPrevio ?? 0) - $abonos),
            ];
        }

        $diario = (new ControlLecheDiario(get_db()))->porRango($semana, $semanaFin);
        $totDia = array_fill_keys($dias, 0.0);
        foreach ($filas as $fila) {
            foreach ($fila['litros_dia'] as $fecha => $l) {
                $totDia[$fecha] += (float) $l;
            }
        }
        $netaDia = [];
        $totNeta = 0.0;
        foreach ($dias as $fecha) {
            $netaDia[$fecha] = ControlLecheDiario::neta((string) $totDia[$fecha], $diario[$fecha] ?? null);
            $totNeta += (float) $netaDia[$fecha];
        }

        view('productores_control_semanal', [
            'semana'       => $semana,
            'semanaFin'    => $semanaFin,
            'semanaActual' => CierreSemanal::semanaDe(date('Y-m-d'), CierreSemanal::DIA_CIERRE_DEFAULT),
            'dias'         => $dias,
            'hoy'          => date('Y-m-d'),
            'filas'        => $filas,
            'diario'       => $diario,
            'netaDia'      => $netaDia,
            'totNeta'      => number_format($totNeta, 3, '.', ''),
        ]);
    }

    /**
     * Guardado de la planilla semanal (masivo o por fila vía fetch): todas las celdas entran o ninguna.
     * Una celda con entrega previa en semana abierta sobrescribe los litros; las celdas sin cambio se ignoran.
     */
    public function semanaStore(): void
    {
        $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        $semana = parse_date($_POST['semana'] ?? '');
        if ($semana === null || !CierreSemanal::esInicioDeCiclo($semana, CierreSemanal::DIA_CIERRE_DEFAULT)) {
            $this->responderSemana($ajax, false, ['Semana inválida.'], []);
        }
        $semanaFin = CierreSemanal::finDe($semana);
        $hoy = date('Y-m-d');
        $volver = ['semana' => $semana];

        $raw = is_array($_POST['litros'] ?? null) ? $_POST['litros'] : [];
        $tieneDiario = isset($_POST['diario']) && is_array($_POST['diario']);
        if ($raw === [] && !$tieneDiario) {
            $this->responderSemana($ajax, false, ['No hay litros para guardar.'], $volver);
        }

        $errors = [];
        $lote = [];
        $celdas = 0;
        foreach ($raw as $pidRaw => $dias) {
            $pid = parse_int_range($pidRaw, 1, PHP_INT_MAX);
            if ($pid === null || !is_array($dias)) {
                $errors[] = 'Fila de productor inválida.';
                continue;
            }
            foreach ($dias as $fecha => $valor) {
                if (++$celdas > self::MAX_CELDAS_SEMANA) {
                    $this->responderSemana($ajax, false, ['Demasiadas celdas en una sola carga.'], $volver);
                }
                if (!is_string($valor) || trim($valor) === '' || (float) str_replace(',', '.', $valor) == 0.0) {
                    continue;
                }
                $fecha = parse_date((string) $fecha);
                $litros = parse_decimal($valor, 3, 100000);
                if ($fecha === null || $fecha < $semana || $fecha > $semanaFin || $fecha > $hoy) {
                    $errors[] = "Productor #$pid: fecha fuera de la semana o futura.";
                } elseif ($litros === null) {
                    $errors[] = "Productor #$pid, $fecha: litros inválidos (hasta 3 decimales).";
                } else {
                    $lote[$pid][$fecha] = $litros;
                }
            }
        }
        if ($errors) {
            $this->responderSemana($ajax, false, $errors, $volver);
        }
        if ($lote === [] && !$tieneDiario) {
            $this->responderSemana($ajax, false, ['No hay litros para guardar.'], $volver);
        }

        $userId = current_user()['id'];
        try {
            $resultado = Database::transaction(function () use ($lote, $userId, $semana, $semanaFin): array {
                $errors = [];
                $guardadas = [];
                ksort($lote);
                foreach ($lote as $pid => $dias) {
                    foreach ($dias as $fecha => $litros) {
                        try {
                            $r = $this->guardarEntrega($pid, $fecha, $litros, $userId);
                            $guardadas[] = ['productor_id' => $pid, 'fecha' => $fecha, 'litros' => $litros, 'accion' => $r['accion']];
                        } catch (ValidationException $e) {
                            foreach ($e->errors as $msg) {
                                $errors[] = "Productor #$pid, $fecha: $msg";
                            }
                        }
                    }
                }
                try {
                    $this->guardarResumenDiario($semana, $semanaFin, $userId);
                } catch (ValidationException $e) {
                    $errors = array_merge($errors, array_values($e->errors));
                }
                if ($errors) {
                    throw new ValidationException($errors);
                }
                return $guardadas;
            });
        } catch (ValidationException $e) {
            $this->responderSemana($ajax, false, $e->errors, $volver);
        } catch (PDOException $e) {
            if (trigger_error_message($e) !== null) {
                $this->responderSemana($ajax, false, ['La semana ya está cerrada para al menos uno de los productores.'], $volver);
            }
            if (duplicate_key($e) === 'uq_entregas_productor_fecha') {
                $this->responderSemana($ajax, false, ['Otro usuario registró una de estas entregas. Recarga la planilla.'], $volver);
            }
            throw $e;
        }

        $cuenta = array_count_values(array_column($resultado, 'accion')) + ['creada' => 0, 'actualizada' => 0, 'sin_cambios' => 0];
        $msg = sprintf(
            'Guardado: %d entrega(s) nuevas, %d sobrescrita(s), %d sin cambios.',
            $cuenta['creada'],
            $cuenta['actualizada'],
            $cuenta['sin_cambios']
        );
        if ($tieneDiario) {
            $msg .= ' Resumen diario de leche actualizado.';
        }
        $this->responderSemana($ajax, true, [$msg], $volver, $resultado);
    }

    private function guardarResumenDiario(string $semana, string $semanaFin, int $userId): void
    {
        $raw = $_POST['diario'] ?? null;
        if (!is_array($raw)) {
            return;
        }
        $modelo = new ControlLecheDiario(get_db());
        foreach ($raw as $fechaRaw => $vals) {
            $fecha = parse_date((string) $fechaRaw);
            if ($fecha === null || $fecha < $semana || $fecha > $semanaFin) {
                throw new ValidationException(['diario' => 'Fecha de resumen diario fuera de la semana.']);
            }
            if (!is_array($vals)) {
                continue;
            }
            $modelo->upsert($fecha, $vals, $userId);
        }
    }

    /** JSON para fetch (el modal guarda fila por fila); flash + redirect para el submit tradicional. */
    private function responderSemana(bool $ajax, bool $ok, array $mensajes, array $query, array $guardadas = []): never
    {
        if ($ajax) {
            http_response_code($ok ? 200 : 422);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => $ok, 'mensajes' => array_values($mensajes), 'guardadas' => $guardadas], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }
        if ($ok) {
            flash('success', implode(' ', $mensajes));
            redirect('productores_control_semanal', $query);
        }
        $this->fallar($mensajes, 'productores_control_semanal', $query);
    }

    // ----- Facturas masivas ------------------------------------------------

    public function facturasMasivas(): void
    {
        [$desde, $hasta] = self::rango($_GET['desde'] ?? null, $_GET['hasta'] ?? null, date('Y-m-01'));
        $grupoRaw = trim(self::queryString('grupo', 50));
        $grupo = match ($grupoRaw) {
            ''     => null,
            '-'    => '',
            default => $grupoRaw,
        };

        view('productores_facturas_masivas', [
            'desde'       => $desde,
            'hasta'       => $hasta,
            'grupo'       => $grupoRaw,
            'grupos'      => $this->productores->gruposCierre(),
            'productores' => $this->entregas->resumenPeriodo($desde, $hasta, $grupo),
        ]);
    }

    /**
     * ZIP de liquidaciones de los productores seleccionados (PDF o PNG).
     * POST: ids[], desde, hasta, formato=pdf|img.
     */
    public function facturasZip(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $ids = array_slice(array_values(array_unique(array_filter(array_map(
            static fn ($v) => parse_int_range($v, 1, PHP_INT_MAX),
            (array) ($_POST['ids'] ?? [])
        )))), 0, 200);
        [$desde, $hasta] = self::rango($_POST['desde'] ?? null, $_POST['hasta'] ?? null, date('Y-m-01'));
        $formato = ($_POST['formato'] ?? '') === 'img' ? 'img' : 'pdf';
        $volver = ['desde' => $desde, 'hasta' => $hasta];
        if (isset($_POST['grupo']) && is_string($_POST['grupo']) && $_POST['grupo'] !== '') {
            $volver['grupo'] = mb_substr($_POST['grupo'], 0, 50);
        }

        if ($ids === []) {
            flash('error', 'Selecciona al menos un productor para descargar el ZIP.');
            redirect('productores_facturas_masivas', $volver);
        }

        $productores = array_values(array_filter(array_map(fn (int $pid): ?array => $this->productores->find($pid), $ids)));
        if ($productores === []) {
            flash('error', 'Ningún productor seleccionado es válido.');
            redirect('productores_facturas_masivas', $volver);
        }

        $tasa = TasaCambio::vigenteEn(get_db(), date('Y-m-d H:i:s'));
        $documentos = [];
        foreach ($productores as $productor) {
            $documentos[] = $this->datosLiquidacion($productor, $desde, $hasta);
        }

        $tasaValor = is_array($tasa) ? ($tasa['tasa'] ?? null) : null;
        try {
            if ($formato === 'pdf') {
                LiquidacionArchivo::consolidado($documentos, $desde, $hasta, $tasaValor);
            }
            LiquidacionArchivo::zip($documentos, $desde, $hasta, $formato, $tasaValor);
        } catch (Throwable $e) {
            error_log('[ADARA] liquidaciones masivas: ' . $e->getMessage());
            flash('error', $formato === 'pdf'
                ? 'No se pudo generar el PDF. Usa «Imprimir todas» para exportarlo desde el navegador.'
                : 'No se pudo generar el ZIP. Revisa que PHP tenga ZipArchive o espacio temporal.');
            redirect('productores_facturas_masivas', $volver);
        }
    }

    // ----- Crear -----------------------------------------------------------

    public function create(): void
    {
        [$old, $errors] = form_state();
        view('productores_form', [
            'modo'      => 'crear',
            'productor' => $old + self::DEFAULTS,
            'errors'    => $errors,
        ]);
    }

    public function store(): void
    {
        try {
            $data = $this->validarProductor(false);
            $userId = current_user()['id'];

            $id = Database::transaction(function () use ($data, $userId): int {
                $id = $this->productores->create($data);
                $this->productores->registrarPrecio($id, null, $data['precio_litro_usd'], 'Alta del productor', $userId);
                audit_log('productores', 'crear', $data, 'productores', $id);
                return $id;
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'productores_nuevo');
        } catch (PDOException $e) {
            $this->failOnDuplicate($e, 'productores_nuevo');
        }

        flash('success', 'Productor registrado correctamente.');
        redirect('productores', ['q' => $data['nombre']]);
    }

    // ----- Editar ----------------------------------------------------------

    public function edit(): void
    {
        $productor = $this->findOr404();
        [$old, $errors] = form_state();

        view('productores_form', [
            'modo'      => 'editar',
            'id'        => (int) $productor['id'],
            'productor' => $old + array_map('strval', array_map(static fn ($v) => $v ?? '', $productor)) + self::DEFAULTS,
            'errors'    => $errors,
        ]);
    }

    public function update(): void
    {
        $id = (int) $this->findOr404()['id'];

        try {
            $data = $this->validarProductor(true);
            $userId = current_user()['id'];

            Database::transaction(function () use ($id, $data, $userId): void {
                $actual = $this->productores->find($id, true);
                if ((int) $actual['dia_cierre'] !== $data['dia_cierre'] && $this->pagos->abiertosDe($id) > 0) {
                    throw new ValidationException(['dia_cierre' => 'Hay pagos aplicados a un ciclo sin cerrar. Cierra ese ciclo antes de cambiar el día de cierre.']);
                }
                $this->productores->update($id, $data);

                if (!self::mismoDecimal($actual['precio_litro_usd'], $data['precio_litro_usd'])) {
                    $this->productores->registrarPrecio(
                        $id,
                        $actual['precio_litro_usd'],
                        $data['precio_litro_usd'],
                        $data['motivo_precio'] ?: 'Actualización de precio',
                        $userId
                    );
                }

                $cambios = [];
                foreach ($data as $campo => $valor) {
                    $igual = $campo === 'precio_litro_usd'
                        ? self::mismoDecimal($actual[$campo], $valor)
                        : (string) ($actual[$campo] ?? '') === (string) $valor;
                    if (array_key_exists($campo, $actual) && !$igual) {
                        $cambios[$campo] = ['antes' => $actual[$campo], 'despues' => $valor];
                    }
                }
                audit_log('productores', 'editar', $cambios, 'productores', $id);
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'productores_editar', ['id' => $id]);
        } catch (PDOException $e) {
            $this->failOnDuplicate($e, 'productores_editar', ['id' => $id]);
        }

        flash('success', 'Productor actualizado.');
        redirect('productores');
    }

    // ----- Archivar --------------------------------------------------------

    /** Sin historial se elimina; con historial solo se desactiva, y nunca con saldo abierto. */
    public function archivar(): void
    {
        $id = parse_int_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
        if ($id === null) {
            $this->fallar(['id' => 'Productor inválido.'], 'productores');
        }

        try {
            $resultado = Database::transaction(function () use ($id): string {
                $productor = $this->productores->find($id, true);
                if ($productor === null) {
                    throw new ValidationException(['id' => 'El productor no existe.']);
                }

                if (!$this->productores->tieneHistorial($id)) {
                    $this->productores->eliminarSinHistorial($id);
                    audit_log('productores', 'eliminar', ['productor' => $productor], 'productores', $id);
                    return 'eliminado';
                }

                if ((int) $productor['activo'] === 0) {
                    throw new ValidationException(['id' => 'El productor ya está archivado.']);
                }
                $saldo = $this->productores->cuenta($id)['saldo_usd'];
                if (Caja::aCentavos($saldo) !== 0) {
                    throw new ValidationException(['id' => sprintf(
                        'No se puede archivar a %s: tiene un saldo %s de $%s. Liquida la cuenta primero.',
                        $productor['nombre'],
                        Caja::aCentavos($saldo) > 0 ? 'pendiente' : 'a favor',
                        num(abs((float) $saldo))
                    )]);
                }

                $this->productores->archivar($id);
                audit_log('productores', 'archivar', ['nombre' => $productor['nombre'], 'saldo_usd' => $saldo], 'productores', $id);
                return 'archivado';
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors, 'productores');
        }

        flash('success', $resultado === 'eliminado'
            ? 'Productor eliminado: no tenía entregas, pagos ni cierres.'
            : 'Productor archivado. Su historial se conserva.');
        redirect('productores');
    }

    // ----- Entrega diaria --------------------------------------------------

    public function entregaForm(): void
    {
        [$old, $errors] = form_state();
        $hoy = date('Y-m-d');
        $fecha = parse_date($old['fecha'] ?? ($_GET['fecha'] ?? '')) ?? $hoy;
        if ($fecha > $hoy) {
            $fecha = $hoy;
        }

        view('productores_entrega', [
            'productores' => $this->productores->activos(),
            'entregasDia' => $this->entregas->delDia($fecha),
            'totales'     => $this->entregas->totalesDia($fecha),
            'fecha'       => $fecha,
            'hoy'         => $hoy,
            'old'         => $old + [
                'productor_id'  => (string) ($_GET['productor_id'] ?? ''),
                'litros'        => '',
                'traslado_usd'  => '',
                'cuajada_kg'    => '',
                'observaciones' => '',
            ],
            'errors'      => $errors,
        ]);
    }

    public function entregaStore(): void
    {
        $fechaForm = parse_date($_POST['fecha'] ?? '') ?? date('Y-m-d');
        [$paginaRetorno, $queryRetorno] = $this->retornoEntrega($fechaForm);

        try {
            $errors = [];
            $productorId = parse_int_range($_POST['productor_id'] ?? null, 1, PHP_INT_MAX);
            $fecha = parse_date($_POST['fecha'] ?? '');
            $litros = parse_decimal($_POST['litros'] ?? null, 3, 100000);
            $traslado = ($_POST['traslado_usd'] ?? '') === '' ? '0' : parse_decimal($_POST['traslado_usd'], 2, 100000, true);
            $cuajada = ($_POST['cuajada_kg'] ?? '') === '' ? '0' : parse_decimal($_POST['cuajada_kg'], 3, 100000, true);
            $observaciones = post_string('observaciones', 255);

            if ($productorId === null) {
                $errors['productor_id'] = 'Selecciona un productor.';
            }
            if ($fecha === null) {
                $errors['fecha'] = 'Fecha inválida.';
            } elseif ($fecha > date('Y-m-d')) {
                $errors['fecha'] = 'No se pueden registrar entregas en fechas futuras.';
            }
            if ($litros === null) {
                $errors['litros'] = 'Litros inválidos: número mayor que 0, hasta 3 decimales.';
            }
            if ($traslado === null) {
                $errors['traslado_usd'] = 'Monto de traslado inválido (máximo 2 decimales).';
            }
            if ($cuajada === null) {
                $errors['cuajada_kg'] = 'Kg de cuajada inválidos (máximo 3 decimales).';
            }
            if ($errors) {
                throw new ValidationException($errors);
            }

            // La carga rápida (cuenta del productor) solo envía litros: traslado, cuajada y observaciones
            // de una entrega existente se conservan salvo que el formulario completo los mande.
            $extras = [];
            if (array_key_exists('traslado_usd', $_POST)) {
                $extras['traslado_usd'] = $traslado;
            }
            if (array_key_exists('cuajada_kg', $_POST)) {
                $extras['cuajada_kg'] = $cuajada;
            }
            if (array_key_exists('observaciones', $_POST)) {
                $extras['observaciones'] = $observaciones === '' ? null : $observaciones;
            }

            $userId = current_user()['id'];
            $entrega = Database::transaction(
                fn (): array => $this->guardarEntrega($productorId, $fecha, $litros, $userId, $extras)
            );
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, $paginaRetorno, $queryRetorno);
        } catch (PDOException $e) {
            if (duplicate_key($e) === 'uq_entregas_productor_fecha') {
                form_fail($_POST, ['productor_id' => 'Otro usuario registró esta entrega al mismo tiempo. Inténtalo de nuevo.'], $paginaRetorno, $queryRetorno);
            }
            if (trigger_error_message($e) !== null) {
                form_fail($_POST, ['fecha' => 'La semana de esa fecha ya está cerrada para este productor.'], $paginaRetorno, $queryRetorno);
            }
            throw $e;
        }

        flash($entrega['accion'] === 'sin_cambios' ? 'info' : 'success', match ($entrega['accion']) {
            'creada'      => sprintf('Entrega registrada: %s L × %s USD/L.', num($entrega['litros'], 3), num($entrega['precio_litro_usd'], 4)),
            'actualizada' => sprintf('Entrega del %s sobrescrita: %s L → %s L (precio congelado %s USD/L).',
                (new DateTimeImmutable($fecha))->format('d/m/Y'), num($entrega['litros_antes'], 3), num($entrega['litros'], 3), num($entrega['precio_litro_usd'], 4)),
            default       => 'La entrega ya tenía esos litros: no hubo cambios.',
        });
        redirect($paginaRetorno, $queryRetorno);
    }

    /**
     * Registra o sobrescribe la entrega de un día. Corre dentro de Database::transaction().
     * Bloquea al productor (serializa con cierres y pagos) y rechaza semanas cerradas.
     * El precio se congela al crear la entrega; sobrescribir solo cambia litros (y extras si llegan).
     *
     * @return array{accion:'creada'|'actualizada'|'sin_cambios', id:int, litros:string, litros_antes:?string, precio_litro_usd:string}
     */
    private function guardarEntrega(int $productorId, string $fecha, string $litros, int $userId, array $extras = []): array
    {
        $productor = $this->productores->paraEntrega($productorId);
        if ($productor === null) {
            throw new ValidationException(['productor_id' => 'El productor no existe o está inactivo.']);
        }
        $precio = $productor['precio_litro_usd'];
        if ($this->cierres->cubreFecha($productorId, $fecha)) {
            $inicio = CierreSemanal::semanaDe($fecha, $productor['dia_cierre']);
            throw new ValidationException(['fecha' => sprintf(
                'El ciclo del %s al %s de este productor está cerrado. Un administrador debe reabrirlo para modificarlo.',
                (new DateTimeImmutable($inicio))->format('d/m'),
                (new DateTimeImmutable(CierreSemanal::finDe($inicio)))->format('d/m/Y')
            )]);
        }

        $actual = $this->entregas->findPorFecha($productorId, $fecha, true);
        if ($actual === null) {
            $data = [
                'productor_id'     => $productorId,
                'fecha'            => $fecha,
                'litros'           => $litros,
                'precio_litro_usd' => $precio,
                'traslado_usd'     => $extras['traslado_usd'] ?? '0',
                'cuajada_kg'       => $extras['cuajada_kg'] ?? '0',
                'observaciones'    => $extras['observaciones'] ?? null,
                'registrado_por'   => $userId,
            ];
            $id = $this->entregas->create($data);
            audit_log('productores', 'registrar_entrega', $data, 'productores_entregas', $id);
            return ['accion' => 'creada', 'id' => $id, 'litros' => $litros, 'litros_antes' => null, 'precio_litro_usd' => $precio];
        }

        $id = (int) $actual['id'];
        $cambios = ['litros' => $litros] + $extras;
        $antes = array_intersect_key($actual, $cambios);
        $igual = Producto::aMilesimas((string) $actual['litros']) === Producto::aMilesimas($litros);
        foreach ($extras as $campo => $valor) {
            $igual = $igual && (string) ($actual[$campo] ?? '') == (string) ($valor ?? '');
        }
        $resultado = ['id' => $id, 'litros' => $litros, 'litros_antes' => (string) $actual['litros'], 'precio_litro_usd' => (string) $actual['precio_litro_usd']];
        if ($igual) {
            return ['accion' => 'sin_cambios'] + $resultado;
        }

        $this->entregas->actualizar($id, $cambios);
        audit_log('productores', 'sobrescribir_entrega', [
            'productor_id'     => $productorId,
            'fecha'            => $fecha,
            'precio_litro_usd' => (string) $actual['precio_litro_usd'],
            'antes'            => $antes,
            'despues'          => $cambios,
        ], 'productores_entregas', $id);
        return ['accion' => 'actualizada'] + $resultado;
    }

    // ----- Cierres semanales -----------------------------------------------

    /** Cierra todas las semanas terminadas con movimientos sin cierre (de un productor o de todos). */
    public function cerrarSemanas(): void
    {
        $productorId = ($_POST['productor_id'] ?? '') === '' ? null : parse_int_range($_POST['productor_id'], 1, PHP_INT_MAX);
        $volver = $productorId === null
            ? ['productores_control_semanal', []]
            : ['productor_control', ['id' => $productorId]];
        $userId = current_user()['id'];

        try {
            $cerradas = Database::transaction(function () use ($productorId, $userId): int {
                $porProductor = [];
                foreach ($this->cierres->pendientesDeCierre(date('Y-m-d'), $productorId) as $p) {
                    $porProductor[$p['productor_id']][] = $p['semana_inicio'];
                }

                $total = 0;
                foreach ($porProductor as $pid => $semanas) {
                    if ($this->productores->find($pid, true) === null) {
                        continue;
                    }
                    $ids = [];
                    foreach ($semanas as $semana) {
                        $ids[$semana] = $this->cierres->cerrar($pid, $semana, $userId);
                    }
                    audit_log('productores', 'cerrar_semanas', ['productor_id' => $pid, 'cierres' => $ids], 'productores_cierres');
                    $total += count($ids);
                }
                return $total;
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors, ...$volver);
        }

        flash($cerradas > 0 ? 'success' : 'info', $cerradas > 0
            ? sprintf('%d semana(s) cerrada(s) y saldos arrastrados.', $cerradas)
            : 'No hay semanas terminadas pendientes de cierre.');
        redirect(...$volver);
    }

    /** Solo administrador: corrige el snapshot del cierre y recalcula la cadena de saldos. */
    public function cierreUpdate(): void
    {
        $this->exigirAdmin('editar_cierre');

        $cierreId = parse_int_range($_POST['cierre_id'] ?? null, 1, PHP_INT_MAX);
        $litros = parse_decimal($_POST['litros'] ?? null, 3, 1000000, true);
        $precioRaw = $_POST['precio'] ?? '';
        $precio = $precioRaw === '' ? null : parse_decimal($precioRaw, 4, 100, true);
        $totalRaw = $_POST['total_factura'] ?? '';
        $total = $totalRaw === '' ? null : parse_decimal($totalRaw, 2, 100000000, true);
        $notas = post_string('notas', 500);
        $motivo = post_string('motivo', 255);

        $errors = [];
        if ($cierreId === null) {
            $errors['cierre_id'] = 'Cierre inválido.';
        }
        if ($litros === null) {
            $errors['litros'] = 'Litros inválidos (hasta 3 decimales).';
        }
        if ($precioRaw !== '' && $precio === null) {
            $errors['precio'] = 'Precio inválido (hasta 4 decimales).';
        }
        if ($totalRaw !== '' && $total === null) {
            $errors['total_factura'] = 'Total inválido (hasta 2 decimales).';
        }
        if ($total === null && $precio === null && !isset($errors['precio']) && !isset($errors['total_factura'])) {
            $errors['total_factura'] = 'Indica el precio $/L o el total de la factura.';
        }
        if (mb_strlen($motivo) < 5) {
            $errors['motivo'] = 'Indica el motivo de la corrección (mínimo 5 caracteres).';
        }

        $original = $cierreId === null ? null : $this->cierres->find($cierreId);
        if ($original === null) {
            $this->fallar($errors + ['cierre_id' => 'El cierre no existe.'], 'productores');
        }
        $volver = ['id' => (int) $original['productor_id'], 'semana' => $original['semana_inicio']];
        if ($errors) {
            form_fail($_POST, $errors, 'productor_control', $volver);
        }

        $bruto = $total ?? number_format(round((float) $litros * (float) $precio, 2), 2, '.', '');
        $userId = current_user()['id'];

        try {
            Database::transaction(function () use ($cierreId, $original, $litros, $bruto, $notas, $motivo, $userId): void {
                $this->productores->find((int) $original['productor_id'], true);
                $antes = $this->cierres->find($cierreId, true);
                if ($antes === null || $antes['estado'] === 'anulada') {
                    throw new ValidationException(['cierre_id' => 'El cierre no existe o está anulado.']);
                }

                $this->cierres->editar($cierreId, $litros, $bruto, $notas === '' ? null : $notas);
                $this->cierres->recalcular((int) $antes['productor_id']);
                $despues = $this->cierres->find($cierreId);

                audit_log('productores', 'editar_cierre', [
                    'motivo'  => $motivo,
                    'admin'   => $userId,
                    'antes'   => self::snapshotCierre($antes),
                    'despues' => self::snapshotCierre($despues),
                ], 'productores_cierres', $cierreId);
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'productor_control', $volver);
        }

        flash('success', 'Cierre corregido. Los saldos de las semanas siguientes se recalcularon.');
        redirect('productor_control', $volver);
    }

    /**
     * Solo administrador: la semana vuelve a estar abierta (entregas editables), sus entregas y pagos se liberan
     * y la cadena de saldos se recalcula sin el arrastre que generaba. Los egresos de caja no se tocan.
     */
    public function reabrirSemana(): void
    {
        $this->exigirAdmin('reabrir_semana');

        $cierreId = parse_int_range($_POST['cierre_id'] ?? null, 1, PHP_INT_MAX);
        $motivo = post_string('motivo', 255);
        $original = $cierreId === null ? null : $this->cierres->find($cierreId);
        if ($original === null) {
            $this->fallar(['cierre_id' => 'El cierre no existe.'], 'productores');
        }
        $volver = ['id' => (int) $original['productor_id'], 'tab' => 'cierres'];
        if (mb_strlen($motivo) < 5) {
            $this->fallar(['motivo' => 'Indica el motivo de la reapertura (mínimo 5 caracteres).'], 'productor_control', $volver);
        }
        $userId = current_user()['id'];

        try {
            Database::transaction(function () use ($cierreId, $original, $motivo, $userId): void {
                $this->productores->find((int) $original['productor_id'], true);
                $antes = $this->cierres->find($cierreId, true);
                $this->cierres->reabrir($cierreId);

                audit_log('productores', 'reabrir_semana', [
                    'motivo'        => $motivo,
                    'admin'         => $userId,
                    'productor_id'  => (int) $antes['productor_id'],
                    'semana_inicio' => $antes['semana_inicio'],
                    'antes'         => self::snapshotCierre($antes),
                ], 'productores_cierres', $cierreId);
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors, 'productor_control', $volver);
        }

        flash('success', sprintf(
            'Semana del %s reabierta: las entregas vuelven a ser editables y los saldos siguientes se recalcularon.',
            (new DateTimeImmutable($original['semana_inicio']))->format('d/m/Y')
        ));
        redirect('productor_control', ['id' => (int) $original['productor_id'], 'semana' => $original['semana_inicio']]);
    }

    // ----- Reportes imprimibles --------------------------------------------

    /**
     * Liquidación individual (o de varios productores con ids[]) lista para imprimir / exportar a PDF.
     * Período: ?semana=Y-m-d (sábado a viernes) o ?desde=&hasta=.
     */
    public function liquidacionPdf(): void
    {
        $pack = $this->documentosDesdeRequest();
        if ($pack === null) {
            render_not_found();
            exit;
        }

        view('productores_liquidacion', [
            'documentos' => $pack['documentos'],
            'desde'      => $pack['desde'],
            'hasta'      => $pack['hasta'],
            'tasa'       => $pack['tasa'],
            'emitida'    => $pack['emitida'],
            'autoprint'  => isset($_POST['autoprint']) || isset($_GET['autoprint']),
        ]);
    }

    /**
     * HTML consolidado de las liquidaciones seleccionadas (JSON) para la cola ZIP del navegador.
     */
    public function liquidacionHtml(): void
    {
        if (!wants_json()) {
            json_response(406, ['ok' => false, 'errores' => ['general' => 'Se espera Accept: application/json.']]);
        }

        $pack = $this->documentosDesdeRequest();
        if ($pack === null) {
            json_response(422, ['ok' => false, 'errores' => ['general' => 'Selecciona al menos un productor válido.']]);
        }

        $items = [];
        foreach ($pack['documentos'] as $doc) {
            $items[] = [
                'nombre'  => (string) $doc['productor']['nombre'],
                'archivo' => LiquidacionArchivo::nombrePng($doc),
            ];
        }

        json_response(200, [
            'ok'    => true,
            'html'  => $this->htmlLiquidaciones($pack),
            'items' => $items,
            'desde' => $pack['desde'],
            'hasta' => $pack['hasta'],
        ]);
    }

    /** Hoja de notaría: matriz vacía de productores activos por día de la semana operativa. */
    public function notariaPdf(): void
    {
        $fecha = parse_date(self::queryString('semana', 10)) ?? date('Y-m-d');
        $semana = CierreSemanal::semanaDe($fecha, CierreSemanal::DIA_CIERRE_DEFAULT);

        view('productores_notaria', [
            'semana'      => $semana,
            'semanaFin'   => CierreSemanal::finDe($semana),
            'dias'        => self::diasDeSemana($semana),
            'productores' => $this->productores->activos(),
            'impreso'     => date('Y-m-d'),
        ]);
    }

    /**
     * Temporal, solo administrador: registra la tasa BCV vigente desde hoy 00:00 para destrabar pagos.
     * Reemplazar por el módulo de Finanzas cuando exista.
     */
    public function fijarTasaBcvRapida(): void
    {
        $this->exigirAdmin('fijar_tasa_bcv');

        $productorId = parse_int_range($_POST['productor_id'] ?? null, 1, PHP_INT_MAX);
        [$page, $query] = $productorId === null ? ['productores', []] : ['productor_control', ['id' => $productorId]];

        $tasa = parse_decimal($_POST['tasa'] ?? null, 4, 10000000);
        if ($tasa === null) {
            $this->fallar(['tasa' => 'Tasa inválida: número mayor que 0 con hasta 4 decimales (Bs por 1 USD).'], $page, $query);
        }
        $userId = current_user()['id'];
        $vigenteDesde = date('Y-m-d 00:00:00');

        $id = Database::transaction(function () use ($tasa, $vigenteDesde, $userId): int {
            $id = TasaCambio::registrar(get_db(), $tasa, $vigenteDesde, $userId);
            audit_log('finanzas', 'fijar_tasa_rapida', ['tasa_bs_por_usd' => $tasa, 'vigente_desde' => $vigenteDesde], 'tasas_cambio', $id);
            return $id;
        });

        flash('success', sprintf('Tasa BCV registrada: 1 USD = Bs. %s, vigente desde hoy (#%d).', num($tasa, 4), $id));
        redirect($page, $query);
    }

    /**
     * @return array{documentos: list<array>, desde: string, hasta: string, tasa: ?string, emitida: string}|null
     */
    private function documentosDesdeRequest(): ?array
    {
        $ids = $_POST['ids'] ?? $_GET['ids'] ?? ($_GET['id'] ?? null);
        if (is_string($ids) && str_starts_with(trim($ids), '[')) {
            try {
                $decoded = json_decode($ids, true, 8, JSON_THROW_ON_ERROR);
                $ids = is_array($decoded) ? $decoded : [$ids];
            } catch (JsonException) {
                $ids = [$ids];
            }
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map(
            static fn ($v) => parse_int_range($v, 1, PHP_INT_MAX),
            is_array($ids) ? $ids : [$ids]
        )))), 0, 200);
        if ($ids === []) {
            return null;
        }

        $semana = parse_date(self::queryString('semana', 10));
        $productores = array_values(array_filter(array_map(fn (int $pid): ?array => $this->productores->find($pid), $ids)));
        if ($productores === []) {
            return null;
        }
        if ($semana !== null) {
            $desde = CierreSemanal::semanaDe($semana, CierreSemanal::diaCierreValido($productores[0]['dia_cierre']));
            $hasta = CierreSemanal::finDe($desde);
        } else {
            [$desde, $hasta] = self::rango(
                $_POST['desde'] ?? $_GET['desde'] ?? null,
                $_POST['hasta'] ?? $_GET['hasta'] ?? null,
                date('Y-m-01')
            );
        }

        $tasa = TasaCambio::vigenteEn(get_db(), date('Y-m-d H:i:s'));
        $documentos = [];
        foreach ($productores as $productor) {
            $documentos[] = $this->datosLiquidacion($productor, $desde, $hasta);
        }
        if ($documentos === []) {
            return null;
        }

        return [
            'documentos' => $documentos,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'tasa'       => is_array($tasa) ? ($tasa['tasa'] ?? null) : null,
            'emitida'    => date('Y-m-d H:i'),
        ];
    }

    /** @param array{documentos: list<array>, desde: string, hasta: string, tasa: ?string, emitida: string} $pack */
    private function htmlLiquidaciones(array $pack): string
    {
        $documentos = $pack['documentos'];
        $desde = $pack['desde'];
        $hasta = $pack['hasta'];
        $tasa = $pack['tasa'];
        $emitida = $pack['emitida'];
        $empresaRif = defined('APP_RIF') ? APP_RIF : 'V-286013356';
        $fecha = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
        $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $tiposPago = PagoProductor::ETIQUETAS;
        $conTasa = $tasa !== null && (float) $tasa > 0;
        $usd = static fn (mixed $v, int $d = 2): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v), $d);
        $bs = static fn (mixed $usdV): string => 'Bs. ' . num((float) $usdV * (float) $tasa);
        $logoSrc = asset_v(LOGO_EMPRESA);

        $html = '';
        foreach ($documentos as $doc) {
            ob_start();
            require ADARA_ROOT . '/pages/partials/liquidacion_doc.php';
            $cuerpo = (string) ob_get_clean();
            $html .= '<div class="fm-doc-item" data-archivo="' . e(LiquidacionArchivo::nombrePng($doc)) . '">'
                . $cuerpo . '</div>';
        }
        return $html;
    }

    /** @return array{productor:array, numero:string, entregas:list<array>, pagos:list<array>, litros:string, facturado_usd:string, pagado_usd:string, saldo_usd:string, precio_promedio:?string} */
    private function datosLiquidacion(array $productor, string $desde, string $hasta): array
    {
        $pid = (int) $productor['id'];
        $entregas = $this->entregas->detallePeriodo($pid, $desde, $hasta);
        $pagos = $this->pagos->aplicadosEnPeriodo($pid, $desde, $hasta, CierreSemanal::diaCierreValido($productor['dia_cierre']));
        // $pagos: fecha, tipo (efectivo/mercancía/transferencia/pago móvil), monto_usd — se lista en el PDF.

        $litros = 0;
        $facturado = 0;
        foreach ($entregas as $e) {
            $litros += Producto::aMilesimas((string) $e['litros']);
            $facturado += Caja::aCentavos($e['subtotal_usd']);
        }
        $pagado = array_sum(array_map(static fn (array $p): int => Caja::aCentavos($p['monto_usd']), $pagos));

        return [
            'productor'       => $productor,
            'numero'          => str_pad((string) $pid, 4, '0', STR_PAD_LEFT) . '-' . date('Ymd'),
            'entregas'        => $entregas,
            'pagos'           => $pagos,
            'litros'          => Producto::desdeMilesimas($litros),
            'facturado_usd'   => Caja::desdeCentavos($facturado),
            'pagado_usd'      => Caja::desdeCentavos($pagado),
            'saldo_usd'       => Caja::desdeCentavos($facturado - $pagado),
            'precio_promedio' => $litros > 0 ? number_format(($facturado / 100) / ($litros / 1000), 4, '.', '') : null,
        ];
    }

    // ----- Pagos -----------------------------------------------------------

    /**
     * Pago + recálculo del cierre + egreso de caja + auditoría en una sola transacción.
     * Sin saldo en caja, o si falla cualquier inserción, se revierte todo.
     */
    public function pagoStore(): void
    {
        $productorId = parse_int_range($_POST['productor_id'] ?? null, 1, PHP_INT_MAX);
        $semanaForm = parse_date($_POST['semana_inicio'] ?? '');
        $volver = ['id' => $productorId, 'semana' => $semanaForm];
        $userId = current_user()['id'];

        if ($productorId === null) {
            $this->fallar(['productor_id' => 'Productor inválido.'], 'productores');
        }

        try {
            $pago = $this->validarPago($productorId);
            $resultado = Database::transaction(fn (): array => $this->procesarPago($pago, $userId));
        } catch (ValidationException $e) {
            $this->auditarFalloPago($e->errors);
            form_fail($_POST, $e->errors, 'productor_control', $volver);
        } catch (Throwable $e) {
            $this->auditarFalloPago(['error' => $e::class, 'sqlstate' => $e instanceof PDOException ? ($e->errorInfo[0] ?? null) : null]);
            throw $e;
        }

        flash('success', sprintf(
            'Pago registrado: $%s (%s).%s',
            num($resultado['monto_usd']),
            PagoProductor::ETIQUETAS[$resultado['tipo']],
            $resultado['caja_movimiento_id'] !== null
                ? ($resultado['cuenta'] === 'banco' ? ' Egreso asentado en banco.' : ' Egreso asentado en caja (efectivo).')
                : ''
        ));
        redirect('productor_control', ['id' => $productorId, 'semana' => $resultado['semana_inicio']]);
    }

    /** Solo administrador. El pago se anula (no se borra) y su egreso se revierte con un contra-asiento. */
    public function pagoDestroy(): void
    {
        $this->exigirAdmin('anular_pago');

        $abonoId = parse_int_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
        $motivo = post_string('motivo', 255);
        $pago = $abonoId === null ? null : $this->pagos->find($abonoId);
        if ($pago === null) {
            $this->fallar(['id' => 'El pago no existe.'], 'productores');
        }
        $volver = ['id' => (int) $pago['productor_id'], 'semana' => $pago['semana_inicio']];
        if (mb_strlen($motivo) < 5) {
            $this->fallar(['motivo' => 'Indica el motivo de la anulación (mínimo 5 caracteres).'], 'productor_control', $volver);
        }
        $userId = current_user()['id'];

        try {
            Database::transaction(function () use ($abonoId, $motivo, $userId): void {
                $pago = $this->pagos->find($abonoId);
                $this->productores->find((int) $pago['productor_id'], true);
                $pago = $this->pagos->find($abonoId, true);
                if ($pago['anulado_en'] !== null) {
                    throw new ValidationException(['id' => 'El pago ya fue anulado.']);
                }

                $this->pagos->anular($abonoId, $userId, $motivo);

                $movimiento = $this->caja->movimientoDeAbono($abonoId);
                $contraId = $movimiento === null ? null : $this->caja->contraAsiento(
                    (int) $movimiento['id'],
                    sprintf('Anulación pago productor #%d: %s', $abonoId, $motivo),
                    $userId
                );

                $this->cierres->recalcular((int) $pago['productor_id']);

                audit_log('productores', 'anular_pago', [
                    'motivo'             => $motivo,
                    'admin'              => $userId,
                    'pago'               => $pago,
                    'caja_movimiento_id' => $movimiento['id'] ?? null,
                    'contra_asiento_id'  => $contraId,
                ], 'productores_abonos', $abonoId);
            });
        } catch (ValidationException $e) {
            $this->fallar($e->errors, 'productor_control', $volver);
        }

        flash('success', 'Pago anulado. El saldo se recalculó y la caja quedó revertida con un contra-asiento.');
        redirect('productor_control', $volver);
    }

    // ----- Internos: pagos -------------------------------------------------

    /** @return array{productor_id:int, tipo:string, moneda:string, monto:string, fecha:string, semana_inicio:string, referencia:?string, descripcion:?string} */
    private function validarPago(int $productorId): array
    {
        $errors = [];
        $hoy = date('Y-m-d');
        $diaCierre = $this->cierres->diaCierreDe($productorId);
        $semanaActual = CierreSemanal::semanaDe($hoy, $diaCierre);

        $tipo = PagoProductor::normalizarTipo($_POST['tipo'] ?? null);
        if ($tipo === null) {
            $errors['tipo'] = 'Tipo de pago inválido: Efectivo, Mercancía, Pago Móvil o Transferencia.';
        }

        $moneda = $_POST['moneda'] ?? 'USD';
        if (!is_string($moneda) || !in_array($moneda, PagoProductor::MONEDAS, true)) {
            $errors['moneda'] = 'Moneda inválida.';
        }

        $monto = parse_decimal($_POST['monto'] ?? null, 2, 100000000);
        if ($monto === null) {
            $errors['monto'] = 'Monto inválido: mayor que 0, hasta 2 decimales.';
        }

        $fecha = parse_date($_POST['fecha'] ?? '');
        if ($fecha === null) {
            $errors['fecha'] = 'Fecha inválida.';
        } elseif ($fecha > $hoy) {
            $errors['fecha'] = 'No se pueden registrar pagos en fechas futuras.';
        }

        $semana = parse_date($_POST['semana_inicio'] ?? '');
        if ($semana === null || !CierreSemanal::esInicioDeCiclo($semana, $diaCierre)) {
            $errors['semana_inicio'] = 'Selecciona el ciclo del productor al que aplica el pago.';
        } elseif ($semana > $semanaActual) {
            $errors['semana_inicio'] = 'No se pueden aplicar pagos a semanas futuras.';
        }

        $referencia = post_string('referencia', 40);
        if ($tipo !== null && PagoProductor::requiereReferencia($tipo) && $referencia === '') {
            $errors['referencia'] = sprintf('La referencia es obligatoria para %s.', PagoProductor::TIPOS[$tipo]);
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        $descripcion = post_string('descripcion', 255);
        return [
            'productor_id'  => $productorId,
            'tipo'          => $tipo,
            'moneda'        => $moneda,
            'monto'         => $monto,
            'fecha'         => $fecha,
            'semana_inicio' => $semana,
            'referencia'    => $referencia === '' ? null : $referencia,
            'descripcion'   => $descripcion === '' ? null : $descripcion,
        ];
    }

    /** Corre dentro de Database::transaction(). Orden de bloqueo: productor → caja → cierres (igual en todo el módulo). */
    private function procesarPago(array $p, int $userId): array
    {
        $productor = $this->productores->find($p['productor_id'], true);
        if ($productor === null) {
            throw new ValidationException(['productor_id' => 'El productor no existe.']);
        }

        $mueveCaja = PagoProductor::mueveCaja($p['tipo']);
        $momento = $p['fecha'] === date('Y-m-d') ? date('Y-m-d H:i:s') : $p['fecha'] . ' 23:59:59';

        $tasa = null;
        if ($mueveCaja || $p['moneda'] === 'VES') {
            $tasa = TasaCambio::vigenteEn(get_db(), $momento);
            if ($tasa === null) {
                throw new ValidationException(['fecha' => 'No hay tasa de cambio vigente para esa fecha. Registra la tasa BCV antes de pagar.']);
            }
        }

        $montoUsd = $p['moneda'] === 'USD'
            ? $p['monto']
            : number_format(round((float) $p['monto'] / (float) $tasa['tasa'], 2), 2, '.', '');
        if (Caja::aCentavos($montoUsd) <= 0) {
            throw new ValidationException(['monto' => 'El monto equivale a menos de $0,01.']);
        }

        $cuenta = Caja::cuentaParaPago($p['tipo']);
        if ($mueveCaja) {
            $this->caja->verificarFondos($cuenta, $p['moneda'], $p['monto']);
        }

        $cierre = $this->cierreParaPago($p['productor_id'], CierreSemanal::diaCierreValido($productor['dia_cierre']), $p['semana_inicio'], $userId);
        $saldoAntes = $cierre === null ? null : Caja::desdeCentavos(Caja::aCentavos($cierre['saldo_usd']) - Caja::aCentavos($cierre['pagado_usd']));

        $abonoId = $this->pagos->create([
            'productor_id'    => $p['productor_id'],
            'fecha'           => $p['fecha'],
            'semana_inicio'   => $p['semana_inicio'],
            'tipo'            => $p['tipo'],
            'moneda'          => $p['moneda'],
            'monto'           => $p['monto'],
            'tasa_bs_por_usd' => $p['moneda'] === 'VES' ? $tasa['tasa'] : null,
            'monto_usd'       => $montoUsd,
            'referencia'      => $p['referencia'],
            'descripcion'     => $p['descripcion'],
            'cierre_id'       => $cierre['id'] ?? null,
            'registrado_por'  => $userId,
        ]);

        $cajaId = null;
        if ($mueveCaja) {
            $cajaId = $this->caja->egresoPagoProductor([
                'fecha'           => $momento,
                'cuenta'          => $cuenta,
                'moneda'          => $p['moneda'],
                'monto'           => $p['monto'],
                'tasa_bs_por_usd' => $tasa['tasa'],
                'monto_usd'       => $montoUsd,
                'concepto'        => sprintf(
                    'Pago productor %s - %s%s - semana %s',
                    $productor['nombre'],
                    PagoProductor::TIPOS[$p['tipo']],
                    $p['referencia'] !== null ? ' ref. ' . $p['referencia'] : '',
                    $p['semana_inicio']
                ),
                'cierre_id'       => isset($cierre['id']) ? (int) $cierre['id'] : null,
                'abono_id'        => $abonoId,
                'registrado_por'  => $userId,
            ]);
        }

        $cierreDespues = null;
        if ($cierre !== null) {
            $this->cierres->recalcular($p['productor_id']);
            $cierreDespues = $this->cierres->find((int) $cierre['id']);
        }

        audit_log('productores', 'registrar_pago', [
            'productor_id'       => $p['productor_id'],
            'tipo'               => $p['tipo'],
            'moneda'             => $p['moneda'],
            'monto'              => $p['monto'],
            'monto_usd'          => $montoUsd,
            'tasa_bs_por_usd'    => $tasa['tasa'] ?? null,
            'fecha'              => $p['fecha'],
            'semana_inicio'      => $p['semana_inicio'],
            'referencia'         => $p['referencia'],
            'cierre_id'          => $cierre['id'] ?? null,
            'pendiente_antes'    => $saldoAntes,
            'pendiente_despues'  => $cierreDespues === null ? null : Caja::desdeCentavos(Caja::aCentavos($cierreDespues['saldo_usd']) - Caja::aCentavos($cierreDespues['pagado_usd'])),
            'estado_cierre'      => $cierreDespues['estado'] ?? 'semana_abierta',
            'caja_movimiento_id' => $cajaId,
            'cuenta'             => $mueveCaja ? $cuenta : null,
        ], 'productores_abonos', $abonoId);

        return [
            'tipo'               => $p['tipo'],
            'monto_usd'          => $montoUsd,
            'semana_inicio'      => $p['semana_inicio'],
            'caja_movimiento_id' => $cajaId,
            'cuenta'             => $cuenta,
        ];
    }

    /**
     * La semana en curso no tiene cierre: el pago queda pendiente de ligarse al cerrarla.
     * Una semana pasada sin cierre se cierra antes (junto con cualquier semana anterior pendiente) para que
     * la cadena de arrastres quede completa.
     */
    private function cierreParaPago(int $productorId, int $diaCierre, string $semana, int $userId): ?array
    {
        if ($semana >= CierreSemanal::semanaDe(date('Y-m-d'), $diaCierre)) {
            return null;
        }

        $cierre = $this->cierres->findPorSemana($productorId, $semana, true);
        if ($cierre !== null) {
            return $cierre;
        }

        $ids = [];
        foreach ($this->cierres->pendientesDeCierre(date('Y-m-d'), $productorId) as $pendiente) {
            if ($pendiente['semana_inicio'] <= $semana) {
                $ids[$pendiente['semana_inicio']] = $this->cierres->cerrar($productorId, $pendiente['semana_inicio'], $userId, 'Cierre automático al registrar pago');
            }
        }
        if (!isset($ids[$semana])) {
            $ids[$semana] = $this->cierres->cerrar($productorId, $semana, $userId, 'Cierre automático al registrar pago');
        }
        audit_log('productores', 'cerrar_semanas', ['productor_id' => $productorId, 'cierres' => $ids, 'origen' => 'pago'], 'productores_cierres');

        return $this->cierres->find($ids[$semana], true);
    }

    /** Se llama tras el rollback, así el intento fallido queda registrado aunque el pago no. */
    private function auditarFalloPago(array $motivo): void
    {
        try {
            audit_log('productores', 'registrar_pago_fallido', [
                'productor_id' => parse_int_range($_POST['productor_id'] ?? null, 1, PHP_INT_MAX),
                'tipo'         => PagoProductor::normalizarTipo($_POST['tipo'] ?? null),
                'monto'        => parse_decimal($_POST['monto'] ?? null, 2, 100000000),
                'motivo'       => $motivo,
            ], 'productores_abonos');
        } catch (Throwable $e) {
            error_log('[ADARA][productores] No se pudo auditar el fallo del pago: ' . $e->getMessage());
        }
    }

    // ----- Internos: vistas ------------------------------------------------

    /** @return array{cerrada:bool, litros:string, bruto_usd:string, precio_promedio:?string, arrastre_usd:string, mercancia_usd:string, pm_trans_usd:string, efectivo_usd:string, restante_usd:string, estado:?string} */
    private function resumenSemana(int $productorId, string $semana): array
    {
        $cierre = $this->cierres->findPorSemana($productorId, $semana);
        $consolidado = $this->cierres->consolidar($productorId, $semana);
        $pagos = $this->pagos->totalesSemana($productorId, $semana);

        $litros = $cierre['litros'] ?? $consolidado['litros'];
        $bruto = $cierre['bruto_usd'] ?? $consolidado['bruto_usd'];
        $arrastre = $cierre['arrastre_anterior_usd'] ?? $this->cierres->pendienteAntes($productorId, $semana);

        $restante = Caja::aCentavos($bruto) + Caja::aCentavos($arrastre)
            - Caja::aCentavos($pagos['mercancia']) - Caja::aCentavos($pagos['pm_trans']) - Caja::aCentavos($pagos['efectivo']);

        return [
            'cerrada'         => $cierre !== null,
            'litros'          => (string) $litros,
            'bruto_usd'       => (string) $bruto,
            'precio_promedio' => (float) $litros > 0 ? number_format((float) $bruto / (float) $litros, 4, '.', '') : null,
            'arrastre_usd'    => (string) $arrastre,
            'mercancia_usd'   => $pagos['mercancia'],
            'pm_trans_usd'    => $pagos['pm_trans'],
            'efectivo_usd'    => $pagos['efectivo'],
            'restante_usd'    => Caja::desdeCentavos($restante),
            'estado'          => $cierre['estado'] ?? null,
        ];
    }

    /** Promedio de las últimas 8 semanas terminadas; el mes se proyecta como 4 semanas al precio vigente. */
    private function proyeccion(int $productorId, string $semanaActual, string $precioVigente): array
    {
        $semanas = $this->entregas->semanasRecientes($productorId, $semanaActual, 8);
        $n = count($semanas);
        $litros = $n > 0 ? array_sum(array_map(static fn ($s) => (float) $s['litros'], $semanas)) / $n : 0.0;
        $monto = $n > 0 ? array_sum(array_map(static fn ($s) => (float) $s['monto_usd'], $semanas)) / $n : 0.0;

        return [
            'semanas'               => $semanas,
            'precio_vigente'        => $precioVigente,
            'promedio_litros'       => number_format($litros, 3, '.', ''),
            'promedio_ingreso_usd'  => number_format($monto, 2, '.', ''),
            'litros_mes'            => number_format($litros * 4, 3, '.', ''),
            'ingreso_mes_usd'       => number_format(round($litros * 4 * (float) $precioVigente, 2), 2, '.', ''),
        ];
    }

    /** @return array{0:string, 1:array} */
    private function retornoEntrega(string $fecha): array
    {
        $volver = $_POST['volver'] ?? '';
        if (!is_string($volver) || !in_array($volver, self::RETORNOS_ENTREGA, true)) {
            $volver = 'productores_entrega';
        }
        $productorId = parse_int_range($_POST['productor_id'] ?? null, 1, PHP_INT_MAX);
        $productor = $productorId === null ? null : $this->productores->find($productorId);

        return match ($volver) {
            'productor_control'           => [$volver, [
                'id'     => $productorId,
                'semana' => CierreSemanal::semanaDe($fecha, CierreSemanal::diaCierreValido($productor['dia_cierre'] ?? CierreSemanal::DIA_CIERRE_DEFAULT)),
            ]],
            'productores_control_semanal' => [$volver, ['semana' => CierreSemanal::semanaDe($fecha, CierreSemanal::DIA_CIERRE_DEFAULT)]],
            'control_leche'               => [$volver, ['vista' => 'diaria', 'desde' => $fecha, 'hasta' => $fecha]],
            default                       => [$volver, ['fecha' => $fecha]],
        };
    }

    private static function semanaSolicitada(mixed $raw, int $diaCierre): string
    {
        $hoy = date('Y-m-d');
        $fecha = is_string($raw) ? (parse_date($raw) ?? $hoy) : $hoy;
        return CierreSemanal::semanaDe(min($fecha, $hoy), $diaCierre);
    }

    /** @return list<string> */
    private static function diasDeSemana(string $semana): array
    {
        $inicio = new DateTimeImmutable($semana);
        return array_map(static fn (int $i): string => $inicio->modify("+$i days")->format('Y-m-d'), range(0, 6));
    }

    /** @return array<string, string>  semana_inicio => etiqueta, de la actual hacia atrás */
    private static function semanasPago(string $semanaActual, int $n): array
    {
        $out = [];
        $inicio = new DateTimeImmutable($semanaActual);
        for ($i = 0; $i < $n; $i++) {
            $s = $inicio->modify('-' . ($i * 7) . ' days');
            $out[$s->format('Y-m-d')] = sprintf(
                'Semana del %s al %s%s',
                $s->format('d/m'),
                $s->modify('+6 days')->format('d/m/Y'),
                $i === 0 ? ' (actual)' : ''
            );
        }
        return $out;
    }

    /** @return array{0:string, 1:string} */
    private static function rango(mixed $desdeRaw, mixed $hastaRaw, string $desdeDefault): array
    {
        $hoy = date('Y-m-d');
        $desde = (is_string($desdeRaw) ? parse_date($desdeRaw) : null) ?? $desdeDefault;
        $hasta = (is_string($hastaRaw) ? parse_date($hastaRaw) : null) ?? $hoy;
        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        $limite = (new DateTimeImmutable($hasta))->modify('-' . self::MAX_DIAS_PERIODO . ' days')->format('Y-m-d');
        return [max($desde, $limite), $hasta];
    }

    private static function queryString(string $key, int $maxLen): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? mb_substr(trim($value), 0, $maxLen) : '';
    }

    private static function snapshotCierre(array $c): array
    {
        return array_intersect_key($c, array_flip([
            'litros', 'bruto_usd', 'arrastre_anterior_usd', 'abonos_usd', 'pagado_usd', 'saldo_usd', 'estado', 'notas',
        ]));
    }

    // ----- Internos: seguridad y validación --------------------------------

    private static function esAdmin(): bool
    {
        return (current_user()['rol'] ?? null) === 'admin';
    }

    /** El permiso RBAC de la ruta no basta: estas acciones son exclusivas del rol administrador. */
    private function exigirAdmin(string $accion): void
    {
        if (self::esAdmin()) {
            return;
        }
        audit_log('seguridad', 'acceso_denegado', ['accion' => $accion, 'p' => $_GET['p'] ?? null]);
        render_forbidden();
        exit;
    }

    /** Para pantallas sin errores por campo: cada error se muestra como flash. */
    private function fallar(array $errors, string $page, array $query = []): never
    {
        foreach (array_slice(array_values($errors), 0, 10) as $mensaje) {
            flash('error', $mensaje);
        }
        if (count($errors) > 10) {
            flash('error', sprintf('… y %d error(es) más.', count($errors) - 10));
        }
        redirect($page, $query);
    }

    /** @return array<string, mixed> */
    private function validarProductor(bool $editando): array
    {
        $errors = [];

        $nombre = preg_replace('/\s+/u', ' ', post_string('nombre', 200));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
            $errors['nombre'] = 'El nombre debe tener entre 2 y 120 caracteres.';
        }

        $documentoRaw = post_documento_compuesto('documento', 30);
        $telefono = post_telefono_compuesto('telefono', 30);
        $documento = $documentoRaw;
        if ($documento !== '') {
            $documento = normalize_documento($documento);
            if ($documento === null) {
                $errors['documento'] = 'Formato inválido. Ej.: V-12345678 o J-12345678-9.';
            }
        }
        if ($telefono !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $telefono)) {
            $errors['telefono'] = 'Teléfono inválido (7 a 20 dígitos).';
        }

        $correo = mb_strtolower(post_string('correo', 150));
        if ($correo !== '' && (mb_strlen($correo) > 100 || filter_var($correo, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['correo'] = 'Correo inválido.';
        }

        $direccion = post_string('direccion', 200);
        if (mb_strlen($direccion) > 160) {
            $errors['direccion'] = 'Máximo 160 caracteres.';
        }

        $precio = parse_decimal($_POST['precio_litro_usd'] ?? null, 4, 100);
        if ($precio === null) {
            $errors['precio_litro_usd'] = 'Precio inválido: mayor que 0, hasta 4 decimales.';
        }

        $credito = parse_int_range($_POST['credito_dias'] ?? null, 0, 90);
        if ($credito === null) {
            $errors['credito_dias'] = 'Días de crédito entre 0 y 90.';
        }

        $diaCierre = parse_int_range($_POST['dia_cierre'] ?? null, 1, 7);
        if ($diaCierre === null) {
            $errors['dia_cierre'] = 'Selecciona el día de cierre de semana (lunes a domingo).';
        }

        $grupoCierre = preg_replace('/\s+/u', ' ', post_string('grupo_cierre', 80));
        if (mb_strlen($grupoCierre) > 50) {
            $errors['grupo_cierre'] = 'La etiqueta de grupo admite hasta 50 caracteres.';
        }

        $tolerancia = $_POST['tolerancia_pago'] ?? '';
        if (!is_string($tolerancia) || !isset(Productor::TOLERANCIAS[$tolerancia])) {
            $errors['tolerancia_pago'] = 'Selecciona la tolerancia de pago: Bajo, Medio o Alto.';
        }

        if ($errors) {
            $_POST['documento'] = $documentoRaw;
            $_POST['telefono'] = $telefono;
            throw new ValidationException($errors);
        }

        $data = [
            'nombre'           => $nombre,
            'documento'        => $documento ?: null,
            'telefono'         => $telefono ?: null,
            'correo'           => $correo ?: null,
            'direccion'        => $direccion ?: null,
            'precio_litro_usd' => $precio,
            'credito_dias'     => $credito,
            'dia_cierre'       => $diaCierre,
            'grupo_cierre'     => $grupoCierre !== '' ? $grupoCierre : null,
            'tolerancia_pago'  => $tolerancia,
        ];
        if ($editando) {
            $data['activo'] = isset($_POST['activo']) || ($_POST['estado'] ?? '') === 'activo' ? 1 : 0;
            $data['motivo_precio'] = post_string('motivo_precio', 160);
        }
        return $data;
    }

    private function findOr404(): array
    {
        $id = parse_int_range($_GET['id'] ?? null, 1, PHP_INT_MAX);
        $productor = $id === null ? null : $this->productores->find($id);
        if ($productor === null) {
            render_not_found();
            exit;
        }
        return $productor;
    }

    private function failOnDuplicate(PDOException $e, string $page, array $query = []): never
    {
        $errors = match (duplicate_key($e)) {
            'uq_productores_nombre'    => ['nombre' => 'Ya existe un productor con ese nombre.'],
            'uq_productores_documento' => ['documento' => 'Ya existe un productor con ese documento.'],
            default                    => throw $e,
        };
        form_fail($_POST, $errors, $page, $query);
    }

    /** '0.5500' (BD) y '0.55' (formulario) son el mismo precio: se comparan a 4 decimales fijos. */
    private static function mismoDecimal(mixed $a, mixed $b): bool
    {
        return number_format((float) $a, 4, '.', '') === number_format((float) $b, 4, '.', '');
    }
}
