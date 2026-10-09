<?php
declare(strict_types=1);

/**
 * Deudas de empleados con terceros. No tocan el neto oficial ni la caja: al marcarse "descontado"
 * se retienen del pago en caja de la semana de nómina indicada.
 */
final class CobranzasController
{
    private CobranzaTercero $cobranzas;

    public function __construct()
    {
        $this->cobranzas = new CobranzaTercero(get_db());
    }

    /**
     * Acepta el monto en USD o en Bs (moneda=VES). En Bs: monto_usd = monto_bs / tasa BCV vigente del servidor,
     * redondeado a 2 decimales. Con descontar=1 queda descontada en el ciclo de nómina en curso, que es lo que
     * la resta del pago en caja (Nomina::cobranzasSemana).
     */
    public function store(): void
    {
        $errors = [];
        $empId = parse_int_range($_POST['empleado_id'] ?? null, 1, PHP_INT_MAX);
        if ($empId === null || (new Empleado(get_db()))->find($empId) === null) {
            $errors['empleado_id'] = 'Selecciona un empleado.';
        }
        $acreedor = preg_replace('/\s+/u', ' ', post_string('acreedor', 100));
        if (mb_strlen($acreedor) < 2 || mb_strlen($acreedor) > 80) {
            $errors['acreedor'] = 'Indica el tercero acreedor (2 a 80 caracteres).';
        }
        $concepto = preg_replace('/\s+/u', ' ', post_string('concepto', 140));
        if (mb_strlen($concepto) < 2 || mb_strlen($concepto) > 120) {
            $errors['concepto'] = 'Indica el concepto (2 a 120 caracteres).';
        }
        $montoUsd = parse_monto($_POST['monto_usd'] ?? null, 2, 100000);
        $moneda = $montoUsd !== null ? 'USD' : ($_POST['moneda'] ?? 'USD');
        $moneda = is_string($moneda) && in_array($moneda, CobranzaTercero::MONEDAS, true) ? $moneda : 'USD';
        $campoMonto = $moneda === 'VES' ? 'monto_bs' : 'monto_usd';
        $monto = $moneda === 'USD' ? $montoUsd : parse_monto($_POST[$campoMonto] ?? null, 2, 1000000000);
        if ($monto === null) {
            $errors[$campoMonto] = 'Monto inválido: mayor que 0, hasta 2 decimales (ej. 3.431,52).';
        }
        $descontar = ($_POST['descontar'] ?? '') === '1';
        $fecha = parse_date($_POST['fecha'] ?? null);
        if ($fecha === null || $fecha > date('Y-m-d')) {
            $errors['fecha'] = 'Fecha inválida o futura.';
        }
        if ($errors) {
            form_fail($_POST + ['_form' => 'cobranza'], $errors, 'empleados', ['tab' => 'cobranzas']);
        }

        [$semana] = Nomina::periodo(date('Y-m-d'));
        $userId = current_user()['id'];
        try {
            [$data, $semanaAplicada] = Database::transaction(function () use ($empId, $acreedor, $concepto, $monto, $moneda, $fecha, $userId, $descontar, $semana): array {
                $tasa = $moneda === 'VES' ? TasaCambio::vigenteEn(get_db(), date('Y-m-d H:i:s')) : null;
                $data = ['empleado_id' => $empId, 'acreedor' => $acreedor, 'concepto' => $concepto, 'fecha' => $fecha]
                    + CobranzaTercero::normalizarMonto($monto, $moneda, $tasa['tasa'] ?? null);
                $abierta = in_array((new Nomina(get_db()))->estadoFila($empId, $semana), [null, 'borrador'], true);
                $semanaAplicada = $descontar && $abierta ? $semana : null;
                $id = $this->cobranzas->create($data, $userId, $semanaAplicada);
                audit_log('empleados', 'crear_cobranza', $data + ['semana_descuento' => $semanaAplicada], 'cobranzas_terceros', $id);
                return [$data, $semanaAplicada];
            });
        } catch (ValidationException $e) {
            form_fail($_POST + ['_form' => 'cobranza'], [$campoMonto => $e->errors['monto'] ?? implode(' ', $e->errors)], 'empleados', ['tab' => 'cobranzas']);
        }

        $detalle = $data['moneda_origen'] === 'VES'
            ? sprintf('Bs %s = $%s (tasa %s)', num($data['monto_origen']), num($data['monto_usd']), num($data['tasa_bs_por_usd']))
            : '$' . num($data['monto_usd']);
        if ($semanaAplicada !== null) {
            flash('success', sprintf('Cobranza registrada: %s. Se descuenta del pago en caja de la semana del %s.', $detalle, date('d/m/Y', strtotime($semanaAplicada))));
        } else {
            flash('success', "Cobranza registrada como pendiente: $detalle.");
            if ($descontar) {
                flash('warning', 'La nómina de esta semana está cerrada para el empleado: la cobranza quedó pendiente. Reabre la nómina para descontarla.');
            }
        }
        redirect('empleados', ['tab' => 'cobranzas']);
    }

    /**
     * pendiente ⇄ descontado; anulado es definitivo. "Descontado" se asigna a la semana de nómina
     * en curso (o a la enviada en semana). No se mueven cobranzas de semanas con nómina cerrada.
     */
    public function estado(): void
    {
        $id = parse_int_range($_POST['id'] ?? null, 1, PHP_INT_MAX);
        $estado = $_POST['estado'] ?? '';
        if ($id === null || !is_string($estado) || !isset(CobranzaTercero::ESTADOS[$estado])) {
            flash('error', 'Solicitud inválida.');
            redirect('empleados', ['tab' => 'cobranzas']);
        }
        if ($estado === 'anulado' && !has_permiso(current_user(), 'empleados', 'anular')) {
            render_forbidden();
            exit;
        }
        [$semana] = Nomina::periodo(parse_date($_POST['semana'] ?? null) ?? date('Y-m-d'));

        $userId = current_user()['id'];
        try {
            Database::transaction(function () use ($id, $estado, $semana, $userId): void {
                $actual = $this->cobranzas->findBloqueada($id);
                if ($actual === null) {
                    throw new ValidationException(['id' => 'La cobranza no existe.']);
                }
                if ($actual['estado'] === 'anulado') {
                    throw new ValidationException(['estado' => 'Una cobranza anulada no cambia de estado.']);
                }
                if ($actual['estado'] === $estado) {
                    return;
                }
                $nomina = new Nomina(get_db());
                $empId = (int) $actual['empleado_id'];
                $semanaAfectada = $estado === 'descontado' ? $semana : $actual['semana_descuento'];
                if ($semanaAfectada !== null && !in_array($nomina->estadoFila($empId, (string) $semanaAfectada), [null, 'borrador'], true)) {
                    throw new ValidationException(['estado' => sprintf(
                        'La nómina de la semana del %s está cerrada para este empleado. Reábrela para mover la cobranza.',
                        date('d/m/Y', strtotime((string) $semanaAfectada))
                    )]);
                }
                $this->cobranzas->cambiarEstado($id, $estado, $semana, $userId);
                audit_log('empleados', 'estado_cobranza', [
                    'antes'   => $actual['estado'],
                    'despues' => $estado,
                    'semana'  => $estado === 'descontado' ? $semana : null,
                ], 'cobranzas_terceros', $id);
            });
        } catch (ValidationException $e) {
            flash('error', implode(' ', $e->errors));
            redirect('empleados', ['tab' => 'cobranzas']);
        }
        $msg = 'Cobranza marcada como ' . mb_strtolower(CobranzaTercero::ESTADOS[$estado]);
        if ($estado === 'descontado') {
            $msg .= ' en el pago de la semana del ' . date('d/m/Y', strtotime($semana));
        }
        flash('success', $msg . '.');
        redirect('empleados', ['tab' => 'cobranzas']);
    }

    public function pdf(): void
    {
        $filas = $this->cobranzas->reportePorEmpleado();
        $totales = $this->cobranzas->totales();
        header('Content-Disposition: inline; filename="rrhh_cobranzas.pdf.html"');
        view('rrhh_cobranzas_pdf', [
            'hoy'     => date('Y-m-d'),
            'empresa' => APP_EMPRESA,
            'filas'   => $filas,
            'totales' => $totales,
        ]);
    }
}
