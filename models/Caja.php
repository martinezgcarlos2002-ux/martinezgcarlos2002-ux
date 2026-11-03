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
        'pago_gas'        => 'Pago de gas',
        'reparacion_gas'  => 'Reparación de gas',
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

    /** Egreso de nómina. Devuelve false si esa fila ya tiene movimiento, para no duplicar el pago. */
    public function egresoNomina(int $nominaId, string $montoUsd, string $tasa, string $concepto, int $usuarioId): bool
    {
        $ya = $this->db->prepare(
            "SELECT 1 FROM caja_movimientos WHERE nomina_id = :id AND origen = 'nomina' AND revierte_id IS NULL LIMIT 1"
        );
        $ya->execute(['id' => $nominaId]);
        if ($ya->fetchColumn() !== false) {
            return false;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO caja_movimientos
                 (fecha, tipo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, nomina_id, registrado_por)
             VALUES
                 (NOW(), 'egreso', 'caja', 'USD', :monto, :tasa, :monto_usd, :concepto, 'nomina', :nomina_id, :usuario)"
        );
        $stmt->execute([
            'monto'      => $montoUsd,
            'monto_usd'  => $montoUsd,
            'tasa'       => $tasa,
            'concepto'   => mb_substr($concepto, 0, 160),
            'nomina_id'  => $nominaId,
            'usuario'    => $usuarioId,
        ]);
        return true;
    }

    /** @param array{fecha:string, cuenta:string, moneda:string, monto:string, tasa_bs_por_usd:string, monto_usd:string, concepto:string, gas_pago_id:int, registrado_por:int} $data */
    public function egresoPagoGas(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO caja_movimientos
                 (fecha, tipo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, gas_pago_id, registrado_por)
             VALUES
                 (:fecha, 'egreso', :cuenta, :moneda, :monto, :tasa_bs_por_usd, :monto_usd, :concepto, 'pago_gas', :gas_pago_id, :registrado_por)"
        );
        $stmt->execute([
            'fecha'           => $data['fecha'],
            'cuenta'          => $data['cuenta'],
            'moneda'          => $data['moneda'],
            'monto'           => $data['monto'],
            'tasa_bs_por_usd' => $data['tasa_bs_por_usd'],
            'monto_usd'       => $data['monto_usd'],
            'concepto'        => mb_substr($data['concepto'], 0, 160),
            'gas_pago_id'     => $data['gas_pago_id'],
            'registrado_por'  => $data['registrado_por'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array{fecha:string, monto:string, tasa_bs_por_usd:string, monto_usd:string, concepto:string, gas_reparacion_id:int, registrado_por:int} $data */
    public function egresoReparacionGas(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO caja_movimientos
                 (fecha, tipo, cuenta, moneda, monto, tasa_bs_por_usd, monto_usd, concepto, origen, gas_reparacion_id, registrado_por)
             VALUES
                 (:fecha, 'egreso', 'caja', 'USD', :monto, :tasa_bs_por_usd, :monto_usd, :concepto, 'reparacion_gas', :gas_reparacion_id, :registrado_por)"
        );
        $stmt->execute([
            'fecha'              => $data['fecha'],
            'monto'              => $data['monto'],
            'tasa_bs_por_usd'    => $data['tasa_bs_por_usd'],
            'monto_usd'          => $data['monto_usd'],
            'concepto'           => mb_substr($data['concepto'], 0, 160),
            'gas_reparacion_id'  => $data['gas_reparacion_id'],
            'registrado_por'     => $data['registrado_por'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function movimientoDePagoGas(int $pagoId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM caja_movimientos WHERE gas_pago_id = :id AND origen = 'pago_gas' LIMIT 1"
        );
        $stmt->execute(['id' => $pagoId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
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
        $vigente = 'm.revierte_id IS NULL AND NOT EXISTS (SELECT 1 FROM caja_movimientos r WHERE r.revierte_id = m.id)';
        $where = $where === '' ? ' WHERE ' . $vigente : $where . ' AND ' . $vigente;
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

        $desde = isset($params['desde']) ? (string) $params['desde'] : null;
        $hasta = isset($params['hasta']) ? (string) $params['hasta'] : null;
        $sinBusqueda = ($filtros['q'] ?? '') === '';
        if ($sinBusqueda && ($filtros['tipo'] ?? null) !== 'egreso') {
            $cobrado = $this->cobradoClientes($desde, $hasta);
            $r['ingresos_usd'] = self::desdeCentavos(self::aCentavos($r['ingresos_usd']) + self::aCentavos($cobrado['usd']));
            $r['ingresos_bs'] = self::desdeCentavos(self::aCentavos($r['ingresos_bs']) + self::aCentavos($cobrado['bs']));
        }
        if ($sinBusqueda && ($filtros['tipo'] ?? null) !== 'ingreso') {
            $salidas = $this->desembolsosOperativos($desde, $hasta);
            $r['egresos_usd'] = self::desdeCentavos(self::aCentavos($r['egresos_usd']) + self::aCentavos($salidas['usd']));
            $r['egresos_bs'] = self::desdeCentavos(self::aCentavos($r['egresos_bs']) + self::aCentavos($salidas['bs']));
        }

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

    /**
     * Cobros de facturas de clientes que sí entraron dinero. El saldo a favor no es un ingreso nuevo.
     * @return array{usd:string, bs:string}
     */
    public function cobradoClientes(?string $desde = null, ?string $hasta = null): array
    {
        $sql = "SELECT COALESCE(SUM(monto), 0) AS usd,
                       COALESCE(SUM(COALESCE(monto_bs, ROUND(monto * tasa_cambio, 2), 0)), 0) AS bs
                FROM cxc_pagos
                WHERE estado = 'aplicado' AND metodo <> 'saldo_favor'";
        $params = [];
        if ($desde !== null) {
            $sql .= ' AND fecha >= :desde';
            $params['desde'] = $desde;
        }
        if ($hasta !== null) {
            $sql .= ' AND fecha < :hasta';
            $params['hasta'] = $hasta;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return ['usd' => (string) $row['usd'], 'bs' => (string) $row['bs']];
    }

    /**
     * Salidas de dinero que no están ya como egreso vigente en caja.
     * Productores: liquidación en efectivo, pago móvil o transferencia (la mercancía es descuento, no caja).
     * Transporte: abonos en dinero de semanas ya pagadas.
     * Nómina: neto de filas pagadas que todavía no tienen asiento.
     *
     * @return array{usd:string, bs:string}
     */
    public function desembolsosOperativos(?string $desde = null, ?string $hasta = null): array
    {
        $usd = 0;
        $bs = 0;
        $sumar = function (string $sql, array $params) use (&$usd, &$bs): void {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch();
            } catch (PDOException $e) {
                if ($e->getCode() === '42S02') {
                    return;
                }
                throw $e;
            }
            if ($row === false) {
                return;
            }
            $usd += self::aCentavos($row['usd']);
            $bs += self::aCentavos($row['bs']);
        };

        $cajaVigente = "m.revierte_id IS NULL AND NOT EXISTS (SELECT 1 FROM caja_movimientos r WHERE r.revierte_id = m.id)";
        [$filtroA, $paramsA] = $this->filtroFecha('a.fecha', $desde, $hasta);
        $sumar(
            "SELECT COALESCE(SUM(a.monto_usd), 0) AS usd,
                    COALESCE(SUM(CASE WHEN a.moneda = 'VES' THEN a.monto
                                      ELSE ROUND(a.monto_usd * IFNULL(a.tasa_bs_por_usd, 0), 2) END), 0) AS bs
             FROM productores_abonos a
             WHERE a.anulado_en IS NULL
               AND a.tipo IN ('efectivo','pago_movil','transferencia','pm_trans')
               AND NOT EXISTS (
                   SELECT 1 FROM caja_movimientos m
                   WHERE m.abono_id = a.id AND m.tipo = 'egreso' AND $cajaVigente
               )" . $filtroA,
            $paramsA
        );

        [$filtroN, $paramsN] = $this->filtroFecha('n.semana_fin', $desde, $hasta);
        $sumar(
            "SELECT COALESCE(SUM(n.neto_usd), 0) AS usd,
                    COALESCE(SUM(ROUND(n.neto_usd * IFNULL(n.tasa_bs_por_usd, 0), 2)), 0) AS bs
             FROM nomina_semanal n
             WHERE n.estado = 'pagada' AND n.neto_usd > 0
               AND NOT EXISTS (
                   SELECT 1 FROM caja_movimientos m
                   WHERE m.nomina_id = n.id AND m.origen = 'nomina' AND $cajaVigente
               )" . $filtroN,
            $paramsN
        );

        [$filtroT, $paramsT] = $this->filtroFecha('t.fecha', $desde, $hasta);
        $sumar(
            "SELECT COALESCE(SUM(t.monto), 0) AS usd, 0 AS bs
             FROM log_pagos_transporte t
             JOIN log_pagos_semanales s ON s.id = t.pago_semanal_id
             WHERE s.estado = 'pagado'
               AND t.tipo IN ('efectivo','transferencia')" . $filtroT,
            $paramsT
        );

        return ['usd' => self::desdeCentavos($usd), 'bs' => self::desdeCentavos($bs)];
    }

    /** @return array{0:string, 1:array<string, string>} */
    private function filtroFecha(string $columna, ?string $desde, ?string $hasta): array
    {
        $sql = '';
        $params = [];
        if ($desde !== null) {
            $sql .= " AND $columna >= :desde";
            $params['desde'] = $desde;
        }
        if ($hasta !== null) {
            $sql .= " AND $columna < :hasta";
            $params['hasta'] = $hasta;
        }
        return [$sql, $params];
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
