<?php
declare(strict_types=1);

/**
 * Crea un administrador o restablece su contraseña si el correo ya existe.
 * Uso: php bin/crear_admin.php --email=correo@dominio.com --nombre="Nombre Apellido"
 * La contraseña se pide por consola (o se toma de la variable de entorno ADARA_ADMIN_PASS).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/bootstrap.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    return trim((string) fgets(STDIN));
}

$opts = getopt('', ['email:', 'nombre:']);

$email = mb_strtolower(trim((string) ($opts['email'] ?? prompt('Correo del administrador: '))));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 100) {
    fwrite(STDERR, "Correo inválido (máximo 100 caracteres).\n");
    exit(1);
}

$password = getenv('ADARA_ADMIN_PASS') ?: prompt(sprintf(
    'Contraseña (%d a %d caracteres): ',
    PASSWORD_MIN_LEN,
    PASSWORD_MAX_BYTES
));
if (mb_strlen($password) < PASSWORD_MIN_LEN || strlen($password) > PASSWORD_MAX_BYTES) {
    fwrite(STDERR, sprintf("La contraseña debe tener entre %d caracteres y %d bytes.\n", PASSWORD_MIN_LEN, PASSWORD_MAX_BYTES));
    exit(1);
}

$hash = password_hash($password, PASSWORD_ALGO, PASSWORD_OPTIONS);
unset($password);

$usuarios = new Usuario(get_db());
$existingId = $usuarios->findIdByEmail($email);

if ($existingId !== null) {
    Database::transaction(static function () use ($usuarios, $existingId, $hash): void {
        $usuarios->updatePasswordHash($existingId, $hash);
        audit_log('usuarios', 'password_reset_cli', null, 'usuarios', $existingId, $existingId);
    });
    fwrite(STDOUT, "Contraseña restablecida para {$email} (id {$existingId}).\n");
    exit(0);
}

$nombre = trim((string) ($opts['nombre'] ?? prompt('Nombre completo: ')));
if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
    fwrite(STDERR, "Nombre inválido (2 a 80 caracteres).\n");
    exit(1);
}

$id = Database::transaction(static function () use ($usuarios, $nombre, $email, $hash): int {
    $id = $usuarios->create($nombre, $email, $hash, 'admin', []);
    audit_log('usuarios', 'crear_admin_cli', ['email' => $email, 'rol' => 'admin'], 'usuarios', $id, $id);
    return $id;
});

fwrite(STDOUT, "Administrador creado: {$email} (id {$id}).\n");
