<?php
declare(strict_types=1);

final class FinanzasController
{
    private const MAX_DIAS_FILTRO = 366;

    private Caja $caja;

    public function __construct()
    {
        $this->caja = new Caja(get_db());
    }

    /** Ingresos y egresos respetan el filtro; el balance y los saldos por cuenta son siempre globales. */
    public function index(): void
    {
        [$old, $errors] = form_state();
        $filtros = $this->filtros();
        $user = current_user();

        view('finanzas', [
            'filtros'     => $filtros,
            'totales'     => $this->caja->totales($filtros),
            'global'      => $this->caja->totales(),
            'saldos'      => $this->caja->saldosPorCuenta(),
            'movimientos' => $this->caja->listar($filtros),
            'tasa'        => TasaCambio::vigente(get_db()),
            'cuentas'     => Caja::CUENTAS,
            'origenes'    => Caja::ORIGENES,
            'puedeCrear'  => has_permiso($user, 'finanzas', 'crear'),
            'puedeEditar' => has_permiso($user, 'finanzas', 'editar'),
            'old'         => $old,
            'errors'      => $errors,
        ]);
    }

    /**
     * Ingreso o egreso manual (saldo inicial, inyección de capital, gastos).
     * Un egreso de la gaveta de efectivo no puede dejarla en negativo; el banco admite sobregiro.
     */
    public function storeMovimiento(): void
    {
        $errors = [];

        $tipo = $_POST['tipo'] ?? '';
        if (!is_string($tipo) || !in_array($tipo, Caja::TIPOS, true)) {
            $errors['tipo'] = 'Selecciona Ingreso o Egreso.';
        }
        $moneda = $_POST['moneda'] ?? '';
        if (!is_string($moneda) || !in_array($moneda, Caja::MONEDAS, true)) {
            $errors['moneda'] = 'Moneda inválida.';
        }
        $cuenta = $_POST['cuenta'] ?? 'caja';
        if (!is_string($cuenta) || !isset(Caja::CUENTAS[$cuenta])) {
            $errors['cuenta'] = 'Cuenta inválida.';
        }
        $monto = parse_decimal($_POST['monto'] ?? null, 2, 1000000000);
        if ($monto === null) {
            $errors['monto'] = 'Monto inválido: mayor que 0, hasta 2 decimales.';
        }
        $descripcion = preg_replace('/\s+/u', ' ', post_string('descripcion', 200));
        if (mb_strlen($descripcion) < 3 || mb_strlen($descripcion) > 160) {
            $errors['descripcion'] = 'La descripción es obligatoria (3 a 160 caracteres).';
        }
        if ($errors) {
            form_fail($_POST, $errors, 'finanzas');
        }

        $userId = current_user()['id'];
        try {
            $mov = Database::transaction(function () use ($tipo, $cuenta, $moneda, $monto, $descripcion, $userId): array {
                $tasa = TasaCambio::vigenteEn(get_db(), date('Y-m-d H:i:s'));
                if ($tasa === null) {
                    throw new ValidationException(['monto' => 'No hay tasa BCV registrada. Actualiza la tasa antes de registrar movimientos.']);
                }
                $montoUsd = $moneda === 'USD'
                    ? $monto
                    : number_format(round((float) $monto / (float) $tasa['tasa'], 2), 2, '.', '');
                if (Caja::aCentavos($montoUsd) <= 0) {
                    throw new ValidationException(['monto' => 'El monto equivale a menos de $0,01.']);
                }
                if ($tipo === 'egreso') {
                    $this->caja->verificarFondos($cuenta, $moneda, $monto);
                }

                $data = [
                    'tipo'            => $tipo,
                    'cuenta'          => $cuenta,
                    'moneda'          => $moneda,
                    'monto'           => $monto,
                    'tasa_bs_por_usd' => $tasa['tasa'],
                    'monto_usd'       => $montoUsd,
                    'concepto'        => $descripcion,
                    'registrado_por'  => $userId,
                ];
                $id = $this->caja->registrarManual($data);
                audit_log('finanzas', 'movimiento_manual', $data + ['tasa_id' => $tasa['id']], 'caja_movimientos', $id);
                return $data + ['id' => $id];
            });
        } catch (ValidationException $e) {
            form_fail($_POST, $e->errors, 'finanzas');
        }

        flash('success', sprintf(
            '%s registrado en %s: %s%s%s.',
            $mov['tipo'] === 'ingreso' ? 'Ingreso' : 'Egreso',
            Caja::CUENTAS_TEXTO[$mov['cuenta']],
            $mov['moneda'] === 'USD' ? '$' : 'Bs. ',
            num($mov['monto']),
            $mov['moneda'] === 'VES' ? sprintf(' (≈ $%s)', num($mov['monto_usd'])) : ''
        ));
        redirect('finanzas');
    }

    /** Registra la tasa BCV vigente desde este momento. Las facturas y asientos previos conservan su tasa congelada. */
    public function updateTasa(): void
    {
        $tasa = parse_decimal($_POST['tasa'] ?? null, 4, 10000000);
        if ($tasa === null) {
            form_fail($_POST, ['tasa' => 'Tasa inválida: número mayor que 0 con hasta 4 decimales (Bs por 1 USD).'], 'finanzas');
        }
        $userId = current_user()['id'];

        $id = Database::transaction(function () use ($tasa, $userId): int {
            $anterior = TasaCambio::vigenteEn(get_db(), date('Y-m-d H:i:s'));
            $vigenteDesde = date('Y-m-d H:i:s');
            $id = TasaCambio::registrar(get_db(), $tasa, $vigenteDesde, $userId);
            audit_log('finanzas', 'actualizar_tasa', [
                'tasa_anterior'   => $anterior['tasa'] ?? null,
                'tasa_anterior_id' => $anterior['id'] ?? null,
                'tasa_nueva'      => $tasa,
                'vigente_desde'   => $vigenteDesde,
            ], 'tasas_cambio', $id);
            return $id;
        });

        flash('success', sprintf('Tasa BCV actualizada: 1 USD = Bs. %s (#%d).', num($tasa, 4), $id));
        redirect('finanzas');
    }

    /** @return array{q:string, tipo:?string, desde:?string, hasta:?string} */
    private function filtros(): array
    {
        $q = $_GET['q'] ?? '';
        $tipo = $_GET['tipo'] ?? '';
        $desde = is_string($_GET['desde'] ?? null) ? parse_date($_GET['desde']) : null;
        $hasta = is_string($_GET['hasta'] ?? null) ? parse_date($_GET['hasta']) : null;
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        if ($desde !== null && $hasta !== null) {
            $limite = (new DateTimeImmutable($hasta))->modify('-' . self::MAX_DIAS_FILTRO . ' days')->format('Y-m-d');
            $desde = max($desde, $limite);
        }

        return [
            'q'     => is_string($q) ? mb_substr(trim($q), 0, 160) : '',
            'tipo'  => is_string($tipo) && in_array($tipo, Caja::TIPOS, true) ? $tipo : null,
            'desde' => $desde,
            'hasta' => $hasta,
        ];
    }
}
