<?php
declare(strict_types=1);

/**
 * Indicadores diarios de merma / venta de leche (litros).
 * Neta = recibida − (ventas + cortesías + vales + faltante).
 */
final class ControlLecheDiario
{
    public const CAMPOS = ['ventas_leche', 'cortesias_leche', 'vales_leche', 'leche_faltante'];

    public function __construct(private PDO $db)
    {
    }

    /** @return array<string, array<string, string>> fecha => fila */
    public function porRango(string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            'SELECT fecha, ventas_leche, cortesias_leche, vales_leche, leche_faltante
             FROM prod_control_leche_diario
             WHERE fecha BETWEEN :desde AND :hasta'
        );
        $stmt->execute(['desde' => $desde, 'hasta' => $hasta]);
        $out = [];
        foreach ($stmt as $row) {
            $out[(string) $row['fecha']] = [
                'ventas_leche'    => (string) $row['ventas_leche'],
                'cortesias_leche' => (string) $row['cortesias_leche'],
                'vales_leche'     => (string) $row['vales_leche'],
                'leche_faltante'  => (string) $row['leche_faltante'],
            ];
        }
        return $out;
    }

    /** @return array<string, string>|null */
    public function find(string $fecha): ?array
    {
        $filas = $this->porRango($fecha, $fecha);
        return $filas[$fecha] ?? null;
    }

    /** @param array<string, mixed> $valores */
    public function upsert(string $fecha, array $valores, int $userId): void
    {
        $norm = [];
        foreach (self::CAMPOS as $campo) {
            $raw = $valores[$campo] ?? '0';
            $raw = is_string($raw) || is_int($raw) || is_float($raw) ? (string) $raw : '0';
            $dec = parse_decimal($raw === '' ? '0' : $raw, 2, 1000000, true);
            if ($dec === null) {
                throw new ValidationException([$campo => "Litros inválidos en $fecha ($campo)."]);
            }
            $norm[$campo] = $dec;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO prod_control_leche_diario
                 (fecha, ventas_leche, cortesias_leche, vales_leche, leche_faltante, registrado_por)
             VALUES
                 (:fecha, :ventas, :cortesias, :vales, :faltante, :usuario)
             ON DUPLICATE KEY UPDATE
                 ventas_leche = VALUES(ventas_leche),
                 cortesias_leche = VALUES(cortesias_leche),
                 vales_leche = VALUES(vales_leche),
                 leche_faltante = VALUES(leche_faltante),
                 registrado_por = VALUES(registrado_por)'
        );
        $stmt->execute([
            'fecha'     => $fecha,
            'ventas'    => $norm['ventas_leche'],
            'cortesias' => $norm['cortesias_leche'],
            'vales'     => $norm['vales_leche'],
            'faltante'  => $norm['leche_faltante'],
            'usuario'   => $userId,
        ]);
    }

    /** @return array<int, array{densidad:string, faltante:string}> */
    public function detalleProductores(string $fecha): array
    {
        $stmt = $this->db->prepare(
            'SELECT productor_id, densidad, faltante
             FROM prod_control_leche_productor
             WHERE fecha = :fecha'
        );
        $stmt->execute(['fecha' => $fecha]);
        $out = [];
        foreach ($stmt as $row) {
            $out[(int) $row['productor_id']] = [
                'densidad' => $row['densidad'] === null ? '' : (string) $row['densidad'],
                'faltante' => (string) $row['faltante'],
            ];
        }
        return $out;
    }

    /**
     * Guarda densidad y faltante de cada productor. El faltante del día es la suma.
     *
     * @param array<int|string, array{densidad?:string, faltante?:string}> $filas
     */
    public function guardarProductores(string $fecha, array $filas): string
    {
        $stmt = $this->db->prepare(
            'INSERT INTO prod_control_leche_productor (fecha, productor_id, densidad, faltante)
             VALUES (:fecha, :productor, :densidad, :faltante)
             ON DUPLICATE KEY UPDATE densidad = VALUES(densidad), faltante = VALUES(faltante)'
        );
        $suma = 0;
        foreach ($filas as $id => $fila) {
            $productorId = (int) $id;
            if ($productorId < 1) {
                continue;
            }
            $faltante = $fila['faltante'] ?? '0.00';
            $densidad = ($fila['densidad'] ?? '') === '' ? null : $fila['densidad'];
            $stmt->execute([
                'fecha'     => $fecha,
                'productor' => $productorId,
                'densidad'  => $densidad,
                'faltante'  => $faltante,
            ]);
            $suma += (int) round((float) $faltante * 100);
        }
        return number_format($suma / 100, 2, '.', '');
    }

    /** @param array<string, string>|null $fila */
    public static function neta(string $recibida, ?array $fila): string
    {
        $mil = (int) round((float) $recibida * 1000);
        foreach (self::CAMPOS as $campo) {
            $mil -= (int) round((float) ($fila[$campo] ?? 0) * 1000);
        }
        return number_format(max(0, $mil) / 1000, 3, '.', '');
    }
}
