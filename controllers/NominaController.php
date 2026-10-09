<?php
declare(strict_types=1);

final class NominaController
{
    private const MOTIVO_MIN = 10;
    private const MOTIVO_MAX = 300;

    private Nomina $nomina;

    public function __construct()
    {
        $this->nomina = new Nomina(get_db());
    }

    public function guardar(): void
    {
        $this->persistir(false);
    }

    /** Congela la fotografía del ciclo: después de cerrar, días libres, cuotas y cobranzas de esa semana no cambian. */
    public function cerrar(): void
    {
        $this->exigirAdmin();
        $this->persistir(true);
    }

    /**
     * Cerrada → Abierta (borrador). Solo administradores y con motivo obligatorio, que queda en auditoria
     * dentro de la misma transacción: si la bitácora falla, la nómina no se reabre.
     */
    public function reabrirNomina(?string $semanaId = null): void
    {
        $this->exigirAdmin();
        $semana = parse_date($semanaId ?? ($_POST['semana'] ?? null));
        if ($semana === null) {
            flash('error', 'Semana inválida.');
            redirect('empleados', ['tab' => 'nomina']);
        }
        [$inicio, $fin] = Nomina::periodo($semana);
        $volver = ['tab' => 'nomina', 'semana' => $inicio];

        $motivo = preg_replace('/\s+/u', ' ', post_string('motivo', self::MOTIVO_MAX + 50));
        if (mb_strlen($motivo) < self::MOTIVO_MIN || mb_strlen($motivo) > self::MOTIVO_MAX) {
            flash('error', sprintf('Indica el motivo de la reapertura (%d a %d caracteres).', self::MOTIVO_MIN, self::MOTIVO_MAX));
            redirect('empleados', $volver);
        }

        try {
            $r = Database::transaction(function () use ($inicio, $fin, $motivo): array {
                $r = $this->nomina->reabrir($inicio);
                if ($r['reabiertas'] === 0) {
                    throw new ValidationException(['semana' => $r['pagadas'] > 0
                        ? 'La nómina de esta semana ya está pagada y no se puede reabrir.'
                        : 'La nómina de esta semana no está cerrada.']);
                }
                audit_log('empleados', 'reabrir_nomina', [
                    'semana_inicio' => $inicio,
                    'semana_fin'    => $fin,
                    'motivo'        => $motivo,
                    'filas'         => $r['reabiertas'],
                    'pagadas'       => $r['pagadas'],
                ], 'nomina_semanal');
                return $r;
            });
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('empleados', $volver);
        }

        flash('success', sprintf('Nómina reabierta: %d empleado%s vuelven a estar editables.', $r['reabiertas'], $r['reabiertas'] === 1 ? '' : 's'));
        if ($r['pagadas'] > 0) {
            flash('warning', sprintf('%d fila%s pagada%s se mantiene%s congelada%s.', $r['pagadas'], ...array_fill(0, 4, $r['pagadas'] === 1 ? '' : 's')));
        }
        redirect('empleados', $volver);
    }

    /** Préstamo interno: aumenta la deuda del empleado que luego se descuenta en cuotas. */
    public function prestamoStore(): void
    {
        $errors = [];
        $rawId = $_POST['empleado_id'] ?? $_GET['empleado_id'] ?? '';
        if (is_array($rawId)) {
            $rawId = reset($rawId);
        }
        $rawId = is_scalar($rawId) ? trim((string) $rawId) : '';
        $empId = parse_int_range($rawId !== '' ? $rawId : null, 1, PHP_INT_MAX);
        $emp = $empId !== null ? (new Empleado(get_db()))->find($empId) : null;
        if ($emp === null) {
            $errors['empleado_id'] = 'Empleado no válido.';
        }
        $monto = parse_decimal($_POST['monto_usd'] ?? ($_POST['monto_total'] ?? null), 2, 100000);
        if ($monto === null) {
            $errors['monto_usd'] = 'Monto inválido: mayor que 0, hasta 2 decimales.';
        }
        $cuota = parse_decimal($_POST['cuota_semanal'] ?? null, 2, 100000, true);
        if ($cuota === null) {
            $errors['cuota_semanal'] = 'Indica la cuota semanal (0 o más, hasta el monto).';
        } elseif ($monto !== null && Caja::aCentavos($cuota) > Caja::aCentavos($monto)) {
            $errors['cuota_semanal'] = 'La cuota no puede superar el monto del préstamo.';
        }
        $tipo = post_string('tipo', 20);
        if (!isset(PrestamoEmpleado::TIPOS[$tipo])) {
            $errors['tipo'] = 'Selecciona el tipo de préstamo.';
        }
        $fecha = parse_date($_POST['fecha'] ?? null);
        if ($fecha === null || $fecha > date('Y-m-d')) {
            $errors['fecha'] = 'Fecha inválida o futura.';
        }
        $concepto = preg_replace('/\s+/u', ' ', post_string('concepto', 140));
        if ($concepto === '') {
            $concepto = 'Préstamo interno';
        }
        if (mb_strlen($concepto) < 2 || mb_strlen($concepto) > 120) {
            $errors['concepto'] = 'Indica el concepto (2 a 120 caracteres).';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('empleados', ['tab' => 'directorio']);
        }

        $data = [
            'empleado_id'   => $empId,
            'fecha'         => $fecha,
            'monto_total'   => $monto,
            'cuota_semanal' => $cuota,
            'tipo'          => $tipo,
            'concepto'      => $concepto,
        ];
        $userId = current_user()['id'];
        Database::transaction(function () use ($data, $userId): void {
            $id = (new PrestamoEmpleado(get_db()))->create($data, $userId);
            audit_log('empleados', 'crear_prestamo', $data, 'hr_prestamos', $id);
        });
        flash('success', sprintf(
            'Préstamo de $%s registrado a %s %s. Cuota semanal $%s.',
            num($monto),
            $emp['nombres'],
            $emp['apellidos'],
            num($cuota)
        ));
        redirect('empleados', ['tab' => 'directorio']);
    }

    /** Dos hojas: nómina completa (oficial) y pago neto para caja. Una página de ancho al imprimir. */
    public function excel(): void
    {
        $datos = $this->calculada();
        $periodo = sprintf('%s al %s', self::dmy($datos['inicio']), self::dmy($datos['fin']));
        $tasa = $datos['tasa'] !== null ? ' · Tasa BCV ' . num($datos['tasa']) : '';

        $estilo = [
            'usd' => XlsxWriter::S_USD, 'usd4' => XlsxWriter::S_USD4, 'bs' => XlsxWriter::S_BS,
            'dias' => XlsxWriter::S_DIAS, 'texto' => XlsxWriter::S_TEXTO, 'fecha' => XlsxWriter::S_TEXTO,
        ];
        $columnas = self::columnas();
        $filas = [];
        foreach ($datos['filas'] as $i => $f) {
            $fila = [[$i + 1, XlsxWriter::S_TEXTO]];
            foreach ($columnas as $campo => [, $formato]) {
                $valor = $formato === 'fecha' ? self::dmy((string) $f[$campo]) : ($f[$campo] ?? '');
                $fila[] = [$valor, $estilo[$formato]];
            }
            $filas[] = $fila;
        }
        $total = [['TOTALES', XlsxWriter::S_TOTAL_TEXTO]];
        foreach ($columnas as $campo => [, $formato]) {
            $valor = in_array($formato, ['usd', 'usd4', 'bs', 'dias'], true) ? ($datos['totales'][$campo] ?? '') : '';
            $total[] = [$valor, $formato === 'bs' ? XlsxWriter::S_TOTAL_BS : ($valor === '' ? XlsxWriter::S_TOTAL_TEXTO : XlsxWriter::S_TOTAL_USD)];
        }
        $filas[] = $total;

        $pago = [];
        foreach (self::pagoNeto($datos) as $i => $p) {
            $pago[] = [[$i + 1, XlsxWriter::S_TEXTO], [$p['nombre'], XlsxWriter::S_TEXTO], [$p['cedula'], XlsxWriter::S_TEXTO], [$p['total'], XlsxWriter::S_USD]];
        }
        $pago[] = [['', XlsxWriter::S_TOTAL_TEXTO], ['TOTAL', XlsxWriter::S_TOTAL_TEXTO], ['', XlsxWriter::S_TOTAL_TEXTO], [$datos['totales']['resto_usd'], XlsxWriter::S_TOTAL_USD]];

        (new XlsxWriter())
            ->hoja('Nómina completa', APP_EMPRESA . ' — Nómina semanal del ' . $periodo . $tasa,
                array_merge(['N'], array_column($columnas, 0)), $filas)
            ->hoja('Pago neto', 'Pago neto en caja — Semana del ' . $periodo,
                ['N', 'Nombres', 'Cédula', 'Total a Pagar Neto'], $pago)
            ->enviar('nomina_' . $datos['inicio'] . '.xlsx');
    }

    /** Hoja 1: nómina completa oficial. Hoja 2: pago neto para cajeros (salto de página entre ambas). */
    public function pdf(): void
    {
        $datos = $this->calculada();
        header('Content-Disposition: inline; filename="nomina_' . $datos['inicio'] . '.html"');
        view('nomina_pdf', [
            'datos'    => $datos,
            'empresa'  => APP_EMPRESA,
            'columnas' => self::columnas(),
            'pago'     => self::pagoNeto($datos),
        ]);
    }

    /**
     * Hoja de firmas: empleados activos con contrato en el ciclo, en orden alfabético (nombres, apellidos).
     * Vales y préstamos (cuota) salen de la nómina de la semana; mercancía y observaciones se llenan a mano.
     */
    public function notariaPdf(): void
    {
        $datos = $this->calculada();
        $montos = array_column($datos['filas'], null, 'id');
        $filas = [];
        foreach ((new Empleado(get_db()))->vigentesEntre($datos['inicio'], $datos['fin'], 'nombres, apellidos') as $emp) {
            $n = $montos[$emp['id']] ?? [];
            $filas[] = [
                'nombre'        => $emp['nombres'] . ' ' . $emp['apellidos'],
                'cargo'         => $emp['cargo'],
                'vales'         => (string) ($n['vales'] ?? '0.00'),
                'mercancia'     => '0.00',
                'prestamo'      => (string) ($n['cuota_deuda'] ?? '0.00'),
                'observaciones' => '',
            ];
        }
        $personas = [];
        foreach ($filas as $f) {
            $personas[] = ['nombre' => $f['nombre']];
        }
        $dias = [];
        $d = new DateTimeImmutable($datos['inicio']);
        for ($i = 0; $i < 7; $i++) {
            $dias[] = $d->modify('+' . $i . ' days')->format('Y-m-d');
        }
        header('Content-Disposition: inline; filename="notaria_empleados_' . $datos['inicio'] . '.html"');
        view('notaria_hoja', [
            'titulo'    => 'NOTARÍA — EMPLEADOS',
            'semana'    => $datos['inicio'],
            'semanaFin' => $datos['fin'],
            'dias'      => $dias,
            'personas'  => $personas,
            'impreso'   => date('Y-m-d'),
            'volver'    => url('empleados', ['tab' => 'nomina', 'semana' => $datos['inicio']]),
            'rutaBase'  => 'rrhh_notaria',
        ]);
    }

    /**
     * Columnas de la nómina oficial.
     * @return array<string, array{0:string, 1:string}> campo => [título, formato usd|usd4|bs|dias|texto|fecha]
     */
    public static function columnas(): array
    {
        return [
            'fecha_ingreso'      => ['Fecha Entrada', 'fecha'],
            'cedula'             => ['Cédula', 'texto'],
            'nombres'            => ['Nombres', 'texto'],
            'apellidos'          => ['Apellidos', 'texto'],
            'cargo'              => ['Cargo', 'texto'],
            'salario_semanal'    => ['Salario Semanal', 'usd'],
            'salario_diario'     => ['Salario Diario', 'usd4'],
            'dias_no_trabajados' => ['Días no trabajados', 'dias'],
            'pagos_extras'       => ['Pagos Extras', 'usd'],
            'salario_mas_extras' => ['Salario + Extras', 'usd'],
            'vales'              => ['Vales / Mercancía', 'usd'],
            'desc_dias'          => ['Desc. Días', 'usd'],
            'cuota_deuda'        => ['Cuota Préstamo', 'usd'],
            'neto'               => ['Neto Pagar $', 'usd'],
            'monto_bs'           => ['Monto en Bs', 'bs'],
            'cobranzas'          => ['Cobranzas Terceros', 'usd'],
            'resto_bs'           => ['Resto a pagar Bs', 'bs'],
            'resto_usd'          => ['Resto $', 'usd'],
            'pendiente'          => ['Pendiente (Deuda)', 'usd'],
        ];
    }

    /**
     * Pago en caja por empleado: neto oficial menos cobranzas a terceros de la semana.
     * @return list<array{nombre:string, cedula:string, total:string, total_bs:?string}>
     */
    public static function pagoNeto(array $datos): array
    {
        return array_map(static fn (array $f): array => [
            'nombre'   => $f['nombres'] . ' ' . $f['apellidos'],
            'cedula'   => (string) $f['cedula'],
            'total'    => (string) $f['resto_usd'],
            'total_bs' => $f['resto_bs'],
        ], $datos['filas']);
    }

    private function persistir(bool $cerrar): void
    {
        $semana = parse_date($_POST['semana'] ?? null);
        if ($semana === null) {
            flash('error', 'Semana inválida.');
            redirect('empleados', ['tab' => 'nomina']);
        }
        [$inicio] = Nomina::periodo($semana);
        $volver = ['tab' => 'nomina', 'semana' => $inicio];
        $esAdmin = current_user()['rol'] === 'admin';

        $entradas = [];
        $invalidos = 0;
        $raw = $_POST['nom'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $empId => $campos) {
            $empId = filter_var($empId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($empId === false || !is_array($campos)) {
                continue;
            }
            $campos = $esAdmin ? $campos : array_diff_key($campos, ['cuota' => true]);
            foreach (['extras', 'vales', 'cuota'] as $campo) {
                if (!array_key_exists($campo, $campos)) {
                    $entradas[$empId][$campo] = null;
                    continue;
                }
                $valor = trim((string) $campos[$campo]);
                $valor = $valor === '' ? '0' : parse_decimal($valor, 2, 100000, true);
                if ($valor === null) {
                    $invalidos++;
                }
                $entradas[$empId][$campo] = $valor ?? '0';
            }
        }
        if ($invalidos > 0) {
            flash('error', "Hay montos inválidos en la nómina ($invalidos). Usa números positivos con hasta 2 decimales.");
            redirect('empleados', $volver);
        }

        $userId = current_user()['id'];
        try {
            $resultado = Database::transaction(function () use ($inicio, $entradas, $userId, $cerrar): array {
                $tasa = TasaCambio::vigenteEn(get_db(), date('Y-m-d H:i:s'));
                if ($tasa === null) {
                    throw new ValidationException(['tasa' => 'No hay tasa BCV registrada. Actualízala en Finanzas antes de guardar la nómina.']);
                }
                $r = $this->nomina->guardar($inicio, $entradas, $tasa['tasa'], $userId, $cerrar);
                audit_log('empleados', $cerrar ? 'cerrar_nomina' : 'guardar_nomina', [
                    'semana_inicio' => $inicio,
                    'tasa'          => $tasa['tasa'],
                    'filas'         => $r['guardadas'],
                    'omitidas'      => $r['omitidas'],
                ], 'nomina_semanal');
                return $r;
            });
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('empleados', $volver);
        }

        $msg = sprintf('%s: %d empleado%s.', $cerrar ? 'Nómina cerrada' : 'Nómina guardada', $resultado['guardadas'], $resultado['guardadas'] === 1 ? '' : 's');
        if ($resultado['omitidas'] > 0) {
            $msg .= sprintf(' %d fila%s ya cerrada%s no se modificó.', $resultado['omitidas'], $resultado['omitidas'] === 1 ? '' : 's', $resultado['omitidas'] === 1 ? '' : 's');
        }
        flash('success', $msg);
        redirect('empleados', $volver);
    }

    /** @return array<string, mixed> */
    private function calculada(): array
    {
        $semana = parse_date($_GET['semana'] ?? null) ?? date('Y-m-d');
        $tasa = TasaCambio::vigenteEn(get_db(), date('Y-m-d H:i:s'));
        return $this->nomina->semana($semana, $tasa['tasa'] ?? null);
    }

    private function exigirAdmin(): void
    {
        if (current_user()['rol'] !== 'admin') {
            audit_log('seguridad', 'acceso_denegado', ['p' => $_GET['p'] ?? '']);
            render_forbidden();
            exit;
        }
    }

    private static function dmy(string $iso): string
    {
        return $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
    }
}
