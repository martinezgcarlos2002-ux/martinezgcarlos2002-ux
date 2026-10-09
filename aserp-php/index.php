<?php
// ── Servir estáticos /public/ aun cuando el proxy añade el prefijo /aserp-php/
// (en XAMPP Apache esto lo hace solo, pero el servidor embebido de PHP necesita
//  esta ruta explícita).
$__uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
if (preg_match('#(?:^|/)public/([A-Za-z0-9._\-/]+)$#', $__uri, $__m)) {
    $__static = __DIR__ . '/public/' . $__m[1];
    if (is_file($__static) && strpos(realpath($__static), realpath(__DIR__ . '/public')) === 0) {
        $__ext = strtolower(pathinfo($__static, PATHINFO_EXTENSION));
        $__mimes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
                    'webp'=>'image/webp','svg'=>'image/svg+xml','ico'=>'image/x-icon',
                    'css'=>'text/css','js'=>'application/javascript','pdf'=>'application/pdf',
                    'html'=>'text/html; charset=utf-8','txt'=>'text/plain; charset=utf-8'];
        header('Content-Type: ' . ($__mimes[$__ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($__static));
        header('Cache-Control: public, max-age=3600');
        readfile($__static);
        exit;
    }
}

// ── Descargas: manejar ANTES de cualquier output ───────────────────────────
if (isset($_GET['dl'])) {
    $allowed_dl = [
        'mysql'   => ['public/aserp-mysql.sql',       'ASERP-BaseDatos-MySQL.sql',       'text/plain'],
        'bd'      => ['public/descarga-bd.sql',        'ASERP-BaseDatos-PostgreSQL.sql',  'text/plain'],
        'csv'     => ['public/aserp-tablas-csv.zip',   'ASERP-Tablas-CSV.zip',            'application/zip'],
        'sistema' => ['public/aserp-sistema.tar.gz',   'ASERP-Sistema.tar.gz',            'application/gzip'],
        'zip'     => ['public/aserp-sistema.zip',      'ASERP-Sistema.zip',               'application/zip'],
        'actualizacion' => ['public/aserp-actualizacion.zip', 'ASERP-Actualizacion.zip',  'application/zip'],
        'manual'  => ['public/manual-usuario.html',    'ASERP-Manual-Usuario.html',       'text/html; charset=utf-8'],
    ];
    $key = $_GET['dl'];
    if (isset($allowed_dl[$key])) {
        [$rel, $name, $mime] = $allowed_dl[$key];
        $path = __DIR__ . '/' . $rel;
        if (file_exists($path)) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            readfile($path);
            exit;
        }
    }
    http_response_code(404);
    echo 'Archivo no encontrado.';
    exit;
}
// ──────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/config.php';

// ── Licencia / bloqueo del sistema (privado) ───────────────────────────────
// Helpers compartidos (también definidos en pages/login.php con la misma guarda).
if (!function_exists('ensure_config_produccion_table')) {
    function ensure_config_produccion_table(): void {
        static $done = false; if ($done) return;
        try {
            $db = get_db();
            if (is_mysql()) {
                $db->exec("CREATE TABLE IF NOT EXISTS config_produccion (clave VARCHAR(50) PRIMARY KEY, valor MEDIUMTEXT NOT NULL, actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS config_produccion (clave VARCHAR(50) PRIMARY KEY, valor TEXT NOT NULL, actualizado_en TIMESTAMP NOT NULL DEFAULT NOW())");
            }
            $done = true;
        } catch (Throwable $e) { error_log('[ensure_config_produccion_table] ' . $e->getMessage()); }
    }
}
if (!function_exists('get_config_produccion')) {
    function get_config_produccion(string $clave = 'panel'): ?array {
        ensure_config_produccion_table();
        try {
            $db = get_db();
            $st = $db->prepare("SELECT valor FROM config_produccion WHERE clave=?");
            $st->execute([$clave]);
            $v = $st->fetchColumn();
            if ($v) { $d = json_decode($v, true); if (is_array($d)) return $d; }
        } catch (Throwable $e) { error_log('[get_config_produccion] ' . $e->getMessage()); }
        return null;
    }
}
if (!function_exists('save_config_produccion')) {
    function save_config_produccion(array $data, string $clave = 'panel'): bool {
        ensure_config_produccion_table();
        try {
            $db = get_db();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (is_mysql()) {
                $db->prepare("INSERT INTO config_produccion (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), actualizado_en=CURRENT_TIMESTAMP")->execute([$clave, $json]);
            } else {
                $db->prepare("INSERT INTO config_produccion (clave, valor) VALUES (?,?) ON CONFLICT (clave) DO UPDATE SET valor=EXCLUDED.valor, actualizado_en=NOW()")->execute([$clave, $json]);
            }
            return true;
        } catch (Throwable $e) { error_log('[save_config_produccion] ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('aserp_get_licencia')) {
    function aserp_get_licencia(): array {
        $lic = get_config_produccion('licencia');
        if (!is_array($lic)) $lic = [];
        $changed = false;
        if (!array_key_exists('activo', $lic)) { $lic['activo'] = true; $changed = true; }
        if (empty($lic['fecha_bloqueo']))      { $lic['fecha_bloqueo'] = date('Y-m-d', strtotime('+1 month')); $changed = true; }
        if (empty($lic['sup_user']))           { $lic['sup_user'] = 'v-30703203'; $changed = true; }
        if (empty($lic['sup_pass_hash']))      { $lic['sup_pass_hash'] = password_hash('30703203', PASSWORD_DEFAULT); $changed = true; }
        if ($changed) save_config_produccion($lic, 'licencia');
        return $lic;
    }
}
if (!function_exists('aserp_sistema_bloqueado')) {
    function aserp_sistema_bloqueado(array $lic): bool {
        if (empty($lic['activo']) || empty($lic['fecha_bloqueo'])) return false;
        return date('Y-m-d') >= $lic['fecha_bloqueo'];
    }
}

// ── Acceso remoto: genera un enlace público (túnel cloudflared) en segundo
//    plano, sin que el cliente descargue ni instale nada manualmente. ─────────
if (!function_exists('aserp_descargar_archivo')) {
    function aserp_descargar_archivo(string $url, string $dest): bool {
        try {
            if (function_exists('curl_init')) {
                $fp = @fopen($dest, 'wb'); if (!$fp) return false;
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 30,
                ]);
                $ok = curl_exec($ch); curl_close($ch); fclose($fp);
                return $ok && is_file($dest) && filesize($dest) > 1000000;
            }
            if (ini_get('allow_url_fopen')) {
                $data = @file_get_contents($url);
                if ($data === false) return false;
                @file_put_contents($dest, $data);
                return is_file($dest) && filesize($dest) > 1000000;
            }
        } catch (Throwable $e) { error_log('[aserp_descargar_archivo] ' . $e->getMessage()); }
        return false;
    }
}
if (!function_exists('aserp_enlace_remoto')) {
    function aserp_enlace_remoto(bool $forzar, int $puerto, string $ruta): array {
        @set_time_limit(0);
        // El servidor debe poder lanzar procesos en segundo plano.
        if (!function_exists('popen') || !function_exists('exec')) {
            return ['ok' => false, 'error' => 'Este servidor no permite generar el enlace automáticamente. Contacte al soporte técnico.'];
        }
        $dir = __DIR__ . '/public/.remoto';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $win     = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $bin     = $dir . DIRECTORY_SEPARATOR . ($win ? 'cloudflared.exe' : 'cloudflared');
        $log     = $dir . DIRECTORY_SEPARATOR . 'tunnel.log';
        $urlFile = $dir . DIRECTORY_SEPARATOR . 'tunnel.url';

        // Un solo proceso de generación a la vez (evita túneles duplicados por
        // doble clic o solicitudes concurrentes).
        $lockFp = @fopen($dir . DIRECTORY_SEPARATOR . 'gen.lock', 'c');
        if ($lockFp) @flock($lockFp, LOCK_EX);
        try {
            // Reutilizar un enlace reciente (salvo que se pida uno nuevo).
            if (!$forzar && is_file($urlFile)) {
                $u = trim((string)@file_get_contents($urlFile));
                if ($u !== '' && (time() - (int)@filemtime($urlFile) < 12 * 3600)) {
                    return ['ok' => true, 'url' => $u . $ruta];
                }
            }

            // Asegurar el binario (descarga única, del lado del servidor = la PC).
            if (!is_file($bin)) {
                $src = $win
                    ? 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe'
                    : 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64';
                if (!aserp_descargar_archivo($src, $bin)) {
                    return ['ok' => false, 'error' => 'No se pudo preparar la herramienta de conexión.'];
                }
                if (!$win) @chmod($bin, 0755);
            }

            // (Re)lanzar el túnel en segundo plano.
            if ($forzar) {
                if ($win) @exec('taskkill /F /IM cloudflared.exe 2>NUL');
                else      @exec('pkill -f cloudflared 2>/dev/null');
                @unlink($urlFile);
            }
            @file_put_contents($log, '');
            if ($win) {
                $run = $dir . DIRECTORY_SEPARATOR . 'run.cmd';
                @file_put_contents($run,
                    "@echo off\r\n" .
                    '"' . $bin . '" tunnel --no-autoupdate --url http://localhost:' . $puerto .
                    ' > "' . $log . '" 2>&1' . "\r\n");
                pclose(popen('cmd /c start "" /B "' . $run . '"', 'r'));
            } else {
                @exec('nohup "' . $bin . '" tunnel --no-autoupdate --url http://localhost:' . $puerto .
                      ' > "' . $log . '" 2>&1 &');
            }

            // Esperar a que aparezca la URL pública (hasta ~20s).
            $url = '';
            for ($i = 0; $i < 40; $i++) {
                usleep(500000);
                $c = (string)@file_get_contents($log);
                if (preg_match('#https://[a-z0-9-]+\.trycloudflare\.com#i', $c, $m)) { $url = $m[0]; break; }
            }
            if ($url === '') return ['ok' => false, 'error' => 'No se pudo generar el enlace. Intente nuevamente.'];
            @file_put_contents($urlFile, $url);
            return ['ok' => true, 'url' => $url . $ruta];
        } finally {
            if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
        }
    }
}

if (isset($_GET['remoto'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $lic = aserp_get_licencia();
    if (!aserp_sistema_bloqueado($lic)) {
        echo json_encode(['ok' => false, 'error' => 'El sistema no está bloqueado.']);
        exit;
    }
    $puerto = (int)($_SERVER['SERVER_PORT'] ?? 80);
    if ($puerto <= 0) $puerto = 80;
    echo json_encode(aserp_enlace_remoto($_GET['remoto'] === 'nuevo', $puerto, '/aserp-php/'));
    exit;
}

$page = preg_replace('/[^a-z_]/', '', strtolower($_GET['p'] ?? 'dashboard'));
if ($page === '') $page = 'dashboard';
// Redirigir sección Proveedores → unificada en Productores
if ($page === 'proveedores') { header('Location: ?p=productores'); exit; }

$public = ['login', 'descargas'];

if (!in_array($page, $public)) {
    require_login();
    // Si el sistema está bloqueado, ninguna sesión normal puede continuar:
    // se cierra la sesión y se envía al login (que muestra el aviso de soporte).
    if (empty($_SESSION['sup_ok']) && aserp_sistema_bloqueado(aserp_get_licencia())) {
        unset($_SESSION['user_id'], $_SESSION['user_nombre'], $_SESSION['user_email'], $_SESSION['user_rol']);
        header('Location: ?p=login');
        exit;
    }
}

// Cierre automático diario de productores (una sola vez al día, primera visita).
if (!empty($_SESSION['user_id'])) {
    daily_auto_close_all();
}

$file = __DIR__ . '/pages/' . $page . '.php';
if (!file_exists($file)) {
    $file = __DIR__ . '/pages/dashboard.php';
    $page = 'dashboard';
}

// ── Control de permisos ────────────────────────────────────────────────────
// Páginas que siempre están disponibles para sesión autenticada
$always_allowed = ['logout', 'dashboard'];
if (!in_array($page, $public) && !in_array($page, $always_allowed)) {
    $modulo = page_to_modulo($page);
    if (!has_permiso($modulo)) {
        // Sin acceso: mostrar página en blanco con mensaje dentro del layout
        require_once __DIR__ . '/layout.php';
        layout_start('Sin acceso', '');
        echo '<div class="d-flex flex-column align-items-center justify-content-center" style="min-height:40vh;text-align:center">';
        echo '<div style="font-size:3rem">🔒</div>';
        echo '<h4 class="mt-3 text-danger">Acceso restringido</h4>';
        echo '<p class="text-muted">No tienes permiso para acceder a este módulo.<br>Contacta al administrador.</p>';
        echo '<a href="?p=dashboard" class="btn btn-outline-primary mt-2">← Ir al inicio</a>';
        echo '</div>';
        layout_end();
        exit;
    }
}
// ──────────────────────────────────────────────────────────────────────────

// ── Auditoría automática ───────────────────────────────────────────────────
// Solo registramos requests autenticados que modifican datos (POST/PUT/DELETE).
// Las páginas públicas (login, descargas) ya se auto-auditan donde corresponda.
if (!empty($_SESSION['user_id'])
    && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST','PUT','DELETE','PATCH'], true)
    && !in_array($page, ['login','logout','auditoria'], true)) {
    $accion = $_POST['action'] ?? ($_POST['accion'] ?? $_SERVER['REQUEST_METHOD']);
    // Resumir POST sin guardar campos sensibles
    $detalle = [];
    foreach ($_POST as $k => $v) {
        if (in_array($k, ['_csrf','password','password2','password_actual','password_nueva','password_repeat'], true)) continue;
        if (is_array($v)) { $detalle[$k] = '[array]'; continue; }
        $s = (string)$v;
        $detalle[$k] = mb_strlen($s) > 120 ? mb_substr($s, 0, 117) . '…' : $s;
    }
    audit_log(page_to_modulo($page), (string)$accion, $detalle ?: null);
    audit_session_touch();
}

require_once __DIR__ . '/layout.php';
require $file;
