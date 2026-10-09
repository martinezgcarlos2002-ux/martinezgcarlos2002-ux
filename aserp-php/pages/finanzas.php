<?php
require_login();
$db = get_db();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $tipo    = $_POST['tipo'] ?? 'ingreso';
        $monto   = (float)($_POST['monto'] ?? 0);
        $desc    = trim($_POST['descripcion'] ?? '');
        $moneda  = $_POST['moneda'] ?? 'USD';
        $origen  = $_POST['origen_tipo'] ?? 'manual';
        $fecha   = $_POST['fecha'] ?? date('Y-m-d');
        if ($id) {
            $db->prepare('UPDATE movimiento_caja SET tipo=?,monto=?,descripcion=?,moneda=?,origen_tipo=?,fecha=? WHERE id=?')
               ->execute([$tipo,$monto,$desc,$moneda,$origen,$fecha,$id]);
            flash('Movimiento actualizado.');
        } else {
            $db->prepare('INSERT INTO movimiento_caja(tipo,monto,descripcion,moneda,origen_tipo,fecha) VALUES(?,?,?,?,?,?)')
               ->execute([$tipo,$monto,$desc,$moneda,$origen,$fecha]);
            flash('Movimiento registrado.');
        }
    } elseif ($act === 'delete') {
        $db->prepare('DELETE FROM movimiento_caja WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Movimiento eliminado.', 'warning');
    } elseif ($act === 'set_tasa') {
        $tasa  = (float)($_POST['tasa'] ?? 0);
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $db->prepare('INSERT INTO tasa_cambio(tasa,fecha) VALUES(?,?)')->execute([$tasa,$fecha]);
        flash('Tasa de cambio registrada.');
    }
    header('Location: ?p=finanzas'); exit;
}

$tipo_f = $_GET['tipo'] ?? '';
$desde  = $_GET['desde'] ?? '';
$hasta  = $_GET['hasta'] ?? '';
$search = trim($_GET['q'] ?? '');
$conds = []; $params = [];
if ($tipo_f) { $conds[] = 'tipo = ?'; $params[] = $tipo_f; }
if ($desde)  { $conds[] = 'CAST(fecha AS DATE) >= ?'; $params[] = $desde; }
if ($hasta)  { $conds[] = 'CAST(fecha AS DATE) <= ?'; $params[] = $hasta; }
if ($search) { $conds[] = 'descripcion LIKE ?'; $params[] = "%$search%"; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$rows = $db->prepare("SELECT * FROM movimiento_caja $where ORDER BY fecha DESC, id DESC");
$rows->execute($params);
$rows = $rows->fetchAll();

$ingresos = array_sum(array_map(fn($r) => $r['tipo']==='ingreso' ? (float)$r['monto'] : 0, $rows));
$egresos  = array_sum(array_map(fn($r) => $r['tipo']==='egreso'  ? (float)$r['monto'] : 0, $rows));

$tasa_actual = $db->query('SELECT tasa, fecha FROM tasa_cambio ORDER BY fecha DESC LIMIT 1')->fetch();

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM movimiento_caja WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

layout_start('Finanzas / Caja', 'finanzas');
?>
<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="stat-card text-center"><div class="stat-val text-success"><?= fmt_dual($ingresos) ?></div><div class="stat-label">Ingresos (filtro)</div></div></div>
  <div class="col-md-3"><div class="stat-card text-center"><div class="stat-val text-danger"><?= fmt_dual($egresos) ?></div><div class="stat-label">Egresos (filtro)</div></div></div>
  <div class="col-md-3"><div class="stat-card text-center"><div class="stat-val <?= ($ingresos-$egresos)>=0?'text-success':'text-danger' ?>"><?= fmt_dual($ingresos-$egresos) ?></div><div class="stat-label">Balance</div></div></div>
  <div class="col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val"><?= $tasa_actual ? number_format((float)$tasa_actual['tasa'],2) : '—' ?></div>
      <div class="stat-label">Tasa cambio Bs/USD<?= $tasa_actual ? ' (' . fmt_date($tasa_actual['fecha']) . ')' : '' ?></div>
      <button class="btn btn-xs btn-sm btn-outline-primary mt-1" data-bs-toggle="modal" data-bs-target="#modalTasa">Actualizar</button>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2 flex-wrap" method="get">
    <input type="hidden" name="p" value="finanzas">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Descripción..." value="<?= h($search) ?>" style="width:160px">
    <select name="tipo" class="form-select form-select-sm" style="width:120px">
      <option value="">Todos</option>
      <option value="ingreso" <?= $tipo_f==='ingreso'?'selected':'' ?>>Ingresos</option>
      <option value="egreso"  <?= $tipo_f==='egreso'?'selected':'' ?>>Egresos</option>
    </select>
    <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>" style="width:140px">
    <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>" style="width:140px">
    <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
    <?php if ($search||$tipo_f||$desde||$hasta): ?><a href="?p=finanzas" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
  </form>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalMov">+ Movimiento</button>
</div>

<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar Movimiento</h5><a href="?p=finanzas" class="btn-close"></a></div>
    <div class="modal-body"><?= render_mov_form($edit) ?></div>
  </div></div>
</div>
<?php endif ?>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead><tr><th>Tipo</th><th>Descripción</th><th>Monto</th><th>Moneda</th><th>Origen</th><th>Fecha</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><span class="badge <?= $r['tipo']==='ingreso'?'bg-success':'bg-danger' ?>"><?= h($r['tipo']) ?></span></td>
        <td><?= h(mb_substr($r['descripcion'],0,50)) ?></td>
        <td><?= fmt_dual($r['monto'], $r['moneda'], true) ?></td>
        <td><?= h($r['moneda']) ?></td>
        <td><?= h($r['origen_tipo']) ?></td>
        <td><?= fmt_date($r['fecha']) ?></td>
        <td>
          <a href="?p=finanzas&action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Eliminar</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin movimientos</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modalMov" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Nuevo Movimiento</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><?= render_mov_form() ?></div>
  </div></div>
</div>

<div class="modal fade" id="modalTasa" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Actualizar Tasa de Cambio</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="set_tasa">
        <div class="mb-2"><label class="form-label">Tasa Bs/USD</label><input name="tasa" type="number" step="0.01" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Fecha</label><input name="fecha" type="date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <button class="btn btn-primary">Guardar</button>
      </form>
    </div>
  </div></div>
</div>
<?php layout_end();

function render_mov_form(?array $r = null): string {
    $csrf = csrf_token(); ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">
      <div class="row g-2">
        <div class="col-6"><label class="form-label">Tipo</label>
          <select name="tipo" class="form-select">
            <option value="ingreso" <?= ($r['tipo']??'')==='ingreso'?'selected':'' ?>>Ingreso</option>
            <option value="egreso"  <?= ($r['tipo']??'')==='egreso'?'selected':'' ?>>Egreso</option>
          </select>
        </div>
        <div class="col-6"><label class="form-label">Moneda</label>
          <select name="moneda" class="form-select">
            <option value="USD" <?= ($r['moneda']??'')==='USD'?'selected':'' ?>>USD</option>
            <option value="VES" <?= ($r['moneda']??'')==='VES'?'selected':'' ?>>VES</option>
          </select>
        </div>
        <div class="col-6"><label class="form-label">Monto</label><input name="monto" type="number" step="0.01" class="form-control" value="<?= h($r['monto'] ?? '0') ?>"></div>
        <div class="col-6"><label class="form-label">Origen</label>
          <select name="origen_tipo" class="form-select">
            <?php foreach (['manual','venta','productor','banco','otro'] as $o): ?>
            <option value="<?= $o ?>" <?= ($r['origen_tipo']??'manual')===$o?'selected':'' ?>><?= ucfirst($o) ?></option>
            <?php endforeach ?>
          </select>
        </div>
        <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2"><?= h($r['descripcion'] ?? '') ?></textarea></div>
        <div class="col-6"><label class="form-label">Fecha</label><input name="fecha" type="date" class="form-control" value="<?= h(isset($r['fecha']) ? substr($r['fecha'],0,10) : date('Y-m-d')) ?>"></div>
      </div>
      <div class="mt-3 d-flex gap-2"><button class="btn btn-primary">Guardar</button><a href="?p=finanzas" class="btn btn-outline-secondary">Cancelar</a></div>
    </form>
    <?php return ob_get_clean();
}
?>
