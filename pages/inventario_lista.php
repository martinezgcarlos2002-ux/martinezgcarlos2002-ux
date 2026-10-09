<?php
declare(strict_types=1);

/**
 * @var list<array{id:int, codigo:?string, nombre:string, unidad:string, precio_usd:string, stock:string, umbral_alerta:string}> $productos
 * @var list<array{id:int, nombre:string}> $almacenes
 * @var array<int, array<int, string>> $stockPorAlmacen
 * @var string $q
 * @var float|null $tasa
 * @var bool $puedeCrear
 * @var bool $puedeEditar
 * @var bool $esAdmin
 */
$productos = $productos ?? [];
$almacenes = $almacenes ?? [];
$stockPorAlmacen = $stockPorAlmacen ?? [];
$q = $q ?? '';
$tasa = $tasa ?? null;
$puedeCrear = $puedeCrear ?? false;
$puedeEditar = $puedeEditar ?? false;
$esAdmin = $esAdmin ?? false;

$unidades = Producto::UOM;
$unidadCorta = ['KG' => 'kg', 'LTS' => 'L', 'UND' => 'uds', 'kg' => 'kg', 'unidad' => 'uds', 'litro' => 'L'];
$fmtQty = static function (float $n): string {
    $texto = num($n, 3);
    return rtrim(rtrim($texto, '0'), ',') ?: '0';
};
$cols = $esAdmin ? 7 : 6;

layout_start('Inventario de Productos', 'inventario');
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <form class="d-flex gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
    <input type="hidden" name="p" value="inventario">
    <input type="search" name="q" class="form-control form-control-sm w-search" placeholder="Buscar producto..." value="<?= e($q) ?>" maxlength="60">
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> Buscar</button>
    <?php if ($q !== ''): ?>
      <a href="<?= e(url('inventario')) ?>" class="btn btn-sm btn-outline-danger" aria-label="Limpiar búsqueda">✕</a>
    <?php endif; ?>
  </form>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-sm btn-pdf" id="btnPrintInv"><i class="bi bi-printer"></i> Imprimir / PDF</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalAlm"><i class="bi bi-building"></i> Almacenes</button>
    <?php if ($puedeCrear): ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalProd"><i class="bi bi-plus-lg"></i> Nuevo</button>
    <?php endif; ?>
  </div>
</div>

<div class="card tbl-inv">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Estado</th>
          <th>Stock total</th>
          <th>Nivel</th>
          <th>Por almacén</th>
          <th>Precio</th>
          <?php if ($esAdmin): ?><th class="text-end">Acciones</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if ($productos === []): ?>
        <tr><td colspan="<?= $cols ?>" class="empty-state">Sin productos.<?= $puedeCrear ? ' Haz clic en <b>+ Nuevo</b> para agregar.' : '' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($productos as $p):
          $stk = (float) $p['stock'];
          $umb = (float) $p['umbral_alerta'];
          $ref = $umb > 0 ? $umb * 2 : max($stk, 1);
          $pct = (int) (round(min(100, $ref > 0 ? $stk / $ref * 100 : 0) / 5) * 5);
          if ($umb > 0 && $stk <= $umb) {
              [$cls, $estado] = ['crit', 'CRÍTICO'];
          } elseif ($umb > 0 && $stk <= $umb * 2) {
              [$cls, $estado] = ['warn', 'BAJO'];
          } else {
              [$cls, $estado] = ['ok', 'OK'];
          }
          $usd = (float) $p['precio_usd'];
          $pid = (int) $p['id'];
      ?>
        <tr>
          <td>
            <div class="nombre"><?= e($p['nombre']) ?></div>
            <?php if (!empty($p['codigo'])): ?><div class="codigo"><?= e($p['codigo']) ?></div><?php endif; ?>
          </td>
          <td><span class="prod-state <?= e($cls) ?>"><?= e($estado) ?></span></td>
          <td>
            <span class="big-stock"><?= e($fmtQty($stk)) ?></span>
            <?php
              $uomMostrar = Producto::uom((string) ($p['unidad_medida'] ?? (Producto::UNIDAD_A_UOM[$p['unidad']] ?? 'KG')));
            ?>
            <div class="unit-small"><?= e($unidadCorta[$uomMostrar] ?? $uomMostrar) ?></div>
          </td>
          <td>
            <div class="stockbar-wrap"><div class="stockbar-fill <?= e($cls) ?> lvl-<?= $pct ?>"></div></div>
            <div class="stockbar-meta"><?= $pct ?>%<?= $umb > 0 ? ' · Mín: ' . e($fmtQty($umb)) : '' ?></div>
          </td>
          <td>
            <div class="alm-row">
              <?php if ($almacenes === []): ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
              <?php foreach ($almacenes as $a):
                  $v = (float) ($stockPorAlmacen[$pid][(int) $a['id']] ?? 0);
              ?>
                <div class="alm-chip <?= $v > 0 ? 'has' : 'zero' ?>" title="<?= e($a['nombre']) ?>">
                  <div class="name"><?= e($a['nombre']) ?></div>
                  <div class="val"><?= e($fmtQty($v)) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </td>
          <td class="prices-cell">
            <div class="usd">$<?= e(num($usd)) ?></div>
            <div class="ves">Bs. <?= $tasa === null ? '—' : e(num($usd * $tasa)) ?></div>
          </td>
          <?php if ($esAdmin): ?>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-outline-primary js-ajuste" data-bs-toggle="modal" data-bs-target="#modalAjuste"
                    data-id="<?= $pid ?>" data-nombre="<?= e($p['nombre']) ?>"
                    data-stock="<?= e(json_encode($stockPorAlmacen[$pid] ?? new stdClass(), JSON_THROW_ON_ERROR)) ?>">Stock</button>
            <form method="post" action="<?= e(url('inventario_eliminar')) ?>" class="d-inline js-confirm"
                  data-confirm="<?= e('¿Eliminar ' . $p['nombre'] . '? Si tiene ventas o producción se archivará.') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $pid ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modalAlm" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-building"></i> Mis almacenes (<?= count($almacenes) ?>)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Cambia el nombre de cada almacén como prefieras. Estos nombres se mostrarán en Producción Diaria y en Ventas. Para borrar uno, usa el botón a su derecha; se eliminará de todas las pantallas y, si tenía stock, se pasará automáticamente al primer almacén (no se pierde inventario).</p>
        <?php if ($almacenes === []): ?>
          <p class="text-muted small mb-2">Todavía no hay almacenes.</p>
        <?php else: ?>
        <form method="post" action="<?= e(url('almacenes_guardar')) ?>">
          <?= csrf_field() ?>
          <?php foreach ($almacenes as $a): ?>
            <div class="mb-2 d-flex align-items-end gap-2">
              <div class="flex-grow-1">
                <label class="form-label small mb-0" for="alm-<?= (int) $a['id'] ?>">Almacén #<?= (int) $a['id'] ?></label>
                <input id="alm-<?= (int) $a['id'] ?>" name="nombre[<?= (int) $a['id'] ?>]" class="form-control" value="<?= e($a['nombre']) ?>" minlength="2" maxlength="60" required>
              </div>
              <?php if ($esAdmin && count($almacenes) > 1): ?>
                <button type="button" class="btn btn-outline-danger js-alm-del" title="Eliminar este almacén"
                        data-id="<?= (int) $a['id'] ?>" data-nombre="<?= e($a['nombre']) ?>"><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($puedeEditar): ?>
            <div class="text-end mt-2">
              <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> Guardar nombres</button>
            </div>
          <?php endif; ?>
        </form>
        <?php endif; ?>
        <?php if ($esAdmin): ?>
          <form method="post" action="<?= e(url('almacenes_eliminar')) ?>" id="formAlmDel" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="almDelId" value="">
          </form>
        <?php endif; ?>
        <?php if ($puedeCrear): ?>
        <hr class="my-3">
        <form method="post" action="<?= e(url('almacenes_nuevo')) ?>">
          <?= csrf_field() ?>
          <label class="form-label small mb-1 fw-bold" for="nombre_nuevo"><i class="bi bi-plus-lg"></i> Crear un nuevo almacén</label>
          <div class="input-group">
            <input id="nombre_nuevo" name="nombre_nuevo" class="form-control" placeholder="Nombre del nuevo almacén" minlength="2" maxlength="60" required>
            <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg"></i> Agregar</button>
          </div>
          <div class="form-text">Se añadirá a la lista de arriba y aparecerá en todas las pantallas que usan almacenes.</div>
        </form>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cerrar</button>
      </div>
    </div>
  </div>
</div>

<?php if ($puedeCrear): ?>
<div class="modal fade" id="modalProd" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Nuevo Producto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="<?= e(url('inventario')) ?>">
          <?= csrf_field() ?>
          <div class="row g-2">
            <div class="col-8">
              <label class="form-label" for="nombre">Nombre</label>
              <input id="nombre" name="nombre" class="form-control" maxlength="100" required>
            </div>
            <div class="col-4">
              <label class="form-label" for="codigo">Código</label>
              <input id="codigo" name="codigo" class="form-control" maxlength="30">
            </div>
            <div class="col-4">
              <label class="form-label" for="precio_usd">Precio</label>
              <input id="precio_usd" name="precio_usd" type="text" inputmode="decimal" class="form-control" value="0,00" required>
            </div>
            <div class="col-4">
              <label class="form-label" for="stock">Stock</label>
              <input id="stock" name="stock" type="text" inputmode="decimal" class="form-control" value="0"<?= $esAdmin ? '' : ' disabled title="Solo administrador"' ?>>
            </div>
            <div class="col-4">
              <label class="form-label" for="umbral_alerta">Alerta mín.</label>
              <input id="umbral_alerta" name="umbral_alerta" type="text" inputmode="decimal" class="form-control" value="0">
            </div>
            <div class="col-6">
              <label class="form-label" for="moneda">Moneda</label>
              <select id="moneda" name="moneda" class="form-select">
                <option value="USD" selected>USD</option>
                <option value="VES">VES</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="unidad_medida">Unidad de medida</label>
              <select id="unidad_medida" name="unidad_medida" class="form-select">
                <?php foreach ($unidades as $valor => $etiqueta): ?>
                  <option value="<?= e($valor) ?>"<?= $valor === 'KG' ? ' selected' : '' ?>><?= e($etiqueta) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php if ($esAdmin && $almacenes !== []): ?>
            <div class="form-text">El stock inicial entra a <?= e($almacenes[0]['nombre']) ?> y queda en el kardex como ajuste.</div>
          <?php endif; ?>
          <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-square"></i> Guardar</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($esAdmin): ?>
<div class="modal fade" id="modalAjuste" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= e(url('inventario_ajuste')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="producto_id" id="ajProducto" value="">
        <div class="modal-header">
          <h5 class="modal-title">Ajustar stock · <span id="ajNombre"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-7">
              <label class="form-label" for="ajAlmacen">Almacén</label>
              <select id="ajAlmacen" name="almacen_id" class="form-select" required>
                <?php foreach ($almacenes as $a): ?>
                  <option value="<?= (int) $a['id'] ?>"><?= e($a['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Stock actual: <strong id="ajActual">0</strong></div>
            </div>
            <div class="col-5">
              <label class="form-label" for="ajNuevo">Nuevo stock</label>
              <input id="ajNuevo" name="stock_nuevo" type="text" inputmode="decimal" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label" for="ajMotivo">Motivo</label>
              <input id="ajMotivo" name="motivo" class="form-control" minlength="5" maxlength="255" placeholder="Ej.: conteo físico, merma, vencido" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Ajustar</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  document.getElementById('btnPrintInv').addEventListener('click', function () { window.print(); });

  document.querySelectorAll('form.js-confirm').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  });

  document.querySelectorAll('.js-alm-del').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('¿Eliminar el almacén "' + btn.dataset.nombre + '"? Su stock pasará al primer almacén de la lista.')) return;
      document.getElementById('almDelId').value = btn.dataset.id;
      document.getElementById('formAlmDel').submit();
    });
  });

  var stockActual = {};
  var sel = document.getElementById('ajAlmacen');
  function pintarActual() {
    if (!sel) return;
    var v = stockActual[sel.value] || '0';
    document.getElementById('ajActual').textContent = v;
    document.getElementById('ajNuevo').value = v;
  }
  document.querySelectorAll('.js-ajuste').forEach(function (btn) {
    btn.addEventListener('click', function () {
      stockActual = JSON.parse(btn.dataset.stock || '{}');
      document.getElementById('ajProducto').value = btn.dataset.id;
      document.getElementById('ajNombre').textContent = btn.dataset.nombre;
      document.getElementById('ajMotivo').value = '';
      pintarActual();
    });
  });
  if (sel) sel.addEventListener('change', pintarActual);
})();
</script>
<?php
layout_end();
