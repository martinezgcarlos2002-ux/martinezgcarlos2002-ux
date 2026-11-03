<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

const CDN_ORIGIN = 'https://cdn.jsdelivr.net';

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
// Sin esto, la flecha Atrás reabre el dashboard desde la caché después de cerrar sesión.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header(sprintf(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; font-src 'self' %1\$s; "
    . "style-src 'self' %1\$s; style-src-attr 'unsafe-inline'; script-src 'self' %1\$s 'nonce-%2\$s'; connect-src 'self'; "
    . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
    CDN_ORIGIN,
    csp_nonce()
));

$routes = require __DIR__ . '/config/routes.php';

$p = $_GET['p'] ?? '';
if (!is_string($p) || !preg_match('/^[a-z_]{1,40}$/', $p)) {
    $p = '';
}
if ($p === '') {
    redirect(match (true) {
        super_admin_actual() !== null => 'master',
        current_user() !== null       => 'dashboard',
        default                       => 'login',
    });
}

$route = $routes[$p] ?? null;
if ($route === null) {
    render_not_found();
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$handler = $route[$method] ?? null;
if ($handler === null) {
    http_response_code(405);
    header('Allow: ' . implode(', ', array_intersect(['GET', 'POST'], array_keys($route))));
    exit;
}

// Las llamadas fetch (Accept: application/json) reciben el fallo en JSON en lugar de un redirect a HTML.
if ($method === 'POST') {
    hydrate_json_body();
}
if ($method === 'POST' && !csrf_validate()) {
    auditar_seguridad('csrf_invalido', ['p' => $p]);
    csrf_rotate();
    if (wants_json()) {
        json_response(403, ['ok' => false, 'codigo' => 'csrf_invalido', 'errores' => ['general' => 'La sesión del formulario expiró. Recarga la página e intenta de nuevo.']]);
    }
    flash('error', 'El formulario expiró o no es válido. Inténtalo de nuevo.');
    redirect(match (true) {
        sesion_es_panel_maestro() => 'master',
        empty($route['publica']) && current_user() !== null => 'dashboard',
        default => 'login',
    });
}

// El super admin no usa el ERP de las empresas. Solo sus rutas (y el logout) siguen;
// ?p=facturas, ?p=dashboard y el resto vuelven al Panel Maestro. El modo soporte no entra aquí.
if (sesion_es_panel_maestro() && empty($route['super_admin']) && !in_array($p, ['logout'], true)) {
    if (wants_json()) {
        json_response(403, ['ok' => false, 'errores' => ['general' => 'El Panel Maestro no opera los módulos de una empresa.']]);
    }
    flash('info', 'Esa pantalla pertenece a una empresa. Desde el Panel Maestro se entra con el botón Soporte.');
    redirect('master');
}

if (!empty($route['super_admin'])) {
    // Panel Maestro SaaS: solo $_SESSION['rol_maestro'] === true con un super admin activo en adara_master.
    if (super_admin_actual() === null) {
        auditar_seguridad('acceso_denegado_master', ['p' => $p]);
        if (wants_json()) {
            json_response(403, ['ok' => false, 'errores' => ['general' => 'Acceso exclusivo del administrador de ADARA.']]);
        }
        flash('error', 'Acceso exclusivo del administrador de ADARA.');
        redirect('login');
    }
} elseif (empty($route['publica'])) {
    $user = current_user();
    if ($user === null) {
        if (wants_json()) {
            json_response(401, ['ok' => false, 'codigo' => 'sesion_expirada', 'errores' => ['general' => 'Tu sesión terminó. Inicia sesión de nuevo.']]);
        }
        flash('info', 'Inicia sesión para continuar.');
        redirect('login');
    }
    if (isset($route['modulo']) && !has_permiso($user, $route['modulo'], $route['accion'] ?? 'ver')) {
        audit_log('seguridad', 'acceso_denegado', ['p' => $p]);
        if (wants_json()) {
            json_response(403, ['ok' => false, 'errores' => ['general' => 'No tienes permiso para esta acción.']]);
        }
        render_forbidden();
        exit;
    }
}

if ($method === 'POST' && !empty($route['idempotente']) && current_user() !== null) {
    Idempotencia::iniciar($p, (int) current_user()['id']);
}

[$class, $action] = $handler;
(new $class())->$action();
