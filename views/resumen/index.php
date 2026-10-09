<?php
declare(strict_types=1);

/**
 * Tablero gerencial por rango de fechas.
 *
 * @var string $inicio
 * @var string $fin
 * @var string $hoy
 * @var array<string, mixed> $datos
 * @var array<string, string> $endpoints
 */
$inicio = $inicio ?? date('Y-m-d');
$fin = $fin ?? date('Y-m-d');
$hoy = $hoy ?? date('Y-m-d');
$datos = $datos ?? [];
$endpoints = $endpoints ?? ['datos' => '', 'pdf' => ''];
$semana = ResumenService::semanaDe($hoy);
$mes = ResumenService::mesDe($hoy);
if ($semana['fin'] > $hoy) {
    $semana['fin'] = $hoy;
}
if ($mes['fin'] > $hoy) {
    $mes['fin'] = $hoy;
}

layout_start('Resumen Gerencial', 'resumen_diario');
?>
<link rel="stylesheet" href="<?= e(asset_v('css/resumen.css')) ?>">
<div id="rgApp"
     data-hoy="<?= e($hoy) ?>"
     data-semana-inicio="<?= e($semana['inicio']) ?>"
     data-semana-fin="<?= e($semana['fin']) ?>"
     data-mes-inicio="<?= e($mes['inicio']) ?>"
     data-mes-fin="<?= e($mes['fin']) ?>"
     data-endpoints="<?= e(json_encode($endpoints, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">

  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
      <h1 class="h4 mb-0"><i class="bi bi-clipboard-data"></i> Resumen Gerencial</h1>
      <div class="small text-muted">Estado de Resultados (P&amp;L) y balances CxC / CxP por rango de fechas</div>
    </div>
    <form id="rgFiltro" class="d-flex flex-wrap align-items-end gap-2" autocomplete="off">
      <div>
        <label class="form-label small mb-0" for="rgDesde">Desde</label>
        <input type="date" id="rgDesde" class="form-control form-control-sm" value="<?= e($inicio) ?>" max="<?= e($hoy) ?>">
      </div>
      <div>
        <label class="form-label small mb-0" for="rgHasta">Hasta</label>
        <input type="date" id="rgHasta" class="form-control form-control-sm" value="<?= e($fin) ?>" max="<?= e($hoy) ?>">
      </div>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Consultar</button>
      <div class="btn-group btn-group-sm" role="group" aria-label="Rangos rápidos">
        <button type="button" class="btn btn-outline-secondary" data-rango="hoy">Hoy</button>
        <button type="button" class="btn btn-outline-secondary" data-rango="semana">Esta Semana</button>
        <button type="button" class="btn btn-outline-secondary" data-rango="mes">Este Mes</button>
      </div>
      <a class="btn btn-sm btn-outline-danger" id="rgPdf" href="<?= e(url('resumen_pdf_rango', ['inicio' => $inicio, 'fin' => $fin])) ?>" target="_blank" rel="noopener">
        <i class="bi bi-file-earmark-pdf"></i> PDF
      </a>
    </form>
  </div>

  <div id="rgToast" class="alert d-none py-2" role="status"></div>
  <p class="small text-muted" id="rgPeriodo"></p>

  <div class="row g-3 mb-3" id="rgKpis"></div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header">Estado de Resultados (P&amp;L)</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 pl-table">
            <tbody id="rgPlBody"></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header">Balance CxC / CxP</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 pl-table">
            <tbody id="rgBalBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header">Producción por producto</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th>Producto</th><th class="text-end">Cantidad</th><th>UoM</th><th class="text-end">Total $</th></tr>
            </thead>
            <tbody id="rgProdBody"></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header">Cobranzas por método de pago</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th>Método</th><th class="text-end">Movs.</th><th class="text-end">Total $</th></tr>
            </thead>
            <tbody id="rgCobBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<script type="application/json" id="rgDatos"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= e(asset_v('js/resumen.js')) ?>" nonce="<?= e(csp_nonce()) ?>" defer></script>
<?php
layout_end();
