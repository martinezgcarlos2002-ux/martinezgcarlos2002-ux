<?php
declare(strict_types=1);

/**
 * Crea un super admin del Panel Maestro SaaS (adara_master) o restablece su contraseña.
 * Uso: ADARA_MASTER_DB_NAME=adara_master php bin/crear_super_admin.php --email=yo@dominio.com --nombre="Nombre"
 * La contraseña se pide por consola (o se toma de ADARA_ADMIN_PASS).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/bootstrap.php';

if (!Database::multiempresa()) {
    fwrite(STDERR, "Define ADARA_MASTER_DB_NAME (p. ej. adara_master) para usar el modo multiempresa.\n");
    exit(1);
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    return trim((string) fgets(STDIN));
}

$opts = getopt('', ['email:', 'nombre:']);

$email = mb_strtolower(trim((string) ($opts['email'] ?? prompt('Correo del super admin: '))));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 100) {
    fwrite(STDERR, "Correo inválido (máximo 100 caracteres).\n");
    exit(1);
}

$password = getenv('ADARA_ADMIN_PASS') ?: prompt(sprintf('Contraseña (%d a %d caracteres): ', PASSWORD_MIN_LEN, PASSWORD_MAX_BYTES));
if (mb_strlen($password) < PASSWORD_MIN_LEN || strlen($password) > PASSWORD_MAX_BYTES) {
    fwrite(STDERR, sprintf("La contraseña debe tener entre %d caracteres y %d bytes.\n", PASSWORD_MIN_LEN, PASSWORD_MAX_BYTES));
    exit(1);
}
$hash = password_hash($password, PASSWORD_ALGO, PASSWORD_OPTIONS);
unset($password);

$db = Database::master();
$saas = new Saas($db);

$conflicto = $saas->clientePorCorreoAdmin($email);
if ($conflicto !== null) {
    fwrite(STDERR, "Ese correo administra la empresa {$conflicto['nombre_empresa']}; usa otro para el super admin.\n");
    exit(1);
}

$existente = $saas->superAdminPorCorreo($email);
if ($existente !== null) {
    $saas->actualizarHashSuperAdmin((int) $existente['id'], $hash);
    $saas->auditar((int) $existente['id'], 'password_reset_cli', null, null);
    fwrite(STDOUT, "Contraseña restablecida para {$email} (id {$existente['id']}).\n");
    exit(0);
}

$nombre = trim((string) ($opts['nombre'] ?? prompt('Nombre completo: ')));
if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
    fwrite(STDERR, "Nombre inválido (2 a 80 caracteres).\n");
    exit(1);
}

$db->prepare('INSERT INTO super_admins (nombre, correo, password_hash) VALUES (:nombre, :correo, :hash)')
    ->execute(['nombre' => $nombre, 'correo' => $email, 'hash' => $hash]);
$id = (int) $db->lastInsertId();
$saas->auditar($id, 'crear_super_admin_cli', null, ['correo' => $email]);

fwrite(STDOUT, "Super admin creado: {$email} (id {$id}).\n");
