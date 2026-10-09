<?php
require_login();
$db = get_db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ?p=proveedores'); exit; }

$prov = $db->prepare('SELECT * FROM proveedores WHERE id=?');
$prov->execute([$id]);
$prov = $prov->fetch();
if (!$prov) { flash('Proveedor no encontrado.', 'error'); header('Location: ?p=proveedores'); exit; }

// ── Determinar semana activa ──────────────────────────────────────────────────
$semana_raw = $_GET['semana'] ?? '';
if ($semana_raw && preg_match('/^\d{4}-W(\d{2})$/', $semana_raw, $m)) {
    $week_num   = (int)$m[1];
    $year       = (int)substr($semana_raw, 0, 4);
    $week_start = new DateTime();
    $week_start->setISODate($year, $week_num, 1);
} else {
    $week_start = new DateTime();
    $dow = (int)$week_start->format('N');
    $week_start->modify('-' . ($dow - 1) . ' days');
}
$week_end = clone $week_start;
$week_end->modify('+6 days');

$ws      = $week_start->format('Y-m-d');
$we      = $week_end->format('Y-m-d');
$iso     = $week_start->format('o-\WW');
$prev_iso = (clone $week_start)->modify('-7 days')->format('o-\WW');
$next_iso = (clone $week_start)->modify('+7 days')->format('o-\WW');

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save_entrega') {
        $fecha   = $_POST['fecha']        ?? $ws;
        $desc    = trim($_POST['desc']    ?? '');
        $cant    = (float)($_POST['cant'] ?? 0);
        $precio  = (float)($_POST['precio'] ?? 0);
        $sub     = round($cant * $precio, 4);
        // Upsert por fecha+proveedor (una entrada por día)
        $exist = $db->prepare('SELECT id FROM proveedores_entregas WHERE proveedor_id=? AND fecha=?');
        $exist->execute([$id, $fecha]);
        $eid = $exist->fetchColumn();
        if ($eid) {
            $db->prepare('UPDATE proveedores_entregas SET descripcion=?,cantidad=?,precio_usd=?,subtotal_usd=? WHERE id=?')
               ->execute([$desc, $cant, $precio, $sub, $eid]);
        } else {
            $db->prepare('INSERT INTO proveedores_entregas(proveedor_id,fecha,descripcion,cantidad,precio_usd,subtotal_usd) VALUES(?,?,?,?,?,?)')
               ->execute([$id, $fecha, $desc, $cant, $precio, $sub]);
        }
        flash('Suministro guardado.');
        header("Location: ?p=proveedor_detalle&id=$id&semana=$iso&tab=control"); exit;
    }

    if ($act === 'del_entrega') {
        $db->prepare('DELETE FROM proveedores_entregas WHERE id=? AND proveedor_id=?')
           ->execute([(int)$_POST['eid'], $id]);
        flash('Suministro eliminado.', 'warning');
        header("Location: ?p=proveedor_detalle&id=$id&semana=$iso&tab=control"); exit;
    }

    if ($act === 'save_pago') {
        $tipo  = $_POST['tipo']  ?? 'efectivo';
        $monto = (float)($_POST['monto'] ?? 0);
        $desc  = trim($_POST['descripcion'] ?? '');
        $fecha = $_POST['fecha'] ?? $ws;
        $db->prepare('INSERT INTO proveedores_pagos(proveedor_id,fecha,tipo,monto_usd,descripcion) VALUES(?,?,?,?,?)')
           ->execute([$id, $fecha, $tipo, $monto, $desc]);
        flash('Pago registrado.');
        header("Location: ?p=proveedor_detalle&id=$id&semana=$iso&tab=control"); exit;
    }

    if ($act === 'del_pago') {
        $db->prepare('DELETE FROM proveedores_pagos WHERE id=? AND proveedor_id=?')
           ->execute([(int)$_POST['pid'], $id]);
        flash('Pago eliminado.', 'warning');
        header("Location: ?p=proveedor_detalle&id=$id&semana=$iso&tab=control"); exit;
    }

    header("Location: ?p=proveedor_detalle&id=$id&semana=$iso&tab=control"); exit;
}

// ── Datos de la semana ────────────────────────────────────────────────────────
$ent_week = $db->prepare('SELECT * FROM proveedores_entregas WHERE proveedor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha');
$ent_week->execute([$id, $ws, $we]);
$ent_week = $ent_week->fetchAll();
$ent_by_date = [];
foreach ($ent_week as $e) { $ent_by_date[$e['fecha']] = $e; }

$pag_week = $db->prepare('SELECT * FROM proveedores_pagos WHERE proveedor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha,tipo');
$pag_week->execute([$id, $ws, $we]);
$pag_week = $pag_week->fetchAll();

// Totales semana
$total_cantidad  = array_sum(array_column($ent_week, 'cantidad'));
$total_semanal   = array_sum(array_column($ent_week, 'subtotal_usd'));
$mercancia       = array_sum(array_map(fn($p) => $p['tipo']==='mercancia'  ? (float)$p['monto_usd'] : 0, $pag_week));
$abono_pm_trans  = array_sum(array_map(fn($p) => $p['tipo']==='pm_trans'   ? (float)$p['monto_usd'] : 0, $pag_week));
$efectivo        = array_sum(array_map(fn($p) => $p['tipo']==='efectivo'   ? (float)$p['monto_usd'] : 0, $pag_week));

// Saldo pendiente = deuda acumulada de semanas anteriores
$q = $db->prepare('SELECT COALESCE(SUM(subtotal_usd),0) FROM proveedores_entregas WHERE proveedor_id=? AND fecha<?');
$q->execute([$id, $ws]); $deuda_ant = (float)$q->fetchColumn();

$q2 = $db->prepare('SELECT COALESCE(SUM(monto_usd),0) FROM proveedores_pagos WHERE proveedor_id=? AND fecha<?');
$q2->execute([$id, $ws]); $pagos_ant = (float)$q2->fetchColumn();

$saldo_pendiente = $deuda_ant - $pagos_ant;
$restante = $total_semanal + $saldo_pendiente - $mercancia - $abono_pm_trans - $efectivo;

// ── Totales globales ──────────────────────────────────────────────────────────
$gq = $db->prepare('SELECT COALESCE(SUM(subtotal_usd),0) FROM proveedores_entregas WHERE proveedor_id=?');
$gq->execute([$id]); $glob_deuda = (float)$gq->fetchColumn();

$gq2 = $db->prepare('SELECT COALESCE(SUM(monto_usd),0) FROM proveedores_pagos WHERE proveedor_id=?');
$gq2->execute([$id]); $glob_pagos = (float)$gq2->fetchColumn();

$glob_saldo = $glob_deuda - $glob_pagos;

// Compras históricas (tabla compras existente)
$gq3 = $db->prepare('SELECT COALESCE(SUM(monto),0) FROM compras WHERE proveedor_id=?');
$gq3->execute([$id]); $glob_compras = (float)$gq3->fetchColumn();

$tab = $_GET['tab'] ?? 'control';

layout_start('Control Proveedor: ' . $prov['nombre'], 'proveedores');
?>
<style>
.week-control-table th { background:#1a2e5a; color:#fff; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
.week-control-table td { vertical-align:middle; font-size:.875rem; }
.week-control-table tr.today-row { background:rgba(255,140,0,.07); }
.summary-box { background:#f8f9fc; border:1px solid #dee2e6; border-radius:8px; padding:1rem; }
.summary-row { display:flex; justify-content:space-between; align-items:center; padding:.35rem 0; border-bottom:1px solid #e9ecef; font-size:.9rem; }
.summary-row:last-child { border-bottom:none; }
.summary-row.total-row { font-weight:700; font-size:1rem; color:#1a2e5a; }
.summary-row.restante-row { font-weight:700; font-size:1.1rem; background:#1a2e5a; color:#fff; border-radius:6px; padding:.5rem .75rem; margin-top:.5rem; }
.pago-badge { font-size:.72rem; }
.week-nav { display:flex; align-items:center; gap:.5rem; }
</style>

<!-- Cabecera -->
<div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
  <a href="?p=proveedores" class="btn btn-sm btn-outline-secondary">← Proveedores</a>
  <h5 class="mb-0 fw-bold" style="color:#1a2e5a">🏭 <?= h($prov['nombre']) ?></h5>
  <?php if ($prov['contacto']): ?>
  <span class="text-muted small">· <?= h($prov['contacto']) ?></span>
  <?php endif ?>
  <?php if ($prov['telefono']): ?>
  <span class="badge bg-secondary"><?= h($prov['telefono']) ?></span>
  <?php endif ?>
</div>

<!-- Stats globales -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val text-primary">$<?= number_format($glob_deuda,2) ?></div>
      <div class="stat-label">Total suministros</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val text-success">$<?= number_format($glob_pagos,2) ?></div>
      <div class="stat-label">Total pagado</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val <?= $glob_saldo>0?'text-danger':'text-success' ?>">$<?= number_format($glob_saldo,2) ?></div>
      <div class="stat-label">Saldo pendiente</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val">$<?= number_format($glob_compras,2) ?></div>
      <div class="stat-label">Compras registradas</div>
    </div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='control'?'active':'' ?>"
       href="?p=proveedor_detalle&id=<?=$id?>&semana=<?=$iso?>&tab=control">📋 Control Semanal</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='historial'?'active':'' ?>"
       href="?p=proveedor_detalle&id=<?=$id?>&tab=historial">📜 Historial</a>
  </li>
</ul>

<?php if ($tab === 'control'): ?>
<!-- ═══════════════════════════════ CONTROL SEMANAL ══════════════════════════ -->

<!-- Navegación de semana -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <strong style="color:#1a2e5a">📅 Semana:</strong>
      <div class="week-nav">
        <a href="?p=proveedor_detalle&id=<?=$id?>&semana=<?=$prev_iso?>&tab=control"
           class="btn btn-sm btn-outline-secondary">‹</a>
        <form method="get" class="d-flex align-items-center gap-1">
          <input type="hidden" name="p" value="proveedor_detalle">
          <input type="hidden" name="id" value="<?=$id?>">
          <input type="hidden" name="tab" value="control">
          <input type="week" name="semana" class="form-control form-control-sm"
                 value="<?=$iso?>" onchange="this.form.submit()" style="width:160px">
        </form>
        <a href="?p=proveedor_detalle&id=<?=$id?>&semana=<?=$next_iso?>&tab=control"
           class="btn btn-sm btn-outline-secondary">›</a>
      </div>
      <span class="text-muted small">
        <?= $week_start->format('d/m/Y') ?> — <?= $week_end->format('d/m/Y') ?>
      </span>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Tabla 7 días -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header py-2" style="background:#1a2e5a;color:#fff">
        <strong>📦 Suministros de la Semana</strong>
        <span class="ms-2 small opacity-75">
          <?= $week_start->format('d/m') ?> al <?= $week_end->format('d/m/Y') ?>
        </span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0 week-control-table">
          <thead>
            <tr>
              <th style="width:100px">Día</th>
              <th>Descripción</th>
              <th style="width:90px">Cantidad</th>
              <th style="width:100px">Precio $</th>
              <th style="width:100px">Total $</th>
              <th style="width:36px"></th>
            </tr>
          </thead>
          <tbody>
          <?php
          $dias_es = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
          $today   = date('Y-m-d');
          $tot_l   = 0; $tot_s = 0;
          for ($d = 0; $d < 7; $d++):
            $fo    = clone $week_start; $fo->modify("+$d days");
            $fecha = $fo->format('Y-m-d');
            $ent   = $ent_by_date[$fecha] ?? null;
            $cant_v  = $ent ? (float)$ent['cantidad']    : 0;
            $prec_v  = $ent ? (float)$ent['precio_usd']  : 0;
            $sub_v   = round($cant_v * $prec_v, 2);
            $desc_v  = $ent ? $ent['descripcion'] : '';
            $tot_l  += $cant_v; $tot_s += $sub_v;
            $is_today = ($fecha === $today);
          ?>
          <tr class="<?= $is_today?'today-row':'' ?>">
            <td>
              <span class="fw-semibold"><?= $dias_es[$d] ?></span>
              <br><small class="text-muted"><?= $fo->format('d/m') ?></small>
              <?php if ($is_today): ?>
                <span class="badge bg-warning text-dark" style="font-size:.62rem">Hoy</span>
              <?php endif ?>
            </td>
            <td>
              <input type="text" id="desc_<?=$d?>" class="form-control form-control-sm"
                     placeholder="Ej: Cuajo, sal, empaques…"
                     value="<?= h($desc_v) ?>" style="min-width:120px">
            </td>
            <td>
              <input type="number" id="cant_<?=$d?>" step="0.001" min="0"
                     class="form-control form-control-sm"
                     value="<?= $cant_v>0 ? number_format($cant_v,3,'.','') : '' ?>"
                     placeholder="0"
                     oninput="calcRowP(<?=$d?>)">
            </td>
            <td>
              <input type="number" id="prec_<?=$d?>" step="0.0001" min="0"
                     class="form-control form-control-sm"
                     value="<?= $prec_v>0 ? number_format($prec_v,4,'.','') : '' ?>"
                     placeholder="0.00"
                     oninput="calcRowP(<?=$d?>)">
            </td>
            <td id="sub_<?=$d?>" class="fw-semibold <?= $sub_v>0?'text-primary':'' ?>">
              $<?= number_format($sub_v,2) ?>
            </td>
            <td>
              <?php if ($ent): ?>
              <!-- Botón guardar (actualizar) -->
              <form method="post" class="d-inline" id="fupd_<?=$d?>">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="action"   value="save_entrega">
                <input type="hidden" name="fecha"    value="<?= $fecha ?>">
                <input type="hidden" id="fdesc_<?=$d?>" name="desc"   value="<?= h($desc_v) ?>">
                <input type="hidden" id="fcant_<?=$d?>" name="cant"   value="<?= $cant_v ?>">
                <input type="hidden" id="fprec_<?=$d?>" name="precio" value="<?= $prec_v ?>">
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="action"  value="del_entrega">
                <input type="hidden" name="eid"     value="<?= $ent['id'] ?>">
                <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1" title="Eliminar">✕</button>
              </form>
              <?php else: ?>
              <!-- Formulario nueva entrada -->
              <form method="post" class="d-inline" id="fupd_<?=$d?>">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="action"   value="save_entrega">
                <input type="hidden" name="fecha"    value="<?= $fecha ?>">
                <input type="hidden" id="fdesc_<?=$d?>" name="desc"   value="">
                <input type="hidden" id="fcant_<?=$d?>" name="cant"   value="">
                <input type="hidden" id="fprec_<?=$d?>" name="precio" value="">
              </form>
              <?php endif ?>
              <button onclick="saveRowP(<?=$d?>)" class="btn btn-xs btn-sm btn-success py-0 px-1" title="Guardar">💾</button>
            </td>
          </tr>
          <?php endfor ?>
          </tbody>
          <tfoot>
            <tr style="background:#f0f4ff;font-weight:700;">
              <td colspan="4" class="text-end pe-2" style="color:#1a2e5a">TOTAL SEMANAL</td>
              <td style="color:#1a2e5a">$<?= number_format($total_semanal,2) ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- Resumen + Pagos -->
  <div class="col-lg-4">
    <!-- Resumen financiero -->
    <div class="card mb-3">
      <div class="card-header py-2" style="background:#1a2e5a;color:#fff">
        <strong>💰 Resumen de Semana</strong>
      </div>
      <div class="card-body">
        <div class="summary-box">
          <div class="summary-row total-row">
            <span>Total Semanal $</span>
            <strong>$<?= number_format($total_semanal,2) ?></strong>
          </div>
          <div class="summary-row" style="color:#856404">
            <span>Saldo Pendiente</span>
            <strong>$<?= number_format($saldo_pendiente,2) ?></strong>
          </div>
          <div class="summary-row text-danger">
            <span>(-) Mercancía</span>
            <strong>-$<?= number_format($mercancia,2) ?></strong>
          </div>
          <div class="summary-row text-danger">
            <span>(-) Abono PM/Trans</span>
            <strong>-$<?= number_format($abono_pm_trans,2) ?></strong>
          </div>
          <div class="summary-row text-danger">
            <span>(-) Efectivo</span>
            <strong>-$<?= number_format($efectivo,2) ?></strong>
          </div>
          <div class="summary-row restante-row">
            <span>RESTANTE</span>
            <strong>$<?= number_format($restante,2) ?></strong>
          </div>
        </div>
      </div>
    </div>

    <!-- Registrar pago -->
    <div class="card mb-3">
      <div class="card-header py-2"><strong>+ Registrar Pago</strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="action"  value="save_pago">
          <div class="mb-2">
            <label class="form-label form-label-sm">Tipo</label>
            <select name="tipo" class="form-select form-select-sm">
              <option value="efectivo">💵 Efectivo</option>
              <option value="mercancia">📦 Mercancía</option>
              <option value="pm_trans">🚚 Abono PM/Trans</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Monto USD</label>
            <input type="number" name="monto" step="0.01" min="0"
                   class="form-control form-control-sm" required placeholder="0.00">
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Fecha</label>
            <input type="date" name="fecha" class="form-control form-control-sm"
                   value="<?= ($today >= $ws && $today <= $we) ? $today : $ws ?>">
          </div>
          <div class="mb-3">
            <label class="form-label form-label-sm">Nota</label>
            <input type="text" name="descripcion" class="form-control form-control-sm"
                   placeholder="Opcional">
          </div>
          <button class="btn btn-primary btn-sm w-100">Guardar Pago</button>
        </form>
      </div>
    </div>

    <!-- Pagos registrados esta semana -->
    <?php if ($pag_week): ?>
    <div class="card">
      <div class="card-header py-2"><strong>Pagos de esta semana</strong></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Tipo</th><th>Monto</th><th>Fecha</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pag_week as $p):
            $tl = ['efectivo'=>'Efectivo','mercancia'=>'Mercancía','pm_trans'=>'PM/Trans'][$p['tipo']] ?? $p['tipo'];
            $tc = ['efectivo'=>'success','mercancia'=>'warning','pm_trans'=>'info'][$p['tipo']] ?? 'secondary';
          ?>
          <tr>
            <td><span class="badge bg-<?=$tc?> pago-badge"><?= h($tl) ?></span></td>
            <td class="fw-semibold">$<?= number_format((float)$p['monto_usd'],2) ?></td>
            <td class="text-muted small"><?= date('d/m', strtotime($p['fecha'])) ?></td>
            <td>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="action"  value="del_pago">
                <input type="hidden" name="pid"     value="<?= $p['id'] ?>">
                <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif ?>
  </div>
</div>

<script>
function calcRowP(d) {
  var cant  = parseFloat(document.getElementById('cant_' + d).value)  || 0;
  var prec  = parseFloat(document.getElementById('prec_' + d).value)  || 0;
  var sub   = (cant * prec).toFixed(2);
  var el    = document.getElementById('sub_' + d);
  el.textContent = '$' + sub;
  el.className = 'fw-semibold ' + (cant > 0 ? 'text-primary' : '');
}
function saveRowP(d) {
  var desc = document.getElementById('desc_' + d).value;
  var cant = document.getElementById('cant_' + d).value;
  var prec = document.getElementById('prec_' + d).value;
  if (!cant || parseFloat(cant) === 0) { alert('Ingresa una cantidad mayor a 0'); return; }
  document.getElementById('fdesc_' + d).value = desc;
  document.getElementById('fcant_' + d).value = cant;
  document.getElementById('fprec_' + d).value = prec;
  document.getElementById('fupd_'  + d).submit();
}
</script>

<?php else: ?>
<!-- ═══════════════════════════════ HISTORIAL ════════════════════════════════ -->
<?php
$h_desde = $_GET['h_desde'] ?? date('Y-m-01');
$h_hasta = $_GET['h_hasta'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $h_desde)) $h_desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $h_hasta)) $h_hasta = date('Y-m-d');

$ent_all = $db->prepare('SELECT * FROM proveedores_entregas WHERE proveedor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha DESC');
$ent_all->execute([$id, $h_desde, $h_hasta]); $ent_all = $ent_all->fetchAll();

$pag_all = $db->prepare('SELECT * FROM proveedores_pagos WHERE proveedor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha DESC');
$pag_all->execute([$id, $h_desde, $h_hasta]); $pag_all = $pag_all->fetchAll();

$comp_all = $db->prepare('SELECT * FROM compras WHERE proveedor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha DESC');
$comp_all->execute([$id, $h_desde, $h_hasta]); $comp_all = $comp_all->fetchAll();

$h_tot_factura = array_sum(array_column($ent_all, 'subtotal_usd'));
$h_tot_pagado  = array_sum(array_column($pag_all, 'monto_usd'));
$h_saldo_per   = $h_tot_factura - $h_tot_pagado;
?>

<!-- Filtro de fechas + botón factura -->
<div class="card mb-3">
  <div class="card-body py-3">
    <form method="get" class="d-flex align-items-end gap-3 flex-wrap">
      <input type="hidden" name="p" value="proveedor_detalle">
      <input type="hidden" name="id" value="<?=$id?>">
      <input type="hidden" name="tab" value="historial">
      <div>
        <label class="form-label small fw-semibold mb-1">Desde</label>
        <input type="date" name="h_desde" class="form-control form-control-sm" value="<?= h($h_desde) ?>">
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1">Hasta</label>
        <input type="date" name="h_hasta" class="form-control form-control-sm" value="<?= h($h_hasta) ?>">
      </div>
      <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
      <a href="?p=proveedor_detalle&id=<?=$id?>&tab=historial&h_desde=<?= date('Y-m-01') ?>&h_hasta=<?= date('Y-m-d') ?>"
         class="btn btn-sm btn-outline-secondary">Este mes</a>
      <a href="?p=proveedor_factura&id=<?=$id?>&desde=<?= urlencode($h_desde) ?>&hasta=<?= urlencode($h_hasta) ?>"
         target="_blank"
         class="btn btn-sm btn-primary ms-auto"
         style="background:#f97316;border-color:#f97316">
        🧾 Generar Factura
      </a>
    </form>
  </div>
</div>

<!-- Resumen del período -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-4"><div class="stat-card text-center py-2"><div class="stat-val fs-5 text-primary">$<?= number_format($h_tot_factura,2) ?></div><div class="stat-label">Facturado período</div></div></div>
  <div class="col-6 col-md-4"><div class="stat-card text-center py-2"><div class="stat-val fs-5 text-success">$<?= number_format($h_tot_pagado,2) ?></div><div class="stat-label">Pagado período</div></div></div>
  <div class="col-12 col-md-4"><div class="stat-card text-center py-2"><div class="stat-val fs-5 <?= $h_saldo_per>0?'text-danger':'text-success' ?>">$<?= number_format($h_saldo_per,2) ?></div><div class="stat-label">Saldo período</div></div></div>
</div>
<div class="row g-3">
  <div class="col-md-7">
    <div class="card">
      <div class="card-header py-2">📦 Historial de Suministros</div>
      <div class="card-body p-0" style="max-height:400px;overflow-y:auto">
        <table class="table table-sm mb-0">
          <thead style="position:sticky;top:0;background:#fff">
            <tr><th>Fecha</th><th>Descripción</th><th>Cant.</th><th>Precio</th><th>Total</th></tr>
          </thead>
          <tbody>
          <?php foreach ($ent_all as $e): ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($e['fecha'])) ?></td>
            <td class="text-muted small"><?= h($e['descripcion']) ?></td>
            <td><?= number_format((float)$e['cantidad'],3) ?></td>
            <td>$<?= number_format((float)$e['precio_usd'],4) ?></td>
            <td class="fw-semibold">$<?= number_format((float)$e['subtotal_usd'],2) ?></td>
          </tr>
          <?php endforeach ?>
          <?php if (!$ent_all): ?><tr><td colspan="5" class="text-center text-muted py-3">Sin suministros</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($comp_all): ?>
    <div class="card mt-3">
      <div class="card-header py-2">🧾 Compras registradas</div>
      <div class="card-body p-0" style="max-height:250px;overflow-y:auto">
        <table class="table table-sm mb-0">
          <thead style="position:sticky;top:0;background:#fff">
            <tr><th>Fecha</th><th>Descripción</th><th>Monto</th><th>Moneda</th></tr>
          </thead>
          <tbody>
          <?php foreach ($comp_all as $c): ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
            <td class="text-muted small"><?= h($c['descripcion']) ?></td>
            <td class="fw-semibold">$<?= number_format((float)$c['monto'],2) ?></td>
            <td><?= h($c['moneda']) ?></td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif ?>
  </div>

  <div class="col-md-5">
    <div class="card">
      <div class="card-header py-2">💵 Historial de Pagos</div>
      <div class="card-body p-0" style="max-height:400px;overflow-y:auto">
        <table class="table table-sm mb-0">
          <thead style="position:sticky;top:0;background:#fff">
            <tr><th>Fecha</th><th>Tipo</th><th>Monto</th></tr>
          </thead>
          <tbody>
          <?php foreach ($pag_all as $p):
            $tl = ['efectivo'=>'Efectivo','mercancia'=>'Mercancía','pm_trans'=>'PM/Trans'][$p['tipo']] ?? $p['tipo'];
            $tc = ['efectivo'=>'success','mercancia'=>'warning','pm_trans'=>'info'][$p['tipo']] ?? 'secondary';
          ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
            <td><span class="badge bg-<?=$tc?> pago-badge"><?= h($tl) ?></span></td>
            <td class="fw-semibold">$<?= number_format((float)$p['monto_usd'],2) ?></td>
          </tr>
          <?php endforeach ?>
          <?php if (!$pag_all): ?><tr><td colspan="3" class="text-center text-muted py-3">Sin pagos</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif ?>

<?php layout_end(); ?>
