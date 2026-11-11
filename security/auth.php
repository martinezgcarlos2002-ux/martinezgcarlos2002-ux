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
    if (!is_int($uid) || (Database::multiempresa() && Database::empresaActiva() === null)) {
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

/**
 * Identidad para auditoría.
 *
 * $_SESSION['uid'] es quien la pantalla cree que opera (en modo soporte, el admin de la empresa).
 * $_SESSION['impersonado_por'] es el super admin real. No se intercambian: el id del maestro
 * vive en adara_master y no puede usarse en una FK hacia usuarios de la empresa.
 */
final class Auth
{
    public static function enSoporte(): bool
    {
        return is_int($_SESSION['impersonado_por'] ?? null);
    }

    /**
     * Actor real del cambio. En modo soporte es el super admin; si no, el usuario de la sesión.
     * En soporte este número no es un usuarios.id: va a soporte_super_admin_id, no a registrado_por.
     */
    public static function getAuditUserId(): int
    {
        $soporte = $_SESSION['impersonado_por'] ?? null;
        if (is_int($soporte)) {
            return $soporte;
        }
        $id = current_user()['id'] ?? null;
        if (!is_int($id)) {
            throw new RuntimeException('No hay un usuario autenticado para auditar.');
        }
        return $id;
    }
}
