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
            'unidad_secundaria' => Producto::unidadSecundaria(isset($p['unidad_secundaria']) ? (string) $p['unidad_secundaria'] : null),
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
$badgeAprox = static function (?string $sec, string $cant, int $emp): string {
    $kgM = ($cant !== '' && !preg_match('/^0+([.,]0*)?$/', str_replace(',', '.', $cant)))
        ? Producto::aMilesimas(str_replace(',', '.', $cant))
        : 0;
    if ($sec === 'Tobos') {
        if ($kgM > 0) {
            $base = 20000;
            $enteros = intdiv($kgM, $base);
            $resto = $kgM % $base;
            $num = $resto * 3;
            $piezas = intdiv($num, $base);
            if (($num % $base) * 2 >= $base) {
                $piezas++;
            }
            if ($piezas >= 3) {
                $enteros += intdiv($piezas, 3);
                $piezas %= 3;
            }
            return $piezas > 0 ? '≈ ' . $enteros . ' tobos y ' . $piezas . ' pzas' : '≈ ' . $enteros . ' tobos';
        }
        if ($emp > 0) {
            return '≈ ' . $emp . ' tobos (' . ($emp * 20) . ' kg)';
        }
    }
    if ($sec === 'Potes') {
        if ($kgM > 0) {
            $base = 10000;
            $enteros = intdiv($kgM, $base);
            $resto = $kgM % $base;
            $num = $resto * 10;
            $decima = intdiv($num, $base);
            if (($num % $base) * 2 >= $base) {
                $decima++;
            }
            if ($decima >= 10) {
                $enteros++;
                $decima = 0;
            }
            return $decima === 0 ? '≈ ' . $enteros . ' potes' : '≈ ' . $enteros . ',' . $decima . ' potes';
        }
        if ($emp > 0) {
            return '≈ ' . $emp . ' potes (' . ($emp * 10) . ' kg)';
        }
    }
    return '≈ …';
};

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
          <th class="text-center">Cantidad (KG ⇄ Tobos / Potes)</th>
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
          <td class="prod-nombre"><?= e((string) $f['nombre']) ?> <span class="text-muted small"><?= e((string) ($f['unidad_medida'] ?? $f['unidad'] ?? 'KG')) ?><?php if (!empty($f['unidad_secundaria'])): ?> · <?= e((string) $f['unidad_secundaria']) ?><?php endif; ?></span></td>
          <td class="text-center">
            <?php
              $uSec = is_string($f['unidad_secundaria'] ?? null) ? (string) $f['unidad_secundaria'] : '';
              $convierte = $uSec === 'Tobos' || $uSec === 'Potes';
              $uom = (string) ($f['unidad_medida'] ?? 'KG');
              $empGuardados = (int) ($f['cantidad_secundaria'] ?? 0);
              $cantMostrar = (string) ($f['cantidad'] ?? '');
              $solo = $puedeCrear ? '' : ' readonly';
            ?>
            <div class="d-inline-flex align-items-center gap-1 flex-nowrap js-conv" data-sec="<?= e($convierte ? $uSec : '') ?>">
              <input type="text" inputmode="decimal" name="items[<?= (int) $f['id'] ?>][cantidad]" class="form-control form-control-sm text-center js-cant prod-kg" value="<?= e($cantMostrar) ?>" placeholder="<?= $convierte ? 'kg' : e(strtolower($uom)) ?>" aria-label="Cantidad de <?= e((string) $f['nombre']) ?>"<?= $solo ?>>
              <span class="prod-uom"><?= e($convierte ? 'kg' : strtolower($uom)) ?></span>
              <?php if ($convierte): ?>
              <span class="prod-swap" aria-hidden="true">⇄</span>
              <input type="text" inputmode="numeric" name="items[<?= (int) $f['id'] ?>][empaques]" class="form-control form-control-sm text-center js-emp prod-emp" value="<?= $empGuardados > 0 ? (string) $empGuardados : '' ?>" placeholder="<?= e(strtolower($uSec)) ?>" aria-label="<?= e($uSec) ?> de <?= e((string) $f['nombre']) ?>"<?= $solo ?>>
              <span class="prod-uom"><?= e(strtolower($uSec)) ?></span>
              <span class="badge bg-light text-dark border js-aprox-lbl"><?= e($badgeAprox($uSec, $cantMostrar, $empGuardados)) ?></span>
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
                  <td class="text-center"><?= e($fmtQty((float) $f['pendiente'])) ?> <div class="small text-muted">kg</div></td>
                  <?php foreach ($almacenes as $a): ?>
                    <td>
                      <input type="text" inputmode="decimal" class="form-control form-control-sm text-center js-dist"
                             name="dist[<?= (int) $f['produccion_id'] ?>][<?= (int) $a['id'] ?>]" value=""
                             placeholder="kg"
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
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var money = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var qty = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
  var convirtiendo = false;
  function num(v) { return parseFloat(String(v == null ? '' : v).replace(/\s/g, '').replace(',', '.')) || 0; }
  function enteros(v) {
    var n = parseInt(String(v == null ? '' : v).replace(/\D/g, ''), 10);
    return n > 0 ? n : 0;
  }
  function tobosDesdeKg(kg) {
    var enterosT = Math.floor(kg / 20);
    var piezas = Math.round(((kg % 20) / 20) * 3);
    if (piezas === 3) { enterosT += 1; piezas = 0; }
    if (enterosT < 0) enterosT = 0;
    if (piezas < 0) piezas = 0;
    return { enteros: enterosT, piezas: piezas };
  }
  function textoTobos(pack) {
    return pack.piezas > 0 ? ('≈ ' + pack.enteros + ' tobos y ' + pack.piezas + ' pzas') : ('≈ ' + pack.enteros + ' tobos');
  }
  function textoPotes(kg) {
    var decimas = Math.round((kg / 10) * 10);
    var enterosP = Math.floor(decimas / 10);
    var dec = decimas % 10;
    return dec === 0 ? ('≈ ' + enterosP + ' potes') : ('≈ ' + enterosP + ',' + dec + ' potes');
  }
  function pintarBadge(tr) {
    var box = tr.querySelector('.js-conv');
    var lbl = tr.querySelector('.js-aprox-lbl');
    if (!box || !lbl) return;
    var sec = box.getAttribute('data-sec') || '';
    var origen = box.getAttribute('data-origen') || '';
    var kg = num((tr.querySelector('.js-cant') || {}).value);
    var emp = enteros((tr.querySelector('.js-emp') || {}).value);
    if (origen === 'emp' && emp > 0) {
      lbl.textContent = sec === 'Tobos'
        ? ('≈ ' + emp + ' tobos (' + (emp * 20) + ' kg)')
        : ('≈ ' + emp + ' potes (' + (emp * 10) + ' kg)');
      return;
    }
    if (sec === 'Tobos') {
      lbl.textContent = kg > 0 ? textoTobos(tobosDesdeKg(kg)) : (emp > 0 ? ('≈ ' + emp + ' tobos (' + (emp * 20) + ' kg)') : '≈ …');
    } else if (sec === 'Potes') {
      lbl.textContent = kg > 0 ? textoPotes(kg) : (emp > 0 ? ('≈ ' + emp + ' potes (' + (emp * 10) + ' kg)') : '≈ …');
    }
  }
  function convertir(tr, origen) {
    var box = tr.querySelector('.js-conv');
    if (!box) return;
    box.setAttribute('data-origen', origen);
    var sec = box.getAttribute('data-sec') || '';
    if (sec !== 'Tobos' && sec !== 'Potes') return;
    var cantEl = tr.querySelector('.js-cant');
    var empEl = tr.querySelector('.js-emp');
    var factor = sec === 'Tobos' ? 20 : 10;
    if (origen === 'cant' && cantEl && empEl) {
      var kg = num(cantEl.value);
      if (kg <= 0) {
        empEl.value = '';
      } else if (sec === 'Tobos') {
        empEl.value = String(tobosDesdeKg(kg).enteros);
      } else {
        empEl.value = String(Math.round(kg / factor));
      }
    } else if (origen === 'emp' && cantEl && empEl) {
      var emp = enteros(empEl.value);
      cantEl.value = emp > 0 ? String(emp * factor) : '';
    }
    pintarBadge(tr);
  }
  function recalcular() {
    var total = 0;
    document.querySelectorAll('#tablaProd tbody tr[data-precio]').forEach(function (tr) {
      pintarBadge(tr);
      var cantEl = tr.querySelector('.js-cant');
      var cant = num(cantEl ? cantEl.value : 0);
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
    if (convirtiendo) return;
    var origen = e.target.classList.contains('js-cant') ? 'cant' : (e.target.classList.contains('js-emp') ? 'emp' : '');
    if (!origen) return;
    var tr = e.target.closest('tr');
    if (!tr) return;
    convirtiendo = true;
    convertir(tr, origen);
    convirtiendo = false;
    recalcular();
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
