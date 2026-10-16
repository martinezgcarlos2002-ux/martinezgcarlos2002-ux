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
<link rel="stylesheet" href="<?= e(asset_v('css/reportes.css')) ?>">
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
    <button type="submit" form="formLiquidaciones" class="btn btn-sm btn-dark" <?= $productores === [] ? 'disabled' : '' ?>><i class="bi bi-printer"></i> Imprimir / Guardar PDF</button>
    <button type="button" class="btn btn-sm btn-success" id="fmZipImg" data-label="📥 Descargar Imágenes (ZIP)" <?= $productores === [] ? 'disabled' : '' ?>>📥 Descargar Imágenes (ZIP)</button>
  </div>
</div>

<p class="text-muted small mb-2">
  <?= count($productores) ?> productores con entregas entre <?= e((new DateTimeImmutable($desde))->format('d/m/Y')) ?> y <?= e((new DateTimeImmutable($hasta))->format('d/m/Y')) ?>:
  <strong><?= e(num($totLitros, 1)) ?> L</strong> por <strong>$<?= e(num($totMonto)) ?></strong>.
  <span class="d-block mt-1"><strong>Imprimir / Guardar PDF</strong> abre el HTML plano y el diálogo de impresión. <strong>Descargar Imágenes</strong> genera un ZIP en cola (una liquidación a la vez) para no congelar el navegador.</span>
</p>

<?php if ($productores === []): ?>
  <div class="card"><div class="card-body text-center text-muted py-4">No hay entregas en el período seleccionado.</div></div>
<?php else: ?>
<form method="post" action="<?= e(url('productores_impresion_masiva')) ?>" id="formLiquidaciones"      data-html-url="<?= e(url('productores_liquidacion_html')) ?>"
      data-desde="<?= e($desde) ?>"
      data-hasta="<?= e($hasta) ?>">
<?= csrf_field() ?>
<input type="hidden" name="autoprint" value="1">
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
      <a class="btn btn-sm btn-link text-decoration-none p-0" href="<?= e(url('productores_liquidacion', ['id' => $pid, 'desde' => $desde, 'hasta' => $hasta])) ?>" aria-label="Liquidación de <?= e($p['nombre']) ?>"><i class="bi bi-file-earmark-pdf"></i></a>
      <a class="btn btn-sm btn-link text-decoration-none p-0" href="<?= e(url('productor_control', ['id' => $pid, 'tab' => 'historial', 'desde' => $desde, 'hasta' => $hasta])) ?>" aria-label="Ver cuenta de <?= e($p['nombre']) ?>"><i class="bi bi-eye"></i></a>
      <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none p-0 fw-bold" data-excluir aria-label="Excluir <?= e($p['nombre']) ?>">×</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
</form>
<div id="fmZipStage" aria-hidden="true"></div>
<div class="modal fade" id="fmZipModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="fmZipStatus" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <div class="fw-semibold mb-3" id="fmZipStatus">Generando imágenes... 0%</div>
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
          <div class="progress-bar bg-success" id="fmZipBar" style="width:0%"></div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/file-saver@2.0.5/dist/FileSaver.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
<script src="<?= e(asset_v('js/facturas_masivas.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<?php endif; ?>

<?php
layout_end();
