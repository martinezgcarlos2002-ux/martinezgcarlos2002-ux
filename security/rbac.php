<?php
declare(strict_types=1);

const RBAC_ACCIONES = ['ver', 'crear', 'editar', 'anular'];

/** @return array<string, array{label:string, p:string, icon:string, siempre?:bool, solo_admin?:bool}> */
function modulos(): array
{
    static $modulos = null;
    return $modulos ??= require ADARA_ROOT . '/config/modulos.php';
}

function has_permiso(?array $user, string $modulo, string $accion = 'ver'): bool
{
    if ($user === null || !isset(modulos()[$modulo]) || !in_array($accion, RBAC_ACCIONES, true)) {
        return false;
    }

    $def = modulos()[$modulo];
    if ($user['rol'] === 'admin') {
        return true;
    }
    if (!empty($def['solo_admin'])) {
        return false;
    }
    if (!empty($def['siempre']) && $accion === 'ver') {
        return true;
    }

    $acciones = $user['permisos'][$modulo] ?? [];
    return is_array($acciones) && in_array($accion, $acciones, true);
}

/**
 * Normaliza el JSON de permisos: descarta módulos y acciones desconocidos.
 * @return array<string, list<string>>
 */
function permisos_normalizar(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach ($raw as $modulo => $acciones) {
        if (!is_string($modulo) || !isset(modulos()[$modulo]) || !is_array($acciones)) {
            continue;
        }
        $validas = array_values(array_intersect(RBAC_ACCIONES, $acciones));
        if ($validas !== []) {
            $out[$modulo] = $validas;
        }
    }
    return $out;
}
