<?php
declare(strict_types=1);

/**
 * Vista simplificada de la app Android (user agent "ADARA-App").
 * La vista web completa se puede forzar por sesión con el botón del globo.
 */

/**
 * Cada acción se muestra si el usuario puede ver al menos uno de sus módulos.
 * 'destinos' va en orden de preferencia: modulo => ruta.
 */
const ACCIONES_RAPIDAS = [
    ['label' => 'Recepción de leche', 'icon' => 'bi-droplet-half',   'destinos' => ['productores' => 'productores_control_semanal', 'control_leche' => 'control_leche']],
    ['label' => 'Pagos y caja',       'icon' => 'bi-cash-coin',      'destinos' => ['finanzas' => 'finanzas']],
    ['label' => 'Cobros a clientes',  'icon' => 'bi-receipt-cutoff', 'destinos' => ['facturas' => 'facturas']],
    ['label' => 'Gas',                'icon' => 'bi-fire',           'destinos' => ['gas' => 'gas']],
    ['label' => 'Transporte',         'icon' => 'bi-truck',          'destinos' => ['transportistas' => 'transportistas']],
    ['label' => 'Ventas',             'icon' => 'bi-receipt',        'destinos' => ['ventas' => 'ventas']],
    ['label' => 'Inventario',         'icon' => 'bi-box-seam-fill',  'destinos' => ['inventario' => 'inventario']],
    ['label' => 'Personal',           'icon' => 'bi-people-fill',    'destinos' => ['empleados' => 'empleados']],
];

function en_app_android(): bool
{
    return str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'ADARA-App');
}

function forzar_web(): bool
{
    return ($_SESSION['forzar_web'] ?? false) === true;
}

/** App Android, usuario de empresa y sin la vista web forzada. */
function vista_movil(): bool
{
    return en_app_android() && !forzar_web() && current_user() !== null && !sesion_es_panel_maestro();
}

/** @return list<array{label:string, icon:string, url:string}> */
function acciones_rapidas(array $user): array
{
    $out = [];
    foreach (ACCIONES_RAPIDAS as $accion) {
        foreach ($accion['destinos'] as $modulo => $ruta) {
            if (has_permiso($user, $modulo)) {
                $out[] = ['label' => $accion['label'], 'icon' => $accion['icon'], 'url' => url($ruta)];
                break;
            }
        }
    }
    return $out;
}
