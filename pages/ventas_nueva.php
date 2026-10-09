<?php
declare(strict_types=1);

/**
 * @var list<array{id:int, nombre:string, unidad:string, precio_usd:string, stock:string}> $productos
 * @var list<array{id:int, nombre:string}> $clientes
 * @var float|null $tasa  Bs por USD vigente; null si aún no hay tasa
 * @var array<string, string> $tipos
 * @var array<string, string> $entidades
 * @var array<string, string> $metodos
 * @var string $buscarUrl
 */
$tipos = $tipos ?? Venta::TIPOS_CLIENTE;
$entidades = $entidades ?? Venta::ENTIDADES;
$metodos = $metodos ?? Venta::METODOS_PAGO;
$buscarUrl = $buscarUrl ?? url('ventas_buscar_entidades');
$catalogo = array_map(static fn (array $p): array => [
    'id'      => (int) $p['id'],
    'nombre'  => $p['nombre'],
    'unidad'  => $p['unidad'],
    'precio'  => (float) $p['precio_usd'],
    'stock'   => (float) $p['stock'],
], $productos);

layout_start('Nueva venta', 'ventas');
?>
<form method="post" action="<?= e(url('ventas_nueva')) ?>" id="formVenta" autocomplete="off">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-header">Datos de la venta</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="tipo_cliente">Tipo de cliente <span class="text-danger">*</span></label>
              <select name="tipo_cliente" id="tipo_cliente" class="form-select" required>
                <?php foreach ($tipos as $k => $lbl): ?>
                  <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4" id="wrapClientePos">
              <label class="form-label fw-semibold" for="cliente_id">Cliente <span class="text-danger">*</span></label>
              <select name="cliente_id" id="cliente_id" class="form-select">
                <option value="">— Seleccionar —</option>
                <?php foreach ($clientes as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 d-none position-relative" id="wrapEntidad">
              <label class="form-label fw-semibold" for="entidad_tipo">Entidad exclusiva</label>
              <select name="entidad_tipo" id="entidad_tipo" class="form-select mb-2">
                <?php foreach ($entidades as $k => $lbl): ?>
                  <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="search" id="entidad_q" class="form-control" placeholder="Buscar nombre o documento…" autocomplete="off">
              <input type="hidden" name="entidad_id" id="entidad_id" value="">
              <div id="entidadSel" class="small text-muted mt-1">Sin selección.</div>
              <div id="entidadList" class="list-group position-absolute shadow-sm d-none" style="z-index:20;max-height:220px;overflow:auto;min-width:240px"></div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="fecha">Fecha</label>
              <input type="date" name="fecha" id="fecha" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="metodo_pago">Método de pago <span class="text-danger">*</span></label>
              <select name="metodo_pago" id="metodo_pago" class="form-select">
                <?php foreach ($metodos as $k => $lbl): ?>
                  <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="moneda_pago">Moneda de cobro</label>
              <select name="moneda_pago" id="moneda_pago" class="form-select">
                <option value="USD">USD</option>
                <option value="VES">VES (Bs)</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Productos</span>
          <button type="button" class="btn btn-sm btn-outline-success" id="btnAgregar">+ Agregar producto</button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0" id="tablaItems">
            <thead class="table-light">
              <tr>
                <th style="min-width:220px">Producto</th>
                <th class="text-center" style="width:110px">Stock</th>
                <th class="text-center" style="width:120px">Cantidad</th>
                <th style="width:130px">Precio unit.</th>
                <th class="text-end" style="width:130px">Subtotal</th>
                <th style="width:50px"></th>
              </tr>
            </thead>
            <tbody id="itemsBody"></tbody>
          </table>
        </div>
        <div class="card-body">
          <div id="alertaStock" class="alert alert-danger d-none py-2 mb-0">La cantidad supera el stock disponible.</div>
          <p id="vacio" class="text-muted text-center mb-0">Agrega al menos un producto para cobrar.</p>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card pos-ticket">
        <div class="card-header">Cobro</div>
        <div class="card-body">
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Tasa vigente</span>
            <strong><?= $tasa === null ? 'Sin tasa' : '1 USD = Bs. ' . e(num($tasa)) ?></strong>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span>Subtotal</span>
            <strong id="sumSubtotal">$0,00</strong>
          </div>
          <hr>
          <div class="d-flex justify-content-between align-items-baseline mb-1">
            <span>Total USD</span>
            <span class="pos-total" id="sumUsd">$0,00</span>
          </div>
          <div class="d-flex justify-content-between align-items-baseline mb-3">
            <span>Total Bs</span>
            <span class="pos-total-bs" id="sumBs"><?= $tasa === null ? 'Bs. —' : 'Bs. 0,00' ?></span>
          </div>
          <button type="submit" class="btn btn-primary w-100" id="btnCobrar" disabled>Cobrar</button>
        </div>
      </div>
    </div>
  </div>
</form>

<template id="tplFila">
  <tr class="pos-line">
    <td>
      <select class="form-select form-select-sm sel-prod" required>
        <option value="">— Seleccionar —</option>
      </select>
    </td>
    <td class="text-center"><small class="stock-disp text-muted">—</small></td>
    <td>
      <input type="number" class="form-control form-control-sm text-center inp-cant" min="0" step="0.001" value="1" required>
    </td>
    <td>
      <input type="number" class="form-control form-control-sm text-end inp-precio" min="0" step="0.01" value="0" required>
    </td>
    <td class="text-end fw-semibold subtotal">$0,00</td>
    <td class="text-center">
      <button type="button" class="btn btn-sm btn-outline-danger btn-quitar" aria-label="Quitar">✕</button>
    </td>
  </tr>
</template>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var PRODS = <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>;
  var TASA = <?= json_encode($tasa, JSON_THROW_ON_ERROR) ?>;
  var BUSCAR = <?= json_encode($buscarUrl, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
  var prodMap = {};
  PRODS.forEach(function (p) { prodMap[p.id] = p; });

  var body = document.getElementById('itemsBody');
  var tpl = document.getElementById('tplFila');
  var idx = 0;
  var money = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function opciones() {
    return PRODS.map(function (p) {
      return '<option value="' + p.id + '">' + p.nombre + ' (' + p.unidad + ')</option>';
    }).join('');
  }

  function fila(tr) { return tr.closest('tr'); }

  function agregar() {
    var tr = tpl.content.firstElementChild.cloneNode(true);
    var n = idx++;
    var sel = tr.querySelector('.sel-prod');
    sel.name = 'items[' + n + '][producto_id]';
    sel.insertAdjacentHTML('beforeend', opciones());
    tr.querySelector('.inp-cant').name = 'items[' + n + '][cantidad]';
    tr.querySelector('.inp-precio').name = 'items[' + n + '][precio_unit_usd]';
    body.appendChild(tr);
    document.getElementById('vacio').classList.add('d-none');
    calcular();
  }

  function pintarFila(tr) {
    var sel = tr.querySelector('.sel-prod');
    var p = prodMap[sel.value] || null;
    var cant = parseFloat(tr.querySelector('.inp-cant').value) || 0;
    var precio = parseFloat(tr.querySelector('.inp-precio').value) || 0;
    var disp = tr.querySelector('.stock-disp');
    var input = tr.querySelector('.inp-cant');
    var excede = false;

    if (p) {
      disp.textContent = p.stock + ' ' + p.unidad;
      disp.className = 'stock-disp ' + (p.stock <= 0 ? 'text-danger fw-bold' : 'text-success');
      excede = cant > p.stock;
    } else {
      disp.textContent = '—';
      disp.className = 'stock-disp text-muted';
    }
    input.classList.toggle('is-invalid', excede);
    tr.querySelector('.subtotal').textContent = '$' + money.format(cant * precio);
    tr.dataset.excede = excede ? '1' : '0';
    tr.dataset.subtotal = String(cant * precio);
    tr.dataset.elegido = p ? '1' : '0';
  }

  function calcular() {
    var filas = body.querySelectorAll('tr');
    var total = 0;
    var excede = false;
    var incompleta = filas.length === 0;
    filas.forEach(function (tr) {
      pintarFila(tr);
      total += parseFloat(tr.dataset.subtotal) || 0;
      if (tr.dataset.excede === '1') excede = true;
      if (tr.dataset.elegido !== '1') incompleta = true;
    });
    document.getElementById('sumSubtotal').textContent = '$' + money.format(total);
    document.getElementById('sumUsd').textContent = '$' + money.format(total);
    document.getElementById('sumBs').textContent = TASA === null ? 'Bs. —' : 'Bs. ' + money.format(total * TASA);
    document.getElementById('alertaStock').classList.toggle('d-none', !excede);
    document.getElementById('btnCobrar').disabled = excede || incompleta || PRODS.length === 0;
  }

  document.getElementById('btnAgregar').addEventListener('click', agregar);
  body.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-quitar');
    if (!btn) return;
    fila(btn).remove();
    if (!body.children.length) document.getElementById('vacio').classList.remove('d-none');
    calcular();
  });
  body.addEventListener('change', function (e) {
    if (!e.target.classList.contains('sel-prod')) return;
    var tr = fila(e.target);
    var p = prodMap[e.target.value];
    if (p) tr.querySelector('.inp-precio').value = p.precio.toFixed(2);
    calcular();
  });
  body.addEventListener('input', function (e) {
    if (e.target.classList.contains('inp-cant') || e.target.classList.contains('inp-precio')) calcular();
  });
  document.getElementById('formVenta').addEventListener('submit', function (e) {
    if (!body.children.length) {
      e.preventDefault();
      document.getElementById('vacio').classList.remove('d-none');
    }
  });

  if (PRODS.length) agregar();

  var tipoSel = document.getElementById('tipo_cliente');
  var wrapPos = document.getElementById('wrapClientePos');
  var wrapEnt = document.getElementById('wrapEntidad');
  var clienteSel = document.getElementById('cliente_id');
  var q = document.getElementById('entidad_q');
  var hid = document.getElementById('entidad_id');
  var list = document.getElementById('entidadList');
  var selLbl = document.getElementById('entidadSel');
  var tBuscar = null;

  function modoExclusivo() { return tipoSel.value === 'exclusivo'; }

  function syncTipo() {
    var ex = modoExclusivo();
    wrapEnt.classList.toggle('d-none', !ex);
    wrapPos.classList.toggle('d-none', ex);
    clienteSel.required = !ex;
    if (!ex) {
      hid.value = '';
      selLbl.textContent = 'Sin selección.';
      list.classList.add('d-none');
    }
  }

  function elegir(id, nombre, doc) {
    hid.value = String(id);
    selLbl.textContent = nombre + (doc ? ' · ' + doc : '');
    q.value = nombre;
    list.classList.add('d-none');
  }

  function buscar() {
    var term = q.value.trim();
    hid.value = '';
    selLbl.textContent = 'Sin selección.';
    if (term.length < 1) {
      list.classList.add('d-none');
      return;
    }
    var url = BUSCAR + (BUSCAR.indexOf('?') >= 0 ? '&' : '?') + 'tipo=' + encodeURIComponent(document.getElementById('entidad_tipo').value) + '&q=' + encodeURIComponent(term);
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        list.innerHTML = '';
        (data.items || []).forEach(function (it) {
          var a = document.createElement('button');
          a.type = 'button';
          a.className = 'list-group-item list-group-item-action py-1';
          a.textContent = it.nombre + (it.documento ? ' · ' + it.documento : '');
          a.addEventListener('click', function () { elegir(it.id, it.nombre, it.documento); });
          list.appendChild(a);
        });
        list.classList.toggle('d-none', !list.children.length);
      })
      .catch(function () { list.classList.add('d-none'); });
  }

  tipoSel.addEventListener('change', syncTipo);
  document.getElementById('entidad_tipo').addEventListener('change', function () {
    hid.value = '';
    q.value = '';
    selLbl.textContent = 'Sin selección.';
    list.classList.add('d-none');
  });
  q.addEventListener('input', function () {
    clearTimeout(tBuscar);
    tBuscar = setTimeout(buscar, 220);
  });
  document.addEventListener('click', function (e) {
    if (!wrapEnt.contains(e.target)) list.classList.add('d-none');
  });
  syncTipo();
})();
</script>
<?php
layout_end();
