<?php
declare(strict_types=1);

/**
 * Control de Leche: consolidado global de entregas (por día y por semana calendario ISO, sin importar
 * el ciclo de cada productor), planilla física de recolección y motor de costos de producción.
 */
final class ControlLecheController
{
    private const VISTAS = ['diaria', 'semanal', 'produccion'];
    private const MAX_DIAS_PERIODO = 366;
    private const DOW = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
    private const FORMULAS_DEFAULT = [
        ['nombre' => 'Queso Guayanés', 'litros_ref' => '5', 'kg_ref' => '1'],
        ['nombre' => 'Queso Blanco', 'litros_ref' => '10', 'kg_ref' => '7'],
        ['nombre' => 'Queso de Mano', 'litros_ref' => '10', 'kg_ref' => '6.5'],
        ['nombre' => 'Queso Telita', 'litros_ref' => '10', 'kg_ref' => '7'],
    ];

    private EntregaLeche $entregas;
    private Productor $productores;
    private CostoProduccion $costos;

    public function __construct()
    {
        $db = get_db();
        $this->entregas = new EntregaLeche($db);
        $this->productores = new Productor($db);
        $this->costos = new CostoProduccion($db);
    }

    // ----- Vistas diaria / semanal / producción ----------------------------

    public function index(): void
    {
        $vista = $_GET['vista'] ?? 'diaria';
        $vista = is_string($vista) && in_array($vista, self::VISTAS, true) ? $vista : 'diaria';
        [$desde, $hasta] = self::rango($_GET['desde'] ?? null, $_GET['hasta'] ?? null);
        $pid = parse_int_range($_GET['pid'] ?? null, 1, PHP_INT_MAX);

        $detalle = $this->entregas->detalleRango($desde, $hasta, $pid);
        $porDia = $this->entregas->consolidadoPorDia($desde, $hasta, $pid);
        $porSemana = $this->entregas->consolidadoPorSemana($desde, $hasta, $pid);

        $litrosMil = 0;
        $montoCent = 0;
        foreach ($porDia as $d) {
            $litrosMil += (int) round((float) $d['litros'] * 1000);
            $montoCent += Caja::aCentavos($d['monto_usd']);
        }
        $nDias = count($porDia);
        $user = current_user();
        $prodFecha = self::fechaGet('prod_fecha') ?? date('Y-m-d');
        $fechaHoja = self::fechaGet('fecha') ?? $hasta;

        view('control_leche', [
            'vista'          => $vista,
            'desde'          => $desde,
            'hasta'          => $hasta,
            'productorId'    => $pid ?? 0,
            'productores'    => $this->productores->activos(),
            'transportistas' => [],
            'kpis'           => [
                'litros'    => $litrosMil / 1000,
                'monto'     => $montoCent / 100,
                'dias'      => $nDias,
                'promedio'  => $nDias > 0 ? $litrosMil / 1000 / $nDias : 0.0,
                'registros' => count($detalle),
            ],
            'dias'           => self::armarDias($porDia, $detalle),
            'semanas'        => self::armarSemanas($porSemana, $detalle),
            ...$this->datosCostos($prodFecha),
            ...$this->datosHoja($fechaHoja),
            'puedeCrear'     => has_permiso($user, 'control_leche', 'crear'),
            'puedeEditar'    => has_permiso($user, 'control_leche', 'editar'),
        ]);
    }

    public function diarioGuardar(): void
    {
        $fecha = parse_date(post_string('fecha', 10));
        if ($fecha === null) {
            flash('error', 'Fecha inválida.');
            redirect('control_leche', ['vista' => 'diaria']);
        }
        $userId = (int) current_user()['id'];
        try {
            Database::transaction(function () use ($fecha, $userId): void {
                $diario = new ControlLecheDiario(get_db());
                $faltante = $diario->guardarProductores($fecha, $this->filasPlanilla($_POST));
                $diario->upsert($fecha, [
                    'ventas_leche'    => $_POST['ventas_leche'] ?? '0',
                    'cortesias_leche' => $_POST['cortesias_leche'] ?? '0',
                    'vales_leche'     => $_POST['vales_leche'] ?? '0',
                    'leche_faltante'  => $faltante,
                ], $userId);
                $this->guardarLitrosPlanilla($fecha, $_POST['litros'] ?? [], $userId);
            });
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('control_leche', ['vista' => 'diaria', 'desde' => $fecha, 'hasta' => $fecha, 'fecha' => $fecha]);
        } catch (PDOException $e) {
            $detalle = (string) ($e->errorInfo[2] ?? '');
            if (str_contains($detalle, 'cerrada')) {
                flash('error', 'No se pudo guardar: esa entrega pertenece a una semana cerrada.');
                redirect('control_leche', ['vista' => 'diaria', 'desde' => $fecha, 'hasta' => $fecha, 'fecha' => $fecha]);
            }
            throw $e;
        }
        flash('success', 'Resumen del día guardado.');
        redirect('control_leche', ['vista' => 'diaria', 'desde' => $fecha, 'hasta' => $fecha, 'fecha' => $fecha]);
    }

    /**
     * Densidad y faltante por productor. El total de faltante no se tipea: es la suma.
     *
     * @param array<string, mixed> $post
     * @return array<int, array{densidad:string, faltante:string}>
     */
    private function filasPlanilla(array $post): array
    {
        $densidad = is_array($post['densidad'] ?? null) ? $post['densidad'] : [];
        $faltante = is_array($post['faltante'] ?? null) ? $post['faltante'] : [];
        $ids = array_unique(array_merge(array_keys($densidad), array_keys($faltante)));
        $out = [];
        foreach ($ids as $idRaw) {
            $id = parse_int_range(is_scalar($idRaw) ? (string) $idRaw : null, 1, PHP_INT_MAX);
            if ($id === null || $this->productores->paraEntrega($id) === null) {
                continue;
            }
            $denRaw = is_scalar($densidad[$idRaw] ?? null) ? trim((string) $densidad[$idRaw]) : '';
            $falRaw = is_scalar($faltante[$idRaw] ?? null) ? trim((string) $faltante[$idRaw]) : '';
            $den = $denRaw === '' ? '' : parse_decimal($denRaw, 3, 100000, true);
            $fal = $falRaw === '' ? '0.00' : parse_decimal($falRaw, 2, 1000000, true);
            if ($denRaw !== '' && $den === null) {
                throw new ValidationException(['densidad' => 'Densidad inválida: 0 o más, hasta 3 decimales.']);
            }
            if ($fal === null) {
                throw new ValidationException(['faltante' => 'Faltante inválido: 0 o más, hasta 2 decimales.']);
            }
            $out[$id] = ['densidad' => $den, 'faltante' => $fal];
        }
        return $out;
    }

    /** Litros escritos en la planilla: crean o actualizan la entrega de ese productor en la fecha. */
    private function guardarLitrosPlanilla(string $fecha, mixed $posted, int $userId): void
    {
        if (!is_array($posted)) {
            return;
        }
        foreach ($posted as $idRaw => $valor) {
            $id = parse_int_range(is_scalar($idRaw) ? (string) $idRaw : null, 1, PHP_INT_MAX);
            $raw = is_scalar($valor) ? trim((string) $valor) : '';
            if ($id === null || $raw === '') {
                continue;
            }
            $litros = parse_decimal($raw, 3, 100000);
            if ($litros === null) {
                throw new ValidationException(['litros' => 'Litros inválidos: mayor que 0, hasta 3 decimales.']);
            }
            $productor = $this->productores->paraEntrega($id);
            if ($productor === null) {
                throw new ValidationException(['litros' => 'Hay un productor de la planilla que ya no está activo.']);
            }
            $actual = $this->entregas->findPorFecha($id, $fecha, true);
            if ($actual === null) {
                $this->entregas->create([
                    'productor_id'     => $id,
                    'fecha'            => $fecha,
                    'litros'           => $litros,
                    'precio_litro_usd' => $productor['precio_litro_usd'],
                    'traslado_usd'     => '0',
                    'cuajada_kg'       => '0',
                    'observaciones'    => null,
                    'registrado_por'   => $userId,
                ]);
                continue;
            }
            if (Producto::aMilesimas((string) $actual['litros']) === Producto::aMilesimas($litros)) {
                continue;
            }
            $this->entregas->actualizar((int) $actual['id'], ['litros' => $litros]);
        }
    }

    /** Planilla física de recolección: productores activos en orden alfabético, lista para imprimir o guardar como PDF. */
    public function generarPlanillaPdf(): void
    {
        $fecha = self::fechaGet('fecha') ?? date('Y-m-d');
        header('Content-Disposition: inline; filename="planilla_recoleccion_' . $fecha . '.html"');
        view('control_leche_planilla', $this->datosHoja($fecha) + [
            'fecha'   => $fecha,
            'empresa' => APP_EMPRESA,
        ]);
    }

    // ----- Motor de costos -------------------------------------------------

    /** Vista previa sin persistir: el cálculo vive solo en el servidor. */
    public function costosCalcular(): void
    {
        try {
            $entrada = self::validarCostos();
            $leche = $this->entregas->totalesCostoDia($entrada['fecha']);
            $entrada = $this->aplicarLecheNeta($entrada, $leche);
            $resultado = CostoProduccion::calcular($entrada, $leche);
        } catch (ValidationException $e) {
            self::json(422, ['ok' => false, 'errores' => $e->errors]);
        }
        self::json(200, ['ok' => true, 'resultado' => $resultado]);
    }

    /** Recalcula dentro de la transacción (la leche del día se lee bloqueada) y guarda la fotografía del día. */
    public function costosGuardar(): void
    {
        $userId = current_user()['id'];
        try {
            $entrada = self::validarCostos();
            [$id, $resultado] = Database::transaction(function () use ($entrada, $userId): array {
                $leche = $this->lecheDiaBloqueada($entrada['fecha']);
                $entrada = $this->aplicarLecheNeta($entrada, $leche);
                $resultado = CostoProduccion::calcular($entrada, $leche);
                $id = $this->costos->guardar($resultado, $userId);
                audit_log('control_leche', 'guardar_costos', [
                    'fecha'              => $resultado['fecha'],
                    'litros_procesados'  => $resultado['litros_procesados'],
                    'gasto_total_usd'    => $resultado['gasto_total_usd'],
                    'kg_total'           => $resultado['kg_total'],
                    'margen_pct'         => $resultado['margen_pct'],
                    'precios'            => array_column($resultado['quesos'], 'precio_venta_kg_usd', 'nombre'),
                ], 'produccion_costos', $id);
                return [$id, $resultado];
            });
        } catch (ValidationException $e) {
            self::json(422, ['ok' => false, 'errores' => $e->errors]);
        } catch (PDOException $e) {
            if (duplicate_key($e) === 'uq_costos_fecha') {
                self::json(409, ['ok' => false, 'errores' => ['fecha' => 'Otro usuario guardó los costos de ese día al mismo tiempo. Vuelve a guardar.']]);
            }
            throw $e;
        }
        self::json(200, ['ok' => true, 'id' => $id, 'resultado' => $resultado]);
    }

    // ----- Internos --------------------------------------------------------

    /** @return array<string, mixed>  variables de la pestaña Producción */
    private function datosCostos(string $fecha): array
    {
        $leche = $this->entregas->totalesCostoDia($fecha);
        $guardado = $this->costos->findPorFecha($fecha);
        $plantilla = $guardado ?? $this->costos->ultimaAntesDe($fecha);
        $merma = (new ControlLecheDiario(get_db()))->find($fecha);
        $neta = ControlLecheDiario::neta((string) $leche['litros'], $merma);

        $formulas = $plantilla === null
            ? array_map(static fn (array $f): array => $f + ['litros_dia' => '', 'sal' => '', 'empaque' => '', 'otros' => ''], self::FORMULAS_DEFAULT)
            : array_map(static fn (array $q): array => [
                'nombre'     => (string) $q['nombre'],
                'litros_ref' => rtrim(rtrim((string) $q['litros_ref'], '0'), '.'),
                'kg_ref'     => rtrim(rtrim((string) $q['kg_ref'], '0'), '.'),
                'litros_dia' => $guardado !== null ? (string) $q['litros_dia'] : '',
                'sal'        => $guardado !== null ? (string) $q['sal_usd'] : '',
                'empaque'    => $guardado !== null ? (string) $q['empaque_usd'] : '',
                'otros'      => $guardado !== null ? (string) $q['otros_usd'] : '',
            ], $plantilla['quesos']);

        $c = $guardado['cabecera'] ?? null;
        return [
            'costos'   => [
                'fecha'            => $fecha,
                'guardado'         => $c !== null,
                'guardado_en'      => $c['actualizado_en'] ?? $c['creado_en'] ?? null,
                'litros_recibidos' => $leche['litros'],
                'litros_procesada' => $c['litros_procesados'] ?? ((float) $neta > 0 ? $neta : $leche['litros']),
                'litros_neta'      => $neta,
                'costo_leche'      => $leche['costo_usd'],
                'traslado'         => $c['transporte_usd'] ?? $leche['traslado_usd'],
                'restante'         => $leche['litros'],
                'nomina'           => $c['nomina_usd'] ?? '',
                'personal'         => $c['personal_extra_usd'] ?? '',
                'operativo'        => $c['operativo_usd'] ?? '',
                'otros'            => $c['otros_usd'] ?? '',
                'margen'           => $c['margen_pct'] ?? $plantilla['cabecera']['margen_pct'] ?? '36',
            ],
            'formulas' => $formulas,
        ];
    }

    /**
     * Si el usuario dejó la leche bruta (recibida) y hay neta en el control semanal, usa la neta.
     *
     * @param array<string, mixed> $entrada
     * @param array{litros:string, costo_usd:string} $leche
     * @return array<string, mixed>
     */
    private function aplicarLecheNeta(array $entrada, array $leche): array
    {
        $merma = (new ControlLecheDiario(get_db()))->find($entrada['fecha']);
        $neta = ControlLecheDiario::neta((string) $leche['litros'], $merma);
        $bruta = (float) $leche['litros'];
        $enviada = (float) $entrada['litros_procesados'];
        if ((float) $neta > 0 && abs($enviada - $bruta) < 0.0005 && abs((float) $neta - $bruta) >= 0.0005) {
            $entrada['litros_procesados'] = $neta;
        }
        return $entrada;
    }

    /** Bloquea las entregas del día para que el costo base no cambie entre el cálculo y el guardado. */
    private function lecheDiaBloqueada(string $fecha): array
    {
        $stmt = get_db()->prepare(
            'SELECT COALESCE(SUM(litros), 0) AS litros, COALESCE(SUM(subtotal_usd), 0) AS costo_usd
             FROM productores_entregas WHERE fecha = :fecha FOR UPDATE'
        );
        $stmt->execute(['fecha' => $fecha]);
        $row = $stmt->fetch();
        return ['litros' => (string) $row['litros'], 'costo_usd' => (string) $row['costo_usd']];
    }

    /**
     * @return array{fecha:string, litros_procesados:string, margen:string, gastos:array<string,string>,
     *               formulas:list<array{nombre:string, litros_ref:string, kg_ref:string, litros_dia:?string, sal:string, empaque:string, otros:string}>}
     */
    private static function validarCostos(): array
    {
        $errors = [];

        $fecha = parse_date($_POST['fecha'] ?? '');
        if ($fecha === null) {
            $errors['fecha'] = 'Fecha inválida.';
        } elseif ($fecha > date('Y-m-d')) {
            $errors['fecha'] = 'No se calculan costos de días futuros.';
        }

        $litros = parse_decimal($_POST['litros_procesados'] ?? null, 3, 1000000);
        if ($litros === null) {
            $errors['litros_procesados'] = 'Litros procesados inválidos: mayor que 0, hasta 3 decimales.';
        }

        $margen = parse_decimal($_POST['margen'] ?? null, 2, CostoProduccion::MARGEN_MAX, true);
        if ($margen === null) {
            $errors['margen'] = sprintf('Margen inválido: 0 a %d %%, hasta 2 decimales.', CostoProduccion::MARGEN_MAX);
        }

        $gastos = [];
        foreach (CostoProduccion::GASTOS as $clave => $etiqueta) {
            $gastos[$clave] = self::montoOpcional($_POST[$clave] ?? '', 2, 10000000);
            if ($gastos[$clave] === null) {
                $errors[$clave] = "$etiqueta: monto inválido (hasta 2 decimales).";
            }
        }

        $raw = $_POST['formulas'] ?? null;
        $formulas = [];
        if (!is_array($raw) || $raw === []) {
            $errors['formulas'] = 'Agrega al menos un tipo de queso.';
        } elseif (count($raw) > CostoProduccion::MAX_FORMULAS) {
            $errors['formulas'] = sprintf('Máximo %d tipos de queso.', CostoProduccion::MAX_FORMULAS);
        } else {
            $nombres = [];
            foreach (array_values($raw) as $i => $f) {
                $n = $i + 1;
                if (!is_array($f)) {
                    $errors["formulas.$i"] = "Fila $n inválida.";
                    continue;
                }
                $nombre = is_string($f['nombre'] ?? null) ? preg_replace('/\s+/u', ' ', trim($f['nombre'])) : '';
                $litrosRef = parse_decimal($f['litros_ref'] ?? null, 3, 100000);
                $kgRef = parse_decimal($f['kg_ref'] ?? null, 3, 100000);
                $litrosDiaRaw = $f['litros_dia'] ?? '';
                $litrosDia = $litrosDiaRaw === '' ? null : parse_decimal($litrosDiaRaw, 3, 1000000);
                $extras = [];
                foreach (CostoProduccion::EXTRAS as $k) {
                    $extras[$k] = self::montoOpcional($f[$k] ?? '', 2, 1000000);
                }

                if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
                    $errors["formulas.$i"] = "Fila $n: el nombre del queso debe tener entre 2 y 80 caracteres.";
                } elseif (isset($nombres[mb_strtolower($nombre)])) {
                    $errors["formulas.$i"] = "Fila $n: el queso \"$nombre\" está repetido.";
                } elseif ($litrosRef === null || $kgRef === null) {
                    $errors["formulas.$i"] = "$nombre: indica litros y kg de referencia mayores que 0 (hasta 3 decimales).";
                } elseif ((float) $kgRef > (float) $litrosRef) {
                    $errors["formulas.$i"] = "$nombre: el rendimiento no puede superar 1 kg por litro.";
                } elseif ($litrosDiaRaw !== '' && $litrosDia === null) {
                    $errors["formulas.$i"] = "$nombre: litros del día inválidos (mayor que 0, hasta 3 decimales).";
                } elseif (in_array(null, $extras, true)) {
                    $errors["formulas.$i"] = "$nombre: sal, empaque y otros deben ser montos válidos (hasta 2 decimales).";
                } else {
                    $nombres[mb_strtolower($nombre)] = true;
                    $formulas[] = ['nombre' => $nombre, 'litros_ref' => $litrosRef, 'kg_ref' => $kgRef, 'litros_dia' => $litrosDia] + $extras;
                }
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return [
            'fecha'             => $fecha,
            'litros_procesados' => $litros,
            'margen'            => $margen,
            'gastos'            => $gastos,
            'formulas'          => $formulas,
        ];
    }

    private static function montoOpcional(mixed $raw, int $decimales, float $max): ?string
    {
        if ($raw === '' || $raw === null) {
            return '0';
        }
        return parse_decimal($raw, $decimales, $max, true);
    }

    /**
     * @param list<array<string, mixed>> $porDia
     * @param list<array<string, mixed>> $detalle
     * @return list<array<string, mixed>>
     */
    private static function armarDias(array $porDia, array $detalle): array
    {
        $porFecha = [];
        foreach ($detalle as $d) {
            $porFecha[(string) $d['fecha']][] = $d;
        }
        return array_map(static function (array $d) use ($porFecha): array {
            $dt = new DateTimeImmutable((string) $d['fecha']);
            return [
                'fecha'       => (string) $d['fecha'],
                'etiqueta'    => $dt->format('d/m/Y') . ' (' . self::DOW[(int) $dt->format('N')] . ')',
                'litros'      => (float) $d['litros'],
                'monto'       => (float) $d['monto_usd'],
                'cuajada'     => (float) $d['cuajada_kg'],
                'traslado'    => (float) $d['traslado_usd'],
                'productores' => (int) $d['productores'],
                'detalle'     => $porFecha[(string) $d['fecha']] ?? [],
            ];
        }, $porDia);
    }

    /**
     * @param list<array<string, mixed>> $porSemana
     * @param list<array<string, mixed>> $detalle
     * @return list<array<string, mixed>>
     */
    private static function armarSemanas(array $porSemana, array $detalle): array
    {
        $porInicio = [];
        foreach ($detalle as $d) {
            $dt = new DateTimeImmutable((string) $d['fecha']);
            $inicio = $dt->modify('-' . ((int) $dt->format('N') - 1) . ' days')->format('Y-m-d');
            $porInicio[$inicio]['detalle'][] = $d;
            $porInicio[$inicio]['productores'][(int) $d['productor_id']] = (string) $d['productor'];
        }
        return array_map(static function (array $s) use ($porInicio): array {
            $nombres = $porInicio[$s['semana_inicio']]['productores'] ?? [];
            natcasesort($nombres);
            return [
                'inicio'      => $s['semana_inicio'],
                'fin'         => $s['semana_fin'],
                'etiqueta'    => (new DateTimeImmutable($s['semana_inicio']))->format('d/m') . ' – ' . (new DateTimeImmutable($s['semana_fin']))->format('d/m/Y'),
                'numero'      => $s['semana'],
                'litros'      => (float) $s['litros'],
                'monto'       => (float) $s['monto_usd'],
                'cuajada'     => (float) $s['cuajada_kg'],
                'traslado'    => (float) $s['traslado_usd'],
                'productores' => array_values($nombres),
                'detalle'     => $porInicio[$s['semana_inicio']]['detalle'] ?? [],
            ];
        }, $porSemana);
    }

    /** @return array{0:string, 1:string} */
    private static function rango(mixed $desdeRaw, mixed $hastaRaw): array
    {
        $hoy = date('Y-m-d');
        $desde = (is_string($desdeRaw) ? parse_date($desdeRaw) : null) ?? date('Y-m-01');
        $hasta = (is_string($hastaRaw) ? parse_date($hastaRaw) : null) ?? $hoy;
        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        $limite = (new DateTimeImmutable($hasta))->modify('-' . self::MAX_DIAS_PERIODO . ' days')->format('Y-m-d');
        return [max($desde, $limite), $hasta];
    }

    /** @return array{fechaHoja:string, filasHoja:list<array<string, mixed>>, seccionesHoja:array<int, array{0:int, 1:string}>, mermaHoja:array<string, string>, totalRecibido:float} */
    private function datosHoja(string $fecha): array
    {
        $entregas = [];
        foreach ($this->entregas->delDia($fecha) as $row) {
            $entregas[(int) $row['productor_id']] = $row;
        }
        $detalle = (new ControlLecheDiario(get_db()))->detalleProductores($fecha);
        $filas = [];
        $recibido = 0.0;
        $productores = $this->productores->activos();
        foreach ($entregas as $pid => $entrega) {
            $ya = false;
            foreach ($productores as $p) {
                if ((int) $p['id'] === (int) $pid) {
                    $ya = true;
                    break;
                }
            }
            if ($ya) {
                continue;
            }
            $extra = $this->productores->find((int) $pid);
            if ($extra !== null) {
                $productores[] = ['id' => (int) $extra['id'], 'nombre' => (string) $extra['nombre']];
            }
        }
        usort($productores, static fn (array $a, array $b): int => strcasecmp((string) $a['nombre'], (string) $b['nombre']));
        foreach ($productores as $n => $p) {
            $pid = (int) $p['id'];
            $entrega = $entregas[$pid] ?? null;
            $litros = $entrega['litros'] ?? '';
            $recibido += (float) $litros;
            $marca = $detalle[$pid] ?? ['densidad' => '', 'faltante' => ''];
            $filas[] = [
                'n'            => $n + 1,
                'productor_id' => $pid,
                'nombre'       => (string) $p['nombre'],
                'litros'       => $litros,
                'densidad'     => $marca['densidad'],
                'faltante'     => $marca['faltante'],
            ];
        }
        $min = max(count($filas) + 8, 24);
        for ($i = count($filas); $i < $min; $i++) {
            $filas[] = ['n' => $i + 1, 'productor_id' => null, 'nombre' => '', 'litros' => '', 'densidad' => '', 'faltante' => ''];
        }
        $merma = (new ControlLecheDiario(get_db()))->find($fecha) ?? [
            'ventas_leche' => '', 'cortesias_leche' => '', 'vales_leche' => '', 'leche_faltante' => '',
        ];
        $faltanteFilas = 0.0;
        foreach ($filas as $fila) {
            $faltanteFilas += (float) ($fila['faltante'] ?? 0);
        }
        $faltante = $faltanteFilas > 0 ? $faltanteFilas : (float) ($merma['leche_faltante'] ?? 0);
        $neto = max(0, $recibido
            - (float) ($merma['ventas_leche'] ?? 0)
            - (float) ($merma['cortesias_leche'] ?? 0)
            - (float) ($merma['vales_leche'] ?? 0)
            - $faltante);
        return [
            'fechaHoja'      => $fecha,
            'filasHoja'      => $filas,
            'seccionesHoja'  => self::seccionesDerecha(count($filas)),
            'mermaHoja'      => $merma,
            'totalRecibido'  => $recibido,
            'totalNeto'      => $neto,
        ];
    }

    /** @return array<int, array{0:int, 1:string}> */
    private static function seccionesDerecha(int $total): array
    {
        if ($total <= 0) {
            return [];
        }
        $base = [1 => 'VENTAS', 5 => 'CORTESIA', 10 => 'VALES', 15 => 'TOTAL FALTANTE', 21 => 'TOTAL', 35 => 'SELLO'];
        $labels = [];
        foreach ($base as $row => $lbl) {
            $r = max(1, min($total, (int) round($row * $total / 72)));
            while (isset($labels[$r]) && $r < $total) {
                $r++;
            }
            $labels[$r] = $lbl;
        }
        $starts = array_keys($labels);
        sort($starts);
        if ($starts === [] || $starts[0] !== 1) {
            array_unshift($starts, 1);
        }
        $out = [];
        foreach ($starts as $k => $s) {
            $next = $starts[$k + 1] ?? ($total + 1);
            $out[$s] = [$next - $s, $labels[$s] ?? ''];
        }
        return $out;
    }

    private static function fechaGet(string $clave): ?string
    {
        $raw = $_GET[$clave] ?? null;
        return is_string($raw) ? parse_date($raw) : null;
    }

    private static function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
