<?php
require_login();
$db = get_db();

/* ── POST: acciones ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $fecha     = $_POST['fecha']       ?? date('Y-m-d');
        $tipo      = trim($_POST['tipo_queso']  ?? 'Queso');
        $litros    = (float)($_POST['litros_leche'] ?? 0);
        $factor    = (float)($_POST['factor']   ?? 8);
        $kg_real   = $_POST['kg_real'] !== '' ? (float)$_POST['kg_real'] : null;
        $obs       = trim($_POST['observaciones'] ?? '');

        if ($factor <= 0) $factor = 1;

        if ($id > 0) {
            $db->prepare("UPDATE producciones_queso
                SET fecha=?, tipo_queso=?, litros_leche=?, factor=?, kg_real=?, observaciones=?
                WHERE id=?")
               ->execute([$fecha, $tipo, $litros, $factor, $kg_real, $obs, $id]);
            set_flash('success', 'Producción actualizada.');
        } else {
            $db->prepare("INSERT INTO producciones_queso(fecha,tipo_queso,litros_leche,factor,kg_real,observaciones)
                VALUES(?,?,?,?,?,?)")
               ->execute([$fecha, $tipo, $litros, $factor, $kg_real, $obs]);
            set_flash('success', 'Producción registrada.');
        }
    }

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM producciones_queso WHERE id=?")->execute([$id]);
            set_flash('success', 'Registro eliminado.');
        }
    }

    header('Location: ?p=produccion_queso'); exit;
}

/* ── Filtros ─────────────────────────────────────────────────────────────── */
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ? $desde : date('Y-m-01');
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) ? $hasta : date('Y-m-d');

/* ── Datos ───────────────────────────────────────────────────────────────── */
$rows = $db->prepare("
    SELECT * FROM producciones_queso
    WHERE fecha BETWEEN ? AND ?
    ORDER BY fecha DESC, id DESC
");
$rows->execute([$desde, $hasta]);
$rows = $rows->fetchAll();

$tot_litros   = array_sum(array_column($rows, 'litros_leche'));
$tot_esperado = array_sum(array_column($rows, 'kg_esperado'));
$tot_real     = array_sum(array_filter(array_column($rows, 'kg_real'), fn($v) => $v !== null));
$n_real       = count(array_filter($rows, fn($r) => $r['kg_real'] !== null));
$rendimiento_pct = ($tot_esperado > 0 && $tot_real > 0)
    ? round(($tot_real / $tot_esperado) * 100, 1) : null;

/* ── Registro a editar ───────────────────────────────────────────────────── */
$edit = null;
if (isset($_GET['edit'])) {
    $st = $db->prepare("SELECT * FROM producciones_queso WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

require_once __DIR__ . '/../layout.php';
layout_start('Producción de Queso', 'produccion_queso');
?>

<style>
.factor-badge { font-size:.75rem; background:#e8efff; color:#1a2e5a; border-radius:20px; padding:2px 10px; font-weight:600; }
.tbl-queso th { background:#1a2e5a; color:#fff; white-space:nowrap; font-size:.84rem; position:sticky; top:0; }
.tbl-queso td { font-size:.84rem; vertical-align:middle; }
.tbl-queso tr:hover { background:#f5f8ff; }
.kpi-card { background:#fff; border:1px solid #dee2e6; border-radius:10px; padding:14px 18px; text-align:center; }
.kpi-card .val { font-size:1.5rem; font-weight:700; }
.kpi-card .lbl { font-size:.72rem; color:#6c757d; margin-top:3px; }
.diff-pos { color:#198754; font-weight:600; }
.diff-neg { color:#dc3545; font-weight:600; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0">🧀 Producción de Queso</h5>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalProd">
    + Nueva Producción
  </button>
</div>

<!-- Filtros -->
<form method="get" class="mb-3">
  <input type="hidden" name="p" value="produccion_queso">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label form-label-sm mb-1">Desde</label>
      <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label form-label-sm mb-1">Hasta</label>
      <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>">
    </div>
    <div class="col-12 col-md-2">
      <button type="submit" class="btn btn-primary btn-sm w-100">🔍 Filtrar</button>
    </div>
    <div class="col-12 col-md-4 d-flex flex-wrap gap-1 align-items-end">
      <?php foreach ([
        'Este mes'     => [date('Y-m-01'), date('Y-m-d')],
        'Mes anterior' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'Últimos 30 d' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
      ] as $lbl => [$d1,$d2]): ?>
      <a href="?p=produccion_queso&desde=<?=$d1?>&hasta=<?=$d2?>" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.72rem"><?= $lbl ?></a>
      <?php endforeach ?>
    </div>
  </div>
</form>

<!-- KPIs -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="val text-primary"><?= number_format($tot_litros, 1) ?> L</div>
      <div class="lbl">Leche procesada</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="val" style="color:#1a2e5a"><?= number_format($tot_esperado, 2) ?> kg</div>
      <div class="lbl">Queso esperado (calculado)</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <div class="val text-success"><?= $tot_real > 0 ? number_format($tot_real, 2).' kg' : '—' ?></div>
      <div class="lbl">Queso real producido</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card">
      <?php if ($rendimiento_pct !== null): ?>
        <div class="val <?= $rendimiento_pct >= 100 ? 'text-success' : 'text-warning' ?>"><?= $rendimiento_pct ?>%</div>
        <div class="lbl">Eficiencia real vs esperado</div>
      <?php else: ?>
        <div class="val text-muted">—</div>
        <div class="lbl">Eficiencia (sin datos reales)</div>
      <?php endif ?>
    </div>
  </div>
</div>

<!-- Tabla -->
<div class="card">
  <div class="card-body p-0" style="max-height:65vh;overflow-y:auto">
    <table class="table table-sm mb-0 tbl-queso">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Tipo de queso</th>
          <th class="text-end">Litros leche</th>
          <th class="text-center">Factor (L/kg)</th>
          <th class="text-end">Kg esperado</th>
          <th class="text-end">Kg real</th>
          <th class="text-end">Diferencia</th>
          <th>Obs.</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted py-5">No hay producciones en el período. ¡Registra la primera!</td></tr>
      <?php endif ?>
      <?php foreach ($rows as $r):
        $esperado = (float)$r['kg_esperado'];
        $real     = $r['kg_real'] !== null ? (float)$r['kg_real'] : null;
        $diff     = $real !== null ? $real - $esperado : null;
        $diff_cls = $diff === null ? '' : ($diff >= 0 ? 'diff-pos' : 'diff-neg');
      ?>
      <tr>
        <td><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
        <td><strong><?= h($r['tipo_queso']) ?></strong></td>
        <td class="text-end text-primary fw-semibold"><?= number_format((float)$r['litros_leche'], 3) ?> L</td>
        <td class="text-center"><span class="factor-badge">÷ <?= number_format((float)$r['factor'], 2) ?></span></td>
        <td class="text-end fw-bold"><?= number_format($esperado, 3) ?> kg</td>
        <td class="text-end text-success fw-semibold"><?= $real !== null ? number_format($real, 3).' kg' : '<span class="text-muted">—</span>' ?></td>
        <td class="text-end <?= $diff_cls ?>">
          <?= $diff !== null ? ($diff >= 0 ? '+' : '').number_format($diff, 3).' kg' : '—' ?>
        </td>
        <td class="text-muted" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['observaciones']) ?>">
          <?= h($r['observaciones']) ?: '—' ?>
        </td>
        <td class="text-end text-nowrap">
          <a href="?p=produccion_queso&edit=<?= $r['id'] ?>&desde=<?= h($desde) ?>&hasta=<?= h($hasta) ?>"
             class="btn btn-xs btn-sm btn-outline-primary py-0 px-1 me-1">✏️</a>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta producción?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1">✕</button>
          </form>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot>
        <tr style="background:#1a2e5a;color:#fff;font-weight:700">
          <td colspan="2">TOTALES</td>
          <td class="text-end"><?= number_format($tot_litros, 3) ?> L</td>
          <td></td>
          <td class="text-end"><?= number_format($tot_esperado, 3) ?> kg</td>
          <td class="text-end"><?= $tot_real > 0 ? number_format($tot_real, 3).' kg' : '—' ?></td>
          <td class="text-end">
            <?php if ($tot_real > 0):
              $td = $tot_real - $tot_esperado; ?>
              <?= ($td >= 0 ? '+' : '').number_format($td, 3) ?> kg
            <?php else: ?>—<?php endif ?>
          </td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif ?>
    </table>
  </div>
</div>

<!-- ── Modal Nueva / Editar ───────────────────────────────────────────────── -->
<?php $is_edit = $edit !== null; ?>
<div class="modal fade <?= $is_edit ? 'show' : '' ?>" id="modalProd" tabindex="-1"
     style="<?= $is_edit ? 'display:block' : '' ?>" <?= $is_edit ? 'aria-modal="true"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#1a2e5a;color:#fff">
        <h5 class="modal-title">🧀 <?= $is_edit ? 'Editar Producción' : 'Nueva Producción' ?></h5>
        <a href="?p=produccion_queso&desde=<?= h($desde) ?>&hasta=<?= h($hasta) ?>" class="btn-close btn-close-white"></a>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= $is_edit ? $edit['id'] : 0 ?>">

          <div class="row g-3">
            <div class="col-6">
              <label class="form-label form-label-sm">Fecha</label>
              <input type="date" name="fecha" class="form-control form-control-sm"
                     value="<?= h($is_edit ? $edit['fecha'] : date('Y-m-d')) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">Tipo de queso</label>
              <input type="text" name="tipo_queso" class="form-control form-control-sm"
                     value="<?= h($is_edit ? $edit['tipo_queso'] : 'Queso Blanco') ?>" required
                     list="tipos-queso-list" placeholder="Ej: Queso Duro">
              <datalist id="tipos-queso-list">
                <option>Queso Blanco</option>
                <option>Queso Duro</option>
                <option>Queso Amarillo</option>
                <option>Cuajada</option>
                <option>Mozzarella</option>
                <option>Queso de Mano</option>
                <option>Telita</option>
              </datalist>
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">Litros de leche usados</label>
              <div class="input-group input-group-sm">
                <input type="number" id="inp-litros" name="litros_leche" class="form-control form-control-sm"
                       step="0.001" min="0"
                       value="<?= h($is_edit ? $edit['litros_leche'] : '') ?>"
                       required placeholder="0.000" oninput="calcResult()">
                <span class="input-group-text">L</span>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">
                Factor de conversión
                <span class="text-muted" style="font-size:.7rem">(litros por kg)</span>
              </label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">÷</span>
                <input type="number" id="inp-factor" name="factor" class="form-control form-control-sm"
                       step="0.0001" min="0.0001"
                       value="<?= h($is_edit ? $edit['factor'] : '8') ?>"
                       required placeholder="8" oninput="calcResult()">
                <span class="input-group-text">L/kg</span>
              </div>
              <div class="form-text" style="font-size:.7rem">Ej: 8 = necesitas 8 litros para 1 kg</div>
            </div>

            <!-- Resultado calculado en vivo -->
            <div class="col-12">
              <div class="p-3 rounded text-center" style="background:#e8f4e8;border:2px dashed #28a745">
                <div style="font-size:.78rem;color:#555;margin-bottom:4px">📐 Queso esperado (calculado automáticamente)</div>
                <div id="resultado" style="font-size:1.8rem;font-weight:700;color:#1a2e5a">
                  <?php if ($is_edit): ?>
                    <?= number_format((float)$edit['kg_esperado'], 3) ?> kg
                  <?php else: ?>
                    — kg
                  <?php endif ?>
                </div>
                <div style="font-size:.72rem;color:#777">litros ÷ factor = kg de queso</div>
              </div>
            </div>

            <div class="col-6">
              <label class="form-label form-label-sm">Kg real producido <span class="text-muted">(opcional)</span></label>
              <div class="input-group input-group-sm">
                <input type="number" name="kg_real" class="form-control form-control-sm"
                       step="0.001" min="0"
                       value="<?= h($is_edit ? $edit['kg_real'] : '') ?>"
                       placeholder="0.000">
                <span class="input-group-text">kg</span>
              </div>
              <div class="form-text" style="font-size:.7rem">Dejar vacío si aún no se conoce</div>
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">Observaciones</label>
              <input type="text" name="observaciones" class="form-control form-control-sm"
                     value="<?= h($is_edit ? $edit['observaciones'] : '') ?>"
                     placeholder="Notas opcionales...">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <a href="?p=produccion_queso&desde=<?= h($desde) ?>&hasta=<?= h($hasta) ?>" class="btn btn-sm btn-outline-secondary">Cancelar</a>
          <button type="submit" class="btn btn-sm btn-primary">💾 <?= $is_edit ? 'Actualizar' : 'Guardar' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if ($is_edit): ?>
<div class="modal-backdrop fade show"></div>
<?php endif ?>

<script>
function calcResult() {
  const litros = parseFloat(document.getElementById('inp-litros').value) || 0;
  const factor = parseFloat(document.getElementById('inp-factor').value) || 0;
  const el = document.getElementById('resultado');
  if (litros > 0 && factor > 0) {
    el.textContent = (litros / factor).toFixed(3) + ' kg';
  } else {
    el.textContent = '— kg';
  }
}
// Abrir modal automáticamente si hay datos en los inputs al cargar
document.addEventListener('DOMContentLoaded', () => {
  <?php if (!$is_edit): ?>
  document.getElementById('inp-litros')?.addEventListener('input', calcResult);
  document.getElementById('inp-factor')?.addEventListener('input', calcResult);
  <?php endif ?>
});
</script>

<?php layout_end(); ?>
