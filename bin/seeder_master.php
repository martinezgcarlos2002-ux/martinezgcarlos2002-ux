<?php
declare(strict_types=1);

/**
 * Seeder de adara_master: crea el super admin propietario (id 1).
 *
 * Uso: ADARA_MASTER_DB_NAME=adara_master php bin/seeder_master.php [--correo=…] [--nombre=…] [--clave=…]
 * Por defecto: carlosmanuel@gmail.com / 123456. La clave inicial queda marcada para cambio obligatorio
 * en el primer ingreso (mínimo Saas::CLAVE_MIN_SUPER_ADMIN caracteres): el panel no permite otra acción
 * hasta cambiarla. Iniciar sesión justo después de ejecutar el seeder.
 *
 * Solo corre sobre una tabla super_admins vacía: nunca sobrescribe ni restablece una cuenta existente
 * (para restablecer claves: bin/crear_super_admin.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/bootstrap.php';

if (!Database::multiempresa()) {
    fwrite(STDERR, "Define ADARA_MASTER_DB_NAME (p. ej. adara_master).\n");
    exit(1);
}

$opts = getopt('', ['correo::', 'nombre::', 'clave::']);
$correo = mb_strtolower(trim((string) ($opts['correo'] ?? 'carlosmanuel@gmail.com')));
$nombre = trim((string) ($opts['nombre'] ?? 'Carlos Manuel'));
$clave = (string) ($opts['clave'] ?? '123456');

if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false || mb_strlen($correo) > 100) {
    fwrite(STDERR, "Correo inválido.\n");
    exit(1);
}
if ($clave === '' || strlen($clave) > PASSWORD_MAX_BYTES) {
    fwrite(STDERR, "Clave inválida.\n");
    exit(1);
}

$db = Database::master();
$saas = new Saas($db);

$total = (int) $db->query('SELECT COUNT(*) FROM super_admins')->fetchColumn();
if ($total > 0) {
    fwrite(STDERR, "super_admins ya tiene {$total} cuenta(s): el seeder no sobrescribe accesos existentes.\n"
        . "Para restablecer una clave usa bin/crear_super_admin.php.\n");
    exit(1);
}
if ($saas->clientePorCorreoAdmin($correo) !== null) {
    fwrite(STDERR, "Ese correo administra una empresa cliente; usa otro para el propietario.\n");
    exit(1);
}

$db->prepare(
    'INSERT INTO super_admins (id, nombre, correo, password_hash, activo, debe_cambiar_clave)
     VALUES (:id, :nombre, :correo, :hash, 1, 1)'
)->execute([
    'id'     => Saas::PROPIETARIO_ID,
    'nombre' => $nombre,
    'correo' => $correo,
    'hash'   => password_hash($clave, PASSWORD_ALGO, PASSWORD_OPTIONS),
]);
unset($clave);
$saas->auditar(Saas::PROPIETARIO_ID, 'seeder_propietario', null, ['correo' => $correo]);

fwrite(STDOUT, "Propietario creado: {$correo} (id " . Saas::PROPIETARIO_ID . ").\n"
    . "Inicia sesión ahora: el panel exigirá cambiar la clave inicial antes de cualquier otra acción.\n");
