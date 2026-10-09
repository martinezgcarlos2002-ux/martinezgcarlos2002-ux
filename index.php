<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

const CDN_ORIGIN = 'https://cdn.jsdelivr.net';

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, private');
header(sprintf(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data:; font-src 'self' %1\$s; "
    . "style-src 'self' %1\$s; script-src 'self' %1\$s 'nonce-%2\$s'; connect-src 'self'; "
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
    redirect(current_user() !== null ? 'dashboard' : 'login');
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
    audit_log('seguridad', 'csrf_invalido', ['p' => $p]);
    csrf_rotate();
    if (wants_json()) {
        json_response(403, ['ok' => false, 'errores' => ['general' => 'La sesión del formulario expiró. Recarga la página e intenta de nuevo.']]);
    }
    flash('error', 'El formulario expiró o no es válido. Inténtalo de nuevo.');
    redirect(empty($route['publica']) && current_user() !== null ? 'dashboard' : 'login');
}

if (empty($route['publica'])) {
    $user = current_user();
    if ($user === null) {
        if (wants_json()) {
            json_response(401, ['ok' => false, 'errores' => ['general' => 'Tu sesión terminó. Inicia sesión de nuevo.']]);
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

[$class, $action] = $handler;
(new $class())->$action();
