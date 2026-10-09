<?php
declare(strict_types=1);

/**
 * Logística y transporte.
 *
 * @var string $hoy
 * @var array<string, mixed> $metricas
 * @var list<array<string, mixed>> $transportistas
 * @var list<array<string, mixed>> $productores
 * @var array<string, string> $vehiculos
 * @var array<string, string> $tiposPago
 * @var bool $puedeCrear
 * @var bool $puedeEditar
 */
$tiposPago = $tiposPago ?? TransporteService::TIPOS_PAGO;
$endpoints = [
    'guardar'     => url('transportistas_guardar'),
    'ruta'        => url('transportistas_ruta'),
    'flete'       => url('transportistas_flete'),
    'cierre'      => url('transportistas_cierre'),
    'pago'        => url('transportistas_pago'),
    'detalle'     => url('transportistas_cierre_detalle'),
    'analisis'    => url('transporte_analisis_ruta'),
    'reabrir'     => url('transportistas_reabrir_semana'),
    'estado'      => url('transportistas_estado'),
    'pdf'         => url('transporte_factura_pdf'),
    'directorio'  => url('transporte_directorio_pdf'),
    'cxp'         => url('transporte_cxp_pdf'),
];
$opVeh = '';
foreach ($vehiculos as $k => $lbl) {
    $opVeh .= '<option value="' . e($k) . '">' . e($lbl) . '</option>';
}
layout_start('Transportistas', 'transportistas');
?>
<link rel="stylesheet" href="<?= e(asset_v('css/transporte.css')) ?>">
<div id="trApp" data-hoy="<?= e($hoy) ?>" data-crear="<?= $puedeCrear ? '1' : '0' ?>" data-editar="<?= $puedeEditar ? '1' : '0' ?>"
     data-endpoints="<?= e(json_encode($endpoints, JSON_THROW_ON_ERROR)) ?>">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h5 mb-0"><i class="bi bi-truck"></i> Logística y Transporte</h1>
      <div class="small text-muted">Rutas, recolecta diaria y cierres semanales</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-notaria" href="<?= e(url('transporte_notaria')) ?>" target="_blank" rel="noopener">
        🖨️ Imprimir Notaría
      </a>
      <a class="btn btn-sm btn-outline-secondary rounded-pill" href="<?= e(url('transporte_directorio_pdf')) ?>" target="_blank" rel="noopener">
        <i class="bi bi-file-earmark-pdf"></i> Directorio Transportistas PDF
      </a>
      <a class="btn btn-sm btn-outline-secondary rounded-pill" href="<?= e(url('transporte_cxp_pdf')) ?>" target="_blank" rel="noopener">
        <i class="bi bi-file-earmark-pdf"></i> Reporte CxP Transporte
      </a>
      <?php if ($puedeCrear): ?>
      <button type="button" class="btn btn-sm btn-warning rounded-pill" id="btnNuevoTr"><i class="bi bi-plus-lg"></i> Nuevo transportista</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-primary cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Gasto Semanal ($)</div>
      <div class="cf-kpi-num text-primary" data-kpi="gasto_semanal">$0,00</div>
      <div class="small text-muted">lunes a domingo</div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-warning cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Gasto Diario ($)</div>
      <div class="cf-kpi-num text-warning" data-kpi="gasto_diario">$0,00</div>
      <div class="small text-muted">fletes de hoy</div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-success cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Carga Promedio Diaria</div>
      <div class="cf-kpi-num text-success" data-kpi="promedio_carga">0 L</div>
      <div class="small text-muted">litros / viaje esta semana</div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-secondary cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Total Pagado Histórico ($)</div>
      <div class="cf-kpi-num" data-kpi="pagado_historico">$0,00</div>
      <div class="small text-muted">abonos a transportistas</div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-danger cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Total Deuda Pendiente (CxP $)</div>
      <div class="cf-kpi-num text-danger" data-kpi="deuda_pendiente">$0,00</div>
      <div class="small text-muted">cierres menos pagos</div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-0 rounded-4 border-start border-4 border-info cf-kpi"><div class="card-body">
      <div class="cf-kpi-lbl">Total Litros Recolectados Histórico</div>
      <div class="cf-kpi-num text-info" data-kpi="litros_historico">0 L</div>
      <div class="small text-muted">todos los viajes</div>
    </div></div></div>
  </div>

  <div id="vistaLista">
    <div class="card shadow-sm border-0 rounded-4">
      <div class="card-header bg-white border-0 pt-3 fw-semibold"><i class="bi bi-people"></i> Transportistas</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr class="small text-uppercase text-muted">
            <th>Nombre</th><th>Documento</th><th>Modalidad</th><th>Flotilla</th><th class="text-end">Capacidad kg</th><th>Estado</th><th></th>
          </tr></thead>
          <tbody id="tbTransportistas"></tbody>
        </table>
      </div>
    </div>
  </div>

  <div id="vistaFicha" class="d-none">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="btnVolver"><i class="bi bi-arrow-left"></i> Volver</button>
      <h2 class="h6 mb-0" id="trNombre"></h2>
      <span class="small text-muted" id="trMeta"></span>
      <?php if ($puedeEditar || $puedeCrear): ?>
      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill ms-auto" id="btnEditarTr"><i class="bi bi-pencil"></i> Editar ficha</button>
      <?php endif; ?>
    </div>
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabRutas" type="button">Rutas y Productores</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDiario" type="button">Control Diario</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCierres" type="button">Cierres y Pagos</button></li>
    </ul>
    <div class="tab-content border border-top-0 rounded-bottom bg-white p-3">
      <div class="tab-pane fade show active" id="tabRutas">
        <?php if ($puedeCrear): ?>
        <button type="button" class="btn btn-sm btn-warning rounded-pill mb-3" id="btnArmarRuta"><i class="bi bi-signpost-2"></i> Armar Ruta</button>
        <?php endif; ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted"><th>Zona</th><th class="text-end">Precio / veh.</th><th class="text-end">Vehículos</th><th class="text-end">Costo semanal</th><th>Productores</th><th></th></tr></thead>
          <tbody id="tbRutas"></tbody>
        </table></div>
      </div>
      <div class="tab-pane fade" id="tabDiario">
        <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <?php if ($puedeCrear): ?>
        <button type="button" class="btn btn-sm btn-info text-white rounded-pill" id="btnViaje"><i class="bi bi-plus-lg"></i> Registrar viaje de hoy</button>
        <?php endif; ?>
          <div>
            <label class="form-label small mb-0" for="precioLecheRef">Precio leche referencial ($)</label>
            <input class="form-control form-control-sm" id="precioLecheRef" inputmode="decimal" value="0,60" aria-label="Precio de leche referencial">
          </div>
          <p class="small text-muted mb-1" id="resumenCostoLeche"></p>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted"><th>Fecha</th><th>Ruta</th><th class="text-end">Litros</th><th class="text-end">Flete diario $</th><th class="text-end">Flete $/L</th><th class="text-end">Costo total $/L</th><th>Estado</th></tr></thead>
          <tbody id="tbFletes"></tbody>
        </table></div>
      </div>
      <div class="tab-pane fade" id="tabCierres">
        <?php if ($puedeCrear): ?>
        <button type="button" class="btn btn-sm btn-success rounded-pill mb-3" id="btnCerrar"><i class="bi bi-calendar-check"></i> Generar cierre semanal</button>
        <?php endif; ?>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr class="small text-muted"><th>Período</th><th class="text-end">Litros</th><th class="text-end">Bruto</th><th class="text-end">Pagado</th><th class="text-end">Neto</th><th>Estado</th><th></th></tr></thead>
          <tbody id="tbPagos"></tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalTransportista" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content border-0 rounded-4 shadow" id="formTransportista" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="tituloTr">Nuevo transportista</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body row g-3">
        <input type="hidden" name="id">
        <div class="col-12"><label class="form-label" for="trNom">Nombre <span class="text-danger">*</span></label><input class="form-control" id="trNom" name="nombre" maxlength="120" required></div>
        <div class="col-md-6"><label class="form-label" for="trDoc">Documento <span class="text-danger">*</span></label><input class="form-control" id="trDoc" name="documento" maxlength="20" placeholder="V-12345678" required></div>
        <div class="col-md-6"><label class="form-label" for="trTel">Teléfono</label><input class="form-control" id="trTel" name="telefono" maxlength="20"></div>
        <div class="col-12">
          <div class="form-label">Modalidad</div>
          <div class="d-flex flex-wrap gap-3">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="modalidad" id="modInd" value="individual" checked>
              <label class="form-check-label" for="modInd">Individual (1 vehículo)</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="modalidad" id="modCoop" value="cooperativa">
              <label class="form-check-label" for="modCoop">Cooperativa (múltiples)</label>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="form-label mb-0">Vehículos de la flotilla</div>
            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill d-none" id="btnAgregarVeh">+ Agregar vehículo</button>
          </div>
          <div id="cajaVehiculos"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning rounded-pill" id="btnGuardarTr">Guardar</button></div>
    </form>
  </div>
</div>

<template id="tplVehiculo">
  <div class="tr-veh-row border rounded-3 p-2 mb-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label small mb-0">Placa *</label><input class="form-control form-control-sm" name="placa" maxlength="15" required></div>
      <div class="col-md-3"><label class="form-label small mb-0">Tipo</label><select class="form-select form-select-sm" name="tipo_vehiculo"><?= $opVeh ?></select></div>
      <div class="col-md-3"><label class="form-label small mb-0">Marca</label><input class="form-control form-control-sm" name="marca" maxlength="60"></div>
      <div class="col-md-3"><label class="form-label small mb-0">Modelo</label><input class="form-control form-control-sm" name="modelo" maxlength="60"></div>
      <div class="col-md-3"><label class="form-label small mb-0">Color</label><input class="form-control form-control-sm" name="color" maxlength="40"></div>
      <div class="col-md-3"><label class="form-label small mb-0">Límite kg *</label><input class="form-control form-control-sm" name="limite_carga_kg" inputmode="decimal" required></div>
      <div class="col-md-3"><button type="button" class="btn btn-sm btn-outline-danger rounded-pill btn-quitar-veh">Quitar</button></div>
    </div>
  </div>
</template>

<div class="modal fade" id="modalRuta" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content border-0 rounded-4 shadow" id="formRuta" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="tituloRuta">Armar ruta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body row g-3">
        <input type="hidden" name="ruta_id">
        <div class="col-md-5"><label class="form-label" for="rutaZona">Nombre de zona <span class="text-danger">*</span></label><input class="form-control" id="rutaZona" name="nombre_zona" maxlength="80" required placeholder="Ej. Ruta Norte"></div>
        <div class="col-md-4"><label class="form-label" for="rutaPrecio">Precio por vehículo $ <span class="text-danger">*</span></label><input class="form-control" id="rutaPrecio" name="precio_viaje" inputmode="decimal" required></div>
        <div class="col-md-3"><label class="form-label" for="rutaNveh">Vehículos asignados <span class="text-danger">*</span></label><input class="form-control" id="rutaNveh" name="vehiculos_asignados" type="number" min="1" max="99" value="1" required></div>
        <div class="col-12"><p class="small text-muted mb-0" id="rutaCostoResumen">Costo semanal = precio × vehículos. Flete diario = costo semanal / 7.</p></div>
        <div class="col-12">
          <div class="form-label">Productores de la zona <span class="text-danger">*</span></div>
          <div class="border rounded-3 p-2 tr-check-list">
            <div class="row g-2" id="cajaProductores"></div>
          </div>
          <div class="invalid-feedback d-block" id="errProductores"></div>
          <div class="alert alert-primary mt-3 mb-0 py-2 small" id="panelPlanRuta" role="status">
            Marca productores para estimar el volumen de la ruta.
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning rounded-pill" id="btnGuardarRuta">Guardar ruta</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalFlete" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content border-0 rounded-4 shadow" id="formFlete" novalidate>
      <div class="modal-header"><h5 class="modal-title">Registrar viaje</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body row g-3">
        <div class="col-12"><label class="form-label" for="fleteRuta">Ruta <span class="text-danger">*</span></label><select class="form-select" id="fleteRuta" name="ruta_id" required></select></div>
        <div class="col-12"><label class="form-label" for="fleteFecha">Fecha <span class="text-danger">*</span></label><input class="form-control" type="date" id="fleteFecha" name="fecha" required value="<?= e($hoy) ?>" max="<?= e($hoy) ?>"></div>
        <div class="col-12"><p class="small text-muted mb-0">Los litros se calculan solos: suma de las entregas de Control de Leche de los productores asignados a esa ruta en la fecha elegida.</p></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-info text-white rounded-pill" id="btnGuardarFlete">Registrar</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalPago" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content border-0 rounded-4 shadow" id="formPago" novalidate>
      <div class="modal-header"><h5 class="modal-title">Pago / adelanto / mercancía</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body row g-3">
        <input type="hidden" name="pago_id" id="pagoCierreId">
        <div class="col-md-6"><label class="form-label" for="pagoFecha">Fecha <span class="text-danger">*</span></label><input class="form-control" type="date" id="pagoFecha" name="fecha" required value="<?= e($hoy) ?>" max="<?= e($hoy) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="pagoMonto">Monto $ <span class="text-danger">*</span></label><input class="form-control" id="pagoMonto" name="monto" inputmode="decimal" required></div>
        <div class="col-12"><label class="form-label" for="pagoTipo">Tipo <span class="text-danger">*</span></label>
          <select class="form-select" id="pagoTipo" name="tipo" required>
            <?php foreach ($tiposPago as $k => $lbl): ?>
            <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><label class="form-label" for="pagoDesc">Descripción de mercancía</label><input class="form-control" id="pagoDesc" name="descripcion_mercancia" maxlength="255" placeholder="Obligatorio si el tipo es mercancía"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success rounded-pill" id="btnGuardarPago">Registrar pago</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalDetalle" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header"><h5 class="modal-title">Detalle del cierre</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
      <div class="modal-body" id="cajaDetalleCierre"></div>
    </div>
  </div>
</div>

<script type="application/json" id="trDatos"><?= json_encode([
    'metricas'       => $metricas,
    'transportistas' => $transportistas,
    'productores'    => $productores,
    'vehiculos'      => $vehiculos,
    'tiposPago'      => $tiposPago,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
<div class="toast-container position-fixed top-0 end-0 p-3" id="trToasts" aria-live="polite"></div>
<script src="<?= e(asset_v('js/transporte.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<?php
layout_end();
