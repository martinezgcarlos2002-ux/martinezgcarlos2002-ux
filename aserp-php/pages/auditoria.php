<?php
require_login();
if (!is_admin()) { flash('Solo administradores pueden ver la auditoría.', 'error'); header('Location: ?p=dashboard'); exit; }
$db = get_db();

// ── POST: eliminar registros por rango de fecha ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'purge') {
        $del_desde = $_POST['del_desde'] ?? '';
        $del_hasta = $_POST['del_hasta'] ?? '';
        if (!$del_desde && !$del_hasta) {
            flash('Debes indicar al menos una fecha.', 'error');
            header('Location: ?p=auditoria'); exit;
        }
        $conds = []; $params = [];
        if ($del_desde) { $conds[] = 'CAST(creado_en AS DATE) >= ?'; $params[] = $del_desde; }
        if ($del_hasta) { $conds[] = 'CAST(creado_en AS DATE) <= ?'; $params[] = $del_hasta; }
        $where = 'WHERE ' . implode(' AND ', $conds);
        // Contar primero para el mensaje
        $cnt = $db->prepare("SELECT COUNT(*) FROM auditoria_sistema $where");
        $cnt->execute($params);
        $n = (int)$cnt->fetchColumn();
        $db->prepare("DELETE FROM auditoria_sistema $where")->execute($params);
        flash("Se eliminaron $n registros de auditoría.", 'warning');
        header('Location: ?p=auditoria'); exit;
    }

    header('Location: ?p=auditoria'); exit;
}

// ── Filtros / búsqueda ────────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$modulo  = $_GET['modulo'] ?? '';
$desde   = $_GET['desde'] ?? '';
$hasta   = $_GET['hasta'] ?? '';
$conds = []; $params = [];
if ($search) { $conds[] = "(a.accion LIKE ? OR a.ruta LIKE ? OR a.ip LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }
if ($modulo) { $conds[] = 'a.modulo = ?'; $params[] = $modulo; }
if ($desde)  { $conds[] = 'CAST(a.creado_en AS DATE) >= ?'; $params[] = $desde; }
if ($hasta)  { $conds[] = 'CAST(a.creado_en AS DATE) <= ?'; $params[] = $hasta; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$rows = $db->prepare(
    "SELECT a.*, u.nombre as usuario_nombre FROM auditoria_sistema a LEFT JOIN usuarios u ON u.id=a.usuario_id $where ORDER BY a.creado_en DESC LIMIT 200"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$modulos = $db->query('SELECT DISTINCT modulo FROM auditoria_sistema ORDER BY modulo')->fetchAll(PDO::FETCH_COLUMN);

// Total global de registros
$total_registros = (int)$db->query('SELECT COUNT(*) FROM auditoria_sistema')->fetchColumn();

layout_start('Auditoría del Sistema', 'auditoria');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2 flex-wrap align-items-center" method="get">
    <input type="hidden" name="p" value="auditoria">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar acción/ruta/IP..." value="<?= h($search) ?>" style="width:200px">
    <select name="modulo" class="form-select form-select-sm" style="width:140px">
      <option value="">Todos los módulos</option>
      <?php foreach ($modulos as $m): ?>
      <option value="<?= h($m) ?>" <?= $modulo===$m?'selected':'' ?>><?= h($m) ?></option>
      <?php endforeach ?>
    </select>
    <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>" style="width:140px">
    <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>" style="width:140px">
    <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
    <?php if ($search||$modulo||$desde||$hasta): ?><a href="?p=auditoria" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
  </form>

  <div class="d-flex align-items-center gap-2">
    <span class="badge bg-secondary"><?= count($rows) ?> / <?= $total_registros ?> registros</span>
    <button class="btn btn-sm btn-outline-danger"
            data-bs-toggle="modal" data-bs-target="#modalPurge"
            title="Eliminar registros por fecha">
      🗑 Limpiar registros
    </button>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover table-sm mb-0">
      <thead><tr><th>Fecha</th><th>Módulo</th><th>Acción</th><th>Método</th><th>Ruta</th><th>IP</th><th>Usuario</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <?php $badges = ['GET'=>'bg-secondary','POST'=>'bg-primary','PUT'=>'bg-warning text-dark','DELETE'=>'bg-danger','PATCH'=>'bg-info']; ?>
      <tr>
        <td class="text-nowrap small"><?= h(substr($r['creado_en'],0,16)) ?></td>
        <td><span class="badge bg-secondary"><?= h($r['modulo']) ?></span></td>
        <td><?= h($r['accion']) ?></td>
        <td><span class="badge <?= $badges[$r['metodo_http']] ?? 'bg-secondary' ?>"><?= h($r['metodo_http']) ?></span></td>
        <td class="small text-muted"><?= h(mb_substr($r['ruta'],0,40)) ?></td>
        <td class="small"><?= h($r['ip']) ?></td>
        <td class="small"><?= h($r['usuario_nombre'] ?? '—') ?></td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin registros de auditoría</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Limpiar registros -->
<div class="modal fade" id="modalPurge" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#dc2626;color:#fff">
        <h6 class="modal-title mb-0">🗑 Eliminar registros de auditoría</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" onsubmit="return confirmarPurge()">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="purge">
        <div class="modal-body">
          <p class="small text-muted mb-3">
            Selecciona el rango de fechas. Los registros dentro del rango serán eliminados de forma permanente.
            Puedes dejar una fecha vacía para eliminar todo hasta una fecha o desde una fecha.
          </p>
          <div class="mb-3">
            <label class="form-label form-label-sm fw-semibold">Desde (opcional)</label>
            <input type="date" name="del_desde" class="form-control form-control-sm" id="purge_desde">
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm fw-semibold">Hasta (opcional)</label>
            <input type="date" name="del_hasta" class="form-control form-control-sm" id="purge_hasta">
          </div>
          <div class="alert alert-warning py-2 small mt-3 mb-0">
            ⚠️ Esta acción es <strong>irreversible</strong>. Los registros eliminados no se pueden recuperar.
          </div>
        </div>
        <div class="modal-footer py-2 gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger btn-sm">Eliminar registros</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function confirmarPurge() {
  var desde = document.getElementById('purge_desde').value;
  var hasta  = document.getElementById('purge_hasta').value;
  if (!desde && !hasta) {
    alert('Debes indicar al menos una fecha.');
    return false;
  }
  var msg = '¿Eliminar registros de auditoría';
  if (desde && hasta) msg += ' del ' + desde + ' al ' + hasta;
  else if (desde)     msg += ' desde el ' + desde;
  else                msg += ' hasta el ' + hasta;
  msg += '?\n\nEsta acción es irreversible.';
  return confirm(msg);
}
</script>

<?php layout_end(); ?>
