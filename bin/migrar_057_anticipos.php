<?php
declare(strict_types=1);

/**
 * Ajusta ck_caja_origen para que un cobro sin factura (anticipo) pueda entrar a caja.
 * Conserva el resto del CHECK de esa base. Idempotente.
 *
 *   php bin/migrar_057_anticipos.php
 * Antes, en la misma base: sql/057_cxc_anticipos.sql
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/bootstrap.php';

$db = get_db();
$check = $db->query(
    "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimientos' AND CONSTRAINT_NAME = 'ck_caja_origen'"
)->fetchColumn();

$permiso = "`origen` = 'cobro_factura' and `cxc_pago_id` is not null";
if ($check !== false && str_contains((string) $check, $permiso) && !str_contains((string) $check, 'cxc_factura_id')) {
    echo "SKIP ck_caja_origen ya admite anticipo\n";
    exit(0);
}

$base = $check === false
    ? "`origen` = 'manual'"
    : str_replace(
        ' and `cxc_factura_id` is not null',
        '',
        (string) $check
    );
if (!str_contains($base, 'cobro_factura')) {
    $base .= ' or ' . $permiso;
}

if ($check !== false) {
    $db->exec('ALTER TABLE caja_movimientos DROP CONSTRAINT ck_caja_origen');
}
$db->exec('ALTER TABLE caja_movimientos ADD CONSTRAINT ck_caja_origen CHECK (' . $base . ')');
echo "OK   ck_caja_origen admite cobro sin factura\n";
