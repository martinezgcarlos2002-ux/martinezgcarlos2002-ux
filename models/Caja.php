<?php
declare(strict_types=1);

/**
 * Libro de caja de solo inserción: los errores se corrigen con contra-asientos, nunca con UPDATE/DELETE.
 * Dos cuentas: 'caja' (efectivo en gaveta, nunca negativa) y 'banco' (Pago Móvil y Transferencia, admite sobregiro).
 */
final class Caja
{
    public const TIPOS = ['ingreso', 'egreso'];
    public const MONEDAS = ['USD', 'VES'];
    public const CUENTAS = ['caja' => 'Caja (efectivo)', 'banco' => 'Banco (Pago Móvil / Transferencia)'];
    public const CUENTAS_TEXTO = ['caja' => 'la caja de efectivo', 'banco' => 'el banco'];
    public const ORIGENES = [
        'manual'          => 'Manual',
        'venta'           => 'Venta',
        'anulacion_venta' => 'Anulación de venta',
        'pago_productor'  => 'Pago a productor',
        'nomina'          => 'Nómina',
        'contra_asiento'  => 'Contra-asiento',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** El efectivo sale de la gaveta; Pago Móvil y Transferencia salen del banco. */
    public static function cuentaParaPago(string $tipoPago): string
    {
        return PagoProductor::esBancario($tipoPago) ? 'banco' : 'caja';
    }

    /** Solo la gaveta física exige saldo: el banco puede quedar en sobregiro virtual. */
    public static function exigeSaldo(string $cuenta): bool
    {
        return $cuenta === 'caja';
    }

    /**
     * Saldo de la cuenta y moneda bloqueando el libro con FOR UPDATE: dos egresos concurrentes
     * no pueden gastar el mismo dinero. Debe llamarse dentro de Database::transaction().
     */
    public function saldoBloqueado(string $moneda, string $cuenta = 'caja'): string
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0)
             FROM caja_movimientos
             WHERE moneda = :moneda AND cuenta = :cuenta
             FOR UPDATE"
        );
        $stmt->execute(['moneda' => $moneda, 'cuenta' => $cuenta]);
        return (string) $stmt->fetchColumn();
    }

    /** Lanza ValidationException si el egreso deja la cuenta en negativo (solo cuentas que exigen saldo). */
    public function verificarFondos(string $cuenta, string $moneda, string $monto, string $campo = 'monto'): void
    {
        if (!self::exigeSaldo($cuenta)) {
            return;
        }
        $saldo = $this->saldoBloqueado($moneda, $cuenta);
        if (self::aCentavos($saldo) < self::aCentavos($monto)) {
            throw new ValidationException([$campo => sprintf(
                'Saldo insuficiente en %s (%s): disponible %s, requerido %s. Registra un ingreso en Finanzas.',
                self::CUENTAS_TEXTO[$cuenta],
                $moneda,
                num($saldo),
                num($monto)
            )]);
        }
    }

    /** @param array{fecha:string, cuenta:string, moneda:string, monto:string, tasa_bs_por_usd:string, monto_usd:string, concepto:string, cierre_id:?int, abono_id:int, registrado_por:int} $data */
    public function egresoPagoProductor(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO caja_movimientos
                 (fecha, tipo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, cierre_id, abono_id, registrado_por)
             VALUES
                 (:fecha, 'egreso', :cuenta, :moneda, :monto, :tasa_bs_por_usd, :monto_usd, :concepto, 'pago_productor', :cierre_id, :abono_id, :registrado_por)"
        );
        $stmt->execute(['concepto' => mb_substr($data['concepto'], 0, 160)] + $data);
        return (int) $this->db->lastInsertId();
    }

    /** @param array{tipo:string, cuenta:string, moneda:string, monto:string, tasa_bs_por_usd:string, monto_usd:string, concepto:string, registrado_por:int} $data */
    public function registrarManual(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO caja_movimientos
                 (fecha, tipo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, registrado_por)
             VALUES
                 (NOW(), :tipo, :cuenta, :moneda, :monto, :tasa_bs_por_usd, :monto_usd, :concepto, 'manual', :registrado_por)"
        );
        $stmt->execute(['concepto' => mb_substr($data['concepto'], 0, 160)] + $data);
        return (int) $this->db->lastInsertId();
    }

    public function movimientoDeAbono(int $abonoId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM caja_movimientos WHERE abono_id = :abono_id');
        $stmt->execute(['abono_id' => $abonoId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** uq_caja_revierte impide revertir dos veces el mismo asiento. El contra-asiento va a la misma cuenta. */
    public function contraAsiento(int $movimientoId, string $concepto, int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO caja_movimientos
                 (fecha, tipo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, revierte_id, registrado_por)
             SELECT NOW(), IF(tipo = 'ingreso', 'egreso', 'ingreso'), cuenta, moneda, monto, tasa_bs_por_usd, monto_usd,
                    :concepto, 'contra_asiento', id, :usuario_id
             FROM caja_movimientos
             WHERE id = :id"
        );
        $stmt->execute([
            'concepto'   => mb_substr($concepto, 0, 160),
            'usuario_id' => $usuarioId,
            'id'         => $movimientoId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(sprintf('No se pudo revertir el asiento de caja #%d.', $movimientoId));
        }
        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array{q?:string, tipo?:?string, desde?:?string, hasta?:?string} $filtros
     * @return list<array>
     */
    public function listar(array $filtros, int $limite = 500): array
    {
        [$where, $params] = self::filtrosSql($filtros);
        $stmt = $this->db->prepare(
            'SELECT m.id, m.fecha, m.tipo, m.cuenta, m.moneda, m.monto, m.tasa_bs_por_usd, m.monto_usd,
                    m.concepto, m.origen, m.revierte_id, u.nombre AS registrado_por
             FROM caja_movimientos m
             JOIN usuarios u ON u.id = m.registrado_por'
            . $where
            . ' ORDER BY m.fecha DESC, m.id DESC LIMIT ' . max(1, min($limite, 2000))
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Totales en USD y su equivalente en Bs a la tasa congelada en cada asiento (no a la tasa de hoy).
     * @return array{ingresos_usd:string, ingresos_bs:string, egresos_usd:string, egresos_bs:string, balance_usd:string, balance_bs:string, movimientos:int}
     */
    public function totales(array $filtros = []): array
    {
        [$where, $params] = self::filtrosSql($filtros);
        $bs = "IF(m.moneda = 'VES', m.monto, ROUND(m.monto_usd * m.tasa_bs_por_usd, 2))";
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS movimientos,
                    COALESCE(SUM(CASE WHEN m.tipo = 'ingreso' THEN m.monto_usd END), 0) AS ingresos_usd,
                    COALESCE(SUM(CASE WHEN m.tipo = 'ingreso' THEN $bs END), 0)         AS ingresos_bs,
                    COALESCE(SUM(CASE WHEN m.tipo = 'egreso'  THEN m.monto_usd END), 0) AS egresos_usd,
                    COALESCE(SUM(CASE WHEN m.tipo = 'egreso'  THEN $bs END), 0)         AS egresos_bs
             FROM caja_movimientos m" . $where
        );
        $stmt->execute($params);
        $r = $stmt->fetch();

        return [
            'movimientos'  => (int) $r['movimientos'],
            'ingresos_usd' => (string) $r['ingresos_usd'],
            'ingresos_bs'  => (string) $r['ingresos_bs'],
            'egresos_usd'  => (string) $r['egresos_usd'],
            'egresos_bs'   => (string) $r['egresos_bs'],
            'balance_usd'  => self::desdeCentavos(self::aCentavos($r['ingresos_usd']) - self::aCentavos($r['egresos_usd'])),
            'balance_bs'   => self::desdeCentavos(self::aCentavos($r['ingresos_bs']) - self::aCentavos($r['egresos_bs'])),
        ];
    }

    /** @return array<string, array<string, string>>  [cuenta][moneda] => saldo en la moneda nativa */
    public function saldosPorCuenta(): array
    {
        $rows = $this->db->query(
            "SELECT cuenta, moneda, COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END), 0) AS saldo
             FROM caja_movimientos
             GROUP BY cuenta, moneda"
        )->fetchAll();

        $out = [];
        foreach (array_keys(self::CUENTAS) as $cuenta) {
            $out[$cuenta] = array_fill_keys(self::MONEDAS, '0.00');
        }
        foreach ($rows as $r) {
            $out[$r['cuenta']][$r['moneda']] = (string) $r['saldo'];
        }
        return $out;
    }

    /** @return array{0:string, 1:array<string, string>} */
    private static function filtrosSql(array $f): array
    {
        $where = [];
        $params = [];
        if (($f['q'] ?? '') !== '') {
            $where[] = 'm.concepto LIKE :q';
            $params['q'] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }
        if (in_array($f['tipo'] ?? null, self::TIPOS, true)) {
            $where[] = 'm.tipo = :tipo';
            $params['tipo'] = $f['tipo'];
        }
        if (!empty($f['desde'])) {
            $where[] = 'm.fecha >= :desde';
            $params['desde'] = $f['desde'] . ' 00:00:00';
        }
        if (!empty($f['hasta'])) {
            $where[] = 'm.fecha < :hasta';
            $params['hasta'] = (new DateTimeImmutable($f['hasta']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public static function aCentavos(mixed $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    public static function desdeCentavos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
