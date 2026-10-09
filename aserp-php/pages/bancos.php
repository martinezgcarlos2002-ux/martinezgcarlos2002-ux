<?php
require_login();
$db = get_db();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $nro    = trim($_POST['numero_cuenta'] ?? '');
        $saldo  = (float)($_POST['saldo'] ?? 0);
        $estado = form_bool($_POST['estado'] ?? 'activo');
        if ($id) {
            $db->prepare('UPDATE bancos SET nombre=?,numero_cuenta=?,saldo=?,estado=? WHERE id=?')
               ->execute([$nombre,$nro,$saldo,$estado,$id]);
            flash('Cuenta actualizada.');
        } else {
            $db->prepare('INSERT INTO bancos(nombre,numero_cuenta,saldo,estado) VALUES(?,?,?,?)')
               ->execute([$nombre,$nro,$saldo,$estado]);
            flash('Cuenta creada.');
        }
    } elseif ($act === 'delete') {
        $db->prepare('DELETE FROM bancos WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Cuenta eliminada.', 'warning');
    }
    header('Location: ?p=bancos'); exit;
}

$search = trim($_GET['q'] ?? '');
$where = $search ? "WHERE nombre LIKE ? OR numero_cuenta LIKE ?" : '';
$params = $search ? ["%$search%","%$search%"] : [];
$rows = $db->prepare("SELECT * FROM bancos $where ORDER BY nombre");
$rows->execute($params);
$rows = $rows->fetchAll();

$total_activo = array_sum(array_map(fn($r) => bool_estado($r['estado']) ? (float)$r['saldo'] : 0, $rows));

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM bancos WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

layout_start('Cuentas Bancarias', 'bancos');
?>
<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="stat-card text-center"><div class="stat-val"><?= count($rows) ?></div><div class="stat-label">Cuentas totales</div></div></div>
  <div class="col-md-3"><div class="stat-card text-center"><div class="stat-val text-success"><?= fmt_money($total_activo) ?></div><div class="stat-label">Saldo cuentas activas</div></div></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
  <form class="d-flex gap-2" method="get">
    <input type="hidden" name="p" value="bancos">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar cuenta..." value="<?= h($search) ?>" style="width:220px">
    <button class="btn btn-sm btn-outline-secondary">Buscar</button>
    <?php if ($search): ?><a href="?p=bancos" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
  </form>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalBanco">+ Nueva cuenta</button>
</div>

<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar Cuenta</h5><a href="?p=bancos" class="btn-close"></a></div>
    <div class="modal-body"><?= render_banco_form($edit) ?></div>
  </div></div>
</div>
<?php endif ?>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead><tr><th>Nombre</th><th>Número de cuenta</th><th>Saldo</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['nombre']) ?></td>
        <td><code><?= h($r['numero_cuenta']) ?></code></td>
        <td class="<?= (float)$r['saldo'] >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold"><?= fmt_money($r['saldo']) ?></td>
        <?php $banco_activo = bool_estado($r['estado']); ?>
        <td><span class="badge <?= $banco_activo ? 'bg-success' : 'bg-secondary' ?>"><?= $banco_activo ? 'Activo' : 'Inactivo' ?></span></td>
        <td>
          <a href="?p=bancos&action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Eliminar</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">Sin cuentas</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modalBanco" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Nueva Cuenta</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><?= render_banco_form() ?></div>
  </div></div>
</div>
<?php layout_end();

function render_banco_form(?array $r = null): string {
    $csrf = csrf_token(); ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">
      <div class="mb-2"><label class="form-label">Nombre del banco</label><input name="nombre" class="form-control" value="<?= h($r['nombre'] ?? '') ?>" required></div>
      <div class="mb-2"><label class="form-label">Número de cuenta</label><input name="numero_cuenta" class="form-control" value="<?= h($r['numero_cuenta'] ?? '') ?>"></div>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label">Saldo inicial</label><input name="saldo" type="number" step="0.01" class="form-control" value="<?= h($r['saldo'] ?? '0') ?>"></div>
        <div class="col-6"><label class="form-label">Estado</label>
          <select name="estado" class="form-select">
            <?php $es_activo_b = !isset($r['estado']) || bool_estado($r['estado']); ?>
            <option value="activo"   <?= $es_activo_b  ? 'selected' : '' ?>>Activa</option>
            <option value="inactivo" <?= !$es_activo_b ? 'selected' : '' ?>>Inactiva</option>
          </select>
        </div>
      </div>
      <div class="d-flex gap-2"><button class="btn btn-primary">Guardar</button><a href="?p=bancos" class="btn btn-outline-secondary">Cancelar</a></div>
    </form>
    <?php return ob_get_clean();
}
?>
