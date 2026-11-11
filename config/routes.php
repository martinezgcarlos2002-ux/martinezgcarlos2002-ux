<?php
declare(strict_types=1);

/**
 * Lista blanca de rutas para index.php?p=...
 * 'publica' => no exige sesión. 'modulo' + 'accion' => permiso RBAC requerido.
 * 'super_admin' => solo el dueño del SaaS (adara_master); nunca un usuario de empresa.
 * Cualquier valor de p que no esté aquí responde 404: nunca se incluye un archivo a partir de la URL.
 */
return [
    'login' => [
        'publica' => true,
        'GET'     => [AuthController::class, 'showLogin'],
        'POST'    => [AuthController::class, 'login'],
    ],
    'logout' => [
        'POST'    => [AuthController::class, 'logout'],
    ],
    'olvide_clave' => [
        'publica' => true,
        'GET'     => [PasswordController::class, 'solicitarForm'],
        'POST'    => [PasswordController::class, 'solicitar'],
    ],
    // El token llega por GET desde el correo; el formulario lo reenvía oculto por POST.
    'nueva_clave' => [
        'publica' => true,
        'GET'     => [PasswordController::class, 'nuevaClaveForm'],
        'POST'    => [PasswordController::class, 'nuevaClave'],
    ],
    // ----- Panel Maestro SaaS (multiempresa) ------------------------------
    'master' => [
        'super_admin' => true,
        'GET'         => [MasterController::class, 'index'],
    ],
    'master_registrar' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'registrar'],
    ],
    'master_licencia' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'licencia'],
    ],
    'master_respaldo' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'respaldoEmpresa'],
    ],
    'master_salir' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'salir'],
    ],
    'master_contrato' => [
        'super_admin' => true,
        'GET'         => [MasterController::class, 'contrato'],
    ],
    'master_arquitectura' => [
        'super_admin' => true,
        'GET'         => [MasterController::class, 'arquitectura'],
    ],
    'master_arquitectura_pdf' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'arquitecturaPdf'],
    ],
    'master_usuario_pdf' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'usuarioPdf'],
    ],
    'master_clave' => [
        'super_admin' => true,
        'GET'         => [MasterController::class, 'clave'],
        'POST'        => [MasterController::class, 'cambiarClave'],
    ],
    'master_accesos' => [
        'super_admin' => true,
        'GET'         => [MasterController::class, 'accesos'],
    ],
    'master_acceso_crear' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'crearAcceso'],
    ],
    'master_acceso_estado' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'estadoAcceso'],
    ],
    'master_acceso_revocar' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'revocarAcceso'],
    ],
    'master_acceso_reactivar' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'reactivarAcceso'],
    ],
    // Modo soporte: POST (no un enlace) para que no se dispare solo y exija el token CSRF.
    'master_soporte' => [
        'super_admin' => true,
        'POST'        => [MasterController::class, 'soporte'],
    ],
    // Sin 'super_admin': durante el soporte la sesión es la del admin de la empresa.
    // El controlador comprueba $_SESSION['impersonado_por'].
    'master_salir_soporte' => [
        'POST' => [MasterController::class, 'salirSoporte'],
    ],
    'dashboard' => [
        'modulo'  => 'dashboard',
        'GET'     => [DashboardController::class, 'index'],
    ],
    // App Android: alterna entre la vista simplificada y el ERP completo (solo cambia la sesión).
    'vista_web' => [
        'modulo'  => 'dashboard',
        'POST'    => [DashboardController::class, 'forzarWeb'],
    ],
    'vista_movil' => [
        'modulo'  => 'dashboard',
        'POST'    => [DashboardController::class, 'volverMovil'],
    ],

    // ----- Productores ------------------------------------------------------
    'productores' => [
        'modulo'  => 'productores',
        'GET'     => [ProductoresController::class, 'index'],
    ],
    'productor_control' => [
        'modulo'  => 'productores',
        'GET'     => [ProductoresController::class, 'control'],
    ],
    'productores_control_semanal' => [
        'modulo'  => 'productores',
        'GET'     => [ProductoresController::class, 'controlSemanal'],
    ],
    'productores_semana_guardar' => [
        'modulo'  => 'productores',
        'accion'  => 'crear',
        'POST'    => [ProductoresController::class, 'semanaStore'],
    ],
    'productores_facturas_masivas' => [
        'modulo'  => 'productores',
        'GET'     => [ProductoresController::class, 'facturasMasivas'],
    ],
    'productores_facturas_zip' => [
        'modulo'  => 'productores',
        'POST'    => [ProductoresController::class, 'facturasZip'],
    ],
    'productores_nuevo' => [
        'modulo'  => 'productores',
        'accion'  => 'crear',
        'GET'     => [ProductoresController::class, 'create'],
        'POST'    => [ProductoresController::class, 'store'],
    ],
    'productores_editar' => [
        'modulo'  => 'productores',
        'accion'  => 'editar',
        'GET'     => [ProductoresController::class, 'edit'],
        'POST'    => [ProductoresController::class, 'update'],
    ],
    'productores_archivar' => [
        'modulo'  => 'productores',
        'accion'  => 'anular',
        'POST'    => [ProductoresController::class, 'archivar'],
    ],
    'productores_cerrar_semanas' => [
        'modulo'  => 'productores',
        'accion'  => 'crear',
        'POST'    => [ProductoresController::class, 'cerrarSemanas'],
    ],
    'productores_pago' => [
        'modulo'  => 'productores',
        'accion'  => 'crear',
        'POST'    => [ProductoresController::class, 'pagoStore'],
    ],
    // Además del permiso RBAC, el controlador exige rol admin en estas dos rutas.
    'productores_cierre_editar' => [
        'modulo'  => 'productores',
        'accion'  => 'editar',
        'POST'    => [ProductoresController::class, 'cierreUpdate'],
    ],
    'productores_pago_eliminar' => [
        'modulo'  => 'productores',
        'accion'  => 'anular',
        'POST'    => [ProductoresController::class, 'pagoDestroy'],
    ],
    'productores_reabrir_semana' => [
        'modulo'  => 'productores',
        'accion'  => 'editar',
        'POST'    => [ProductoresController::class, 'reabrirSemana'],
    ],
    // Temporal: exige rol admin en el controlador. Se retira cuando Finanzas gestione las tasas.
    'productores_tasa_rapida' => [
        'modulo'  => 'productores',
        'accion'  => 'editar',
        'POST'    => [ProductoresController::class, 'fijarTasaBcvRapida'],
    ],
    'productores_liquidacion' => [
        'modulo'  => 'productores',
        'GET'     => [ProductoresController::class, 'liquidacionPdf'],
        'POST'    => [ProductoresController::class, 'liquidacionPdf'],
    ],
    'productores_impresion_masiva' => [
        'modulo'  => 'productores',
        'GET'     => [ProductoresController::class, 'liquidacionPdf'],
        'POST'    => [ProductoresController::class, 'liquidacionPdf'],
    ],
    'productores_liquidacion_html' => [
        'modulo'  => 'productores',
        'POST'    => [ProductoresController::class, 'liquidacionHtml'],
    ],
    'productores_notaria' => [
        'modulo'  => 'productores',
        'GET'     => [ProductoresController::class, 'notariaPdf'],
    ],
    'produccion' => [
        'modulo'  => 'produccion',
        'GET'     => [ProduccionController::class, 'index'],
    ],
    'produccion_resumen' => [
        'modulo'  => 'produccion',
        'GET'     => [ProduccionController::class, 'resumen'],
    ],
    'resumen' => [
        'modulo'  => 'resumen_diario',
        'GET'     => [ResumenController::class, 'index'],
    ],
    'resumen_diario' => [
        'modulo'  => 'resumen_diario',
        'GET'     => [ResumenController::class, 'index'],
    ],
    'resumen_datos_rango' => [
        'modulo'  => 'resumen_diario',
        'GET'     => [ResumenController::class, 'datos'],
    ],
    'resumen_pdf_rango' => [
        'modulo'  => 'resumen_diario',
        'GET'     => [ResumenController::class, 'pdf'],
    ],
    'produccion_catalogo' => [
        'modulo'  => 'produccion',
        'GET'     => [ProduccionController::class, 'catalogo'],
    ],
    'produccion_catalogo_guardar' => [
        'modulo'  => 'produccion',
        'accion'  => 'crear',
        'POST'    => [ProduccionController::class, 'catalogoGuardar'],
    ],
    'produccion_catalogo_eliminar' => [
        'modulo'  => 'produccion',
        'accion'  => 'editar',
        'POST'    => [ProduccionController::class, 'catalogoEliminar'],
    ],
    'produccion_diaria_pdf' => [
        'modulo'  => 'produccion',
        'GET'     => [ProduccionController::class, 'diariaPdf'],
    ],
    'produccion_registro' => [
        'modulo'  => 'produccion',
        'accion'  => 'crear',
        'POST'    => [ProduccionController::class, 'store'],
    ],
    'productores_entrega' => [
        'modulo'  => 'productores',
        'accion'  => 'crear',
        'GET'     => [ProductoresController::class, 'entregaForm'],
        'POST'    => [ProductoresController::class, 'entregaStore'],
    ],
    // ----- Control de Leche -------------------------------------------------
    'control_leche' => [
        'modulo'  => 'control_leche',
        'GET'     => [ControlLecheController::class, 'index'],
    ],
    'control_leche_planilla' => [
        'modulo'  => 'control_leche',
        'GET'     => [ControlLecheController::class, 'generarPlanillaPdf'],
    ],
    'control_leche_excel' => [
        'modulo'  => 'control_leche',
        'GET'     => [ControlLecheController::class, 'planillaExcel'],
    ],
    'control_leche_entrega' => [
        'modulo'  => 'control_leche',
        'accion'  => 'crear',
        'POST'    => [ProductoresController::class, 'entregaStore'],
    ],
    'control_leche_costos_calcular' => [
        'modulo'  => 'control_leche',
        'POST'    => [ControlLecheController::class, 'costosCalcular'],
    ],
    'control_leche_costos_guardar' => [
        'modulo'  => 'control_leche',
        'accion'  => 'crear',
        'POST'    => [ControlLecheController::class, 'costosGuardar'],
    ],
    'control_leche_diario' => [
        'modulo'  => 'control_leche',
        'accion'  => 'crear',
        'POST'    => [ControlLecheController::class, 'diarioGuardar'],
    ],

    'inventario' => [
        'modulo'  => 'inventario',
        'GET'     => [InventarioController::class, 'index'],
        'POST'    => [InventarioController::class, 'store'],
    ],
    'inventario_eliminar' => [
        'modulo'  => 'inventario',
        'accion'  => 'anular',
        'POST'    => [InventarioController::class, 'destroy'],
    ],
    'inventario_ajuste' => [
        'modulo'  => 'inventario',
        'accion'  => 'editar',
        'POST'    => [InventarioController::class, 'ajustarStock'],
    ],
    'almacenes_nuevo' => [
        'modulo'  => 'inventario',
        'accion'  => 'crear',
        'POST'    => [InventarioController::class, 'almacenStore'],
    ],
    'almacenes_guardar' => [
        'modulo'  => 'inventario',
        'accion'  => 'editar',
        'POST'    => [InventarioController::class, 'almacenRename'],
    ],
    'almacenes_eliminar' => [
        'modulo'  => 'inventario',
        'accion'  => 'anular',
        'POST'    => [InventarioController::class, 'almacenDestroy'],
    ],
    'produccion_guardar' => [
        'modulo'  => 'produccion',
        'accion'  => 'crear',
        'POST'    => [ProduccionController::class, 'guardarDia'],
    ],
    'produccion_distribuir' => [
        'modulo'  => 'produccion',
        'accion'  => 'crear',
        'POST'    => [ProduccionController::class, 'distribuir'],
    ],
    // ----- Usuarios (solo_admin) --------------------------------------------
    'usuarios' => [
        'modulo'  => 'usuarios',
        'GET'     => [UsuariosController::class, 'index'],
    ],
    'usuarios_nuevo' => [
        'modulo'  => 'usuarios',
        'accion'  => 'crear',
        'POST'    => [UsuariosController::class, 'store'],
    ],
    'usuarios_editar' => [
        'modulo'  => 'usuarios',
        'accion'  => 'editar',
        'POST'    => [UsuariosController::class, 'update'],
    ],
    'usuarios_permisos' => [
        'modulo'  => 'usuarios',
        'accion'  => 'editar',
        'POST'    => [UsuariosController::class, 'permisos'],
    ],
    'usuarios_movil' => [
        'modulo'  => 'usuarios',
        'accion'  => 'editar',
        'POST'    => [UsuariosController::class, 'movil'],
    ],
    'usuarios_eliminar' => [
        'modulo'  => 'usuarios',
        'accion'  => 'anular',
        'POST'    => [UsuariosController::class, 'destroy'],
    ],

    // ----- Auditoría y respaldo (solo_admin) --------------------------------
    'auditoria' => [
        'modulo'  => 'auditoria',
        'GET'     => [AuditoriaController::class, 'index'],
    ],
    'respaldo' => [
        'modulo'  => 'respaldo',
        'GET'     => [RespaldoController::class, 'index'],
    ],
    'respaldo_descargar' => [
        'modulo'  => 'respaldo',
        'accion'  => 'crear',
        'POST'    => [RespaldoController::class, 'descargarSql'],
    ],
    'respaldo_mapa_codigo' => [
        'modulo'  => 'respaldo',
        'POST'    => [RespaldoController::class, 'descargarMapaCodigo'],
    ],
    'configuracion' => [
        'modulo'  => 'configuracion',
        'GET'     => [ConfiguracionController::class, 'index'],
    ],
    'configuracion_guardar' => [
        'modulo'  => 'configuracion',
        'accion'  => 'editar',
        'POST'    => [ConfiguracionController::class, 'guardar'],
    ],

    // ----- Finanzas / Caja -------------------------------------------------
    'finanzas' => [
        'modulo'  => 'finanzas',
        'GET'     => [FinanzasController::class, 'index'],
    ],
    'finanzas_movimiento' => [
        'modulo'  => 'finanzas',
        'accion'  => 'crear',
        'POST'    => [FinanzasController::class, 'storeMovimiento'],
    ],
    'finanzas_tasa' => [
        'modulo'  => 'finanzas',
        'accion'  => 'editar',
        'POST'    => [FinanzasController::class, 'updateTasa'],
    ],

    'ventas' => [
        'modulo'  => 'ventas',
        'accion'  => 'crear',
        'GET'     => [VentasController::class, 'create'],
    ],
    // ----- Control de Facturas de Clientes (maqueta) --------------------
    'facturas' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'index'],
    ],
    'facturas_numero' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'numero'],
    ],
    'facturas_buscar' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'buscar'],
    ],
    'facturas_cliente' => [
        'modulo'  => 'facturas',
        'accion'  => 'crear',
        'POST'    => [FacturaController::class, 'storeCliente'],
        'idempotente' => true,
    ],
    'facturas_cliente_eliminar' => [
        'modulo'  => 'facturas',
        'accion'  => 'anular',
        'POST'    => [FacturaController::class, 'destroyCliente'],
        'idempotente' => true,
    ],
    'facturas_guardar' => [
        'modulo'  => 'facturas',
        'accion'  => 'crear',
        'POST'    => [FacturaController::class, 'storeFactura'],
        'idempotente' => true,
    ],
    'facturas_pago' => [
        'modulo'  => 'facturas',
        'accion'  => 'crear',
        'POST'    => [FacturaController::class, 'storePago'],
        'idempotente' => true,
    ],
    'sync_offline' => [
        'POST' => [SyncController::class, 'recibir'],
    ],
    'facturas_anticipo' => [
        'modulo'  => 'facturas',
        'accion'  => 'crear',
        'POST'    => [FacturaController::class, 'storeAnticipo'],
        'idempotente' => true,
    ],
    'facturas_abono' => [
        'modulo'  => 'facturas',
        'accion'  => 'crear',
        'POST'    => [FacturaController::class, 'storeAbono'],
        'idempotente' => true,
    ],
    'facturas_devolucion' => [
        'modulo'  => 'facturas',
        'accion'  => 'crear',
        'POST'    => [FacturaController::class, 'storeDevolucion'],
        'idempotente' => true,
    ],
    'facturas_excel' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'excel'],
    ],
    'facturas_pdf' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'pdf'],
    ],
    'facturas_detalle' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'detalle'],
    ],
    'facturas_alertas_campana' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'alertasCampana'],
    ],
    'facturas_detalle_devolucion' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'detalleDevolucion'],
    ],
    'facturas_imprimir' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'imprimirFactura'],
    ],
    'facturas_estado_cuenta' => [
        'modulo'  => 'facturas',
        'GET'     => [FacturaController::class, 'estadoCuentaPdf'],
    ],
    'facturas_anular_pago' => [
        'modulo'  => 'facturas',
        'accion'  => 'anular',
        'POST'    => [FacturaController::class, 'anularPago'],
        'idempotente' => true,
    ],
    'facturas_anular_devolucion' => [
        'modulo'  => 'facturas',
        'accion'  => 'anular',
        'POST'    => [FacturaController::class, 'anularDevolucion'],
        'idempotente' => true,
    ],
    'facturas_anular_factura' => [
        'modulo'  => 'facturas',
        'accion'  => 'anular',
        'POST'    => [FacturaController::class, 'anularFactura'],
        'idempotente' => true,
    ],

    'ventas_nueva' => [
        'modulo'  => 'ventas',
        'accion'  => 'crear',
        'GET'     => [VentasController::class, 'create'],
        'POST'    => [VentasController::class, 'store'],
    ],
    'ventas_historial' => [
        'modulo'  => 'ventas',
        'GET'     => [VentasController::class, 'historial'],
    ],
    'ventas_buscar_entidades' => [
        'modulo'  => 'ventas',
        'GET'     => [VentasController::class, 'buscarEntidades'],
    ],
    'ventas_cliente_nuevo' => [
        'modulo'  => 'ventas',
        'accion'  => 'crear',
        'POST'    => [VentasController::class, 'clienteStore'],
    ],
    'ventas_ascender_exclusivo' => [
        'modulo'  => 'ventas',
        'accion'  => 'editar',
        'POST'    => [VentasController::class, 'ascenderExclusivo'],
    ],
    'ventas_ticket' => [
        'modulo'  => 'ventas',
        'GET'     => [VentasController::class, 'ticket'],
    ],

    // ----- Recursos Humanos (nómina, directorio, días libres, cobranzas) ---
    'empleados' => [
        'modulo'  => 'empleados',
        'GET'     => [EmpleadosController::class, 'index'],
    ],
    'empleados_nuevo' => [
        'modulo'  => 'empleados',
        'accion'  => 'crear',
        'POST'    => [EmpleadosController::class, 'store'],
    ],
    'empleados_editar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [EmpleadosController::class, 'update'],
    ],
    'empleados_baja' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [EmpleadosController::class, 'darDeBaja'],
    ],
    'empleados_reingreso' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [EmpleadosController::class, 'reingresar'],
    ],
    'nomina_guardar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [NominaController::class, 'guardar'],
    ],
    // Además del permiso RBAC, el controlador exige rol admin.
    'nomina_cerrar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [NominaController::class, 'cerrar'],
    ],
    // Además del permiso RBAC, el controlador exige rol admin y motivo (queda en auditoria).
    'nomina_pagar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [NominaController::class, 'pagar'],
    ],
    'nomina_reabrir' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [NominaController::class, 'reabrirNomina'],
    ],
    'prestamos_nuevo' => [
        'modulo'  => 'empleados',
        'accion'  => 'crear',
        'POST'    => [NominaController::class, 'prestamoStore'],
    ],
    'prestamos_editar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [NominaController::class, 'prestamoUpdate'],
    ],
    'prestamos_eliminar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [NominaController::class, 'prestamoDestroy'],
    ],
    'nomina_excel' => [
        'modulo'  => 'empleados',
        'GET'     => [NominaController::class, 'excel'],
    ],
    'nomina_pdf' => [
        'modulo'  => 'empleados',
        'GET'     => [NominaController::class, 'pdf'],
    ],
    'empleados_notaria' => [
        'modulo'  => 'empleados',
        'GET'     => [NominaController::class, 'notariaPdf'],
    ],
    'rrhh_notaria' => [
        'modulo'  => 'empleados',
        'GET'     => [NominaController::class, 'notariaPdf'],
    ],
    'hr_planilla_asistencia' => [
        'modulo'  => 'empleados',
        'GET'     => [EmpleadosController::class, 'planillaAsistencia'],
    ],
    'cargos_nuevo' => [
        'modulo'  => 'empleados',
        'accion'  => 'crear',
        'POST'    => [EmpleadosController::class, 'cargoStore'],
    ],
    'dias_libres_limpiar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [DiasLibresController::class, 'limpiarMes'],
    ],
    'dias_libres_aleatorio' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [DiasLibresController::class, 'generarAleatorio'],
    ],
    'dias_libres' => [
        'modulo'  => 'empleados',
        'GET'     => [EmpleadosController::class, 'diasLibres'],
    ],
    'dias_libres_verificar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [DiasLibresController::class, 'verificar'],
    ],
    'dias_libres_guardar' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [DiasLibresController::class, 'guardar'],
    ],
    'cobranzas_nueva' => [
        'modulo'  => 'empleados',
        'accion'  => 'crear',
        'POST'    => [CobranzasController::class, 'store'],
    ],
    // 'anulado' exige además la acción anular; el controlador lo verifica.
    'cobranzas_estado' => [
        'modulo'  => 'empleados',
        'accion'  => 'editar',
        'POST'    => [CobranzasController::class, 'estado'],
    ],
    'rrhh_cobranzas_pdf' => [
        'modulo'  => 'empleados',
        'GET'     => [CobranzasController::class, 'pdf'],
    ],
    'rrhh_cv_pdf' => [
        'modulo'  => 'empleados',
        'GET'     => [EmpleadosController::class, 'cvPdf'],
    ],

    'catalogo' => [
        'modulo'  => 'catalogo',
        'GET'     => [CatalogoController::class, 'index'],
    ],
    'catalogo_precio' => [
        'modulo'  => 'catalogo',
        'accion'  => 'editar',
        'POST'    => [CatalogoController::class, 'actualizarPrecio'],
    ],
    'catalogo_toggle' => [
        'modulo'  => 'catalogo',
        'accion'  => 'editar',
        'POST'    => [CatalogoController::class, 'togglePantalla'],
    ],
    'catalogo_tasa' => [
        'modulo'  => 'catalogo',
        'accion'  => 'editar',
        'POST'    => [CatalogoController::class, 'actualizarTasa'],
    ],
    'catalogo_foto' => [
        'modulo'  => 'catalogo',
        'accion'  => 'editar',
        'POST'    => [CatalogoController::class, 'subirFoto'],
    ],
    'catalogo_lista_precios_pdf' => [
        'modulo'  => 'catalogo',
        'GET'     => [CatalogoController::class, 'listaPreciosPdf'],
    ],
    'catalogo_pantalla' => [
        'publica' => true,
        'GET'     => [CatalogoController::class, 'pantalla'],
    ],
    'catalogo_pantalla_datos' => [
        'publica' => true,
        'GET'     => [CatalogoController::class, 'pantallaDatos'],
    ],

    'transportistas' => [
        'modulo'  => 'transportistas',
        'GET'     => [TransporteController::class, 'index'],
    ],
    'transportistas_guardar' => [
        'modulo'  => 'transportistas',
        'accion'  => 'crear',
        'POST'    => [TransporteController::class, 'storeTransportista'],
    ],
    'transportistas_ruta' => [
        'modulo'  => 'transportistas',
        'accion'  => 'crear',
        'POST'    => [TransporteController::class, 'guardarRuta'],
    ],
    'transportistas_flete' => [
        'modulo'  => 'transportistas',
        'accion'  => 'crear',
        'POST'    => [TransporteController::class, 'registrarFlete'],
    ],
    'transportistas_cierre' => [
        'modulo'  => 'transportistas',
        'accion'  => 'crear',
        'POST'    => [TransporteController::class, 'cerrarSemana'],
    ],
    'transporte_factura_pdf' => [
        'modulo'  => 'transportistas',
        'GET'     => [TransporteController::class, 'facturaPdf'],
    ],
    'transportistas_pago' => [
        'modulo'  => 'transportistas',
        'accion'  => 'crear',
        'POST'    => [TransporteController::class, 'registrarPago'],
    ],
    'transportistas_cierre_detalle' => [
        'modulo'  => 'transportistas',
        'GET'     => [TransporteController::class, 'detalleCierre'],
        'POST'    => [TransporteController::class, 'detalleCierre'],
    ],
    'transporte_analisis_ruta' => [
        'modulo'  => 'transportistas',
        'POST'    => [TransporteController::class, 'analizarRuta'],
    ],
    'transportistas_reabrir_semana' => [
        'modulo'  => 'transportistas',
        'accion'  => 'editar',
        'GET'     => [TransporteController::class, 'reabrirSemana'],
        'POST'    => [TransporteController::class, 'reabrirSemana'],
    ],
    'transportistas_estado' => [
        'modulo'  => 'transportistas',
        'accion'  => 'editar',
        'POST'    => [TransporteController::class, 'cambiarEstado'],
    ],
    'transporte_directorio_pdf' => [
        'modulo'  => 'transportistas',
        'GET'     => [TransporteController::class, 'directorioPdf'],
    ],
    'transporte_cxp_pdf' => [
        'modulo'  => 'transportistas',
        'GET'     => [TransporteController::class, 'cxpPdf'],
    ],
    'transporte_notaria' => [
        'modulo'  => 'transportistas',
        'GET'     => [TransporteController::class, 'notariaPdf'],
    ],

    // ----- Gas --------------------------------------------------------------
    'gas' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'index'],
    ],
    'gas_resumen' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'resumen'],
    ],
    'gas_catalogo' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'catalogo'],
    ],
    'gas_proveedores' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'proveedores'],
    ],
    'gas_proveedor_crear' => [
        'modulo'  => 'gas',
        'accion'  => 'crear',
        'POST'    => [GasController::class, 'crearProveedor'],
    ],
    'gas_proveedor_editar' => [
        'modulo'  => 'gas',
        'accion'  => 'editar',
        'POST'    => [GasController::class, 'editarProveedor'],
    ],
    'gas_cilindros' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'cilindros'],
    ],
    'gas_cilindros_disponibles' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'cilindrosDisponibles'],
    ],
    'gas_cilindro_crear' => [
        'modulo'  => 'gas',
        'accion'  => 'crear',
        'POST'    => [GasController::class, 'crearCilindro'],
    ],
    'gas_cilindro_editar' => [
        'modulo'  => 'gas',
        'accion'  => 'editar',
        'POST'    => [GasController::class, 'editarCilindro'],
    ],
    'gas_cilindro_eliminar' => [
        'modulo'  => 'gas',
        'accion'  => 'anular',
        'POST'    => [GasController::class, 'eliminarCilindro'],
    ],
    'gas_despachos' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'despachos'],
    ],
    'gas_despacho' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'despacho'],
    ],
    'gas_despacho_crear' => [
        'modulo'  => 'gas',
        'accion'  => 'crear',
        'POST'    => [GasController::class, 'crearDespacho'],
    ],
    'gas_despacho_editar' => [
        'modulo'  => 'gas',
        'accion'  => 'editar',
        'POST'    => [GasController::class, 'editarDespacho'],
    ],
    'gas_despacho_cilindros' => [
        'modulo'  => 'gas',
        'accion'  => 'editar',
        'POST'    => [GasController::class, 'agregarCilindros'],
    ],
    'gas_despacho_quitar' => [
        'modulo'  => 'gas',
        'accion'  => 'editar',
        'POST'    => [GasController::class, 'quitarCilindro'],
    ],
    'gas_despacho_eliminar' => [
        'modulo'  => 'gas',
        'accion'  => 'editar',
        'POST'    => [GasController::class, 'eliminarDespacho'],
    ],
    'gas_pago' => [
        'modulo'  => 'gas',
        'accion'  => 'crear',
        'POST'    => [GasController::class, 'registrarPago'],
    ],
    'gas_pago_anular' => [
        'modulo'  => 'gas',
        'accion'  => 'anular',
        'POST'    => [GasController::class, 'anularPago'],
    ],
    'gas_reparaciones' => [
        'modulo'  => 'gas',
        'GET'     => [GasController::class, 'reparaciones'],
    ],
    'gas_reparacion_enviar' => [
        'modulo'  => 'gas',
        'accion'  => 'crear',
        'POST'    => [GasController::class, 'enviarReparacion'],
    ],
    'gas_reparacion_cerrar' => [
        'modulo'  => 'gas',
        'accion'  => 'editar',
        'POST'    => [GasController::class, 'cerrarReparacion'],
    ],

    // ----- Conciliación bancaria -------------------------------------------
    'bancos' => [
        'modulo'  => 'bancos',
        'GET'     => [BancosController::class, 'index'],
    ],
    'bancos_plantilla' => [
        'modulo'  => 'bancos',
        'GET'     => [BancosController::class, 'descargarPlantilla'],
    ],
    'bancos_subir' => [
        'modulo'  => 'bancos',
        'accion'  => 'crear',
        'POST'    => [BancosController::class, 'subir'],
    ],
    'bancos_auditoria' => [
        'modulo'  => 'bancos',
        'GET'     => [BancosController::class, 'auditoria'],
    ],
    'bancos_cuenta_crear' => [
        'modulo'  => 'bancos',
        'accion'  => 'crear',
        'POST'    => [BancosController::class, 'crearCuenta'],
    ],
    'bancos_cuenta_editar' => [
        'modulo'  => 'bancos',
        'accion'  => 'editar',
        'POST'    => [BancosController::class, 'editarCuenta'],
    ],
    'bancos_cuenta_desactivar' => [
        'modulo'  => 'bancos',
        'accion'  => 'anular',
        'POST'    => [BancosController::class, 'desactivarCuenta'],
    ],
    'bancos_cuenta_activar' => [
        'modulo'  => 'bancos',
        'accion'  => 'editar',
        'POST'    => [BancosController::class, 'activarCuenta'],
    ],
];
