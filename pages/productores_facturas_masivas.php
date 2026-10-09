<?php
declare(strict_types=1);

/**
 * Selección de productores con entregas en el período para facturas masivas.
 * @var string $desde
 * @var string $hasta
 * @var string $grupo
 * @var list<string> $grupos
 * @var list<array{id:int, nombre:string, documento:?string, grupo_cierre:?string, litros:string, monto_usd:string, precio_promedio:?string, dias:int}> $productores
 */
$totLitros = 0.0;
$totMonto = 0.0;
foreach ($productores as $p) {
    $totLitros += (float) $p['litros'];
    $totMonto += (float) $p['monto_usd'];
}

layout_start('Facturas masivas — Productores', 'productores');
?>
<div class="fm-bar d-flex flex-wrap align-items-center gap-2 p-3 mb-3">
  <form class="d-flex flex-wrap align-items-center gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
    <input type="hidden" name="p" value="productores_facturas_masivas">
    <span class="fw-semibold">Período:</span>
    <input type="date" name="desde" class="form-control form-control-sm w-fecha" value="<?= e($desde) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="Desde">
    <span aria-hidden="true">—</span>
    <input type="date" name="hasta" class="form-control form-control-sm w-fecha" value="<?= e($hasta) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="Hasta">
    <label class="fw-semibold mb-0" for="fmGrupo">Grupo:</label>
    <select name="grupo" id="fmGrupo" class="form-select form-select-sm w-tipo" aria-label="Grupo de cierre">
      <option value="" <?= $grupo === '' ? 'selected' : '' ?>>Todos</option>
      <option value="-" <?= $grupo === '-' ? 'selected' : '' ?>>Sin grupo</option>
      <?php foreach ($grupos as $g): ?>
      <option value="<?= e($g) ?>" <?= $grupo === $g ? 'selected' : '' ?>><?= e($g) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-secondary">Aplicar</button>
  </form>
  <div class="vr d-none d-md-block"></div>
  <button type="button" class="btn btn-sm btn-outline-secondary" id="fmIncluir">Incluir todos</button>
  <button type="button" class="btn btn-sm btn-outline-secondary" id="fmExcluir">Excluir todos</button>
  <span class="badge rounded-pill fm-count"><span id="fmCount"><?= count($productores) ?></span> seleccionados</span>
  <div class="ms-md-auto d-flex flex-wrap gap-2">
    <button type="submit" form="formLiquidaciones" class="btn btn-sm btn-dark" <?= $productores === [] ? 'disabled' : '' ?>><i class="bi bi-printer"></i> Imprimir todas</button>
    <button type="button" class="btn btn-sm btn-danger" id="fmZipPdf" <?= $productores === [] ? 'disabled' : '' ?>><i class="bi bi-file-earmark-pdf"></i> Descargar PDFs (ZIP)</button>
    <button type="button" class="btn btn-sm btn-success" id="fmZipImg" <?= $productores === [] ? 'disabled' : '' ?>><i class="bi bi-images"></i> Descargar Imágenes (ZIP)</button>
  </div>
</div>

<p class="text-muted small mb-2">
  <?= count($productores) ?> productores con entregas entre <?= e((new DateTimeImmutable($desde))->format('d/m/Y')) ?> y <?= e((new DateTimeImmutable($hasta))->format('d/m/Y')) ?>:
  <strong><?= e(num($totLitros, 1)) ?> L</strong> por <strong>$<?= e(num($totMonto)) ?></strong>.
</p>

<?php if ($productores === []): ?>
  <div class="card"><div class="card-body text-center text-muted py-4">No hay entregas en el período seleccionado.</div></div>
<?php else: ?>
<form method="get" action="<?= e(base_path() . 'index.php') ?>" id="formLiquidaciones" target="_blank">
<input type="hidden" name="p" value="productores_liquidacion">
<input type="hidden" name="desde" value="<?= e($desde) ?>">
<input type="hidden" name="hasta" value="<?= e($hasta) ?>">
<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4 g-2" id="fmGrid">
  <?php foreach ($productores as $p): $pid = (int) $p['id']; $grupoP = trim((string) ($p['grupo_cierre'] ?? '')); ?>
  <div class="col">
    <div class="fm-card d-flex align-items-center gap-2 border rounded bg-white px-2 py-2">
      <input class="form-check-input m-0" type="checkbox" name="ids[]" value="<?= $pid ?>" checked id="fm-<?= $pid ?>" aria-label="Incluir <?= e($p['nombre']) ?>">
      <label class="form-check-label fm-nombre flex-grow-1 text-truncate small mb-0" for="fm-<?= $pid ?>" title="<?= e($p['nombre']) ?>">
        <?= e($p['nombre']) ?>
        <span class="d-block text-muted"><?= e(num($p['litros'], 1)) ?> L · $<?= e(num($p['monto_usd'])) ?><?= $grupoP !== '' ? ' · ' . e($grupoP) : '' ?></span>
      </label>
      <a class="btn btn-sm btn-link text-decoration-none p-0" target="_blank" rel="noopener" href="<?= e(url('productores_liquidacion', ['id' => $pid, 'desde' => $desde, 'hasta' => $hasta])) ?>" aria-label="Liquidación de <?= e($p['nombre']) ?>"><i class="bi bi-file-earmark-pdf"></i></a>
      <a class="btn btn-sm btn-link text-decoration-none p-0" href="<?= e(url('productor_control', ['id' => $pid, 'tab' => 'historial', 'desde' => $desde, 'hasta' => $hasta])) ?>" aria-label="Ver cuenta de <?= e($p['nombre']) ?>"><i class="bi bi-eye"></i></a>
      <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none p-0 fw-bold" data-excluir aria-label="Excluir <?= e($p['nombre']) ?>">×</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
</form>
<form method="post" action="<?= e(url('productores_facturas_zip')) ?>" id="formZip" class="d-none">
  <?= csrf_field() ?>
  <input type="hidden" name="desde" value="<?= e($desde) ?>">
  <input type="hidden" name="hasta" value="<?= e($hasta) ?>">
  <input type="hidden" name="grupo" value="<?= e($grupo) ?>">
  <input type="hidden" name="formato" id="fmZipFormato" value="pdf">
  <div id="fmZipIds"></div>
</form>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var grid = document.getElementById('fmGrid');
  var count = document.getElementById('fmCount');
  var formLiq = document.getElementById('formLiquidaciones');
  var formZip = document.getElementById('formZip');
  if (!grid || !formLiq) return;

  formLiq.addEventListener('submit', function (ev) {
    if (!grid.querySelector('input:checked')) {
      ev.preventDefault();
      window.alert('Selecciona al menos un productor.');
    }
  });

  function sync() {
    var n = 0;
    grid.querySelectorAll('.fm-card').forEach(function (card) {
      var on = card.querySelector('input').checked;
      card.classList.toggle('is-out', !on);
      if (on) n += 1;
    });
    count.textContent = String(n);
  }

  function idsSeleccionados() {
    return Array.prototype.map.call(grid.querySelectorAll('input:checked'), function (el) { return el.value; });
  }

  function enviarZip(formato) {
    if (!formZip) return;
    var ids = idsSeleccionados();
    if (!ids.length) {
      window.alert('Selecciona al menos un productor.');
      return;
    }
    var caja = document.getElementById('fmZipIds');
    caja.replaceChildren();
    ids.forEach(function (id) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'ids[]';
      input.value = id;
      caja.appendChild(input);
    });
    document.getElementById('fmZipFormato').value = formato;
    formZip.submit();
  }

  document.getElementById('fmIncluir').addEventListener('click', function () {
    grid.querySelectorAll('input').forEach(function (input) { input.checked = true; });
    sync();
  });
  document.getElementById('fmExcluir').addEventListener('click', function () {
    grid.querySelectorAll('input').forEach(function (input) { input.checked = false; });
    sync();
  });
  var btnPdf = document.getElementById('fmZipPdf');
  var btnImg = document.getElementById('fmZipImg');
  if (btnPdf) btnPdf.addEventListener('click', function () { enviarZip('pdf'); });
  if (btnImg) btnImg.addEventListener('click', function () { enviarZip('img'); });
  grid.addEventListener('change', sync);
  grid.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-excluir]');
    if (!btn) return;
    btn.closest('.fm-card').querySelector('input').checked = false;
    sync();
  });
  sync();
})();
</script>
<?php
layout_end();
