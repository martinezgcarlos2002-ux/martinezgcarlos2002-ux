<?php
declare(strict_types=1);

/** Préstamos internos (hr_prestamos) cobrados con cuotas al cerrar la nómina. */
final class PrestamoEmpleado
{
    public const TIPOS = [
        'efectivo'      => 'Efectivo',
        'transferencia' => 'Transferencia',
        'pago_movil'    => 'Pago móvil',
        'mercancia'     => 'Mercancía',
        'otros'         => 'Otros',
    ];

    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{empleado_id:int, fecha:string, monto_total:string, cuota_semanal:string, tipo:string, concepto:string} $d
     */
    public function create(array $d, int $userId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO hr_prestamos
                 (empleado_id, monto_total, cuota_semanal, tipo, fecha, estado, concepto, registrado_por)
             VALUES
                 (:empleado_id, :monto_total, :cuota_semanal, :tipo, :fecha, \'activo\', :concepto, :usuario)'
        );
        $stmt->execute($d + ['usuario' => $userId]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array{por_cobrar:string, cobrado:string} */
    public function totales(): array
    {
        $row = $this->db->query(
            'SELECT
                COALESCE(SUM(CASE WHEN p.estado = \'activo\' THEN p.monto_total - COALESCE(c.pagado, 0) ELSE 0 END), 0) AS por_cobrar,
                COALESCE(SUM(COALESCE(c.pagado, 0)), 0) AS cobrado
             FROM hr_prestamos p
             LEFT JOIN (
                 SELECT prestamo_id, SUM(monto_cuota) AS pagado
                 FROM hr_prestamos_cuotas
                 GROUP BY prestamo_id
             ) c ON c.prestamo_id = p.id'
        )->fetch();
        return [
            'por_cobrar' => Caja::desdeCentavos(Caja::aCentavos($row['por_cobrar'] ?? 0)),
            'cobrado'    => Caja::desdeCentavos(Caja::aCentavos($row['cobrado'] ?? 0)),
        ];
    }

    /**
     * Saldo vivo por empleado: préstamos hasta $hasta menos cuotas (excepto las de $excluirNominaSemana).
     * @return array<int, string>
     */
    public function saldos(?string $excluirSemana = null, ?string $hasta = null, bool $bloquear = false): array
    {
        $lock = $bloquear ? ' FOR UPDATE' : '';
        $p = $this->db->prepare(
            'SELECT empleado_id, SUM(monto_total) AS usd FROM hr_prestamos
             WHERE fecha <= :hasta GROUP BY empleado_id' . $lock
        );
        $p->execute(['hasta' => $hasta ?? '9999-12-31']);

        $sqlCuotas = 'SELECT p.empleado_id, SUM(c.monto_cuota) AS usd
                      FROM hr_prestamos_cuotas c
                      JOIN hr_prestamos p ON p.id = c.prestamo_id
                      JOIN nomina_semanal n ON n.id = c.nomina_id
                      WHERE n.semana_inicio <> :excluir
                      GROUP BY p.empleado_id' . $lock;
        $c = $this->db->prepare($sqlCuotas);
        $c->execute(['excluir' => $excluirSemana ?? '0001-01-01']);

        $cents = [];
        foreach ($p as $row) {
            $cents[(int) $row['empleado_id']] = Caja::aCentavos($row['usd']);
        }
        foreach ($c as $row) {
            $id = (int) $row['empleado_id'];
            $cents[$id] = ($cents[$id] ?? 0) - Caja::aCentavos($row['usd']);
        }
        return array_map(static fn (int $v): string => Caja::desdeCentavos(max(0, $v)), $cents);
    }

    /** Suma de cuotas semanales de préstamos activos con saldo, topeada al saldo. */
    public function cuotaAutomatica(int $empleadoId, string $saldo): string
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.cuota_semanal, p.monto_total, COALESCE(c.pagado, 0) AS pagado
             FROM hr_prestamos p
             LEFT JOIN (
                 SELECT prestamo_id, SUM(monto_cuota) AS pagado
                 FROM hr_prestamos_cuotas
                 GROUP BY prestamo_id
             ) c ON c.prestamo_id = p.id
             WHERE p.empleado_id = :e AND p.estado = \'activo\'
             ORDER BY p.fecha, p.id'
        );
        $stmt->execute(['e' => $empleadoId]);
        $cuotaC = 0;
        foreach ($stmt as $row) {
            $resto = Caja::aCentavos($row['monto_total']) - Caja::aCentavos($row['pagado']);
            if ($resto <= 0) {
                continue;
            }
            $ped = Caja::aCentavos($row['cuota_semanal']);
            $cuotaC += $ped > 0 ? min($ped, $resto) : 0;
        }
        return Caja::desdeCentavos(min($cuotaC, Caja::aCentavos($saldo)));
    }

    /**
     * Aplica la cuota de la fila de nómina a los préstamos activos (FIFO) y marca pagados.
     * @return int cuotas insertadas
     */
    public function registrarCuotasDeNomina(int $empleadoId, int $nominaId, string $montoCuota): int
    {
        $queda = Caja::aCentavos($montoCuota);
        if ($queda <= 0) {
            return 0;
        }
        $lock = $this->db->prepare(
            'SELECT p.id, p.monto_total FROM hr_prestamos p
             WHERE p.empleado_id = :e AND p.estado = \'activo\'
             ORDER BY p.fecha, p.id
             FOR UPDATE'
        );
        $lock->execute(['e' => $empleadoId]);
        $prestamos = $lock->fetchAll();
        $pagadoStmt = $this->db->prepare(
            'SELECT COALESCE(SUM(monto_cuota), 0) FROM hr_prestamos_cuotas WHERE prestamo_id = :id FOR UPDATE'
        );
        $ins = $this->db->prepare(
            'INSERT INTO hr_prestamos_cuotas (prestamo_id, monto_cuota, nomina_id)
             VALUES (:p, :m, :n)
             ON DUPLICATE KEY UPDATE monto_cuota = VALUES(monto_cuota)'
        );
        $upd = $this->db->prepare('UPDATE hr_prestamos SET estado = :e WHERE id = :id');
        $n = 0;
        foreach ($prestamos as $pr) {
            if ($queda <= 0) {
                break;
            }
            $pagadoStmt->execute(['id' => $pr['id']]);
            $resto = Caja::aCentavos($pr['monto_total']) - Caja::aCentavos((string) $pagadoStmt->fetchColumn());
            if ($resto <= 0) {
                $upd->execute(['e' => 'pagado', 'id' => $pr['id']]);
                continue;
            }
            $aplica = min($queda, $resto);
            $ins->execute(['p' => $pr['id'], 'm' => Caja::desdeCentavos($aplica), 'n' => $nominaId]);
            $n++;
            $queda -= $aplica;
            if ($aplica >= $resto) {
                $upd->execute(['e' => 'pagado', 'id' => $pr['id']]);
            }
        }
        return $n;
    }

    /** Quita las cuotas de filas de nómina reabiertas y reactiva el préstamo. */
    public function revertirCuotasDeNominas(array $nominaIds): void
    {
        if ($nominaIds === []) {
            return;
        }
        $in = implode(',', array_map('intval', $nominaIds));
        $ids = $this->db->query(
            "SELECT DISTINCT prestamo_id FROM hr_prestamos_cuotas WHERE nomina_id IN ($in)"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->db->exec("DELETE FROM hr_prestamos_cuotas WHERE nomina_id IN ($in)");
        if ($ids === []) {
            return;
        }
        $pin = implode(',', array_map('intval', $ids));
        $this->db->exec("UPDATE hr_prestamos SET estado = 'activo' WHERE id IN ($pin)");
    }
}
