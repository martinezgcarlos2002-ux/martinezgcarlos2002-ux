<?php
declare(strict_types=1);

/**
 * Control de Facturas de Clientes.
 * La primera carga llega en #cfDatos; KPIs, tablas y ficha se pintan desde ese estado en JS,
 * y cada respuesta de los endpoints lo actualiza sin recargar la página.
 *
 * @var string $hoy
 * @var array<string, mixed> $metricas   KPIs calculados en SQL (CxCService::obtenerMetricasDashboard)
 * @var bool $puedeCrear
 * @var bool $puedeAnular
 * @var list<array<string, mixed>> $clientes
 * @var list<array<string, mixed>> $facturas
 * @var list<array<string, mixed>> $pagos
 * @var list<array<string, mixed>> $devoluciones
 * @var list<string> $metodos
 * @var float|null $tasa
 */
$endpoints = [
    'inicio'           => url('facturas'),
    'numero'           => url('facturas_numero'),
    'buscar'           => url('facturas_buscar'),
    'cliente'          => url('facturas_cliente'),
    'cliente_eliminar' => url('facturas_cliente_eliminar'),
    'factura'          => url('facturas_guardar'),
    'pago'             => url('facturas_pago'),
    'abono'            => url('facturas_abono'),
    'devolucion'       => url('facturas_devolucion'),
    'detalle'            => url('facturas_detalle'),
    'detalle_devolucion' => url('facturas_detalle_devolucion'),
    'imprimir'           => url('facturas_imprimir'),
    'estado_cuenta'      => url('facturas_estado_cuenta'),
    'alertas_campana'    => url('facturas_alertas_campana'),
    'anular_pago'      => url('facturas_anular_pago'),
    'anular_devolucion'=> url('facturas_anular_devolucion'),
    'anular_factura'   => url('facturas_anular_factura'),
];
$opcionesMetodo = static function () use ($metodos): string {
    $html = '<option value="">—</option>';
    foreach ($metodos as $m) {
        $html .= '<option value="' . e($m) . '">' . e($m) . '</option>';
    }
    return $html;
};
$vencePorDefecto = (new DateTimeImmutable($hoy))->modify('+7 days')->format('Y-m-d');

layout_start('Control de Facturas', 'facturas');
?>
<div id="cfApp" data-hoy="<?= e($hoy) ?>" data-csrf="<?= e(csrf_token()) ?>"
     data-crear="<?= $puedeCrear ? '1' : '0' ?>" data-anular="<?= $puedeAnular ? '1' : '0' ?>"
     data-tasa="<?= e($tasa !== null ? (string) $tasa : '') ?>"
     data-endpoints="<?= e(json_encode($endpoints, JSON_THROW_ON_ERROR)) ?>">

  <!-- Encabezado y acciones -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h5 mb-0"><i class="bi bi-receipt-cutoff"></i> Control de Facturas de Clientes</h1>
      <div class="small text-muted">Módulo independiente</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-outline-success rounded-pill" href="<?= e(url('facturas_excel')) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
      <a class="btn btn-sm btn-outline-danger rounded-pill" href="<?= e(url('facturas_pdf')) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Reporte general PDF</a>
      <?php if ($puedeCrear): ?>
      <button type="button" class="btn btn-sm btn-outline-warning rounded-pill" data-bs-toggle="modal" data-bs-target="#modalCliente"><i class="bi bi-plus-lg"></i> Cliente</button>
      <button type="button" class="btn btn-sm btn-outline-info rounded-pill" data-bs-toggle="modal" data-bs-target="#modalPagoRapido"><i class="bi bi-lightning-charge-fill"></i> Pago rápido</button>
      <button type="button" class="btn btn-sm btn-info text-white rounded-pill" data-bs-toggle="modal" data-bs-target="#modalAbono"><i class="bi bi-cash-stack"></i> Abono a varias</button>
      <button type="button" class="btn btn-sm btn-warning rounded-pill" data-bs-toggle="modal" data-bs-target="#modalFactura"><i class="bi bi-plus-lg"></i> Nueva Factura</button>
      <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-bs-toggle="modal" data-bs-target="#modalDevolucion"><i class="bi bi-arrow-return-left"></i> Nueva devolución</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-info cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl"><i class="bi bi-people"></i> Clientes registrados</div>
      <div class="cf-kpi-num text-info" data-kpi="clientes">0</div><div class="small text-muted">clientes del módulo</div>
    </div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-warning cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl"><i class="bi bi-file-earmark-text"></i> Facturas por cobrar</div>
      <div class="cf-kpi-num text-warning" data-kpi="por_cobrar">0</div><div class="small text-muted">con saldo pendiente</div>
    </div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-success cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl"><i class="bi bi-check2-circle"></i> Facturas pagadas</div>
      <div class="cf-kpi-num text-success" data-kpi="pagadas">0</div><div class="small text-muted">sin saldo pendiente</div>
    </div></div></div>
    <div class="col-md-3"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-primary cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl"><i class="bi bi-arrow-return-left"></i> Total devoluciones</div>
      <div class="cf-kpi-num text-primary" data-kpi="devoluciones">$0,00</div><div class="small text-muted">monto devuelto (vigente)</div>
    </div></div></div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-4 col-xxl-2"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-primary cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Total facturado histórico</div><div class="cf-kpi-num text-primary" data-kpi="facturado">$0,00</div><div class="small text-muted">todas las facturas</div>
    </div></div></div>
    <div class="col-6 col-lg-4 col-xxl-2"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-dark cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Total a cobrar</div><div class="cf-kpi-num" data-kpi="a_cobrar">$0,00</div><div class="small text-muted">saldo pendiente</div>
    </div></div></div>
    <div class="col-6 col-lg-4 col-xxl-2"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-success cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Total cobrado</div><div class="cf-kpi-num text-success" data-kpi="cobrado">$0,00</div><div class="small text-muted">pagos registrados</div>
    </div></div></div>
    <div class="col-6 col-lg-4 col-xxl-2"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-danger cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl"><i class="bi bi-exclamation-triangle"></i> Vencido (crítico)</div><div class="cf-kpi-num text-danger" data-kpi="vencido">$0,00</div><div class="small text-muted" data-kpi="vencidas_n">0 factura(s) vencida(s)</div>
    </div></div></div>
    <div class="col-6 col-lg-4 col-xxl-2"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-warning cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl"><i class="bi bi-hourglass-split"></i> Por vencer (≤3 días)</div><div class="cf-kpi-num text-warning" data-kpi="por_vencer">0</div><div class="small text-muted">facturas</div>
    </div></div></div>
    <div class="col-6 col-lg-4 col-xxl-2"><div class="card shadow-sm border-0 rounded-4 h-100 border-start border-4 border-secondary cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl"><i class="bi bi-arrow-return-left"></i> Crédito devoluciones sin aplicar</div><div class="cf-kpi-num" data-kpi="credito">$0,00</div><div class="small text-muted">saldo a favor</div>
    </div></div></div>
  </div>

  <!-- Vista principal -->
  <div id="vistaLista">
    <div class="card shadow-sm border-0 rounded-4 mb-3">
      <div class="card-header bg-white border-0 pt-3 fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill"></i> A quiénes cobrar primero</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="small text-uppercase text-muted">
            <th>Cliente</th><th class="text-end">Saldo total</th><th class="text-end">Vencido</th><th class="text-center">Fact. vencidas</th><th class="text-end">Días de atraso (máx.)</th>
          </tr></thead>
          <tbody id="tbPrioridad"></tbody>
        </table>
      </div>
    </div>

    <form class="d-flex flex-wrap align-items-center gap-2 mb-2" id="formBuscar" role="search">
      <div class="input-group input-group-sm cf-buscar">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input type="search" class="form-control" id="qClientes" placeholder="Buscar por N° factura, cliente, documento…" autocomplete="off">
      </div>
      <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-search"></i> Buscar</button>
      <button type="button" class="btn btn-sm btn-outline-danger rounded-pill" id="btnLimpiar"><i class="bi bi-x-lg"></i> Limpiar</button>
      <div class="d-flex gap-1" id="pillsEstado" role="group" aria-label="Filtrar por estado">
        <button type="button" class="btn btn-sm btn-warning rounded-pill" data-estado="">Todas</button>
        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill" data-estado="pendiente">Pendientes</button>
        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill" data-estado="vencida">Vencidas</button>
        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill" data-estado="pagada">Pagadas</button>
      </div>
      <span class="small text-muted ms-auto" id="contadorClientes"></span>
    </form>
    <p class="small text-muted mb-2"><i class="bi bi-folder2-open"></i> Abre la ficha de un cliente (botón <strong>Facturas</strong>) para ver todas sus facturas, pagos y devoluciones.</p>

    <div class="card shadow-sm border-0 rounded-4">
      <div class="card-header bg-white border-0 pt-3 fw-semibold"><i class="bi bi-people"></i> Clientes del módulo</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr class="small text-uppercase text-muted">
            <th>Nombre</th><th>Documento</th><th>Teléfono</th><th>Domicilio/Dirección</th><th class="text-end">Saldo pendiente</th><th class="text-end">Acciones</th>
          </tr></thead>
          <tbody id="tbClientes"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Ficha del cliente -->
  <div id="vistaCliente" class="d-none">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="btnVolver"><i class="bi bi-arrow-left"></i> Volver</button>
      <h2 class="h6 mb-0"><i class="bi bi-person"></i> <span id="detNombre"></span></h2>
      <span class="small text-muted" id="detDoc"></span>
      <a class="btn btn-sm btn-success rounded-pill ms-auto" id="btnEstadoCuenta" href="#" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> Generar Estado de Cuenta</a>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-3"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-dark cf-kpi"><div class="card-body py-2"><div class="cf-kpi-lbl">Debe en total</div><div class="cf-kpi-num" id="detDebe"></div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-success cf-kpi"><div class="card-body py-2"><div class="cf-kpi-lbl">Total cobrado</div><div class="cf-kpi-num text-success" id="detCobrado"></div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-danger cf-kpi"><div class="card-body py-2"><div class="cf-kpi-lbl">Vencido</div><div class="cf-kpi-num text-danger" id="detVencido"></div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-secondary cf-kpi"><div class="card-body py-2"><div class="cf-kpi-lbl">Crédito por devoluciones</div><div class="cf-kpi-num" id="detCredito"></div></div></div></div>
    </div>

    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabFacturas" type="button" role="tab"><i class="bi bi-receipt"></i> Facturas <span class="badge text-bg-light border" id="nFacturas">0</span></button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPagos" type="button" role="tab"><i class="bi bi-cash-coin"></i> Historial de pagos <span class="badge text-bg-light border" id="nPagos">0</span></button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDevoluciones" type="button" role="tab"><i class="bi bi-arrow-return-left"></i> Devoluciones <span class="badge text-bg-light border" id="nDevoluciones">0</span></button></li>
    </ul>
    <div class="tab-content card shadow-sm border-0 rounded-bottom-4">
      <div class="tab-pane fade show active" id="tabFacturas" role="tabpanel">
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="small text-uppercase text-muted">
            <th>Estado</th><th>N° factura</th><th>Domicilio</th><th>Emisión</th><th>Vence</th>
            <th class="text-end">Monto</th><th class="text-end">Pagado</th><th class="text-end">Devuelto</th><th class="text-end">Saldo</th><th class="text-end">Acciones</th>
          </tr></thead>
          <tbody id="tbDetFacturas"></tbody>
        </table></div>
      </div>
      <div class="tab-pane fade" id="tabPagos" role="tabpanel">
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="small text-uppercase text-muted"><th>Fecha</th><th>Factura</th><th>Método</th><th>Referencia</th><th>Notas</th><th class="text-end">Monto</th><th class="text-end">Acciones</th></tr></thead>
          <tbody id="tbDetPagos"></tbody>
        </table></div>
      </div>
      <div class="tab-pane fade" id="tabDevoluciones" role="tabpanel">
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
          <thead><tr class="small text-uppercase text-muted"><th>Fecha</th><th class="text-end">Monto</th><th>Motivo</th><th>Factura origen</th><th>Aplicada a</th><th class="text-end">Estado</th><th class="text-end">Acciones</th></tr></thead>
          <tbody id="tbDetDevoluciones"></tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="cfToasts" aria-live="polite"></div>

<!-- Modal: cliente -->
<div class="modal fade" id="modalCliente" tabindex="-1" aria-labelledby="tituloCliente" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content border-0 rounded-4 shadow" id="formCliente" data-endpoint="cliente" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="tituloCliente">Nuevo Cliente</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body row g-3">
        <input type="hidden" name="id">
        <div class="col-12"><label class="form-label" for="cliNombre">Nombre <span class="text-danger">*</span></label><input class="form-control" id="cliNombre" name="nombre" maxlength="120" required><div class="invalid-feedback"></div></div>
        <div class="col-12"><label class="form-label" for="cliDoc">Documento (RIF/CI)</label><input class="form-control" id="cliDoc" name="documento" maxlength="20" placeholder="V-12345678 o J-12345678-9"><div class="invalid-feedback"></div></div>
        <div class="col-12"><label class="form-label" for="cliTel">Teléfono</label><input class="form-control" id="cliTel" name="telefono" maxlength="20"><div class="invalid-feedback"></div></div>
        <div class="col-12"><label class="form-label" for="cliDom">Domicilio</label><input class="form-control" id="cliDom" name="domicilio" maxlength="160" placeholder="Ej: Av. Bolívar, local 5, Santaella…"><div class="invalid-feedback"></div></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button><button type="submit" class="btn btn-warning rounded-pill"><i class="bi bi-save"></i> Guardar</button></div>
    </form>
  </div>
</div>

<!-- Modal: nueva factura -->
<div class="modal fade" id="modalFactura" tabindex="-1" aria-labelledby="tituloFactura" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content border-0 rounded-4 shadow" id="formFactura" data-endpoint="factura" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="tituloFactura"><i class="bi bi-receipt"></i> Nueva Factura</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="facCliente">Cliente <span class="text-danger">*</span></label>
            <select class="form-select js-clientes" id="facCliente" name="cliente_id" required></select><div class="invalid-feedback"></div>
            <div class="form-text" id="facCreditoInfo"></div></div>
          <div class="col-md-6"><label class="form-label" for="facNumero">Número de factura <span class="text-danger">*</span></label>
            <div class="d-flex gap-3 small mb-1">
              <div class="form-check"><input class="form-check-input" type="radio" name="modo_numero" id="numManual" value="manual" checked><label class="form-check-label" for="numManual">Escribir número</label></div>
              <div class="form-check"><input class="form-check-input" type="radio" name="modo_numero" id="numAleatorio" value="aleatorio"><label class="form-check-label" for="numAleatorio">Número aleatorio (4 dígitos)</label></div>
            </div>
            <input class="form-control" id="facNumero" name="numero" maxlength="20" required placeholder="Ej: 00125" autocomplete="off">
            <div class="invalid-feedback"></div>
            <div id="facNumeroEstado" class="small text-muted mt-1 d-none" aria-live="polite">
              <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generando…
            </div>
          </div>
          <div class="col-12"><label class="form-label" for="facDom">Domicilio</label><input class="form-control" id="facDom" name="domicilio" maxlength="160" placeholder="Ej: Av. Bolívar, local 5, Santaella…"></div>
          <div class="col-12"><label class="form-label" for="facObs">Observaciones</label><input class="form-control" id="facObs" name="observaciones" maxlength="240" placeholder="Opcional"></div>
          <div class="col-md-6"><label class="form-label" for="facEmision">Fecha emisión <span class="text-danger">*</span></label><input class="form-control" type="date" id="facEmision" name="emision" required value="<?= e($hoy) ?>" max="<?= e($hoy) ?>"><div class="invalid-feedback"></div></div>
          <div class="col-md-6"><label class="form-label" for="facVence">Fecha vencimiento <span class="text-danger">*</span></label><input class="form-control" type="date" id="facVence" name="vence" required value="<?= e($vencePorDefecto) ?>" min="<?= e($hoy) ?>"><div class="invalid-feedback"></div></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3 mb-1">
          <span class="fw-semibold small">Productos de la factura</span>
          <button type="button" class="btn btn-sm btn-outline-warning rounded-pill js-agregar-fila" data-tabla="tablaFactura"><i class="bi bi-plus-lg"></i> Agregar producto</button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1" id="tablaFactura" data-total="facTotal">
            <thead><tr class="small text-muted"><th class="cf-cant">Cant.</th><th class="cf-precio">Precio</th><th class="text-end cf-precio">Subtotal</th><th>Descripción</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="form-text mb-2">Cantidad × Precio = subtotal. Deja las filas en blanco si prefieres escribir el monto total a mano.</div>
        <div class="row g-3 align-items-end">
          <div class="col-md-5"><label class="form-label" for="facTotal">Monto total $ <span class="text-danger">*</span></label>
            <input class="form-control text-end fw-bold" id="facTotal" name="monto" inputmode="decimal" required><div class="invalid-feedback"></div></div>
          <div class="col-md-7"><div class="form-check">
            <input class="form-check-input" type="checkbox" id="facCredito" name="descontar_credito" value="1">
            <label class="form-check-label" for="facCredito">¿Descontar de saldo a favor del cliente? (si posee)</label>
          </div></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button><button type="submit" class="btn btn-warning rounded-pill"><i class="bi bi-save"></i> Guardar</button></div>
    </form>
  </div>
</div>

<!-- Modal: pago rápido -->
<div class="modal fade" id="modalPagoRapido" tabindex="-1" aria-labelledby="tituloPagoRapido" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header"><h5 class="modal-title" id="tituloPagoRapido"><i class="bi bi-lightning-charge-fill text-warning"></i> Agregar pago rápido</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <div class="input-group mb-3">
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input class="form-control" id="qPago" placeholder="Buscar por número de factura o nombre del cliente…" autocomplete="off">
          <button class="btn btn-outline-secondary" type="button" id="btnLimpiarPago">Limpiar</button>
        </div>
        <div class="list-group cf-lista" id="listaPago"></div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: registrar pago de una factura (desde pago rápido o desde la ficha) -->
<div class="modal fade" id="modalPago" tabindex="-1" aria-labelledby="tituloPago" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content border-0 rounded-4 shadow" id="formPago" data-endpoint="pago" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="tituloPago"><i class="bi bi-cash"></i> Registrar Pago</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body row g-3">
        <input type="hidden" name="factura_id">
        <div class="col-12"><div class="alert alert-info py-2 mb-0">Saldo actual de la factura: <strong id="pagoSaldo"></strong></div></div>
        <div class="col-12 d-none" id="cajaPagoCredito"><div class="alert alert-info py-2 mb-0" id="pagoCreditoInfo"></div></div>
        <div class="col-md-6"><label class="form-label" for="pagoMonto">Monto $ <span class="text-danger">*</span></label><input class="form-control" id="pagoMonto" name="monto" inputmode="decimal" required><div class="invalid-feedback"></div></div>
        <div class="col-md-6"><label class="form-label" for="pagoMontoBs">Monto Bs</label><input class="form-control" id="pagoMontoBs" name="monto_bs" inputmode="decimal"><div class="invalid-feedback"></div></div>
        <div class="col-md-6"><label class="form-label" for="pagoTasa">Tasa de Cambio (Bs/$)</label><input class="form-control" id="pagoTasa" name="tasa_cambio" inputmode="decimal" placeholder="Ej: 185.50"><div class="invalid-feedback"></div></div>
        <div class="col-md-6"><label class="form-label" for="pagoFecha">Fecha <span class="text-danger">*</span></label><input class="form-control" type="date" id="pagoFecha" name="fecha" required value="<?= e($hoy) ?>" max="<?= e($hoy) ?>"><div class="invalid-feedback"></div></div>
        <div class="col-12"><label class="form-label" for="pagoMetodo">Método</label><select class="form-select" id="pagoMetodo" name="metodo"><?= $opcionesMetodo() ?></select><div class="invalid-feedback"></div></div>
        <div class="col-12"><label class="form-label" for="pagoNotas">Notas</label><input class="form-control" id="pagoNotas" name="notas" maxlength="160"></div>
        <div class="col-12"><label class="form-label" for="pagoRef">Referencia</label><input class="form-control" id="pagoRef" name="referencia" maxlength="60" placeholder="N° de transferencia, recibo…"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button><button type="submit" class="btn btn-info text-white rounded-pill"><i class="bi bi-check2-circle"></i> Registrar Pago</button></div>
    </form>
  </div>
</div>

<!-- Modal: abono a varias -->
<div class="modal fade" id="modalAbono" tabindex="-1" aria-labelledby="tituloAbono" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content border-0 rounded-4 shadow" id="formAbono" data-endpoint="abono" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="tituloAbono"><i class="bi bi-cash-stack"></i> Abonar a varias facturas</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> El abono se aplicará en cascada desde la factura más vieja/vencida hacia la más reciente. Se crea un pago separado en cada factura afectada.</div>
        <div class="d-none" id="cajaAbonoCredito"><div class="alert alert-info py-2 mb-3" id="abonoCreditoInfo"></div></div>
        <div class="row g-3">
          <div class="col-md-7"><label class="form-label" for="abonoCliente">Cliente <span class="text-danger">*</span></label><select class="form-select js-clientes" id="abonoCliente" name="cliente_id" data-con-deuda="1" required></select><div class="invalid-feedback"></div></div>
          <div class="col-md-5"><label class="form-label" for="abonoMonto">Abono total $ <span class="text-danger">*</span></label><input class="form-control" id="abonoMonto" name="monto" inputmode="decimal" required><div class="invalid-feedback"></div></div>
          <div class="col-md-6"><label class="form-label" for="abonoFecha">Fecha <span class="text-danger">*</span></label><input class="form-control" type="date" id="abonoFecha" name="fecha" required value="<?= e($hoy) ?>" max="<?= e($hoy) ?>"><div class="invalid-feedback"></div></div>
          <div class="col-md-6"><label class="form-label" for="abonoMetodo">Método</label><select class="form-select" id="abonoMetodo" name="metodo"><?= $opcionesMetodo() ?></select><div class="invalid-feedback"></div></div>
          <div class="col-md-6"><label class="form-label" for="abonoNotas">Nota para cada pago</label><input class="form-control" id="abonoNotas" name="notas" maxlength="160" placeholder="Ej: Abono recibido del cliente"></div>
          <div class="col-md-6"><label class="form-label" for="abonoRef">Referencia</label><input class="form-control" id="abonoRef" name="referencia" maxlength="60" placeholder="N° de transferencia, recibo…"></div>
        </div>
        <div class="border rounded-3 p-2 mt-3 bg-body-tertiary small">
          <div class="d-flex justify-content-between fw-semibold mb-1"><span>Vista previa del reparto</span><span id="abonoDeuda">Selecciona un cliente</span></div>
          <div id="abonoPreview" class="text-muted">Selecciona un cliente para ver sus facturas pendientes.</div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button><button type="submit" class="btn btn-info text-white rounded-pill"><i class="bi bi-check2-circle"></i> Registrar abono</button></div>
    </form>
  </div>
</div>

<!-- Modal: devolución -->
<div class="modal fade" id="modalDevolucion" tabindex="-1" aria-labelledby="tituloDevolucion" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content border-0 rounded-4 shadow" id="formDevolucion" data-endpoint="devolucion" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="tituloDevolucion"><i class="bi bi-arrow-return-left"></i> Registrar Devolución</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="devCliente">Cliente <span class="text-danger">*</span></label><select class="form-select js-clientes" id="devCliente" name="cliente_id" required></select><div class="invalid-feedback"></div></div>
          <div class="col-md-6"><label class="form-label" for="devOrigen">Factura de donde viene (opcional)</label><select class="form-select" id="devOrigen" name="factura_id"><option value="">—</option></select><div class="invalid-feedback"></div>
            <div class="form-text">Puede ser una factura ya pagada: la devolución no la modifica, solo queda como referencia.</div></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3 mb-1">
          <span class="fw-semibold small">Productos devueltos</span>
          <button type="button" class="btn btn-sm btn-outline-warning rounded-pill js-agregar-fila" data-tabla="tablaDevolucion"><i class="bi bi-plus-lg"></i> Agregar producto</button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-1" id="tablaDevolucion" data-total="devMonto">
            <thead><tr class="small text-muted"><th class="cf-cant">Cant.</th><th class="cf-precio">Precio</th><th class="text-end cf-precio">Subtotal</th><th>Descripción</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-6"><label class="form-label" for="devMonto">Monto $ <span class="text-danger">*</span></label><input class="form-control text-end fw-bold" id="devMonto" name="monto" inputmode="decimal" required><div class="invalid-feedback"></div></div>
          <div class="col-md-6"><label class="form-label" for="devFecha">Fecha <span class="text-danger">*</span></label><input class="form-control" type="date" id="devFecha" name="fecha" required value="<?= e($hoy) ?>" max="<?= e($hoy) ?>"><div class="invalid-feedback"></div></div>
          <div class="col-12"><label class="form-label" for="devMotivo">Nota / motivo de la devolución</label><textarea class="form-control" id="devMotivo" name="motivo" rows="2" maxlength="160" placeholder="Ej: 3 quesos dañados de la factura 00120"></textarea></div>
          <div class="col-md-6"><label class="form-label" for="devDestino">¿Qué hacer con el dinero de la devolución? <span class="text-danger">*</span></label>
            <select class="form-select" id="devDestino" name="destino" required>
              <option value="origen">Descontarlo de la misma factura de origen</option>
              <option value="otra">Descontarlo de otra factura vigente</option>
              <option value="credito" selected>Dejarlo como crédito pendiente (aplicar después)</option>
            </select><div class="invalid-feedback"></div></div>
          <div class="col-md-6 d-none" id="cajaOtra"><label class="form-label" for="devOtra">Factura vigente a descontar</label>
            <select class="form-select" id="devOtra" name="factura_destino"><option value="">Selecciona…</option></select><div class="invalid-feedback"></div></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button><button type="submit" class="btn btn-warning rounded-pill" id="btnGuardarDevolucion"><i class="bi bi-save"></i> Registrar Devolución</button></div>
    </form>
  </div>
</div>

<!-- Modal: detalle de factura (solo lectura) -->
<div class="modal fade" id="modalDetalleFactura" tabindex="-1" aria-labelledby="tituloDetalleFactura" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloDetalleFactura"><i class="bi bi-eye"></i> Detalle de factura</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2" id="detFacResumen"></p>
        <h6 class="small text-uppercase text-muted">Renglones</h6>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle mb-0">
            <thead><tr class="small text-muted"><th>Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th>Descripción</th></tr></thead>
            <tbody id="tbDetRenglones"></tbody>
          </table>
        </div>
        <h6 class="small text-uppercase text-muted">Pagos aplicados</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr class="small text-muted"><th>Fecha</th><th>Método</th><th>Referencia</th><th class="text-end">Monto $</th><th class="text-end">Bs / Tasa</th><th class="text-end">Estado</th><th></th></tr></thead>
            <tbody id="tbDetPagosFac"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline-primary rounded-pill" id="btnImprimirFactura" href="#" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Imprimir factura</a>
        <button type="button" class="btn btn-outline-danger rounded-pill d-none" id="btnAnularFacturaDetalle"><i class="bi bi-slash-circle"></i> Anular factura</button>
        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: detalle de devolución (solo lectura) -->
<div class="modal fade" id="modalDetalleDevolucion" tabindex="-1" aria-labelledby="tituloDetalleDevolucion" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloDetalleDevolucion"><i class="bi bi-eye"></i> Detalle de devolución</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2" id="detDevResumen"></p>
        <dl class="row small mb-3">
          <dt class="col-sm-4">Motivo</dt><dd class="col-sm-8" id="detDevMotivo">—</dd>
          <dt class="col-sm-4">Factura origen</dt><dd class="col-sm-8" id="detDevOrigen">—</dd>
          <dt class="col-sm-4">Aplicación</dt><dd class="col-sm-8" id="detDevAplicacion">—</dd>
        </dl>
        <h6 class="small text-uppercase text-muted">Productos devueltos</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr class="small text-muted"><th>Cant.</th><th class="text-end">Precio origen</th><th class="text-end">Precio aplicado</th><th class="text-end">Subtotal</th><th>Descripción</th></tr></thead>
            <tbody id="tbDetDevRenglones"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script type="application/json" id="cfDatos"><?= json_encode([
    'clientes'     => $clientes,
    'facturas'     => $facturas,
    'pagos'        => $pagos,
    'devoluciones' => $devoluciones,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>

<script src="<?= e(asset_v('js/facturas.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<?php
layout_end();
