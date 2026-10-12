<?php
declare(strict_types=1);

/**
 * @var list<array{id:int, nombre:string, unidad:string, precio_usd:string, stock:string}> $productos
 * @var list<array{id:int, nombre:string, documento:?string}> $frecuentes
 * @var float|null $tasa
 * @var array<string, string> $tipos
 * @var array<string, string> $entidades
 * @var array<string, string> $metodos
 * @var list<array{value:string, label:string}> $semanas
 * @var string $buscarUrl
 * @var string $nuevoUrl
 * @var string $ascenderUrl
 */
$semanas = $semanas ?? [];
$tipos = $tipos ?? Venta::TIPOS_CLIENTE;
$entidades = $entidades ?? Venta::ENTIDADES;
$metodos = $metodos ?? Venta::METODOS_PAGO;
$frecuentes = $frecuentes ?? [];
$catalogo = array_map(static fn (array $p): array => [
    'id'                 => (int) $p['id'],
    'nombre'             => $p['nombre'],
    'unidad'             => $p['unidad'],
    'unidad_medida'      => Producto::uom((string) ($p['unidad_medida'] ?? 'KG')),
    'unidad_secundaria'  => Producto::unidadSecundaria(isset($p['unidad_secundaria']) ? (string) $p['unidad_secundaria'] : null),
    'precio'             => (float) $p['precio_usd'],
    'stock'              => (float) $p['stock'],
    'stock_secundario'   => (int) ($p['stock_secundario'] ?? 0),
], $productos);

layout_start('Nueva venta', 'ventas');
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link active" href="<?= e(url('ventas_nueva')) ?>">Nueva venta</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= e(url('ventas_historial')) ?>">Historial de facturación</a></li>
</ul>

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

            <div class="col-md-8" id="wrapGenerico">
              <label class="form-label fw-semibold" for="cliente_nombre">Nombre del cliente</label>
              <input type="text" name="cliente_nombre" id="cliente_nombre" class="form-control" maxlength="120" placeholder="Consumidor ocasional">
            </div>

            <div class="col-md-8 d-none" id="wrapFrecuente">
              <label class="form-label fw-semibold" for="ventas_cliente_id">Cliente frecuente</label>
              <div class="d-flex gap-2 flex-wrap">
                <select name="ventas_cliente_id" id="ventas_cliente_id" class="form-select" style="min-width:220px">
                  <option value="">— Seleccionar —</option>
                  <?php foreach ($frecuentes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?><?= !empty($c['documento']) ? ' · ' . e($c['documento']) : '' ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-primary" id="btnNuevoFrecuente">+ Nuevo cliente frecuente</button>
                <button type="button" class="btn btn-outline-warning" id="btnAscender">Ascender a exclusivo</button>
              </div>
              <div id="boxNuevoFrecuente" class="border rounded p-2 mt-2 d-none">
                <div class="row g-2 align-items-end">
                  <div class="col-md-5">
                    <label class="form-label small mb-0" for="nf_nombre">Nombre</label>
                    <input type="text" id="nf_nombre" class="form-control form-control-sm" maxlength="120">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label small mb-0" for="nf_documentoNum">Cédula / RIF (opc.)</label>
                    <?php input_group_documento(['name' => 'nf_documento', 'id' => 'nf_documento', 'class' => 'form-control-sm']); ?>
                  </div>
                  <div class="col-md-3">
                    <button type="button" class="btn btn-sm btn-success w-100" id="btnGuardarFrecuente">Guardar</button>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label small mb-0" for="nf_telefonoNum">Teléfono</label>
                    <?php input_group_telefono(['name' => 'nf_telefono', 'id' => 'nf_telefono', 'class' => 'form-control-sm']); ?>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label small mb-0" for="nf_direccion">Dirección</label>
                    <input type="text" id="nf_direccion" class="form-control form-control-sm" maxlength="160" placeholder="Av. Bolívar, local 5…">
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-8 d-none position-relative" id="wrapEntidad">
              <label class="form-label fw-semibold" for="entidad_tipo">Entidad exclusiva</label>
              <select name="entidad_tipo" id="entidad_tipo" class="form-select mb-2">
                <?php foreach ($entidades as $k => $lbl): ?>
                  <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="search" id="entidad_q" class="form-control" placeholder="Buscar productor, empleado, transportista o CxC…" autocomplete="off">
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

            <div class="col-md-4 d-none" id="wrapRef">
              <label class="form-label fw-semibold" for="referencia">Nro. referencia <span class="text-danger">*</span></label>
              <input type="text" name="referencia" id="referencia" class="form-control" maxlength="40">
            </div>
            <div class="col-md-8 d-none" id="wrapDesc">
              <label class="form-label fw-semibold" for="descripcion_pago">Descripción <span class="text-danger">*</span></label>
              <input type="text" name="descripcion_pago" id="descripcion_pago" class="form-control" maxlength="255">
            </div>
            <div class="col-md-8 d-none" id="wrapSemana">
              <label class="form-label fw-semibold" for="semana_aplicacion">Semana a descontar</label>
              <select name="semana_aplicacion" id="semana_aplicacion" class="form-select">
                <?php foreach ($semanas as $sem): ?>
                  <option value="<?= e($sem['value']) ?>"><?= e($sem['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Solo semanas ya iniciadas, de lunes a domingo. No hay fechas futuras.</div>
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
                <th class="text-center" style="width:90px">Envases</th>
                <th class="text-center" style="min-width:150px">Bruto − tara</th>
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
      <input type="number" class="form-control form-control-sm text-center inp-emp d-none" min="1" step="1" value="1">
    </td>
    <td>
      <div class="cw-inputs js-cw d-none">
        <label class="cw-lbl js-tara-lbl">Tara / envase</label>
        <select class="form-select form-select-sm js-tara"></select>
        <div class="cw-hint js-tara-hint d-none"></div>
        <label class="cw-lbl">Peso bruto (balanza)</label>
        <input type="number" class="form-control form-control-sm text-center inp-bruto" min="0" step="0.001" placeholder="kg en balanza">
        <div class="cw-neto js-neto-lbl">Neto: —</div>
      </div>
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

<script src="<?= e(asset_v('js/catch_weight.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var PRODS = <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>;
  var TASA = <?= json_encode($tasa, JSON_THROW_ON_ERROR) ?>;
  var BUSCAR = <?= json_encode($buscarUrl ?? url('ventas_buscar_entidades'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
  var NUEVO = <?= json_encode($nuevoUrl ?? url('ventas_cliente_nuevo'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
  var ASCENDER = <?= json_encode($ascenderUrl ?? url('ventas_ascender_exclusivo'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
  var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var TARA = window.AdaraTara;
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

  function armarTara(tr, sec) {
    var sel = tr.querySelector('.js-tara');
    var lbl = tr.querySelector('.js-tara-lbl');
    var hint = tr.querySelector('.js-tara-hint');
    var modo = TARA ? TARA.modo(sec) : 'ninguna';
    sel.innerHTML = '';
    if (modo === 'variable' && TARA.TARA_VAR[sec]) {
      lbl.classList.remove('d-none');
      sel.classList.remove('d-none');
      hint.classList.add('d-none');
      TARA.TARA_VAR[sec].forEach(function (kg) {
        var o = document.createElement('option');
        o.value = kg.toFixed(3);
        o.textContent = kg + ' kg';
        sel.appendChild(o);
      });
    } else {
      lbl.classList.add('d-none');
      sel.classList.add('d-none');
      var tara = TARA ? TARA.unitaria(sec, 0) : 0;
      var hid = document.createElement('option');
      hid.value = tara.toFixed(3);
      hid.selected = true;
      sel.appendChild(hid);
      if (modo === 'fija') {
        hint.textContent = 'Tara: ' + TARA.fmt(tara) + ' kg / ' + sec;
        hint.classList.remove('d-none');
      } else {
        hint.textContent = 'Peso directo (sin tara plástica)';
        hint.classList.remove('d-none');
      }
    }
  }

  function agregar() {
    var tr = tpl.content.firstElementChild.cloneNode(true);
    var n = idx++;
    var sel = tr.querySelector('.sel-prod');
    sel.name = 'items[' + n + '][producto_id]';
    sel.insertAdjacentHTML('beforeend', opciones());
    tr.querySelector('.inp-emp').name = 'items[' + n + '][empaques]';
    tr.querySelector('.inp-cant').name = 'items[' + n + '][cantidad]';
    tr.querySelector('.inp-precio').name = 'items[' + n + '][precio_unit_usd]';
    tr.querySelector('.inp-bruto').name = 'items[' + n + '][peso_bruto]';
    tr.querySelector('.js-tara').name = 'items[' + n + '][tara_unitaria]';
    body.appendChild(tr);
    document.getElementById('vacio').classList.add('d-none');
    calcular();
  }

  function pintarFila(tr) {
    var sel = tr.querySelector('.sel-prod');
    var p = prodMap[sel.value] || null;
    var empInp = tr.querySelector('.inp-emp');
    var cantInp = tr.querySelector('.inp-cant');
    var brutoInp = tr.querySelector('.inp-bruto');
    var taraSel = tr.querySelector('.js-tara');
    var cwBox = tr.querySelector('.js-cw');
    var netoLbl = tr.querySelector('.js-neto-lbl');
    var emp = parseInt(empInp.value, 10) || 0;
    var precio = parseFloat(tr.querySelector('.inp-precio').value) || 0;
    var disp = tr.querySelector('.stock-disp');
    var catchW = !!(p && p.unidad_secundaria);
    var tara = catchW && TARA ? TARA.unitaria(p.unidad_secundaria, taraSel.value) : 0;
    var bruto = TARA ? TARA.parse(brutoInp.value) : (parseFloat(brutoInp.value) || 0);
    var neto = catchW && TARA ? TARA.neto(bruto, emp, tara) : (parseFloat(cantInp.value) || 0);
    empInp.classList.toggle('d-none', !catchW);
    empInp.required = catchW;
    cwBox.classList.toggle('d-none', !catchW);
    cantInp.classList.toggle('d-none', catchW);
    cantInp.required = !catchW;
    if (catchW) {
      cantInp.value = neto > 0 ? neto.toFixed(3) : '';
      var taraTot = emp * tara;
      netoLbl.textContent = neto > 0
        ? ('Neto: ' + TARA.fmt(neto) + ' kg  ·  tara ' + TARA.fmt(taraTot) + ' kg')
        : 'Neto: —';
      netoLbl.classList.toggle('is-bad', bruto > 0 && neto <= 0);
    }
    var cant = catchW ? Math.max(neto, 0) : (parseFloat(cantInp.value) || 0);
    var incompleta = !p || cant <= 0 || (catchW && (emp < 1 || bruto <= 0 || neto <= 0));
    var excede = false;
    if (p) {
      disp.textContent = p.stock + ' ' + (p.unidad_medida || p.unidad) + ' neto';
      disp.className = 'stock-disp ' + (p.stock <= 0 ? 'text-danger fw-bold' : 'text-success');
      excede = cant > p.stock;
    } else {
      disp.textContent = '—';
      disp.className = 'stock-disp text-muted';
    }
    cantInp.classList.toggle('is-invalid', excede);
    brutoInp.classList.toggle('is-invalid', catchW && (bruto <= 0 || neto <= 0));
    empInp.classList.toggle('is-invalid', catchW && emp < 1);
    tr.querySelector('.subtotal').textContent = '$' + money.format(cant * precio);
    tr.dataset.excede = excede ? '1' : '0';
    tr.dataset.incompleta = incompleta ? '1' : '0';
    tr.dataset.subtotal = String(cant * precio);
    tr.dataset.elegido = p && !incompleta ? '1' : '0';
  }

  function calcular() {
    var filas = body.querySelectorAll('tr');
    var total = 0, excede = false, incompleta = filas.length === 0;
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
    if (e.target.classList.contains('js-tara') || e.target.classList.contains('inp-emp') || e.target.classList.contains('inp-bruto')) {
      calcular();
      return;
    }
    if (!e.target.classList.contains('sel-prod')) return;
    var p = prodMap[e.target.value];
    if (p) {
      var tr = fila(e.target);
      tr.querySelector('.inp-precio').value = p.precio.toFixed(2);
      if (p.unidad_secundaria) {
        tr.querySelector('.inp-emp').value = '1';
        tr.querySelector('.inp-cant').value = '';
        tr.querySelector('.inp-bruto').value = '';
        armarTara(tr, p.unidad_secundaria);
      } else {
        tr.querySelector('.inp-emp').value = '0';
        if (!tr.querySelector('.inp-cant').value) tr.querySelector('.inp-cant').value = '1';
        tr.querySelector('.inp-cant').placeholder = '';
        tr.querySelector('.inp-bruto').value = '';
      }
    }
    calcular();
  });
  body.addEventListener('input', function (e) {
    if (e.target.classList.contains('inp-cant') || e.target.classList.contains('inp-precio') || e.target.classList.contains('inp-emp') || e.target.classList.contains('inp-bruto') || e.target.classList.contains('js-tara')) calcular();
  });

  var tipoSel = document.getElementById('tipo_cliente');
  var metodoSel = document.getElementById('metodo_pago');
  var wrapGen = document.getElementById('wrapGenerico');
  var wrapFre = document.getElementById('wrapFrecuente');
  var wrapEnt = document.getElementById('wrapEntidad');
  var wrapRef = document.getElementById('wrapRef');
  var wrapDesc = document.getElementById('wrapDesc');
  var wrapSem = document.getElementById('wrapSemana');
  var q = document.getElementById('entidad_q');
  var hid = document.getElementById('entidad_id');
  var list = document.getElementById('entidadList');
  var selLbl = document.getElementById('entidadSel');
  var tBuscar = null;

  function syncTipo() {
    var t = tipoSel.value;
    wrapGen.classList.toggle('d-none', t !== 'generico');
    wrapFre.classList.toggle('d-none', t !== 'frecuente');
    wrapEnt.classList.toggle('d-none', t !== 'exclusivo');
    if (t !== 'exclusivo') {
      hid.value = '';
      selLbl.textContent = 'Sin selección.';
      list.classList.add('d-none');
    }
    syncPago();
  }

  function syncPago() {
    var m = metodoSel.value;
    var banco = m === 'pago_movil' || m === 'transferencia';
    var mercancia = m === 'mercancia_descuento';
    var ent = document.getElementById('entidad_tipo').value;
    var exclusivoInterno = tipoSel.value === 'exclusivo'
      && (ent === 'productor' || ent === 'transportista' || ent === 'empleado');
    var pideSemana = mercancia && exclusivoInterno;
    wrapRef.classList.toggle('d-none', !banco);
    wrapDesc.classList.toggle('d-none', !banco);
    wrapSem.classList.toggle('d-none', !pideSemana);
    document.getElementById('referencia').required = banco;
    document.getElementById('descripcion_pago').required = banco;
    var semana = document.getElementById('semana_aplicacion');
    semana.required = pideSemana;
    semana.disabled = !pideSemana;
  }

  function elegir(id, nombre, doc) {
    hid.value = String(id);
    selLbl.textContent = nombre + (doc ? ' · ' + doc : '');
    q.value = nombre;
    list.classList.add('d-none');
    syncPago();
  }

  function buscar() {
    var term = q.value.trim();
    hid.value = '';
    selLbl.textContent = 'Sin selección.';
    if (term.length < 1) { list.classList.add('d-none'); return; }
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

  function jsonPost(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); });
  }

  function pintarFrecuentes(items, selected) {
    var sel = document.getElementById('ventas_cliente_id');
    sel.innerHTML = '<option value="">— Seleccionar —</option>';
    (items || []).forEach(function (c) {
      var o = document.createElement('option');
      o.value = String(c.id);
      o.textContent = c.nombre + (c.documento ? ' · ' + c.documento : '');
      if (String(c.id) === String(selected)) o.selected = true;
      sel.appendChild(o);
    });
  }

  document.getElementById('btnNuevoFrecuente').addEventListener('click', function () {
    document.getElementById('boxNuevoFrecuente').classList.toggle('d-none');
  });
  document.getElementById('btnGuardarFrecuente').addEventListener('click', function () {
    jsonPost(NUEVO, {
      nombre: document.getElementById('nf_nombre').value,
      documento: window.AdaraContacto ? AdaraContacto.valor('nf_documento') : document.getElementById('nf_documento').value,
      telefono: window.AdaraContacto ? AdaraContacto.valor('nf_telefono') : document.getElementById('nf_telefono').value,
      direccion: document.getElementById('nf_direccion').value
    })
      .then(function (res) {
        if (!res.body.ok) {
          alert((res.body.errores && (res.body.errores.nombre || res.body.errores.documento || res.body.errores.telefono || res.body.errores.direccion)) || 'No se pudo guardar.');
          return;
        }
        pintarFrecuentes(res.body.frecuentes, res.body.cliente.id);
        document.getElementById('boxNuevoFrecuente').classList.add('d-none');
        document.getElementById('nf_nombre').value = '';
        document.getElementById('nf_direccion').value = '';
        if (window.AdaraContacto) {
          AdaraContacto.setDocumento('nf_documento', '');
          AdaraContacto.setTelefono('nf_telefono', '');
        } else {
          document.getElementById('nf_documento').value = '';
          document.getElementById('nf_telefono').value = '';
        }
      });
  });
  document.getElementById('btnAscender').addEventListener('click', function () {
    var id = document.getElementById('ventas_cliente_id').value;
    if (!id) { alert('Selecciona un cliente frecuente.'); return; }
    if (!confirm('¿Ascender este cliente a exclusivo y crear su ficha en Control de Facturas (CxC)?')) return;
    jsonPost(ASCENDER, { id: id })
      .then(function (res) {
        if (!res.body.ok) {
          alert((res.body.errores && res.body.errores.id) || 'No se pudo ascender.');
          return;
        }
        pintarFrecuentes(res.body.frecuentes, '');
        tipoSel.value = 'exclusivo';
        document.getElementById('entidad_tipo').value = 'cxc';
        syncTipo();
        if (res.body.cliente) {
          elegir(res.body.cxc_cliente_id, res.body.cliente.nombre, res.body.cliente.documento);
        }
        alert('Cliente exclusivo creado en CxC.');
      });
  });

  tipoSel.addEventListener('change', syncTipo);
  metodoSel.addEventListener('change', syncPago);
  document.getElementById('entidad_tipo').addEventListener('change', function () {
    hid.value = ''; q.value = ''; selLbl.textContent = 'Sin selección.'; list.classList.add('d-none'); syncPago();
  });
  q.addEventListener('input', function () { clearTimeout(tBuscar); tBuscar = setTimeout(buscar, 220); });
  document.addEventListener('click', function (e) { if (!wrapEnt.contains(e.target)) list.classList.add('d-none'); });
  document.getElementById('formVenta').addEventListener('submit', function (e) {
    if (!body.children.length) { e.preventDefault(); document.getElementById('vacio').classList.remove('d-none'); }
  });

  if (PRODS.length) agregar();
  syncTipo();
})();
</script>
<?php
layout_end();
