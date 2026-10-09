<?php
declare(strict_types=1);

/**
 * Administración del catálogo publicitario.
 *
 * @var list<array<string, mixed>> $productos
 * @var string|null $tasa
 * @var bool $puedeEditar
 * @var array<string, string> $endpoints
 */
layout_start('Catálogo y Publicidad', 'catalogo');
?>
<link rel="stylesheet" href="<?= e(asset_v('css/catalogo.css')) ?>">
<div id="catApp" data-tasa="<?= e($tasa ?? '') ?>" data-editar="<?= $puedeEditar ? '1' : '0' ?>"
     data-endpoints="<?= e(json_encode($endpoints, JSON_THROW_ON_ERROR)) ?>">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h5 mb-0"><i class="bi bi-display"></i> Catálogo y Publicidad</h1>
      <div class="small text-muted">Precios al detal, fotos y pantalla de TV. Los productos se crean en Producción / Inventario.</div>
    </div>
    <div class="d-flex flex-wrap align-items-end gap-2">
      <div>
        <label class="form-label small mb-1" for="catTasa">Tasa de Cambio (Bs/$)</label>
        <div class="input-group input-group-sm">
          <input class="form-control" id="catTasa" name="tasa" inputmode="decimal" value="<?= e($tasa ?? '') ?>" placeholder="Ej. 185.50"<?= $puedeEditar ? '' : ' disabled' ?>>
          <?php if ($puedeEditar): ?>
          <button type="button" class="btn btn-warning" id="btnGuardarTasa"><i class="bi bi-save"></i> Guardar</button>
          <?php endif; ?>
        </div>
      </div>
      <a class="btn btn-sm btn-outline-danger rounded-pill" href="<?= e($endpoints['pdf']) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Generar Lista PDF</a>
      <a class="btn btn-sm btn-dark rounded-pill" href="<?= e($endpoints['tv']) ?>" target="_blank" rel="noopener"><i class="bi bi-tv"></i> Abrir Pantalla Pública</a>
    </div>
  </div>

  <div class="card shadow-sm border-0 rounded-4">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr class="small text-uppercase text-muted">
            <th>Foto</th>
            <th>Producto</th>
            <th class="text-end">Precio producción</th>
            <th class="text-end">Precio detal $</th>
            <th>Cambiar foto</th>
            <th class="text-center">Mostrar en pantalla</th>
          </tr>
        </thead>
        <tbody id="tbCatalogo"></tbody>
      </table>
    </div>
  </div>
</div>

<script type="application/json" id="catDatos"><?= json_encode($productos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
<div class="toast-container position-fixed top-0 end-0 p-3" id="catToasts" aria-live="polite"></div>
<script src="<?= e(asset_v('js/catalogo.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<?php
layout_end();
