<?php
declare(strict_types=1);

/**
 * La sesión solo guarda el id. El usuario se recarga en cada petición, así que
 * desactivarlo o cambiarle permisos surte efecto de inmediato y el hash nunca sale de la BD.
 *
 * @return array{id:int, nombre:string, email:string, rol:string, permisos:array}|null
 */
function current_user(): ?array
{
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid)) {
        return null;
    }

    $user = (new Usuario(get_db()))->findActiveById($uid);
    if ($user === null) {
        auth_logout();
    }
    return $user;
}

function auth_login(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    $_SESSION['auth_at'] = time();
    csrf_rotate();
}

function auth_logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    csrf_rotate();
}
