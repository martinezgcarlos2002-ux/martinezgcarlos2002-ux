<?php
declare(strict_types=1);

/**
 * Catálogo único de módulos. La clave es la que se guarda en usuarios.permisos (JSON)
 * con la forma {"productores": ["ver", "crear", "editar", "anular"], ...}.
 * El orden de este arreglo es el orden del menú lateral; 'icon' es una clase de Bootstrap Icons.
 */
return [
    'dashboard'      => ['label' => 'Dashboard',           'p' => 'dashboard',      'icon' => 'bi-grid-1x2-fill',       'siempre' => true],
    'inventario'     => ['label' => 'Inventario',          'p' => 'inventario',     'icon' => 'bi-box-seam-fill'],
    'produccion'     => ['label' => 'Producción Diaria',   'p' => 'produccion',     'icon' => 'bi-gear-fill'],
    'catalogo'       => ['label' => 'Catálogo',            'p' => 'catalogo',       'icon' => 'bi-display'],
    'ventas'         => ['label' => 'Ventas',              'p' => 'ventas',         'icon' => 'bi-receipt'],
    'empleados'      => ['label' => 'Recursos Humanos',    'p' => 'empleados',      'icon' => 'bi-people-fill'],
    'usuarios'       => ['label' => 'Usuarios',            'p' => 'usuarios',       'icon' => 'bi-person-vcard-fill',   'solo_admin' => true],
    'transportistas' => ['label' => 'Transportistas',      'p' => 'transportistas', 'icon' => 'bi-truck'],
    'productores'    => ['label' => 'Productores',         'p' => 'productores',    'icon' => 'bi-droplet-fill'],
    'control_leche'  => ['label' => 'Control Leche',       'p' => 'control_leche',  'icon' => 'bi-clipboard2-data-fill'],
    'resumen_diario' => ['label' => 'Resumen Gerencial',  'p' => 'resumen',        'icon' => 'bi-calendar3'],
    'facturas'       => ['label' => 'Control de Facturas', 'p' => 'facturas',       'icon' => 'bi-receipt-cutoff'],
    'finanzas'       => ['label' => 'Finanzas',            'p' => 'finanzas',       'icon' => 'bi-cash-coin'],
    'bancos'         => ['label' => 'Bancos',              'p' => 'bancos',         'icon' => 'bi-bank2'],
    'auditoria'      => ['label' => 'Auditoría',           'p' => 'auditoria',      'icon' => 'bi-search',              'solo_admin' => true],
    'respaldo'       => ['label' => 'Respaldo Diario',     'p' => 'respaldo',       'icon' => 'bi-database-down',       'solo_admin' => true],
];
