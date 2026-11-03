<?php
declare(strict_types=1);

/**
 * Genera sql/adara_esquema_limpio.sql (plantilla de empresas nuevas para SaaSManager) a partir de una
 * base con todas las migraciones aplicadas. Regenerar cada vez que se agregue una migración.
 *
 * Uso: php bin/generar_esquema_limpio.php --origen=adara_castillo
 *      (host/puerto/usuario/clave: ADARA_DB_HOST, ADARA_DB_PORT, ADARA_DB_USER, ADARA_DB_PASS)
 *
 * Contenido: estructura, vistas, triggers y rutinas, sin datos de negocio. Solo se copian los
 * catálogos de sistema de TABLAS_CATALOGO. Se eliminan DEFINER (el hosting no da SUPER) y los
 * AUTO_INCREMENT del origen (una empresa nueva empieza en 1).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const TABLAS_CATALOGO = ['bancos_catalogo', 'gas_correlativos'];

$opts = getopt('', ['origen:', 'mysqldump::']);
$origen = (string) ($opts['origen'] ?? '');
if (!preg_match('/^[a-z0-9_]{1,64}$/', $origen)) {
    fwrite(STDERR, "Indica --origen=<base con todas las migraciones aplicadas>.\n");
    exit(1);
}
$mysqldump = (string) ($opts['mysqldump'] ?? (PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump'));
$env = static fn (string $k, string $d): string => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;

// Credenciales en un archivo temporal: nunca en la línea de comandos (visible en la lista de procesos).
$cnf = tempnam(sys_get_temp_dir(), 'adara_cnf_');
file_put_contents($cnf, sprintf(
    "[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\ndefault-character-set=utf8mb4\n",
    $env('ADARA_DB_HOST', '127.0.0.1'),
    $env('ADARA_DB_PORT', '3306'),
    $env('ADARA_DB_USER', 'root'),
    addcslashes((string) (getenv('ADARA_DB_PASS') ?: ''), '"\\')
));
chmod($cnf, 0600);

$ejecutar = static function (array $args) use ($mysqldump, $cnf): string {
    $cmd = array_merge([$mysqldump, '--defaults-extra-file=' . $cnf], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('No se pudo ejecutar mysqldump.');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException('mysqldump falló: ' . trim((string) $err));
    }
    return (string) $out;
};

try {
    $estructura = $ejecutar(['--no-data', '--routines', '--triggers', '--skip-add-drop-table', '--skip-comments', '--skip-add-locks', $origen]);
    $catalogos = $ejecutar(['--no-create-info', '--skip-triggers', '--skip-comments', '--skip-add-locks', '--complete-insert', $origen, ...TABLAS_CATALOGO]);
} finally {
    unlink($cnf);
}

$limpiar = static function (string $sql): string {
    $sql = preg_replace('/\s*DEFINER=`[^`]+`@`[^`]+`/', '', $sql) ?? $sql;
    $sql = str_replace('SQL SECURITY DEFINER', 'SQL SECURITY INVOKER', $sql);
    return preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $sql) ?? $sql;
};

$tablas = preg_match_all('/^CREATE TABLE `/m', $estructura);
$cabecera = "-- =====================================================================\n"
    . "-- ADARA · Esquema limpio para empresas nuevas (SaaSManager::aprovisionar)\n"
    . "-- Generado: " . date('Y-m-d H:i:s') . " desde `{$origen}` con bin/generar_esquema_limpio.php\n"
    . "-- {$tablas} tablas, sin datos de negocio. Catálogos de sistema: " . implode(', ', TABLAS_CATALOGO) . ".\n"
    . "-- Sin USE ni CREATE DATABASE: se ejecuta sobre la base recién creada.\n"
    . "-- =====================================================================\n";

$destino = dirname(__DIR__) . '/sql/adara_esquema_limpio.sql';
file_put_contents($destino, $cabecera . $limpiar($estructura) . "\n-- Catálogos de sistema\n" . $limpiar($catalogos));
fwrite(STDOUT, sprintf("Esquema limpio generado: %s (%d tablas, %s bytes).\n", $destino, $tablas, number_format(filesize($destino))));
