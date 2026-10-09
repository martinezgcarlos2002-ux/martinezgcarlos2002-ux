<?php
declare(strict_types=1);

/**
 * Números de factura aleatorios con prefijo (ej. "R-4821") para facturas digitalizadas sin número de talonario.
 * La verificación previa evita casi todos los choques; el índice único uq_cxc_facturas_numero es la garantía final,
 * así que quien inserte debe tratar el error 1062 pidiendo otro número (CxCService lo hace).
 */
final class CorrelativoService
{
    private const PREFIJO = 'R-';
    private const INTENTOS_POR_LONGITUD = 25;
    private const DIGITOS_MIN = 4;
    private const DIGITOS_MAX = 6;

    public function __construct(private PDO $db)
    {
    }

    /** @throws RuntimeException si no encuentra un número libre (espacio agotado). */
    public function generarAleatorio(): string
    {
        $existe = $this->db->prepare('SELECT 1 FROM cxc_facturas WHERE numero_factura = :numero LIMIT 1');
        for ($digitos = self::DIGITOS_MIN; $digitos <= self::DIGITOS_MAX; $digitos++) {
            $max = 10 ** $digitos - 1;
            for ($i = 0; $i < self::INTENTOS_POR_LONGITUD; $i++) {
                $numero = self::PREFIJO . str_pad((string) random_int(0, $max), $digitos, '0', STR_PAD_LEFT);
                $existe->execute(['numero' => $numero]);
                if ($existe->fetchColumn() === false) {
                    return $numero;
                }
            }
        }
        throw new RuntimeException('No hay números aleatorios de factura disponibles.');
    }

    public function existe(string $numero): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM cxc_facturas WHERE numero_factura = :numero LIMIT 1');
        $stmt->execute(['numero' => $numero]);
        return $stmt->fetchColumn() !== false;
    }

    /** Normaliza un número escrito a mano: sin espacios, mayúsculas; null si no cumple el formato de la tabla. */
    public static function normalizar(string $raw): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', $raw));
        return preg_match('/^[A-Z0-9-]{1,20}$/', $v) ? $v : null;
    }
}
