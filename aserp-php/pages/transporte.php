<?php
require_login();
$db = get_db();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $nombre   = trim($_POST['nombre_vehiculo'] ?? '');
        $placa    = trim($_POST['placa'] ?? '');
        $cap      = (float)($_POST['capacidad'] ?? 0);
        $estado   = $_POST['estado'] ?? 'disponible';
        $cond_id  = (int)($_POST['conductor_actual_id'] ?? 0) ?: null;
        if ($id) {
            $db->prepare('UPDATE vehiculos SET nombre_vehiculo=?,placa=?,capacidad=?,estado=?,conductor_actual_id=? WHERE id=?')
               ->execute([$nombre,$placa,$cap,$estado,$cond_id,$id]);
            flash('Vehículo actualizado.');
        } else {
            $db->prepare('INSERT INTO vehiculos(nombre_vehiculo,placa,capacidad,estado,conductor_actual_id) VALUES(?,?,?,?,?)')
               ->execute([$nombre,$placa,$cap,$estado,$cond_id]);
            flash('Vehículo creado.');
        }
    } elseif ($act === 'delete') {
        $db->prepare('DELETE FROM vehiculos WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Vehículo eliminado.', 'warning');
    }
    header('Location: ?p=transporte'); exit;
}

$search  = trim($_GET['q'] ?? '');
$est_f   = $_GET['estado'] ?? '';
$conds = []; $params = [];
if ($search) { $conds[] = '(v.nombre_vehiculo LIKE ? OR v.placa LIKE ?)'; $params = ["%$search%","%$search%"]; }
if ($est_f)  { $conds[] = 'v.estado = ?'; $params[] = $est_f; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$rows = $db->prepare(
    "SELECT v.*, e.nombre as conductor_nombre FROM vehiculos v LEFT JOIN empleados e ON e.id=v.conductor_actual_id $where ORDER BY v.nombre_vehiculo"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$empleados = $db->query("SELECT id, nombre FROM empleados WHERE estado=true ORDER BY nombre")->fetchAll();

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM vehiculos WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

layout_start('Transporte / Vehículos', 'transporte');
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2 flex-wrap" method="get">
    <input type="hidden" name="p" value="transporte">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar vehículo/placa..." value="<?= h($search) ?>" style="width:200px">
    <select name="estado" class="form-select form-select-sm" style="width:140px">
      <option value="">Todos</option>
      <option value="disponible" <?= $est_f==='disponible'?'selected':'' ?>>Disponible</option>
      <option value="en_ruta" <?= $est_f==='en_ruta'?'selected':'' ?>>En ruta</option>
      <option value="mantenimiento" <?= $est_f==='mantenimiento'?'selected':'' ?>>Mantenimiento</option>
    </select>
    <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
    <?php if ($search || $est_f): ?><a href="?p=transporte" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
  </form>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalVeh">+ Nuevo</button>
</div>

<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar Vehículo</h5><a href="?p=transporte" class="btn-close"></a></div>
    <div class="modal-body"><?= render_veh_form($edit, $empleados) ?></div>
  </div></div>
</div>
<?php endif ?>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead><tr><th>Vehículo</th><th>Placa</th><th>Capacidad</th><th>Estado</th><th>Conductor</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <?php $badges = ['disponible'=>'bg-success','en_ruta'=>'bg-primary','mantenimiento'=>'bg-warning text-dark']; ?>
      <tr>
        <td><?= h($r['nombre_vehiculo']) ?></td>
        <td><?= h($r['placa']) ?></td>
        <td><?= h($r['capacidad']) ?> kg</td>
        <td><span class="badge <?= $badges[$r['estado']] ?? 'bg-secondary' ?>"><?= h($r['estado']) ?></span></td>
        <td><?= h($r['conductor_nombre'] ?? '—') ?></td>
        <td>
          <a href="?p=transporte&action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Eliminar</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin vehículos</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modalVeh" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Nuevo Vehículo</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><?= render_veh_form(null, $empleados) ?></div>
  </div></div>
</div>
<?php layout_end();

function render_veh_form(?array $r, array $empleados): string {
    $csrf = csrf_token(); ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">
      <div class="row g-2">
        <div class="col-8"><label class="form-label">Nombre vehículo</label><input name="nombre_vehiculo" class="form-control" value="<?= h($r['nombre_vehiculo'] ?? '') ?>" required></div>
        <div class="col-4"><label class="form-label">Placa</label><input name="placa" class="form-control" value="<?= h($r['placa'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label">Capacidad (kg)</label><input name="capacidad" type="number" step="0.1" class="form-control" value="<?= h($r['capacidad'] ?? '0') ?>"></div>
        <div class="col-6"><label class="form-label">Estado</label>
          <select name="estado" class="form-select">
            <option value="disponible" <?= ($r['estado']??'')==='disponible'?'selected':'' ?>>Disponible</option>
            <option value="en_ruta" <?= ($r['estado']??'')==='en_ruta'?'selected':'' ?>>En ruta</option>
            <option value="mantenimiento" <?= ($r['estado']??'')==='mantenimiento'?'selected':'' ?>>Mantenimiento</option>
          </select>
        </div>
        <div class="col-12"><label class="form-label">Conductor</label>
          <select name="conductor_actual_id" class="form-select">
            <option value="">Sin conductor</option>
            <?php foreach ($empleados as $e): ?>
            <option value="<?= $e['id'] ?>" <?= ($r['conductor_actual_id'] ?? '') == $e['id'] ? 'selected' : '' ?>><?= h($e['nombre']) ?></option>
            <?php endforeach ?>
          </select>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2"><button class="btn btn-primary">Guardar</button><a href="?p=transporte" class="btn btn-outline-secondary">Cancelar</a></div>
    </form>
    <?php return ob_get_clean();
}
?>
