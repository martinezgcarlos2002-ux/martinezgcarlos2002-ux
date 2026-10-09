<?php
require_login();
$db = get_db();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $prod_id    = (int)($_POST['producto_id'] ?? 0);
        $cantidad   = (int)($_POST['cantidad'] ?? 0);
        $vencim     = $_POST['fecha_vencimiento'] ?? null;
        if ($id) {
            $db->prepare('UPDATE lotes SET producto_id=?,cantidad=?,fecha_vencimiento=? WHERE id=?')
               ->execute([$prod_id,$cantidad,$vencim ?: null,$id]);
            flash('Lote actualizado.');
        } else {
            $db->prepare('INSERT INTO lotes(producto_id,cantidad,fecha_vencimiento) VALUES(?,?,?)')
               ->execute([$prod_id,$cantidad,$vencim ?: null]);
            flash('Lote creado.');
        }
    } elseif ($act === 'delete') {
        $db->prepare('DELETE FROM lotes WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Lote eliminado.', 'warning');
    }
    header('Location: ?p=lotes'); exit;
}

$search   = trim($_GET['q'] ?? '');
$desde    = $_GET['desde'] ?? '';
$hasta    = $_GET['hasta'] ?? '';
$conds = []; $params = [];
if ($search) { $conds[] = 'pr.nombre LIKE ?'; $params[] = "%$search%"; }
if ($desde)  { $conds[] = 'CAST(l.creado_en AS DATE) >= ?'; $params[] = $desde; }
if ($hasta)  { $conds[] = 'CAST(l.creado_en AS DATE) <= ?'; $params[] = $hasta; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$rows = $db->prepare(
    "SELECT l.*, pr.nombre as producto_nombre FROM lotes l JOIN productos pr ON pr.id=l.producto_id $where ORDER BY l.creado_en DESC"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$productos = $db->query('SELECT id, nombre FROM productos ORDER BY nombre')->fetchAll();

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM lotes WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

layout_start('Lotes de Producción', 'lotes');
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2 flex-wrap" method="get">
    <input type="hidden" name="p" value="lotes">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar producto..." value="<?= h($search) ?>" style="width:180px">
    <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>" style="width:140px">
    <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>" style="width:140px">
    <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
    <?php if ($search || $desde || $hasta): ?><a href="?p=lotes" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
  </form>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalLote">+ Nuevo</button>
</div>

<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar Lote</h5><a href="?p=lotes" class="btn-close"></a></div>
    <div class="modal-body"><?= render_lote_form($edit, $productos) ?></div>
  </div></div>
</div>
<?php endif ?>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Producto</th><th>Cantidad</th><th>Vencimiento</th><th>Creado</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <?php
        $venc = $r['fecha_vencimiento'] ? new DateTime($r['fecha_vencimiento']) : null;
        $hoy  = new DateTime();
        $estado = 'Vigente';
        $badge  = 'bg-success';
        if ($venc) {
            $diff = $hoy->diff($venc)->days * ($venc < $hoy ? -1 : 1);
            if ($diff < 0) { $estado = 'Vencido'; $badge = 'bg-danger'; }
            elseif ($diff <= 30) { $estado = 'Por vencer'; $badge = 'bg-warning text-dark'; }
        }
      ?>
      <tr>
        <td><?= h($r['id']) ?></td>
        <td><?= h($r['producto_nombre']) ?></td>
        <td><?= h($r['cantidad']) ?></td>
        <td><?= fmt_date($r['fecha_vencimiento']) ?></td>
        <td><?= fmt_date($r['creado_en']) ?></td>
        <td><span class="badge <?= $badge ?>"><?= $estado ?></span></td>
        <td>
          <a href="?p=lotes&action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Eliminar</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin lotes</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modalLote" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Nuevo Lote</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><?= render_lote_form(null, $productos) ?></div>
  </div></div>
</div>
<?php layout_end();

function render_lote_form(?array $r, array $productos): string {
    $csrf = csrf_token();
    ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">
      <div class="mb-3">
        <label class="form-label">Producto</label>
        <select name="producto_id" class="form-select" required>
          <option value="">Seleccionar...</option>
          <?php foreach ($productos as $p): ?>
          <option value="<?= $p['id'] ?>" <?= ($r['producto_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label">Cantidad</label><input name="cantidad" type="number" class="form-control" value="<?= h($r['cantidad'] ?? '0') ?>" required></div>
      <div class="mb-3"><label class="form-label">Fecha de vencimiento</label>
        <input name="fecha_vencimiento" type="date" class="form-control" value="<?= h(isset($r['fecha_vencimiento']) ? substr($r['fecha_vencimiento'],0,10) : '') ?>">
      </div>
      <div class="d-flex gap-2"><button class="btn btn-primary">Guardar</button><a href="?p=lotes" class="btn btn-outline-secondary">Cancelar</a></div>
    </form>
    <?php return ob_get_clean();
}
?>
