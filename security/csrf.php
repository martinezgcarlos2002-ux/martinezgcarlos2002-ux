<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_validate(): bool
{
    $sent = $_POST['_csrf'] ?? null;
    if (!is_string($sent) || $sent === '') {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sent = is_string($header) && $header !== '' ? $header : null;
    }
    $expected = $_SESSION['_csrf'] ?? null;
    return is_string($sent) && is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
}

function csrf_rotate(): void
{
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
