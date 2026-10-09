<?php
declare(strict_types=1);

final class TasaCambio
{
    /**
     * Tasa Bs/USD vigente en este momento. Se memoriza por petición: el navbar y el dashboard la comparten.
     * @return array{id:int, tasa:float, vigente_desde:string}|null
     */
    public static function vigente(PDO $db): ?array
    {
        static $loaded = false;
        static $tasa = null;

        if ($loaded) {
            return $tasa;
        }
        $loaded = true;

        $stmt = $db->prepare(
            'SELECT id, tasa_bs_por_usd, vigente_desde
             FROM tasas_cambio
             WHERE vigente_desde <= :ahora
             ORDER BY vigente_desde DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['ahora' => date('Y-m-d H:i:s')]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $tasa = [
                'id'            => (int) $row['id'],
                'tasa'          => (float) $row['tasa_bs_por_usd'],
                'vigente_desde' => $row['vigente_desde'],
            ];
        }
        return $tasa;
    }

    public static function registrar(PDO $db, string $tasa, string $vigenteDesde, int $usuarioId, string $fuente = 'BCV'): int
    {
        $stmt = $db->prepare(
            'INSERT INTO tasas_cambio (tasa_bs_por_usd, vigente_desde, fuente, registrada_por)
             VALUES (:tasa, :vigente_desde, :fuente, :usuario_id)'
        );
        $stmt->execute(['tasa' => $tasa, 'vigente_desde' => $vigenteDesde, 'fuente' => $fuente, 'usuario_id' => $usuarioId]);
        return (int) $db->lastInsertId();
    }

    /** @return array{id:int, tasa:string}|null  Tasa como string para congelarla en el asiento sin pasar por float. */
    public static function vigenteEn(PDO $db, string $momento): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, tasa_bs_por_usd
             FROM tasas_cambio
             WHERE vigente_desde <= :momento
             ORDER BY vigente_desde DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['momento' => $momento]);
        $row = $stmt->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'tasa' => (string) $row['tasa_bs_por_usd']];
    }
}
