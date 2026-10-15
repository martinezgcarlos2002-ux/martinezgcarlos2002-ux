<?php
declare(strict_types=1);

/** Pagos y descuentos al productor (tabla productores_abonos). Inmutables: se anulan, no se borran. */
final class PagoProductor
{
    /** Tipos que se pueden registrar hoy. */
    public const TIPOS = [
        'efectivo'      => 'Efectivo',
        'mercancia'     => 'Mercancía',
        'pago_movil'    => 'Pago Móvil',
        'transferencia' => 'Transferencia',
    ];

    /** Etiquetas para mostrar, incluido el tipo histórico anterior a la migración 008. */
    public const ETIQUETAS = self::TIPOS + ['pm_trans' => 'PM/Transf. (histórico)'];

    /** Salen de la cuenta Banco: admiten sobregiro y exigen referencia. */
    public const TIPOS_BANCO = ['pago_movil', 'transferencia', 'pm_trans'];

    /** Tipos que mueven dinero y por tanto generan egreso en caja o banco. */
    public const TIPOS_CAJA = ['efectivo', ...self::TIPOS_BANCO];

    /**
     * Columnas de los resúmenes semanales. 'pm_trans' agrupa todo lo bancario
     * (Pago Móvil + Transferencia + histórico) para que planillas y saldos no cambien de forma.
     */
    public const RESUMEN = ['efectivo', 'mercancia', 'pm_trans'];

    /** Textos que puede enviar el formulario además de la clave. */
    private const ALIAS = [
        'pago móvil'    => 'pago_movil',
        'pago movil'    => 'pago_movil',
        'transferencia' => 'transferencia',
        'efectivo'      => 'efectivo',
        'mercancía'     => 'mercancia',
        'mercancia'     => 'mercancia',
    ];

    public const MONEDAS = ['USD', 'VES'];

    public function __construct(private PDO $db)
    {
    }

    /** Clave de TIPOS a partir de la clave o de la etiqueta ("Pago Móvil", "Transferencia"); null si no es un tipo vigente. */
    public static function normalizarTipo(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if (isset(self::TIPOS[$raw])) {
            return $raw;
        }
        return self::ALIAS[mb_strtolower(preg_replace('/\s+/u', ' ', $raw))] ?? null;
    }

    public static function mueveCaja(string $tipo): bool
    {
        return in_array($tipo, self::TIPOS_CAJA, true);
    }

    public static function esBancario(string $tipo): bool
    {
        return in_array($tipo, self::TIPOS_BANCO, true);
    }

    public static function requiereReferencia(string $tipo): bool
    {
        return self::esBancario($tipo);
    }

    /** Expresión SQL que colapsa los tipos bancarios en la columna de resumen 'pm_trans'. */
    private static function sqlTipoResumen(string $columna = 'tipo'): string
    {
        return "CASE WHEN $columna IN ('pago_movil','transferencia','pm_trans') THEN 'pm_trans' ELSE $columna END";
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO productores_abonos
                 (productor_id, fecha, semana_inicio, tipo, moneda, monto, tasa_bs_por_usd, monto_usd,
                  referencia, descripcion, cierre_id, registrado_por)
             VALUES
                 (:productor_id, :fecha, :semana_inicio, :tipo, :moneda, :monto, :tasa_bs_por_usd, :monto_usd,
                  :referencia, :descripcion, :cierre_id, :registrado_por)'
        );
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $id, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM productores_abonos WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function anular(int $id, int $usuarioId, string $motivo): void
    {
        $stmt = $this->db->prepare(
            'UPDATE productores_abonos
             SET anulado_en = NOW(), anulado_por = :usuario_id, motivo_anulacion = :motivo
             WHERE id = :id AND anulado_en IS NULL'
        );
        $stmt->execute(['usuario_id' => $usuarioId, 'motivo' => $motivo, 'id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new ValidationException(['pago' => 'El pago ya fue anulado.']);
        }
    }

    /** Liga al cierre recién creado los pagos vigentes que se registraron mientras la semana estaba abierta. */
    public function vincularCierre(int $productorId, string $semanaInicio, int $cierreId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE productores_abonos
             SET cierre_id = :cierre_id
             WHERE productor_id = :productor_id AND semana_inicio = :semana
               AND cierre_id IS NULL AND anulado_en IS NULL'
        );
        $stmt->execute(['cierre_id' => $cierreId, 'productor_id' => $productorId, 'semana' => $semanaInicio]);
    }

    /**
     * Totales vigentes por semana.
     * mercancia_usd = descuentos (mercancía y vale). dinero_usd = liquidación
     * (efectivo, pago móvil, transferencia y el histórico pm_trans). Cada tipo entra en un solo balde.
     * @return array<string, array{mercancia_usd:string, dinero_usd:string}>  indexado por semana_inicio
     */
    public function totalesPorSemana(int $productorId): array
    {
        $stmt = $this->db->prepare(
            "SELECT semana_inicio,
                    COALESCE(SUM(CASE WHEN tipo IN ('mercancia','vale') THEN monto_usd ELSE 0 END), 0) AS mercancia_usd,
                    COALESCE(SUM(CASE WHEN tipo IN ('efectivo','pago_movil','transferencia','pm_trans') THEN monto_usd ELSE 0 END), 0) AS dinero_usd
             FROM productores_abonos
             WHERE productor_id = :productor_id AND anulado_en IS NULL
             GROUP BY semana_inicio"
        );
        $stmt->execute(['productor_id' => $productorId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['semana_inicio']] = ['mercancia_usd' => (string) $row['mercancia_usd'], 'dinero_usd' => (string) $row['dinero_usd']];
        }
        return $out;
    }

    /** @return array{efectivo:string, mercancia:string, pm_trans:string}  pm_trans = Pago Móvil + Transferencia */
    public function totalesSemana(int $productorId, string $semanaInicio): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::sqlTipoResumen() . ' AS columna, COALESCE(SUM(monto_usd), 0) AS total
             FROM productores_abonos
             WHERE productor_id = :productor_id AND semana_inicio = :semana AND anulado_en IS NULL
             GROUP BY columna'
        );
        $stmt->execute(['productor_id' => $productorId, 'semana' => $semanaInicio]);
        return array_merge(array_fill_keys(self::RESUMEN, '0.00'), array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR)));
    }

    /** Pagos vigentes sin cierre: mientras existan, el día de cierre del productor no puede cambiar. */
    public function abiertosDe(int $productorId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM productores_abonos WHERE productor_id = :productor_id AND cierre_id IS NULL AND anulado_en IS NULL'
        );
        $stmt->execute(['productor_id' => $productorId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Totales por productor y tipo del ciclo de cada productor que contiene $fechaRef, para la planilla masiva.
     * @return array<int, array{efectivo:string, mercancia:string, pm_trans:string}>
     */
    public function totalesSemanaTodos(string $fechaRef): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.productor_id, ' . self::sqlTipoResumen('a.tipo') . ' AS columna,
                    COALESCE(SUM(a.monto_usd), 0) AS total
             FROM productores_abonos a
             JOIN productores p ON p.id = a.productor_id
             WHERE a.semana_inicio = ' . CierreSemanal::sqlInicioCiclo(':ref1', ':ref2') . ' AND a.anulado_en IS NULL
             GROUP BY a.productor_id, columna'
        );
        $stmt->execute(['ref1' => $fechaRef, 'ref2' => $fechaRef]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['productor_id']] ??= array_fill_keys(self::RESUMEN, '0.00');
            $out[(int) $row['productor_id']][$row['columna']] = (string) $row['total'];
        }
        return $out;
    }

    public function deSemana(int $productorId, string $semanaInicio): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, fecha, semana_inicio, tipo, moneda, monto, tasa_bs_por_usd, monto_usd, referencia, descripcion,
                    anulado_en, motivo_anulacion
             FROM productores_abonos
             WHERE productor_id = :productor_id AND semana_inicio = :semana
             ORDER BY fecha, id'
        );
        $stmt->execute(['productor_id' => $productorId, 'semana' => $semanaInicio]);
        return $stmt->fetchAll();
    }

    /** Pagos vigentes cuya semana aplicada cae en el período (un pago tardío aparece en la semana que salda). */
    public function aplicadosEnPeriodo(int $productorId, string $desde, string $hasta, int $diaCierre): array
    {
        $stmt = $this->db->prepare(
            'SELECT fecha, semana_inicio, tipo, monto_usd, referencia, descripcion
             FROM productores_abonos
             WHERE productor_id = :productor_id AND anulado_en IS NULL
               AND semana_inicio BETWEEN :desde AND :hasta
             ORDER BY semana_inicio, fecha, id'
        );
        $stmt->execute([
            'productor_id' => $productorId,
            'desde'        => CierreSemanal::semanaDe($desde, $diaCierre),
            'hasta'        => $hasta,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Pagos vigentes aún sin cierre por productor (en USD), para descontarlos de la deuda de ciclos cerrados.
     * @return array<int, string>
     */
    public function abiertosPorProductor(): array
    {
        return array_map('strval', $this->db->query(
            'SELECT productor_id, COALESCE(SUM(monto_usd), 0)
             FROM productores_abonos
             WHERE cierre_id IS NULL AND anulado_en IS NULL
             GROUP BY productor_id'
        )->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function historial(int $productorId, string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, fecha, semana_inicio, tipo, moneda, monto, monto_usd, referencia, descripcion, anulado_en, motivo_anulacion
             FROM productores_abonos
             WHERE productor_id = :productor_id AND fecha BETWEEN :desde AND :hasta
             ORDER BY fecha DESC, id DESC'
        );
        $stmt->execute(['productor_id' => $productorId, 'desde' => $desde, 'hasta' => $hasta]);
        return $stmt->fetchAll();
    }
}
