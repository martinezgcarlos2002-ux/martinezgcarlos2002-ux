<?php
require_login();
$db = get_db();
ensure_almacenes_schema();
$action = $_GET['action'] ?? '';

/* ── POST ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'nueva_venta') {
        $cliente = trim($_POST['cliente'] ?? '');
        $moneda  = $_POST['moneda'] ?? 'USD';
        $tasa    = (float)($_POST['tasa_cambio'] ?? 1);
        $fecha   = $_POST['fecha'] ?? date('Y-m-d');
        $items   = $_POST['items'] ?? [];

        if (!$cliente || empty($items)) {
            flash('Completa cliente y al menos un producto.', 'danger');
            header('Location: ?p=ventas'); exit;
        }

        // Aplanar items: una venta puede salir de varios almacenes por producto.
        // Estructura esperada: items[idx] = { producto_id, precio, qty_alm: { almacen_id => cantidad } }
        $lineas = []; // lista de [pid, aid, cant, prec]
        $total  = 0.0;
        foreach ($items as $it) {
            $pid  = (int)($it['producto_id'] ?? 0);
            $prec = (float)($it['precio']     ?? 0);
            $qta  = $it['qty_alm'] ?? [];
            if (!$pid || !is_array($qta)) continue;
            foreach ($qta as $aid => $cant) {
                $aid  = (int)$aid;
                $cant = (float)$cant;
                if ($aid <= 0 || $cant <= 0) continue;
                $lineas[] = [$pid, $aid, $cant, $prec];
                $total   += $cant * $prec;
            }
        }
        if (empty($lineas)) {
            flash('Indica al menos una cantidad mayor a cero en algún almacén.', 'danger');
            header('Location: ?p=ventas'); exit;
        }

        $db->beginTransaction();
        try {
            // almacen_id en facturas: el primer almacén usado (para compatibilidad / vista resumen).
            $alm_principal = (int)$lineas[0][1];
            $db->prepare('INSERT INTO facturas(cliente,total,moneda,tasa_cambio,fecha,almacen_id) VALUES(?,?,?,?,?,?)')
               ->execute([$cliente, $total, $moneda, $tasa, $fecha, $alm_principal]);
            $fid = (int)$db->lastInsertId('facturas_id_seq');

            foreach ($lineas as [$pid, $aid, $cant, $prec]) {
                // Verificar stock disponible en ese almacén
                $st = $db->prepare('SELECT stock FROM productos_stock WHERE producto_id=? AND almacen_id=?');
                $st->execute([$pid, $aid]);
                $stk_alm = (float)($st->fetchColumn() ?: 0);
                if ($stk_alm < $cant) {
                    throw new \Exception("Stock insuficiente para producto #$pid en el almacén #$aid (disponible: $stk_alm, requerido: $cant)");
                }
                $db->prepare('INSERT INTO detalle_factura(factura_id,producto_id,cantidad,precio,almacen_id) VALUES(?,?,?,?,?)')
                   ->execute([$fid, $pid, $cant, $prec, $aid]);
                ajustar_stock_almacen($pid, $aid, -$cant);
                $db->prepare('UPDATE productos SET stock = stock - ? WHERE id=?')
                   ->execute([$cant, $pid]);
            }
            $db->commit();
            flash("Factura #$fid creada correctamente.");
            header("Location: ?p=ventas&action=view&id=$fid"); exit;
        } catch (\Exception $e) {
            $db->rollBack();
            flash('Error: ' . $e->getMessage(), 'danger');
            header('Location: ?p=ventas'); exit;
        }

    } elseif ($act === 'delete') {
        $fid = (int)($_POST['id'] ?? 0);
        $fst = $db->prepare('SELECT almacen_id FROM facturas WHERE id=?');
        $fst->execute([$fid]);
        $alm_fact = (int)($fst->fetchColumn() ?: 0);
        // Devolver stock: usar detalle_factura.almacen_id si existe; si no, el de la factura.
        $items_st = $db->prepare('SELECT producto_id, cantidad, almacen_id FROM detalle_factura WHERE factura_id=?');
        $items_st->execute([$fid]);
        foreach ($items_st->fetchAll() as $it) {
            $db->prepare('UPDATE productos SET stock = stock + ? WHERE id=?')
               ->execute([$it['cantidad'], $it['producto_id']]);
            $aid = (int)($it['almacen_id'] ?? 0) ?: $alm_fact;
            if ($aid > 0) {
                ajustar_stock_almacen((int)$it['producto_id'], $aid, (float)$it['cantidad']);
            }
        }
        $db->prepare('DELETE FROM facturas WHERE id=?')->execute([$fid]);
        flash('Factura eliminada y stock reintegrado.', 'warning');
        header('Location: ?p=ventas'); exit;
    }
    header('Location: ?p=ventas'); exit;
}

/* ── Almacenes + stock por almacén para JS ────────────────── */
$almacenes = $db->query("SELECT * FROM almacenes WHERE " . (is_mysql() ? "activo=1" : "activo=TRUE") . " ORDER BY orden, id")->fetchAll();
$alm_default = $almacenes[0]['id'] ?? 0;
$ps = $db->query('SELECT producto_id, almacen_id, stock FROM productos_stock')->fetchAll();
$stock_alm = []; // [pid][aid] = stock
foreach ($ps as $r) $stock_alm[(int)$r['producto_id']][(int)$r['almacen_id']] = (float)$r['stock'];

/* ── Productos disponibles para JS ───────────────────────── */
$productos_raw = $db->query('SELECT id, nombre, precio, stock, moneda, unidad_medida FROM productos WHERE stock > 0 ORDER BY nombre')->fetchAll();
foreach ($productos_raw as &$_p) {
    $_p['stock_alm'] = $stock_alm[(int)$_p['id']] ?? new stdClass();
}
unset($_p);
$productos_json = json_encode($productos_raw, JSON_UNESCAPED_UNICODE);

/* ── Filtros lista ────────────────────────────────────────── */
$search = trim($_GET['q'] ?? '');
$desde  = $_GET['desde'] ?? '';
$hasta  = $_GET['hasta'] ?? '';
$conds  = []; $params = [];
if ($search) { $conds[] = 'f.cliente LIKE ?'; $params[] = "%$search%"; }
if ($desde)  { $conds[] = 'CAST(f.fecha AS DATE) >= ?'; $params[] = $desde; }
if ($hasta)  { $conds[] = 'CAST(f.fecha AS DATE) <= ?'; $params[] = $hasta; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$rows = $db->prepare("SELECT f.*, (SELECT COUNT(*) FROM detalle_factura d WHERE d.factura_id=f.id) as n_items
    FROM facturas f $where ORDER BY f.fecha DESC, f.id DESC");
$rows->execute($params);
$rows = $rows->fetchAll();

$total_usd = array_sum(array_map(fn($r) => $r['moneda']==='USD' ? (float)$r['total'] : 0, $rows));
$total_ves = array_sum(array_map(fn($r) => $r['moneda']==='VES' ? (float)$r['total'] : 0, $rows));

/* ── Vista detalle ────────────────────────────────────────── */
$detail = null;
if ($action === 'view' && isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM facturas WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $detail = $st->fetch();
    if ($detail) {
        $items_st = $db->prepare('SELECT d.*, p.nombre as producto_nombre, p.unidad_medida FROM detalle_factura d JOIN productos p ON p.id=d.producto_id WHERE d.factura_id=?');
        $items_st->execute([$detail['id']]);
        $raw_items = $items_st->fetchAll();
        // Agrupar líneas iguales (mismo producto y precio) provenientes de varios almacenes
        $grp = [];
        foreach ($raw_items as $it) {
            $k = (int)$it['producto_id'] . '|' . (string)$it['precio'];
            if (!isset($grp[$k])) {
                $grp[$k] = $it; $grp[$k]['cantidad'] = 0;
            }
            $grp[$k]['cantidad'] += (float)$it['cantidad'];
        }
        $detail['items'] = array_values($grp);
    }
}

/* ── Tasa actual ──────────────────────────────────────────── */
$tasa_actual = get_tasa();

layout_start('Ventas / Facturas', 'ventas');
?>

<?php if ($detail): ?>
<!-- ═══════════════ VISTA FACTURA ═══════════════ -->
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.55)">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Factura #<?= h($detail['id']) ?></h5>
        <div class="d-flex gap-2">
          <a href="?p=factura_imprimir&id=<?= $detail['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">🖨 Imprimir / PDF</a>
          <a href="?p=ventas" class="btn-close"></a>
        </div>
      </div>
      <div class="modal-body">
        <div class="row mb-3">
          <div class="col-6"><strong>Cliente:</strong> <?= h($detail['cliente']) ?></div>
          <div class="col-3"><strong>Fecha:</strong> <?= fmt_date($detail['fecha']) ?></div>
          <div class="col-3"><strong>Moneda:</strong> <?= h($detail['moneda']) ?> / Tasa: <?= number_format((float)$detail['tasa_cambio'],2) ?></div>
        </div>
        <table class="table table-bordered table-sm">
          <thead class="table-light">
            <tr><th>Producto</th><th class="text-center">Cant.</th><th class="text-center">Unidad</th><th class="text-end">P. Unit.</th><th class="text-end">Subtotal</th></tr>
          </thead>
          <tbody>
          <?php foreach ($detail['items'] as $it): ?>
          <tr>
            <td><?= h($it['producto_nombre']) ?></td>
            <td class="text-center"><?= number_format((float)$it['cantidad'],2) ?></td>
            <td class="text-center"><?= h($it['unidad_medida']) ?></td>
            <td class="text-end"><?= fmt_money($it['precio'], $detail['moneda']) ?></td>
            <td class="text-end"><?= fmt_money($it['precio'] * $it['cantidad'], $detail['moneda']) ?></td>
          </tr>
          <?php endforeach ?>
          </tbody>
          <tfoot>
            <tr><td colspan="4" class="text-end fw-bold">TOTAL</td><td class="text-end fw-bold"><?= fmt_money($detail['total'], $detail['moneda']) ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif ?>

<!-- ═══════════════ ESTADÍSTICAS ═══════════════ -->
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="stat-card text-center">
      <div class="stat-val text-success"><?= fmt_dual($total_usd, 'USD') ?></div>
      <div class="stat-label">Total USD (filtro)</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card text-center">
      <div class="stat-val" style="color:#1a2e5a"><?= fmt_dual($total_ves, 'VES') ?></div>
      <div class="stat-label">Total VES (filtro)</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card text-center">
      <div class="stat-val"><?= count($rows) ?></div>
      <div class="stat-label">Facturas en vista</div>
    </div>
  </div>
</div>

<!-- ═══════════════ BARRA BÚSQUEDA ═══════════════ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2 flex-wrap" method="get">
    <input type="hidden" name="p" value="ventas">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar cliente..." value="<?= h($search) ?>" style="width:180px">
    <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>" style="width:140px">
    <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>" style="width:140px">
    <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
    <?php if ($search || $desde || $hasta): ?><a href="?p=ventas" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
  </form>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevaVenta">+ Nueva Venta</button>
</div>

<!-- ═══════════════ TABLA ═══════════════ -->
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Cliente</th><th class="text-center">Artículos</th><th class="text-end">Total</th><th>Moneda</th><th>Fecha</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['id']) ?></td>
        <td><?= h($r['cliente']) ?></td>
        <td class="text-center"><?= (int)$r['n_items'] ?></td>
        <td class="text-end"><?= fmt_dual($r['total'], $r['moneda'], true) ?></td>
        <td><?= h($r['moneda']) ?></td>
        <td><?= fmt_date($r['fecha']) ?></td>
        <td class="d-flex gap-1">
          <a href="?p=ventas&action=view&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Ver</a>
          <a href="?p=factura_imprimir&id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Imprimir">🖨</a>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar factura #<?= $r['id'] ?>? Se reintegrará el stock.')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Eliminar</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin facturas registradas</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════ MODAL NUEVA VENTA ═══════════════ -->
<div class="modal fade" id="modalNuevaVenta" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">🧀 Nueva Venta</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="formVenta">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="nueva_venta">

          <!-- Datos generales -->
          <div class="row g-3 mb-4">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
              <input name="cliente" class="form-control" placeholder="Nombre del cliente" required>
            </div>
            <div class="col-md-5">
              <div class="alert alert-info py-2 mb-0 small">
                <strong>🏬 Multi-almacén:</strong> en cada producto vas a indicar cuántas unidades salen de cada almacén. Puedes dejar en cero los almacenes de los que no sacas nada.
              </div>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Moneda</label>
              <select name="moneda" id="selMoneda" class="form-select">
                <option value="USD">USD</option>
                <option value="VES">VES (Bs)</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Tasa Bs/USD</label>
              <input name="tasa_cambio" id="inpTasa" type="number" step="0.01" class="form-control" value="<?= h($tasa_actual) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Fecha</label>
              <input name="fecha" type="date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
          </div>

          <!-- Productos -->
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Productos</h6>
            <button type="button" class="btn btn-sm btn-outline-success" onclick="addRow()">+ Agregar producto</button>
          </div>

          <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle" id="tablaItems">
              <thead class="table-light">
                <tr>
                  <th rowspan="2" style="min-width:220px;vertical-align:middle">Producto</th>
                  <th colspan="<?= count($almacenes) ?>" class="text-center" style="background:#fff3cd">Cantidad por almacén (stock disponible)</th>
                  <th rowspan="2" style="width:90px;vertical-align:middle" class="text-center">Total uds</th>
                  <th rowspan="2" style="width:110px;vertical-align:middle">Precio unit.</th>
                  <th rowspan="2" style="width:120px;vertical-align:middle">Subtotal</th>
                  <th rowspan="2" style="width:50px"></th>
                </tr>
                <tr>
                  <?php foreach ($almacenes as $i => $a):
                    $colors = ['#d1e7dd','#cfe2ff','#f8d7da','#fff3cd','#e2d4f0'];
                    $bg = $colors[$i % count($colors)];
                  ?>
                    <th class="text-center" style="background: <?= $bg ?>; min-width:120px"><?= h($a['nombre']) ?></th>
                  <?php endforeach ?>
                </tr>
              </thead>
              <tbody id="itemsBody">
                <!-- filas JS -->
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="<?= 4 + count($almacenes) ?>" class="text-end fw-bold">TOTAL:</td>
                  <td class="fw-bold text-success fs-5" id="totalDisplay">$0.00</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div id="alertaStock" class="alert alert-danger d-none">Cantidad supera el stock disponible.</div>

          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary" id="btnGuardar">Guardar Factura</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const PRODS = <?= $productos_json ?>;
const ALMS  = <?= json_encode(array_map(fn($a) => ['id'=>(int)$a['id'],'nombre'=>$a['nombre']], $almacenes), JSON_UNESCAPED_UNICODE) ?>;
const prodMap = {};
PRODS.forEach(p => prodMap[p.id] = p);
let rowIdx = 0;

function addRow() {
  const tbody = document.getElementById('itemsBody');
  const idx = rowIdx++;
  const opts = PRODS.map(p =>
    `<option value="${p.id}" data-precio="${p.precio}" data-moneda="${p.moneda}">${p.nombre} (${p.unidad_medida})</option>`
  ).join('');

  // Una celda por almacén con input cantidad + badge stock disponible
  const almCells = ALMS.map(a => `
    <td class="text-center">
      <input type="number" name="items[${idx}][qty_alm][${a.id}]" id="qa_${idx}_${a.id}"
        class="form-control form-control-sm text-center qa-input" min="0" step="0.01" value="0"
        data-idx="${idx}" data-aid="${a.id}" oninput="calcRow(${idx})" style="font-weight:600">
      <small class="text-muted d-block mt-1" id="stk_${idx}_${a.id}">stock: —</small>
    </td>
  `).join('');

  const tr = document.createElement('tr');
  tr.id = 'row_' + idx;
  tr.innerHTML = `
    <td>
      <select name="items[${idx}][producto_id]" class="form-select form-select-sm sel-prod" onchange="onProd(${idx})" required>
        <option value="">— Seleccionar —</option>
        ${opts}
      </select>
    </td>
    ${almCells}
    <td class="text-center fw-bold text-warning bg-light" id="totUds_${idx}" style="font-size:1.1rem">0</td>
    <td>
      <input type="number" name="items[${idx}][precio]" id="precio_${idx}" class="form-control form-control-sm"
        min="0" step="0.01" value="0" oninput="calcRow(${idx})" required>
    </td>
    <td class="text-end fw-semibold" id="sub_${idx}">$0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(${idx})">✕</button></td>
  `;
  tbody.appendChild(tr);
  calcTotal();
}

function removeRow(idx) {
  const tr = document.getElementById('row_' + idx);
  if (tr) tr.remove();
  calcTotal();
}

function stockEnAlmacen(p, aid) {
  if (!p) return 0;
  const map = p.stock_alm || {};
  return parseFloat(map[aid] || 0);
}

function onProd(idx) {
  const sel = document.querySelector(`#row_${idx} .sel-prod`);
  const pid = sel.value;
  const p   = prodMap[pid];
  if (!p) return;
  document.getElementById('precio_' + idx).value = p.precio;
  // Actualizar badges stock por almacén y limpiar cantidades
  ALMS.forEach(a => {
    const stk = stockEnAlmacen(p, a.id);
    const lbl = document.getElementById(`stk_${idx}_${a.id}`);
    if (lbl) {
      lbl.textContent = `stock: ${stk} ${p.unidad_medida}`;
      lbl.className = stk <= 0 ? 'text-danger d-block mt-1 fw-bold' : 'text-success d-block mt-1';
    }
    const inp = document.getElementById(`qa_${idx}_${a.id}`);
    if (inp) { inp.value = 0; inp.max = stk; }
  });
  calcRow(idx);
}

function calcRow(idx) {
  const sel  = document.querySelector(`#row_${idx} .sel-prod`);
  const pid  = sel?.value;
  const p    = prodMap[pid];
  const prec = parseFloat(document.getElementById('precio_' + idx)?.value || 0);
  let totUds = 0, exceso = false;
  ALMS.forEach(a => {
    const inp = document.getElementById(`qa_${idx}_${a.id}`);
    const v = parseFloat(inp?.value || 0);
    totUds += v;
    if (p && v > stockEnAlmacen(p, a.id)) {
      exceso = true;
      inp.classList.add('is-invalid');
    } else {
      inp?.classList.remove('is-invalid');
    }
  });
  document.getElementById('totUds_' + idx).textContent = totUds.toFixed(2).replace(/\.00$/,'');
  const sub = totUds * prec;
  document.getElementById('sub_' + idx).textContent = '$' + sub.toFixed(2);
  // Alerta global
  const alerta = document.getElementById('alertaStock');
  const hayExceso = document.querySelectorAll('#itemsBody .qa-input.is-invalid').length > 0;
  if (hayExceso) {
    alerta.classList.remove('d-none');
    document.getElementById('btnGuardar').disabled = true;
  } else {
    alerta.classList.add('d-none');
    document.getElementById('btnGuardar').disabled = false;
  }
  calcTotal();
}

function calcTotal() {
  let total = 0;
  document.querySelectorAll('[id^="sub_"]').forEach(el => {
    total += parseFloat(el.textContent.replace('$','')) || 0;
  });
  document.getElementById('totalDisplay').textContent = '$' + total.toFixed(2);
}

document.getElementById('formVenta').addEventListener('submit', function(e) {
  const rows = document.querySelectorAll('#itemsBody tr');
  if (rows.length === 0) {
    e.preventDefault();
    alert('Agrega al menos un producto.');
  }
});

// Agregar primera fila al abrir el modal
document.getElementById('modalNuevaVenta').addEventListener('show.bs.modal', function() {
  if (document.getElementById('itemsBody').children.length === 0) addRow();
});
</script>

<?php layout_end(); ?>
