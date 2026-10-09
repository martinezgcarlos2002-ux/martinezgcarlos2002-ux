<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Nonce CSP de la petición actual: solo los <script> que lo lleven se ejecutan. */
function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

/** Formato venezolano: 1.234,56 */
function num(mixed $value, int $decimals = 2): string
{
    return number_format((float) $value, $decimals, ',', '.');
}

/** Ruta base de la app en el servidor (ej. /ADARA/), usada para enlaces y cookie de sesión. */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = rtrim($dir, '/') . '/';
    }
    return $base;
}

function url(string $page, array $query = []): string
{
    return base_path() . 'index.php?' . http_build_query(['p' => $page] + $query);
}

function asset(string $path): string
{
    return base_path() . 'assets/' . ltrim($path, '/');
}

function redirect(string $page, array $query = []): never
{
    header('Location: ' . url($page, $query), true, 303);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Solo REMOTE_ADDR: las cabeceras X-Forwarded-For las controla el cliente. */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Si el POST llega como application/json (adaraFetch), copia el cuerpo a $_POST
 * para que post_string() y los controladores sigan leyendo el mismo contrato.
 * Los arrays (p. ej. renglones) se re-codifican a JSON string.
 */
function hydrate_json_body(): void
{
    $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($ct, 'application/json')) {
        return;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return;
    }
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        if (wants_json()) {
            json_response(400, ['ok' => false, 'errores' => ['general' => 'JSON inválido.']]);
        }
        return;
    }
    if (!is_array($data)) {
        return;
    }
    foreach ($data as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (is_array($value) || is_object($value)) {
            $_POST[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $_POST[$key] = $value ? '1' : '';
        } elseif ($value === null) {
            $_POST[$key] = '';
        } else {
            $_POST[$key] = (string) $value;
        }
    }
}

function post_string(string $key, int $maxLen = 255): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    return mb_substr(trim($value), 0, $maxLen);
}

function wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/** @param 'success'|'error'|'info'|'warning' $type */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string, message:string}> */
function flash_take(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

/** Renderiza pages/{$page}.php. $page nunca proviene del usuario: lo decide el controlador. */
function view(string $page, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require ADARA_ROOT . '/pages/' . $page . '.php';
}
