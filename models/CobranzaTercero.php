<?php
declare(strict_types=1);

/**
 * Deudas de empleados con terceros (bodegas externas). Siempre se guardan en USD; si llegan en Bs
 * se conservan el monto y la tasa de origen. Nunca se restan del neto oficial: solo del pago en caja
 * de la semana indicada en semana_descuento.
 */
final class CobranzaTercero
{
    public const ESTADOS = ['pendiente' => 'Pendiente', 'descontado' => 'Descontado', 'anulado' => 'Anulado'];
    public const MONEDAS = ['USD', 'VES'];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listar(?string $estado = null): array
    {
        $sql = "SELECT c.id, c.empleado_id, CONCAT(e.nombres, ' ', e.apellidos) AS empleado, e.cedula,
                       c.acreedor, c.concepto, c.monto_usd, c.moneda_origen, c.monto_origen, c.tasa_bs_por_usd,
                       c.fecha, c.estado, c.semana_descuento, c.descontado_en
                FROM cobranzas_terceros c
                JOIN empleados e ON e.id = c.empleado_id";
        $params = [];
        if ($estado !== null) {
            $sql .= ' WHERE c.estado = :estado';
            $params['estado'] = $estado;
        }
        $stmt = $this->db->prepare($sql . " ORDER BY c.estado = 'pendiente' DESC, c.fecha DESC, c.id DESC LIMIT 500");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array{pendiente:string, descontado:string, cobrado_historico:string, por_cobrar:string} */
    public function totales(): array
    {
        $out = ['pendiente' => '0.00', 'descontado' => '0.00'];
        foreach ($this->db->query("SELECT estado, SUM(monto_usd) AS usd FROM cobranzas_terceros WHERE estado <> 'anulado' GROUP BY estado") as $row) {
            $out[(string) $row['estado']] = (string) $row['usd'];
        }
        $out['cobrado_historico'] = $out['descontado'];
        $out['por_cobrar'] = $out['pendiente'];
        return $out;
    }

    /**
     * @return list<array{empleado:string, cedula:string, original:string, descontado:string, pendiente:string}>
     */
    public function reportePorEmpleado(): array
    {
        return $this->db->query(
            "SELECT CONCAT(e.nombres, ' ', e.apellidos) AS empleado, e.cedula,
                    COALESCE(SUM(c.monto_usd), 0) AS original,
                    COALESCE(SUM(CASE WHEN c.estado = 'descontado' THEN c.monto_usd ELSE 0 END), 0) AS descontado,
                    COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN c.monto_usd ELSE 0 END), 0) AS pendiente
             FROM cobranzas_terceros c
             JOIN empleados e ON e.id = c.empleado_id
             WHERE c.estado <> 'anulado'
             GROUP BY e.id, e.nombres, e.apellidos, e.cedula
             ORDER BY e.apellidos, e.nombres"
        )->fetchAll();
    }

    /**
     * Convierte a USD con la tasa recibida cuando la moneda es VES.
     * @return array{monto_usd:string, monto_origen:string, moneda_origen:string, tasa_bs_por_usd:?string}
     */
    public static function normalizarMonto(string $monto, string $moneda, ?string $tasa): array
    {
        if ($moneda === 'USD') {
            return ['monto_usd' => $monto, 'monto_origen' => $monto, 'moneda_origen' => 'USD', 'tasa_bs_por_usd' => null];
        }
        if ($tasa === null || (float) $tasa <= 0) {
            throw new ValidationException(['monto' => 'No hay tasa BCV registrada para convertir bolívares. Actualízala en Finanzas.']);
        }
        $usd = number_format(round((float) $monto / (float) $tasa, 2), 2, '.', '');
        if (Caja::aCentavos($usd) <= 0) {
            throw new ValidationException(['monto' => 'El monto en bolívares equivale a menos de $0,01.']);
        }
        return ['monto_usd' => $usd, 'monto_origen' => $monto, 'moneda_origen' => 'VES', 'tasa_bs_por_usd' => $tasa];
    }

    /**
     * $semanaDescuento != null registra la cobranza ya descontada en ese ciclo de nómina.
     * @param array{empleado_id:int, acreedor:string, concepto:string, monto_usd:string, monto_origen:string, moneda_origen:string, tasa_bs_por_usd:?string, fecha:string} $d
     */
    public function create(array $d, int $userId, ?string $semanaDescuento = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO cobranzas_terceros
                 (empleado_id, acreedor, concepto, monto_usd, moneda_origen, monto_origen, tasa_bs_por_usd, fecha,
                  estado, semana_descuento, descontado_en, registrado_por)
             VALUES
                 (:empleado_id, :acreedor, :concepto, :monto_usd, :moneda_origen, :monto_origen, :tasa_bs_por_usd, :fecha,
                  :estado, :semana, :descontado_en, :usuario)'
        );
        $stmt->execute($d + [
            'estado'        => $semanaDescuento !== null ? 'descontado' : 'pendiente',
            'semana'        => $semanaDescuento,
            'descontado_en' => $semanaDescuento !== null ? date('Y-m-d H:i:s') : null,
            'usuario'       => $userId,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findBloqueada(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cobranzas_terceros WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** $semana solo aplica a 'descontado': es el inicio del ciclo de nómina donde se retiene. */
    public function cambiarEstado(int $id, string $estado, ?string $semana, int $userId): void
    {
        $descontado = $estado === 'descontado';
        $stmt = $this->db->prepare(
            'UPDATE cobranzas_terceros
             SET estado = :estado, semana_descuento = :semana, descontado_en = :descontado_en, actualizado_por = :usuario
             WHERE id = :id'
        );
        $stmt->execute([
            'estado'        => $estado,
            'semana'        => $descontado ? $semana : null,
            'descontado_en' => $descontado ? date('Y-m-d H:i:s') : null,
            'usuario'       => $userId,
            'id'            => $id,
        ]);
    }
}
