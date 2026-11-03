<?php
declare(strict_types=1);

const MSG_LICENCIA_SUSPENDIDA = 'La licencia de ADARA de tu empresa está suspendida. Contacta al soporte de ADARA para reactivarla.';

/**
 * Multiempresa: en cada petición, vuelve a apuntar PDO a la base de la empresa de la sesión.
 * La licencia y la base se leen de adara_master cada vez: suspender una empresa la saca del
 * sistema en su siguiente clic, y $_SESSION['db_activa'] nunca se usa sin contrastarla.
 */
function tenant_restaurar(): void
{
    if (!Database::multiempresa()) {
        return;
    }
    $clienteId = $_SESSION['tenant_id'] ?? null;
    if (!is_int($clienteId)) {
        return;
    }
    $saas = new Saas(Database::master());
    $cliente = $saas->clientePorId($clienteId);
    $baseOk = $cliente !== null
        && hash_equals((string) $cliente['nombre_db_fisica'], (string) ($_SESSION['db_activa'] ?? ''));
    // Modo soporte: la sesión sigue viva solo mientras el super admin que entró siga activo.
    // Una licencia suspendida no lo expulsa: precisamente entra para diagnosticar.
    $soporteId = $_SESSION['impersonado_por'] ?? null;
    if (is_int($soporteId)) {
        if (!$baseOk || $saas->superAdminActivo($soporteId) === null) {
            auth_logout();
            flash('error', 'El modo soporte terminó. Inicia sesión de nuevo.');
            return;
        }
        Database::usarEmpresa((string) $cliente['nombre_db_fisica']);
        return;
    }
    if (!$baseOk || $cliente['estado_licencia'] !== 'activa') {
        auth_logout();
        flash('error', $cliente !== null && $cliente['estado_licencia'] !== 'activa'
            ? MSG_LICENCIA_SUSPENDIDA
            : 'Tu sesión terminó. Inicia sesión de nuevo.');
        return;
    }
    Database::usarEmpresa((string) $cliente['nombre_db_fisica']);
}

/** Sesión del Panel Maestro, no el modo soporte (ese sí debe ver el ERP del cliente). */
function sesion_es_panel_maestro(): bool
{
    return Database::multiempresa()
        && ($_SESSION['rol_maestro'] ?? null) === true
        && is_int($_SESSION['super_admin_id'] ?? null)
        && !is_int($_SESSION['impersonado_por'] ?? null);
}

/** HTML del acuerdo que firma un super admin en su primer ingreso (pages/partials/acuerdo_confidencialidad.php). */
function acuerdo_confidencialidad_html(): string
{
    $admin = super_admin_actual();
    $super_admin = [
        'nombre'    => (string) ($admin['nombre'] ?? ''),
        'cedula'    => (string) ($admin['cedula'] ?? ''),
        'telefono'  => (string) ($admin['telefono'] ?? ''),
        'direccion' => (string) ($admin['direccion'] ?? ''),
    ];
    ob_start();
    require ADARA_ROOT . '/pages/partials/acuerdo_confidencialidad.php';
    return (string) ob_get_clean();
}

/** CLI (bin/*.php) en multiempresa: la empresa se elige con ADARA_EMPRESA_DB=adara_castillo. */
function tenant_cli(): void
{
    $base = getenv('ADARA_EMPRESA_DB');
    if (Database::multiempresa() && is_string($base) && $base !== '') {
        Database::usarEmpresa($base);
    }
}

/**
 * Super admin de la sesión, recargado desde adara_master en cada petición (desactivarlo surte
 * efecto de inmediato). Exige $_SESSION['rol_maestro'] === true y un id válido.
 *
 * @return array{id:int, nombre:string, correo:string, cedula:string, telefono:string, direccion:string, debe_cambiar_clave:bool}|null
 */
function super_admin_actual(): ?array
{
    static $cargado = false;
    static $admin = null;
    if ($cargado) {
        return $admin;
    }
    $cargado = true;

    if (!Database::multiempresa() || ($_SESSION['rol_maestro'] ?? null) !== true || !is_int($_SESSION['super_admin_id'] ?? null)) {
        return null;
    }
    $admin = (new Saas(Database::master()))->superAdminActivo($_SESSION['super_admin_id']);
    if ($admin === null) {
        auth_logout();
    }
    return $admin;
}

/**
 * Evento de seguridad en la bitácora que corresponde: la de la empresa si hay una elegida,
 * master_auditoria si la petición es del login multiempresa o de un super admin.
 *
 * @param array<string, mixed> $detalle
 */
function auditar_seguridad(string $accion, array $detalle): void
{
    if (Database::multiempresa() && Database::empresaActiva() === null) {
        $admin = ($_SESSION['rol_maestro'] ?? null) === true && is_int($_SESSION['super_admin_id'] ?? null)
            ? $_SESSION['super_admin_id']
            : null;
        (new Saas(Database::master()))->auditar($admin, $accion, null, $detalle);
        return;
    }
    audit_log('seguridad', $accion, $detalle);
}

/** Sesión nueva y limpia: un login nunca hereda claves de otra identidad (empresa o super admin). */
function sesion_nueva(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['_last_activity'] = time();
    csrf_rotate();
}
