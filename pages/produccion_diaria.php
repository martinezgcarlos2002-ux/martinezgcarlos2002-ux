<?php
declare(strict_types=1);

/**
 * @var list<array<string, mixed>> $filas
 * @var list<array<string, mixed>> $productos
 * @var list<array{id:int, nombre:string}> $almacenes
 * @var array<int, array<int, float|string>> $stockPorAlmacen
 * @var string $fecha
 * @var string $hoy
 * @var bool $puedeCrear
 */
$productos = $productos ?? [];
$almacenes = $almacenes ?? [];
$stockPorAlmacen = $stockPorAlmacen ?? [];
$fecha = (isset($fecha) && is_string($fecha) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) ? $fecha : date('Y-m-d');
$puedeCrear = $puedeCrear ?? false;

if (!isset($filas)) {
    $filas = [];
    foreach ($productos as $p) {
        $filas[] = [
            'id'          => (int) $p['id'],
            'nombre'      => (string) $p['nombre'],
            'unidad'      => (string) ($p['unidad'] ?? ''),
            'unidad_medida' => Producto::uom((string) ($p['unidad_medida'] ?? 'KG')),
            'precio'      => (string) ($p['precio_usd'] ?? '0'),
            'cantidad'    => '',
            'distribuido' => '0',
            'inventario'  => (string) $p['nombre'],
        ];
    }
}

$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$stamp = strtotime($fecha) ?: time();
$etiqueta = date('d-m-Y', $stamp) . ' (' . ucfirst($dias[(int) date('w', $stamp)]) . ')';
$fmtQty = static function (float $n): string {
    $texto = num($n, 3);
    return rtrim(rtrim($texto, '0'), ',') ?: '0';
};
$conPendiente = array_values(array_filter(
    $filas,
    static fn (array $f): bool => !empty($f['produccion_id']) && (float) ($f['pendiente'] ?? 0) > 0
));
$semanaRes = ProduccionCatalogoService::semanaDe($fecha);

layout_start('Producción Diaria', 'produccion');
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3 prod-toolbar">
  <div>
    <h1 class="h4 mb-0">Producción Diaria</h1>
    <p class="prod-kicker">Inventario General de Producción Diaria</p>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <form method="get" action="<?= e(base_path() . 'index.php') ?>" id="formFecha" class="m-0">
      <input type="hidden" name="p" value="produccion">
      <input type="date" name="fecha" id="inpFecha" class="form-control form-control-sm" value="<?= e($fecha) ?>" max="<?= e($hoy ?? date('Y-m-d')) ?>" aria-label="Fecha de producción">
    </form>
    <?php if ($puedeCrear): ?>
      <button type="button" class="btn btn-sm btn-dist" data-bs-toggle="modal" data-bs-target="#modalDist"<?= $conPendiente === [] ? ' disabled title="No hay producción pendiente de distribuir"' : '' ?>><i class="bi bi-box-arrow-right"></i> Distribuir a almacenes</button>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-stock" data-bs-toggle="modal" data-bs-target="#modalStockAlm"><i class="bi bi-boxes"></i> Stock por almacén</button>
    <a class="btn btn-sm btn-pdf" href="<?= e(url('produccion_diaria_pdf', ['fecha' => $fecha])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> PDF del día</a>
    <button type="button" class="btn btn-sm btn-mes" id="btnGenerarResumen"
            data-url-dashboard="<?= e(url('resumen')) ?>"
            data-url-pdf="<?= e(url('resumen_pdf_rango')) ?>"><i class="bi bi-calendar3"></i> Generar Resumen</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('produccion_catalogo')) ?>"><i class="bi bi-grid"></i> Catálogo</a>
  </div>
</div>

<div class="prod-week">
  <span>SEMANA / FECHA: <?= e($etiqueta) ?></span>
  <span>TOTAL DEL DÍA: $ <span id="totalDia">0,00</span></span>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
  <div class="form-check mb-0">
    <input class="form-check-input" type="checkbox" id="marcaDia" checked>
    <label class="form-check-label" for="marcaDia">Producción del día <?= e($fecha) ?></label>
  </div>
  <?php if ($puedeCrear): ?>
    <button type="submit" form="formDia" class="btn btn-guardar-dia" id="btnGuardarDia"><i class="bi bi-save"></i> Guardar producción del día</button>
  <?php endif; ?>
</div>

<div class="alert alert-info py-2 small">
  <i class="bi bi-info-circle"></i> <strong>Paso 1 — Registrar producción:</strong> tobos y potes son <strong>tara</strong> (peso del envase). Indica cuántos hay y el <strong>peso bruto de balanza</strong>. El queso que entra a inventario es <strong>Neto = Bruto − (envases × tara)</strong>. No se inventarían los envases.
</div>

<form method="post" action="<?= e(url('produccion_guardar')) ?>" id="formDia" autocomplete="off">
<?= csrf_field() ?>
<input type="hidden" name="fecha" value="<?= e($fecha) ?>">
<div class="card tbl-prod">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="tablaProd">
      <thead>
        <tr>
          <th class="text-center col-num">#</th>
          <th>Producto</th>
          <th class="text-center">Bruto / tara → neto</th>
          <th class="text-center">Precio unit</th>
          <th class="text-end">Total $</th>
          <th class="text-center">Distribuido</th>
          <th class="text-center">Pendiente</th>
          <th class="text-center">Estado</th>
          <th>Inventario</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($filas === []): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No hay productos de producción para esta fecha.</td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $i => $f):
          $precio = (float) ($f['precio'] ?? 0);
          $cant = ($f['cantidad'] ?? '') === '' ? 0.0 : (float) $f['cantidad'];
          $dist = (float) ($f['distribuido'] ?? 0);
          $pend = max(0, $cant - $dist);
          $listo = $cant > 0 && $pend <= 0.0001;
      ?>
        <tr data-precio="<?= e(number_format($precio, 2, '.', '')) ?>" data-distribuido="<?= e(number_format($dist, 3, '.', '')) ?>">
          <td class="text-center text-muted"><?= $i + 1 ?></td>
          <td class="prod-nombre"><?= e((string) $f['nombre']) ?> <span class="text-muted small"><?= e((string) ($f['unidad_medida'] ?? $f['unidad'] ?? 'KG')) ?><?php if (!empty($f['unidad_secundaria'])): ?> · tara <?= e((string) $f['unidad_secundaria']) ?><?php endif; ?></span></td>
          <td class="text-center">
            <?php
              $uSec = $f['unidad_secundaria'] ?? null;
              $taraModo = Producto::taraModo(is_string($uSec) ? $uSec : null);
              $taraOps = Producto::taraOpciones(is_string($uSec) ? $uSec : null);
              $taraSel = Producto::taraUnitaria(is_string($uSec) ? $uSec : null, $f['tara_unitaria'] ?? null);
              $brutoMostrar = (string) ($f['peso_bruto'] ?? '');
              if ($uSec && $brutoMostrar === '' && ($f['cantidad'] ?? '') !== '') {
                  $taraM = Producto::aMilesimas($taraSel);
                  $empM = (int) ($f['cantidad_secundaria'] ?? 0);
                  $brutoMostrar = rtrim(rtrim(Producto::desdeMilesimas(Producto::aMilesimas((string) $f['cantidad']) + $empM * $taraM), '0'), '.');
              }
            ?>
            <div class="<?= $uSec ? 'cw-inputs' : '' ?>" data-sec="<?= e((string) ($uSec ?? '')) ?>" data-tara-modo="<?= e($taraModo) ?>">
              <?php if ($uSec): ?>
              <label class="cw-lbl"><?= e((string) $uSec) ?> (envases)</label>
              <input type="text" inputmode="numeric" name="items[<?= (int) $f['id'] ?>][empaques]" class="form-control form-control-sm text-center inp-emp js-emp" value="<?= ((int) ($f['cantidad_secundaria'] ?? 0)) > 0 ? (int) $f['cantidad_secundaria'] : '' ?>" placeholder="<?= e((string) $uSec) ?>" aria-label="<?= e((string) $uSec) ?> de <?= e((string) $f['nombre']) ?>"<?= $puedeCrear ? '' : ' readonly' ?>>
              <?php if ($taraModo === 'variable'): ?>
              <label class="cw-lbl">Tara / envase</label>
              <select name="items[<?= (int) $f['id'] ?>][tara_unitaria]" class="form-select form-select-sm js-tara"<?= $puedeCrear ? '' : ' disabled' ?>>
                <?php foreach ($taraOps as $op): ?>
                  <option value="<?= e($op) ?>"<?= Producto::aMilesimas($op) === Producto::aMilesimas($taraSel) ? ' selected' : '' ?>><?= e(rtrim(rtrim($op, '0'), '.')) ?> kg</option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="hidden" name="items[<?= (int) $f['id'] ?>][tara_unitaria]" class="js-tara" value="<?= e($taraSel) ?>">
              <?php if ($taraModo === 'fija'): ?>
              <div class="cw-hint">Tara: <?= e(rtrim(rtrim($taraSel, '0'), '.')) ?> kg / <?= e((string) $uSec) ?></div>
              <?php endif; ?>
              <?php endif; ?>
              <label class="cw-lbl">Peso bruto (balanza)</label>
              <input type="text" inputmode="decimal" name="items[<?= (int) $f['id'] ?>][peso_bruto]" class="form-control form-control-sm text-center inp-bruto js-bruto" value="<?= e($brutoMostrar) ?>" placeholder="kg en balanza" aria-label="Peso bruto de <?= e((string) $f['nombre']) ?>"<?= $puedeCrear ? '' : ' readonly' ?>>
              <div class="cw-neto js-neto-lbl">Neto: <?= ($f['cantidad'] ?? '') !== '' ? e($fmtQty((float) $f['cantidad'])) . ' kg' : '—' ?></div>
              <input type="hidden" name="items[<?= (int) $f['id'] ?>][cantidad]" class="js-cant" value="<?= e((string) ($f['cantidad'] ?? '')) ?>">
              <?php else: ?>
              <input type="text" inputmode="decimal" name="items[<?= (int) $f['id'] ?>][cantidad]" class="form-control form-control-sm text-center inp-cant js-cant" value="<?= e((string) ($f['cantidad'] ?? '')) ?>" aria-label="Cantidad de <?= e((string) $f['nombre']) ?>"<?= $puedeCrear ? '' : ' readonly' ?>>
              <?php endif; ?>
            </div>
          </td>
          <td class="text-center"><?= e(num($precio)) ?></td>
          <td class="text-end fw-semibold js-sub">$<?= e(num($cant * $precio)) ?></td>
          <td class="text-center"><?= e($fmtQty($dist)) ?></td>
          <td class="text-center js-pend"><?= e($fmtQty($pend)) ?></td>
          <td class="text-center"><span class="badge <?= $listo ? 'badge-listo' : 'badge-pendiente' ?> js-estado"><?= $listo ? 'LISTO' : 'PENDIENTE' ?></span></td>
          <td><a class="link-inv" href="<?= e(url('inventario', ['q' => (string) ($f['inventario'] ?? $f['nombre'])])) ?>">→ <?= e((string) ($f['inventario'] ?? $f['nombre'])) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</form>

<?php if ($puedeCrear): ?>
<div class="modal fade" id="modalDist" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="<?= e(url('produccion_distribuir')) ?>" id="formDist" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-box-arrow-right"></i> Distribuir producción del <?= e($fecha) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 small mb-3">
            <strong>Paso 2:</strong> indica cuánto va a cada almacén. Lo distribuido se resta del pendiente y se suma al stock del producto y del almacén.
          </div>
          <?php if ($almacenes === []): ?>
            <p class="text-muted mb-0">No hay almacenes activos. Créalos desde Inventario → Almacenes.</p>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0" id="tablaDist">
              <thead class="table-light">
                <tr>
                  <th>Producto</th>
                  <th class="text-center">Pendiente</th>
                  <?php foreach ($almacenes as $i => $a): ?>
                    <th class="text-center th-alm-<?= ($i % 4) + 1 ?>"><?= e($a['nombre']) ?></th>
                  <?php endforeach; ?>
                  <th class="text-center">Queda</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($conPendiente as $f): ?>
                <tr data-pendiente="<?= e(number_format((float) $f['pendiente'], 3, '.', '')) ?>">
                  <td class="fw-semibold"><?= e((string) $f['nombre']) ?></td>
                  <td class="text-center"><?= e($fmtQty((float) $f['pendiente'])) ?> <div class="small text-muted">kg neto</div></td>
                  <?php foreach ($almacenes as $a): ?>
                    <td>
                      <input type="text" inputmode="decimal" class="form-control form-control-sm text-center js-dist"
                             name="dist[<?= (int) $f['produccion_id'] ?>][<?= (int) $a['id'] ?>]" value=""
                             placeholder="kg neto"
                             aria-label="<?= e($f['nombre'] . ' a ' . $a['nombre']) ?>">
                    </td>
                  <?php endforeach; ?>
                  <td class="text-center fw-bold js-queda"><?= e($fmtQty((float) $f['pendiente'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div id="alertaDist" class="alert alert-danger d-none py-2 mt-2 mb-0">La suma por producto no puede superar su pendiente.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-dist" id="btnDistribuir"<?= $almacenes === [] ? ' disabled' : '' ?>><i class="bi bi-check2"></i> Distribuir</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade modal-stock" id="modalStockAlm" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-box-seam"></i> Stock total por almacén</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <?php if ($almacenes === []): ?>
            <div class="col-12"><div class="card alm-total-card"><div class="card-body py-3 text-muted">No hay almacenes registrados.</div></div></div>
          <?php endif; ?>
          <?php foreach ($almacenes as $i => $a):
              $suma = 0.0;
              foreach ($stockPorAlmacen as $porAlm) {
                  $suma += (float) ($porAlm[(int) $a['id']] ?? 0);
              }
          ?>
            <div class="col-md-4">
              <div class="card alm-total-card tono-<?= ($i % 4) + 1 ?>">
                <div class="card-body py-2">
                  <div class="alm-nombre"><?= e($a['nombre']) ?></div>
                  <div class="alm-num"><?= e($fmtQty($suma)) ?></div>
                  <div class="alm-unidad">unidades totales</div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <input type="search" id="buscarStockAlm" class="form-control mb-1" placeholder="Buscar producto por nombre...">
        <div class="small text-muted mb-2"><?= count($filas) ?> productos en inventario</div>
        <div class="table-responsive card">
          <table class="table table-sm align-middle mb-0" id="tablaStockAlm">
            <thead>
              <tr>
                <th>Producto</th>
                <?php foreach ($almacenes as $i => $a): ?>
                  <th class="text-center th-alm-<?= ($i % 4) + 1 ?>"><?= e($a['nombre']) ?></th>
                <?php endforeach; ?>
                <th class="text-end th-total">Total</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($filas as $f):
                $pid = (int) ($f['id'] ?? 0);
                $total = 0.0;
            ?>
              <tr data-nombre="<?= e(mb_strtolower((string) $f['nombre'])) ?>">
                <td>
                  <strong><?= e((string) $f['nombre']) ?></strong>
                  <span class="text-muted small"> · <?= e((string) ($f['unidad_medida'] ?? $f['unidad'] ?? 'KG')) ?></span>
                </td>
                <?php foreach ($almacenes as $a):
                    $v = (float) ($stockPorAlmacen[$pid][(int) $a['id']] ?? 0);
                    $total += $v;
                ?>
                  <td class="text-center"><?= e($fmtQty($v)) ?></td>
                <?php endforeach; ?>
                <td class="text-end td-total"><?= e($fmtQty($total)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($filas === []): ?>
              <tr><td colspan="<?= count($almacenes) + 2 ?>" class="text-center text-muted py-4">Sin productos.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <a class="btn btn-outline-primary" href="<?= e(url('inventario')) ?>"><i class="bi bi-box-arrow-up-right"></i> Ir a inventario</a>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cerrar</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="modalResumenRango" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-calendar3"></i> Generar Resumen</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Elige el rango operativo. Puedes verlo en el tablero gerencial o descargar el PDF.</p>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label" for="resDesde">Desde</label>
            <input type="date" id="resDesde" class="form-control" value="<?= e($semanaRes['inicio']) ?>" max="<?= e($hoy ?? date('Y-m-d')) ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="resHasta">Hasta</label>
            <input type="date" id="resHasta" class="form-control" value="<?= e($fecha) ?>" max="<?= e($hoy ?? date('Y-m-d')) ?>">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-outline-danger" id="btnResumenPdf"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</button>
        <button type="button" class="btn btn-primary" id="btnResumenDash"><i class="bi bi-speedometer2"></i> Ver en Dashboard</button>
      </div>
    </div>
  </div>
</div>
<script src="<?= e(asset_v('js/catch_weight.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var money = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var qty = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
  function num(v) { return (window.AdaraTara && AdaraTara.parse) ? AdaraTara.parse(v) : (parseFloat(String(v).replace(',', '.')) || 0); }
  function aplicarTara(tr) {
    var box = tr.querySelector('.cw-inputs');
    if (!box || !window.AdaraTara) return;
    var sec = box.getAttribute('data-sec') || '';
    if (!sec) return;
    var emp = parseInt((tr.querySelector('.js-emp') || {}).value, 10) || 0;
    var taraEl = tr.querySelector('.js-tara');
    var tara = AdaraTara.unitaria(sec, taraEl ? taraEl.value : 0);
    var bruto = num((tr.querySelector('.js-bruto') || {}).value);
    var neto = AdaraTara.neto(bruto, emp, tara);
    var cant = tr.querySelector('.js-cant');
    var lbl = tr.querySelector('.js-neto-lbl');
    if (cant) cant.value = neto > 0 ? String(neto) : '';
    if (lbl) {
      lbl.textContent = bruto > 0
        ? ('Neto: ' + AdaraTara.fmt(Math.max(neto, 0)) + ' kg  ·  tara ' + AdaraTara.fmt(emp * tara) + ' kg')
        : 'Neto: —';
      lbl.classList.toggle('is-bad', bruto > 0 && neto <= 0);
    }
  }
  function recalcular() {
    var total = 0;
    document.querySelectorAll('#tablaProd tbody tr[data-precio]').forEach(function (tr) {
      aplicarTara(tr);
      var cant = num(tr.querySelector('.js-cant').value);
      var precio = num(tr.dataset.precio);
      var dist = num(tr.dataset.distribuido);
      var pend = Math.max(0, cant - dist);
      var sub = cant * precio;
      total += sub;
      tr.querySelector('.js-sub').textContent = '$' + money.format(sub);
      tr.querySelector('.js-pend').textContent = qty.format(pend);
      var badge = tr.querySelector('.js-estado');
      var listo = cant > 0 && pend <= 0.0001;
      badge.textContent = listo ? 'LISTO' : 'PENDIENTE';
      badge.className = 'badge js-estado ' + (listo ? 'badge-listo' : 'badge-pendiente');
    });
    document.getElementById('totalDia').textContent = money.format(total);
  }
  document.getElementById('tablaProd').addEventListener('input', function (e) {
    if (e.target.classList.contains('js-cant') || e.target.classList.contains('js-bruto') || e.target.classList.contains('js-emp') || e.target.classList.contains('js-tara')) recalcular();
  });
  document.getElementById('tablaProd').addEventListener('change', function (e) {
    if (e.target.classList.contains('js-tara')) recalcular();
  });
  document.getElementById('inpFecha').addEventListener('change', function () {
    if (this.value) document.getElementById('formFecha').submit();
  });

  var tablaDist = document.getElementById('tablaDist');
  if (tablaDist) {
    var recalcDist = function () {
      var excede = false;
      tablaDist.querySelectorAll('tbody tr').forEach(function (tr) {
        var suma = 0;
        tr.querySelectorAll('.js-dist').forEach(function (inp) { suma += num(inp.value); });
        var queda = num(tr.dataset.pendiente) - suma;
        var mal = queda < -0.0005;
        excede = excede || mal;
        tr.querySelector('.js-queda').textContent = qty.format(Math.max(queda, 0));
        tr.querySelectorAll('.js-dist').forEach(function (inp) { inp.classList.toggle('is-invalid', mal); });
      });
      document.getElementById('alertaDist').classList.toggle('d-none', !excede);
      document.getElementById('btnDistribuir').disabled = excede;
    };
    tablaDist.addEventListener('input', function (e) {
      if (e.target.classList.contains('js-dist')) recalcDist();
    });
  }
  var buscador = document.getElementById('buscarStockAlm');
  buscador.addEventListener('input', function () {
    var q = buscador.value.toLowerCase();
    document.querySelectorAll('#tablaStockAlm tbody tr[data-nombre]').forEach(function (tr) {
      tr.classList.toggle('d-none', q !== '' && tr.dataset.nombre.indexOf(q) === -1);
    });
  });
  recalcular();
})();
</script>
<script src="<?= e(asset_v('js/produccion_resumen.js')) ?>" nonce="<?= e(csp_nonce()) ?>" defer></script>
<?php
layout_end();
