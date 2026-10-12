<?php
declare(strict_types=1);

/**
 * Catálogo de productos de producción.
 *
 * @var list<array<string, mixed>> $items
 * @var bool $puedeCrear
 * @var bool $puedeEditar
 * @var array<string, string> $endpoints
 */
$items = $items ?? [];
$puedeCrear = $puedeCrear ?? false;
$puedeEditar = $puedeEditar ?? false;
$endpoints = $endpoints ?? ['guardar' => '', 'eliminar' => ''];

layout_start('Catálogo de Producción', 'produccion');
?>
<div id="prodCatApp"
     data-crear="<?= $puedeCrear ? '1' : '0' ?>"
     data-editar="<?= $puedeEditar ? '1' : '0' ?>"
     data-endpoints="<?= e(json_encode($endpoints, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h1 class="h4 mb-0">Catálogo de productos de producción</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('produccion')) ?>"><i class="bi bi-arrow-left"></i> Volver al día</a>
      <?php if ($puedeCrear): ?>
      <button type="button" class="btn btn-sm btn-primary" id="btnNuevoCat"><i class="bi bi-plus-lg"></i> Agregar producto</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="alert alert-info py-2 small">Define aquí los productos que se producen. Al guardar se crean automáticamente en Inventario. El precio de producción alimenta la planilla diaria y el precio al detal aparece en el Catálogo para televisión.</div>
  <div id="catToast" class="alert d-none py-2" role="status"></div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="tablaCatalogo">
        <thead class="table-light">
          <tr>
            <th>Orden</th>
            <th>Producto</th>
            <th>Descripción</th>
            <th>UoM</th>
            <th>Producción ($)</th>
            <th>Detal ($)</th>
            <th>TV</th>
            <th>Estado</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody id="catBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script type="application/json" id="catDatos"><?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>

<div class="modal fade" id="modalCatalogo" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="formCatalogo" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="catTitulo">Agregar producto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="cat-id" name="id" value="">
          <div class="row g-2">
            <div class="col-8">
              <label class="form-label" for="cat-nombre">Producto</label>
              <input id="cat-nombre" name="nombre" class="form-control" maxlength="100" required>
              <div class="invalid-feedback" data-error="nombre"></div>
            </div>
            <div class="col-4">
              <label class="form-label" for="cat-orden">Orden</label>
              <input id="cat-orden" name="orden" class="form-control" inputmode="numeric" value="0">
              <div class="invalid-feedback" data-error="orden"></div>
            </div>
            <div class="col-6">
              <label class="form-label" for="cat-prod">Producción ($)</label>
              <input id="cat-prod" name="precio_produccion" class="form-control" inputmode="decimal" value="0,00">
              <div class="invalid-feedback" data-error="precio_produccion"></div>
            </div>
            <div class="col-6">
              <label class="form-label" for="cat-det">Detal ($)</label>
              <input id="cat-det" name="precio_detal" class="form-control" inputmode="decimal" value="0,00">
              <div class="invalid-feedback" data-error="precio_detal"></div>
            </div>
            <div class="col-6">
              <label class="form-label" for="cat-uom">Unidad de medida</label>
              <select id="cat-uom" name="unidad_medida" class="form-select">
                <option value="KG" selected>Kilogramos (KG)</option>
                <option value="LTS">Litros (LTS)</option>
                <option value="UND">Unidades (UND)</option>
              </select>
              <div class="invalid-feedback" data-error="unidad_medida"></div>
            </div>
            <div class="col-6">
              <label class="form-label" for="cat-sec">Empaque variable</label>
              <select id="cat-sec" name="unidad_secundaria" class="form-select">
                <option value="">Ninguno</option>
                <option value="Tobos">Tobos</option>
                <option value="Piezas">Piezas</option>
                <option value="Potes">Potes</option>
              </select>
              <div class="form-text">Tobos/potes = tara a restar del bruto. El stock es solo queso neto. Mozzarella (Piezas): peso directo.</div>
            </div>
            <div class="col-12">
              <label class="form-label" for="cat-desc">Descripción</label>
              <textarea id="cat-desc" name="descripcion" class="form-control" rows="3" maxlength="2000" placeholder="Opcional: notas de elaboración, presentación o merma"></textarea>
              <div class="invalid-feedback" data-error="descripcion"></div>
            </div>
          </div>
          <div class="invalid-feedback d-block" data-error="general"></div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary" id="btnGuardarCat">Guardar</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script src="<?= e(asset_v('js/produccion_catalogo.js')) ?>" nonce="<?= e(csp_nonce()) ?>" defer></script>
<?php
layout_end();
