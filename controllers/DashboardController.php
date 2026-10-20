<?php
declare(strict_types=1);

final class DashboardController
{
    private const DIAS_SEMANA = 7;

    private PDO $db;
    private ?float $tasa;

    public function __construct()
    {
        $this->db = get_db();
        $this->tasa = TasaCambio::vigente($this->db)['tasa'] ?? null;
    }

    public function index(): void
    {
        $user = current_user();

        // Semana operativa: sábado a viernes.
        $hoy = new DateTimeImmutable('today');
        $semanaInicio = $hoy->modify('-' . (((int) $hoy->format('w') + 1) % 7) . ' days');
        $semanaFin = $semanaInicio->modify('+6 days');
        $ayer = $hoy->modify('-1 day');

        $puede = static fn (string $modulo): bool => has_permiso($user, $modulo);

        $empleados = $puede('empleados')
            ? $this->row(
                'SELECT COUNT(*) AS n, COALESCE(SUM(salario_semanal_usd), 0) AS usd FROM empleados WHERE activo = :activo',
                ['activo' => 1],
                ['n' => 0, 'usd' => 0]
            )
            : null;

        $operacion = [];
        if ($puede('inventario')) {
            $operacion[] = $this->tile('Productos', 'bi-box-seam', 'inventario',
                $this->count('SELECT COUNT(*) FROM productos WHERE activo = :activo', ['activo' => 1]));
            $operacion[] = $this->tile('Lotes', 'bi-stack', 'inventario',
                $this->count('SELECT COUNT(*) FROM lotes'));
        }
        if ($puede('ventas')) {
            $operacion[] = $this->tile('Ventas', 'bi-receipt', 'ventas',
                $this->count('SELECT COUNT(*) FROM ventas WHERE estado = :estado', ['estado' => 'emitida']));
        }
        if ($empleados !== null) {
            $operacion[] = $this->tile('Recursos Humanos', 'bi-people-fill', 'empleados', (int) $empleados['n']);
        }
        if ($puede('productores')) {
            // La tabla proveedores llega con su propio módulo; mientras no exista, la tarjeta muestra 0.
            $operacion[] = $this->tile('Proveedores', 'bi-bus-front', 'productores',
                $this->count('SELECT COUNT(*) FROM proveedores WHERE activo = :activo', ['activo' => 1]));
            $operacion[] = $this->tile('Productores', 'bi-droplet-fill', 'productores',
                $this->count('SELECT COUNT(*) FROM productores WHERE activo = :activo', ['activo' => 1]));
        }

        $finanzas = null;
        if ($puede('finanzas')) {
            $caja = $this->row(
                'SELECT COALESCE(SUM(CASE WHEN tipo = :ing THEN monto_usd END), 0) AS ingresos,
                        COALESCE(SUM(CASE WHEN tipo = :egr THEN monto_usd END), 0) AS egresos
                 FROM caja_movimientos',
                ['ing' => 'ingreso', 'egr' => 'egreso'],
                ['ingresos' => 0, 'egresos' => 0]
            );
            $ingresos = (float) $caja['ingresos'];
            $egresos = (float) $caja['egresos'];
            $finanzas = [
                'ingresos' => $this->money($ingresos),
                'egresos'  => $this->money($egresos),
                'balance'  => $this->money($ingresos - $egresos),
            ];
        }

        $indicadores = [];
        if ($puede('facturas')) {
            // Misma cifra que "Total a cobrar" de Control de Facturas (CxCService::obtenerMetricasDashboard):
            // suma de saldo_pendiente de toda la cartera no anulada (vigente, por vencer y vencida).
            $fact = $this->row(
                "SELECT COALESCE(SUM(saldo_pendiente > 0), 0) AS n,
                        COALESCE(SUM(saldo_pendiente), 0) AS usd
                 FROM cxc_facturas
                 WHERE estado <> 'anulada'",
                [],
                ['n' => 0, 'usd' => 0]
            );
            $n = (int) $fact['n'];
            $indicadores['facturas'] = [
                'monto' => $this->money((float) $fact['usd']),
                'nota'  => $n . ($n === 1 ? ' factura con saldo' : ' facturas con saldo'),
            ];
        }
        if ($puede('control_leche')) {
            $leche = $this->row(
                'SELECT COUNT(*) AS n, COALESCE(SUM(litros), 0) AS litros FROM productores_entregas WHERE fecha = :fecha',
                ['fecha' => $ayer->format('Y-m-d')],
                ['n' => 0, 'litros' => 0]
            );
            $n = (int) $leche['n'];
            $indicadores['leche_ayer'] = [
                'litros' => (float) $leche['litros'],
                'nota'   => ($n === 0 ? 'Sin entregas registradas' : $n . ($n === 1 ? ' entrega' : ' entregas'))
                    . ' · ' . $ayer->format('d/m/Y'),
            ];
        }
        if ($puede('productores')) {
            $gasto = $this->row(
                'SELECT COUNT(*) AS n, COALESCE(SUM(litros), 0) AS litros, COALESCE(SUM(subtotal_usd), 0) AS usd
                 FROM productores_entregas
                 WHERE fecha BETWEEN :ini AND :fin',
                ['ini' => $semanaInicio->format('Y-m-d'), 'fin' => $semanaFin->format('Y-m-d')],
                ['n' => 0, 'litros' => 0, 'usd' => 0]
            );
            $n = (int) $gasto['n'];
            $usd = (float) $gasto['usd'];
            $diasTranscurridos = (int) $semanaInicio->diff($hoy)->days + 1;
            $indicadores['gasto_leche'] = [
                'monto' => $this->money($usd),
                'nota'  => sprintf(
                    '%s L acumulados · promedio %s $/día · %s',
                    num((float) $gasto['litros'], 1),
                    num($usd / $diasTranscurridos),
                    $n === 0 ? 'Sin entregas' : $n . ($n === 1 ? ' entrega' : ' entregas')
                ),
            ];
        }
        if ($empleados !== null) {
            $n = (int) $empleados['n'];
            $semanal = (float) $empleados['usd'];
            $indicadores['presupuesto'] = [
                'monto'  => $this->money($semanal),
                'nota'   => $n . ($n === 1 ? ' empleado activo' : ' empleados activos') . ' · salarios semanales',
                'diario' => $this->money($semanal / self::DIAS_SEMANA),
            ];
        }

        view('dashboard', [
            'semanaInicio' => $semanaInicio,
            'semanaFin'    => $semanaFin,
            'operacion'    => $operacion,
            'finanzas'     => $finanzas,
            'indicadores'  => $indicadores,
        ]);
    }

    /** @return array{label:string, icon:string, p:string, valor:int} */
    private function tile(string $label, string $icon, string $modulo, int $valor): array
    {
        return ['label' => $label, 'icon' => $icon, 'p' => modulos()[$modulo]['p'], 'valor' => $valor];
    }

    /** Monto en USD y su equivalente en Bs a la tasa vigente (null si aún no hay tasa registrada). */
    private function money(float $usd): array
    {
        $usd = round($usd, 2);
        return ['usd' => $usd, 'bs' => $this->tasa === null ? null : round($usd * $this->tasa, 2)];
    }

    private function count(string $sql, array $params = []): int
    {
        return (int) ($this->row($sql, $params, [0])[0] ?? 0);
    }

    /**
     * Ejecuta una consulta preparada de una sola fila. Si la tabla aún no existe
     * (módulo sin migrar, SQLSTATE 42S02) devuelve $default; cualquier otro error se propaga.
     */
    private function row(string $sql, array $params, array $default): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(array_is_list($default) ? PDO::FETCH_NUM : PDO::FETCH_ASSOC);
            return $row === false ? $default : $row;
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
            return $default;
        }
    }
}
