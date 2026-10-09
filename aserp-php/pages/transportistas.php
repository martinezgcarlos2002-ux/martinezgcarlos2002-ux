<?php
require_login();
$db = get_db();
ensure_transportistas_schema();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $nombre  = trim($_POST['nombre'] ?? '');
        $doc     = trim($_POST['documento'] ?? '');
        $tel     = trim($_POST['telefono'] ?? '');
        $dir     = trim($_POST['direccion'] ?? '');
        $tarifa  = (float)($_POST['tarifa_litro_usd'] ?? 0);
        $prod_id = (int)($_POST['productor_id'] ?? 0) ?: null;
        $estado  = isset($_POST['estado']) ? 1 : 0;
        $est_val = is_mysql() ? $estado : ($estado ? 't' : 'f');
        if ($nombre === '') {
            flash('El nombre es obligatorio.', 'error');
        } elseif ($id) {
            $db->prepare('UPDATE transportistas SET nombre=?,documento=?,telefono=?,direccion=?,tarifa_litro_usd=?,productor_id=?,estado=? WHERE id=?')
               ->execute([$nombre,$doc,$tel,$dir,$tarifa,$prod_id,$est_val,$id]);
            $n = $prod_id ? backfill_entregas_transportista($db, (int)$prod_id, $id) : 0;
            flash($n > 0 ? "Transportista actualizado. Se vincularon $n entregas de leche de su productor." : 'Transportista actualizado.');
        } else {
            $db->prepare('INSERT INTO transportistas(nombre,documento,telefono,direccion,tarifa_litro_usd,productor_id,estado) VALUES(?,?,?,?,?,?,?)')
               ->execute([$nombre,$doc,$tel,$dir,$tarifa,$prod_id,$est_val]);
            $new_id = (int)$db->lastInsertId();
            $n = ($prod_id && $new_id) ? backfill_entregas_transportista($db, (int)$prod_id, $new_id) : 0;
            flash($n > 0 ? "Transportista creado. Se vincularon $n entregas de leche de su productor." : 'Transportista creado.');
        }
    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // ¿Tiene entregas ligadas? Si sí, solo desactivar para no perder el historial.
        $usados = (int)$db->query("SELECT COUNT(*) FROM productores_entregas WHERE transportista_id=$id")->fetchColumn();
        if ($usados > 0) {
            $db->prepare('UPDATE transportistas SET estado=? WHERE id=?')->execute([is_mysql()?0:'f', $id]);
            flash('El transportista tiene entregas registradas: se desactivó en lugar de eliminarse.', 'warning');
        } else {
            $db->prepare('DELETE FROM transportistas WHERE id=?')->execute([$id]);
            flash('Transportista eliminado.', 'warning');
        }
    }
    header('Location: ?p=transportistas'); exit;
}

// Backfill idempotente: vincula entregas de leche sin transportista al transportista
// asignado a su productor, para que los litros del control de leche se reflejen aquí.
try {
    $vinc = $db->query("SELECT id, productor_id FROM transportistas WHERE productor_id IS NOT NULL ORDER BY estado DESC, id ASC")->fetchAll();
    foreach ($vinc as $v) { backfill_entregas_transportista($db, (int)$v['productor_id'], (int)$v['id']); }
} catch (Throwable $e) {}

$search = trim($_GET['q'] ?? '');
$est_f  = $_GET['estado'] ?? '';
$conds = []; $params = [];
if ($search) { $conds[] = '(nombre LIKE ? OR documento LIKE ?)'; $params = ["%$search%","%$search%"]; }
if ($est_f === 'activo')   { $conds[] = 'estado' . (is_mysql() ? '=1' : '=true'); }
if ($est_f === 'inactivo') { $conds[] = 'estado' . (is_mysql() ? '=0' : '=false'); }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$rows = $db->prepare("SELECT * FROM transportistas $where ORDER BY nombre");
$rows->execute($params);
$rows = $rows->fetchAll();

// Litros y días transportados (resumen general, por transportista)
$stats = [];
try {
    $st = $db->query("
        SELECT transportista_id,
               COUNT(DISTINCT fecha) AS dias,
               COALESCE(SUM(litros),0) AS litros
        FROM productores_entregas
        WHERE transportista_id IS NOT NULL
        GROUP BY transportista_id
    ");
    foreach ($st->fetchAll() as $r) { $stats[(int)$r['transportista_id']] = $r; }
} catch (Throwable $e) {}

// Productores (para el buscador del formulario)
$productores_lista = $db->query("SELECT id, nombre FROM productores ORDER BY nombre ASC")->fetchAll();
$prod_map = [];
foreach ($productores_lista as $p) { $prod_map[(int)$p['id']] = $p['nombre']; }

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $s = $db->prepare('SELECT * FROM transportistas WHERE id=?');
    $s->execute([(int)$_GET['id']]);
    $edit = $s->fetch();
}

layout_start('Transportistas', 'transportistas');
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Transportistas</h4>
    <small class="text-muted">Personas que traen la leche. Se les paga por litro transportado.</small>
  </div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalTransp">+ Nuevo transportista</button>
</div>

<form class="d-flex gap-2 flex-wrap mb-3" method="get">
  <input type="hidden" name="p" value="transportistas">
  <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar nombre / documento..." value="<?= h($search) ?>" style="width:220px">
  <select name="estado" class="form-select form-select-sm" style="width:150px">
    <option value="">Todos</option>
    <option value="activo"   <?= $est_f==='activo'?'selected':'' ?>>Activos</option>
    <option value="inactivo" <?= $est_f==='inactivo'?'selected':'' ?>>Inactivos</option>
  </select>
  <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
  <?php if ($search || $est_f): ?><a href="?p=transportistas" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
</form>

<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar Transportista</h5><a href="?p=transportistas" class="btn-close"></a></div>
    <div class="modal-body"><?= render_transp_form($edit, $productores_lista) ?></div>
  </div></div>
</div>
<?php endif ?>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Transportista</th>
          <th>Documento</th>
          <th>Productor</th>
          <th class="text-end">Tarifa / Litro</th>
          <th class="text-end">Días con leche</th>
          <th class="text-end">Litros traídos</th>
          <th>Estado</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $s = $stats[(int)$r['id']] ?? ['dias'=>0,'litros'=>0];
        $activo = is_mysql() ? (int)$r['estado'] === 1 : ($r['estado'] === true || $r['estado'] === 't' || $r['estado'] === '1' || $r['estado'] === 1);
      ?>
      <tr>
        <td class="fw-semibold"><?= h($r['nombre']) ?></td>
        <td><?= h($r['documento']) ?: '—' ?></td>
        <td><?= h($prod_map[(int)($r['productor_id'] ?? 0)] ?? '') ?: '—' ?></td>
        <td class="text-end">$<?= number_format((float)$r['tarifa_litro_usd'], 4) ?></td>
        <td class="text-end"><?= (int)$s['dias'] ?></td>
        <td class="text-end"><?= number_format((float)$s['litros'], 1) ?> L</td>
        <td><span class="badge <?= $activo ? 'bg-success' : 'bg-secondary' ?>"><?= $activo ? 'Activo' : 'Inactivo' ?></span></td>
        <td class="text-end text-nowrap">
          <a href="?p=transportista_detalle&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-success">Detalle</a>
          <a href="?p=transportista_factura&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-dark" target="_blank">Factura</a>
          <a href="?p=transportistas&action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar transportista?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Eliminar</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Sin transportistas. Crea el primero con el botón "+ Nuevo transportista".</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modalTransp" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Nuevo Transportista</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><?= render_transp_form(null, $productores_lista) ?></div>
  </div></div>
</div>

<script>
function filtrarProductor(input){
  var q = input.value.toLowerCase();
  var field = input.closest('.prod-field');
  if(!field) return;
  field.querySelectorAll('.prod-select option').forEach(function(op){
    if(op.value === ''){ op.hidden = false; return; }
    op.hidden = op.textContent.toLowerCase().indexOf(q) === -1;
  });
}
</script>
<?php layout_end();

function render_transp_form(?array $r, array $productores = []): string {
    $csrf = csrf_token();
    $activo = $r === null ? true : (is_mysql() ? (int)$r['estado'] === 1 : ($r['estado'] === true || $r['estado'] === 't' || $r['estado'] === '1' || $r['estado'] === 1));
    $sel_prod = (int)($r['productor_id'] ?? 0);
    ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">
      <div class="row g-2">
        <div class="col-12"><label class="form-label">Nombre completo</label><input name="nombre" class="form-control" value="<?= h($r['nombre'] ?? '') ?>" required></div>
        <div class="col-6"><label class="form-label">Documento (C.I. / RIF)</label><input name="documento" class="form-control" value="<?= h($r['documento'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label">Teléfono</label><input name="telefono" class="form-control" value="<?= h($r['telefono'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Dirección</label><input name="direccion" class="form-control" value="<?= h($r['direccion'] ?? '') ?>"></div>
        <div class="col-12 prod-field">
          <label class="form-label">Productor a quien le busca la leche</label>
          <input type="text" class="form-control mb-1 prod-search" placeholder="Buscar productor por nombre..." autocomplete="off" onkeyup="filtrarProductor(this)">
          <select name="productor_id" class="form-select prod-select" size="5">
            <option value="" <?= $sel_prod ? '' : 'selected' ?>>— Ninguno —</option>
            <?php foreach ($productores as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $sel_prod === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option>
            <?php endforeach ?>
          </select>
          <small class="text-muted">El productor cuya leche recoge este transportista. Escriba arriba para buscarlo.</small>
        </div>
        <div class="col-6">
          <label class="form-label">Tarifa por litro (USD)</label>
          <div class="input-group">
            <span class="input-group-text">$</span>
            <input name="tarifa_litro_usd" type="number" step="0.0001" min="0" class="form-control" value="<?= h($r['tarifa_litro_usd'] ?? '0') ?>" required>
          </div>
          <small class="text-muted">Lo que se le paga por cada litro que transporta.</small>
        </div>
        <div class="col-6 d-flex align-items-center">
          <div class="form-check mt-4">
            <input type="checkbox" class="form-check-input" name="estado" id="tr_estado" <?= $activo ? 'checked' : '' ?>>
            <label class="form-check-label" for="tr_estado">Activo</label>
          </div>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2"><button class="btn btn-primary">Guardar</button><a href="?p=transportistas" class="btn btn-outline-secondary">Cancelar</a></div>
    </form>
    <?php return ob_get_clean();
}
?>
