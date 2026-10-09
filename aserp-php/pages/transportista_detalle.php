<?php
require_login();
$db = get_db();
ensure_transportistas_schema();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ?p=transportistas'); exit; }

// ── Guardar / eliminar abonos (pagos al transportista) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save_abono') {
        $fecha   = $_POST['fecha'] ?? date('Y-m-d');
        $monto   = (float)($_POST['monto_usd'] ?? 0);
        $tipo    = $_POST['tipo'] ?? 'efectivo';
        $desc    = trim($_POST['descripcion'] ?? '');
        $semana  = $_POST['semana_aplicada'] ?? null;
        if ($semana === '') $semana = null;
        $db->prepare('INSERT INTO transportistas_abonos(transportista_id,fecha,monto_usd,tipo,descripcion,semana_aplicada) VALUES(?,?,?,?,?,?)')
           ->execute([$id,$fecha,$monto,$tipo,$desc,$semana]);
        flash('Pago registrado.');
    } elseif ($act === 'del_abono') {
        $db->prepare('DELETE FROM transportistas_abonos WHERE id=? AND transportista_id=?')->execute([(int)($_POST['abono_id'] ?? 0), $id]);
        flash('Pago eliminado.', 'warning');
    }
    $qs = 'desde=' . urlencode($_GET['desde'] ?? '') . '&hasta=' . urlencode($_GET['hasta'] ?? '');
    header("Location: ?p=transportista_detalle&id=$id&$qs"); exit;
}

$t = $db->prepare('SELECT * FROM transportistas WHERE id=?');
$t->execute([$id]);
$t = $t->fetch();
if (!$t) { flash('Transportista no encontrado.', 'error'); header('Location: ?p=transportistas'); exit; }

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta))  $hasta = date('Y-m-d');

$tarifa = (float)$t['tarifa_litro_usd'];
$tasa   = get_tasa();

// Entregas transportadas en el período
$ent = $db->prepare("
    SELECT e.fecha, e.litros, p.nombre AS productor_nombre, e.subtotal_usd
    FROM productores_entregas e
    JOIN productores p ON p.id = e.productor_id
    WHERE e.transportista_id = ? AND e.fecha >= ? AND e.fecha <= ?
    ORDER BY e.fecha DESC, p.nombre ASC
");
$ent->execute([$id, $desde, $hasta]);
$entregas = $ent->fetchAll();

// Resumen por día y por productor
$dias = []; $por_productor = [];
$tot_litros = 0.0; $tot_leche = 0.0;
foreach ($entregas as $e) {
    $tot_litros += (float)$e['litros'];
    $tot_leche  += (float)$e['subtotal_usd'];
    $dias[$e['fecha']] = true;
    $pn = $e['productor_nombre'];
    if (!isset($por_productor[$pn])) $por_productor[$pn] = ['litros'=>0,'dias'=>[]];
    $por_productor[$pn]['litros'] += (float)$e['litros'];
    $por_productor[$pn]['dias'][$e['fecha']] = true;
}
arsort($por_productor);
$n_dias      = count($dias);
$tot_pago    = round($tot_litros * $tarifa, 2);

// Abonos del período
$abo = $db->prepare('SELECT * FROM transportistas_abonos WHERE transportista_id=? AND COALESCE(semana_aplicada,fecha)>=? AND COALESCE(semana_aplicada,fecha)<=? ORDER BY fecha DESC');
$abo->execute([$id, $desde, $hasta]);
$abonos = $abo->fetchAll();
$tot_pagado = array_sum(array_column($abonos, 'monto_usd'));
$saldo = $tot_pago - $tot_pagado;

$tipo_labels = ['efectivo'=>'Efectivo','transferencia'=>'Transferencia','pago_movil'=>'Pago Móvil','otro'=>'Otro'];

layout_start('Transportista — ' . $t['nombre'], 'transportistas');
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0"><?= h($t['nombre']) ?></h4>
    <small class="text-muted">
      <?php if ($t['documento']): ?>C.I./RIF: <?= h($t['documento']) ?> · <?php endif ?>
      Tarifa: <strong>$<?= number_format($tarifa, 4) ?></strong> / litro
    </small>
  </div>
  <div class="d-flex gap-2">
    <a href="?p=transportista_factura&id=<?= $id ?>&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>" class="btn btn-sm btn-dark" target="_blank">🧾 Generar Factura</a>
    <a href="?p=transportistas" class="btn btn-sm btn-outline-secondary">← Volver</a>
  </div>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <input type="hidden" name="p" value="transportista_detalle">
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="col-auto"><label class="form-label form-label-sm mb-0">Desde</label><input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>"></div>
  <div class="col-auto"><label class="form-label form-label-sm mb-0">Hasta</label><input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>"></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Filtrar período</button></div>
</form>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon">📅</div>
      <div class="stat-val"><?= $n_dias ?></div>
      <div class="stat-label">Días que trajo leche</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon">🥛</div>
      <div class="stat-val"><?= number_format($tot_litros, 1) ?> L</div>
      <div class="stat-label">Litros transportados</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon">💵</div>
      <div class="stat-val text-primary"><?= fmt_dual($tot_pago) ?></div>
      <div class="stat-label">Total a pagar (tarifa × litros)</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon">🧾</div>
      <div class="stat-val <?= $saldo > 0 ? 'text-danger' : 'text-success' ?>"><?= fmt_dual($saldo) ?></div>
      <div class="stat-label">Saldo pendiente</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Desglose por productor -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header">🥛 Leche traída por productor</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Productor</th><th class="text-end">Días</th><th class="text-end">Litros</th></tr></thead>
          <tbody>
          <?php foreach ($por_productor as $pn => $d): ?>
          <tr>
            <td><?= h($pn) ?></td>
            <td class="text-end"><?= count($d['dias']) ?></td>
            <td class="text-end"><?= number_format($d['litros'], 1) ?> L</td>
          </tr>
          <?php endforeach ?>
          <?php if (!$por_productor): ?><tr><td colspan="3" class="text-center text-muted py-3">Sin entregas en el período</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Entregas transportadas -->
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header">🚛 Entregas transportadas</div>
      <div class="card-body p-0" style="max-height:340px;overflow:auto">
        <table class="table table-sm mb-0">
          <thead><tr><th>Fecha</th><th>Productor</th><th class="text-end">Litros</th><th class="text-end">Pago ($<?= number_format($tarifa,4) ?>/L)</th></tr></thead>
          <tbody>
          <?php foreach ($entregas as $e): ?>
          <tr>
            <td><?= fmt_date($e['fecha']) ?></td>
            <td><?= h($e['productor_nombre']) ?></td>
            <td class="text-end"><?= number_format((float)$e['litros'], 1) ?></td>
            <td class="text-end">$<?= number_format((float)$e['litros'] * $tarifa, 2) ?></td>
          </tr>
          <?php endforeach ?>
          <?php if (!$entregas): ?><tr><td colspan="4" class="text-center text-muted py-3">Sin entregas en el período</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Pagos / abonos -->
<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>💰 Pagos al transportista</span>
    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalAbono">+ Registrar pago</button>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Fecha</th><th>Semana aplicada</th><th>Tipo</th><th>Descripción</th><th class="text-end">Monto</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($abonos as $a): ?>
      <tr>
        <td><?= fmt_date($a['fecha']) ?></td>
        <td><?= $a['semana_aplicada'] ? 'Sem. ' . fmt_date($a['semana_aplicada']) : '—' ?></td>
        <td><?= h($tipo_labels[$a['tipo']] ?? $a['tipo']) ?></td>
        <td class="text-muted small"><?= h($a['descripcion']) ?: '—' ?></td>
        <td class="text-end fw-semibold"><?= fmt_dual($a['monto_usd']) ?></td>
        <td class="text-end">
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este pago?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="del_abono">
            <input type="hidden" name="abono_id" value="<?= $a['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">✕</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$abonos): ?><tr><td colspan="6" class="text-center text-muted py-3">Sin pagos registrados en el período</td></tr><?php endif ?>
      </tbody>
      <tfoot>
        <tr class="table-light">
          <td colspan="4"><strong>Total pagado</strong></td>
          <td class="text-end"><strong><?= fmt_dual($tot_pagado) ?></strong></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="modal fade" id="modalAbono" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <div class="modal-header"><h5 class="modal-title">Registrar pago a <?= h($t['nombre']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_abono">
        <div class="row g-2">
          <div class="col-6"><label class="form-label">Fecha del pago</label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-6">
            <label class="form-label">Monto (USD)</label>
            <div class="input-group"><span class="input-group-text">$</span><input type="number" name="monto_usd" step="0.01" min="0" class="form-control" required></div>
          </div>
          <div class="col-6">
            <label class="form-label">Tipo</label>
            <select name="tipo" class="form-select">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia">Transferencia</option>
              <option value="pago_movil">Pago Móvil</option>
              <option value="otro">Otro</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label">Semana aplicada <span class="text-muted">(opc.)</span></label><input type="date" name="semana_aplicada" class="form-control"></div>
          <div class="col-12"><label class="form-label">Descripción <span class="text-muted">(opc.)</span></label><input type="text" name="descripcion" class="form-control" placeholder="Notas..."></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success">💾 Guardar pago</button>
      </div>
    </form>
  </div></div>
</div>
<?php layout_end(); ?>
