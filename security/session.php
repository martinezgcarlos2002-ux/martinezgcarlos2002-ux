<?php
declare(strict_types=1);

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path(),
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['_last_activity']) && $now - $_SESSION['_last_activity'] > SESSION_IDLE_SECONDS) {
        $hadUser = isset($_SESSION['uid']);
        $_SESSION = [];
        session_regenerate_id(true);
        if ($hadUser) {
            flash('info', 'Tu sesión expiró por inactividad. Inicia sesión de nuevo.');
        }
    }
    $_SESSION['_last_activity'] = $now;
}
