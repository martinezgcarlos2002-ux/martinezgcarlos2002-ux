<?php
declare(strict_types=1);

/**
 * Arranque de adara_master: el super admin y el primer cliente (la base `adara` que ya existe).
 *
 * Consola:  php bin/init_master.php
 * Navegador: http://localhost/ADARA/bin/init_master.php  (solo desde esta máquina, y solo una vez)
 *
 * No sobrescribe nada. Si ya hay un super admin, se detiene. La clave 123456 queda marcada para
 * cambio obligatorio en el primer ingreso del Panel Maestro.
 */

if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}

require __DIR__ . '/../config/bootstrap.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
});

const INIT_CORREO = 'carlosmanuel@gmail.com';
const INIT_CLAVE = '123456';
const INIT_NOMBRE = 'Carlos Manuel';
const INIT_EMPRESA = 'El Castillo del Queso';
const INIT_SLUG = 'castillo';
const INIT_ADMIN_EMPRESA = 'admin@elcastillo.com';
const INIT_BASE = 'adara';

function salir(string $texto, int $codigo = 0): never
{
    fwrite($codigo === 0 ? STDOUT : STDERR, $texto . PHP_EOL);
    exit($codigo);
}

if (!Database::multiempresa()) {
    salir('Falta ADARA_MASTER_DB_NAME. En Apache: SetEnv ADARA_MASTER_DB_NAME adara_master', 1);
}

try {
    $db = Database::master();
} catch (DatabaseException $e) {
    salir('No pude conectar con adara_master. ¿Ejecutaste sql/master/001_adara_master.sql en el puerto 3306?', 1);
}

$ya = (int) $db->query('SELECT COUNT(*) FROM super_admins')->fetchColumn();
if ($ya > 0) {
    salir("adara_master ya tiene {$ya} super admin(s). Este arranque no sobrescribe accesos ni empresas.", 1);
}

// La migración 001 solo aceptaba adara_*. La base del primer cliente se llama adara, sin sufijo.
$db->exec('ALTER TABLE clientes_saas DROP CONSTRAINT ck_clientes_db');
$db->exec(
    "ALTER TABLE clientes_saas ADD CONSTRAINT ck_clientes_db CHECK
     (nombre_db_fisica REGEXP '^(adara|adara_[a-z0-9_]{1,58})$' AND nombre_db_fisica <> 'adara_master')"
);

$columnas = $db->query('SHOW COLUMNS FROM super_admins')->fetchAll(PDO::FETCH_COLUMN);
$forzarCambio = in_array('debe_cambiar_clave', $columnas, true);

$hash = password_hash(INIT_CLAVE, PASSWORD_ALGO, PASSWORD_OPTIONS);

$db->beginTransaction();
try {
    $sqlAdmin = $forzarCambio
        ? 'INSERT INTO super_admins (id, nombre, correo, password_hash, activo, debe_cambiar_clave)
           VALUES (1, :nombre, :correo, :hash, 1, 1)'
        : 'INSERT INTO super_admins (id, nombre, correo, password_hash, activo)
           VALUES (1, :nombre, :correo, :hash, 1)';
    $db->prepare($sqlAdmin)->execute([
        'nombre' => INIT_NOMBRE,
        'correo' => INIT_CORREO,
        'hash'   => $hash,
    ]);

    $db->prepare(
        'INSERT INTO clientes_saas
            (nombre_empresa, dominio_o_slug, correo_admin_cliente, nombre_db_fisica, estado_licencia, registrado_por)
         VALUES (:nombre, :slug, :correo, :base, \'activa\', 1)'
    )->execute([
        'nombre' => INIT_EMPRESA,
        'slug'   => INIT_SLUG,
        'correo' => INIT_ADMIN_EMPRESA,
        'base'   => INIT_BASE,
    ]);

    (new Saas($db))->auditar(1, 'init_master', 1, [
        'super_admin' => INIT_CORREO,
        'empresa'     => INIT_EMPRESA,
        'base'        => INIT_BASE,
    ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    salir('No se pudo inicializar adara_master: ' . $e->getMessage(), 1);
}

$aviso = '';
try {
    $stmt = Database::conectarA(INIT_BASE)->prepare(
        'SELECT activo FROM usuarios WHERE email = :email AND eliminado_en IS NULL LIMIT 1'
    );
    $stmt->execute(['email' => INIT_ADMIN_EMPRESA]);
    if ((int) $stmt->fetchColumn() !== 1) {
        $aviso = 'AVISO: ' . INIT_ADMIN_EMPRESA . ' no es un usuario activo de la base `' . INIT_BASE
            . '`. El login de esa empresa no entrará hasta que ese correo exista en su tabla usuarios.';
    }
} catch (Throwable $e) {
    $aviso = 'AVISO: no pude revisar la tabla usuarios de `' . INIT_BASE . '`: ' . $e->getMessage();
}

salir(implode(PHP_EOL, array_filter([
    'Listo.',
    'Super admin: ' . INIT_CORREO . ' / ' . INIT_CLAVE . ' (id 1).'
        . ($forzarCambio ? ' El panel pedirá cambiar esa clave al entrar.' : ' Aplica sql/master/002_gestion_accesos.sql para obligar el cambio de esa clave.'),
    'Cliente: ' . INIT_EMPRESA . ' → base `' . INIT_BASE . '`, slug ' . INIT_SLUG . ', admin ' . INIT_ADMIN_EMPRESA . '.',
    $aviso,
    'Entra por http://localhost/ADARA/ con el correo del super admin. No vuelvas a ejecutar este script.',
])));
