<?php
require_login();
$db = get_db();
ensure_transportistas_schema();

if (!function_exists('save_config_produccion')) {
    function ensure_config_produccion_table(): void {
        static $done = false; if ($done) return;
        try {
            $db = get_db();
            if (is_mysql()) {
                $db->exec("CREATE TABLE IF NOT EXISTS config_produccion (clave VARCHAR(50) PRIMARY KEY, valor MEDIUMTEXT NOT NULL, actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS config_produccion (clave VARCHAR(50) PRIMARY KEY, valor TEXT NOT NULL, actualizado_en TIMESTAMP NOT NULL DEFAULT NOW())");
            }
            $done = true;
        } catch (Throwable $e) { error_log('[ensure_config_produccion_table] ' . $e->getMessage()); }
    }
    function get_config_produccion(string $clave = 'panel'): ?array {
        ensure_config_produccion_table();
        try {
            $db = get_db();
            $st = $db->prepare("SELECT valor FROM config_produccion WHERE clave=?");
            $st->execute([$clave]);
            $v = $st->fetchColumn();
            if ($v) { $d = json_decode($v, true); if (is_array($d)) return $d; }
        } catch (Throwable $e) { error_log('[get_config_produccion] ' . $e->getMessage()); }
        return null;
    }
    function save_config_produccion(array $data, string $clave = 'panel'): bool {
        ensure_config_produccion_table();
        try {
            $db = get_db();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (is_mysql()) {
                $db->prepare("INSERT INTO config_produccion (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), actualizado_en=CURRENT_TIMESTAMP")->execute([$clave, $json]);
            } else {
                $db->prepare("INSERT INTO config_produccion (clave, valor) VALUES (?,?) ON CONFLICT (clave) DO UPDATE SET valor=EXCLUDED.valor, actualizado_en=NOW()")->execute([$clave, $json]);
            }
            return true;
        } catch (Throwable $e) { error_log('[save_config_produccion] ' . $e->getMessage()); return false; }
    }
}

if (($_POST['accion'] ?? '') === 'guardar_produccion') {
    header('Content-Type: application/json');
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $data = json_decode($_POST['config'] ?? '', true);
    echo json_encode(['ok' => is_array($data) ? save_config_produccion($data, 'panel') : false]);
    exit;
}
$cc_cfg = get_config_produccion('panel');

$vista   = $_GET['vista'] ?? 'diaria';
$hoy     = date('Y-m-d');
$desde   = $_GET['desde'] ?? date('Y-m-01');
$hasta   = $_GET['hasta'] ?? $hoy;
$desde   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ? $desde : date('Y-m-01');
$hasta   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) ? $hasta : $hoy;
$prod_id = (int)($_GET['pid'] ?? 0);

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    // ── Entregas de leche ────────────────────────────────────────────────────
    if ($act === 'save_entrega') {
        $eid      = (int)($_POST['id']              ?? 0);
        $pid_e    = (int)($_POST['productor_id']    ?? 0);
        $fecha_e  = $_POST['fecha']                 ?? date('Y-m-d');
        $litros_e = (float)($_POST['litros']        ?? 0);
        $precio_e = (float)($_POST['precio_litro_usd'] ?? 0);
        $cuajada  = (float)($_POST['cuajada_kg']    ?? 0);
        $traslado = (float)($_POST['gasto_traslado_usd'] ?? 0);
        $obs_e    = trim($_POST['observaciones']    ?? '');
        $transp_e = (int)($_POST['transportista_id'] ?? 0) ?: null;
        // Si no se eligió transportista, usar el vinculado al productor (si existe),
        // para que los litros del control de leche se reflejen en el transportista.
        if ($transp_e === null) { $transp_e = transportista_de_productor($db, $pid_e); }
        $subtotal = round($litros_e * $precio_e, 4);

        if ($eid > 0) {
            // Semana/productor ANTERIOR de la entrega (por si se mueve de día o productor).
            $old = $db->prepare("SELECT productor_id, fecha FROM productores_entregas WHERE id=?");
            $old->execute([$eid]);
            $orow = $old->fetch();
            $db->prepare("UPDATE productores_entregas SET productor_id=?,fecha=?,litros=?,precio_litro_usd=?,subtotal_usd=?,cuajada_kg=?,gasto_traslado_usd=?,observaciones=?,transportista_id=? WHERE id=?")
               ->execute([$pid_e,$fecha_e,$litros_e,$precio_e,$subtotal,$cuajada,$traslado,$obs_e,$transp_e,$eid]);
            if ($orow) sync_cierre_from_entregas($db, (int)$orow['productor_id'], $orow['fecha']);
            flash('Entrega actualizada.');
        } else {
            $db->prepare("INSERT INTO productores_entregas(productor_id,fecha,litros,precio_litro_usd,subtotal_usd,cuajada_kg,gasto_traslado_usd,observaciones,transportista_id) VALUES(?,?,?,?,?,?,?,?,?)")
               ->execute([$pid_e,$fecha_e,$litros_e,$precio_e,$subtotal,$cuajada,$traslado,$obs_e,$transp_e]);
            flash('Entrega registrada.');
        }
        // Si la fecha cae en una semana ya cerrada, refresca su snapshot.
        sync_cierre_from_entregas($db, $pid_e, $fecha_e);
        header("Location: ?p=control_leche&vista=$vista&desde=$desde&hasta=$hasta&pid=$prod_id"); exit;
    }

    if ($act === 'del_entrega') {
        $eid = (int)($_POST['id'] ?? 0);
        $del_pid = 0; $del_fecha = null;
        if ($eid > 0) {
            $dst = $db->prepare("SELECT productor_id, fecha FROM productores_entregas WHERE id=?");
            $dst->execute([$eid]);
            if ($drow = $dst->fetch()) { $del_pid = (int)$drow['productor_id']; $del_fecha = $drow['fecha']; }
            $db->prepare("DELETE FROM productores_entregas WHERE id=?")->execute([$eid]);
            if ($del_fecha) sync_cierre_from_entregas($db, $del_pid, $del_fecha);
        }
        flash('Entrega eliminada.', 'warning');
        header("Location: ?p=control_leche&vista=$vista&desde=$desde&hasta=$hasta&pid=$prod_id"); exit;
    }

    header("Location: ?p=control_leche&vista=$vista&desde=$desde&hasta=$hasta&pid=$prod_id"); exit;
}

// ── Productores (para dropdown) ───────────────────────────────────────────────
$stmt = $db->prepare("SELECT id, nombre, precio_litro_usd FROM productores ORDER BY nombre ASC");
$stmt->execute(); $productores_lista = $stmt->fetchAll();

// ── Transportistas (para dropdown) ────────────────────────────────────────────
// Se cargan TODOS (activos e inactivos) para que una entrega ya ligada a un
// transportista desactivado conserve su opción al editar y no se desvincule.
try {
    $transportistas_lista = $db->query("SELECT id, nombre, tarifa_litro_usd, estado FROM transportistas ORDER BY estado DESC, nombre ASC")->fetchAll();
} catch (Throwable $e) { $transportistas_lista = []; }

// ── Entregas del período ──────────────────────────────────────────────────────
$params = [$desde, $hasta];
$where_prod = '';
if ($prod_id > 0) { $where_prod = ' AND e.productor_id=?'; $params[] = $prod_id; }

$rows_st = $db->prepare("
    SELECT e.id AS entrega_id, e.fecha,
           p.nombre AS productor_nombre, p.id AS productor_id,
           e.litros, e.precio_litro_usd, e.subtotal_usd,
           e.cuajada_kg, e.gasto_traslado_usd, e.observaciones,
           e.transportista_id, t.nombre AS transportista_nombre
    FROM productores_entregas e
    JOIN productores p ON p.id = e.productor_id
    LEFT JOIN transportistas t ON t.id = e.transportista_id
    WHERE e.fecha BETWEEN ? AND ? $where_prod
    ORDER BY e.fecha DESC, p.nombre ASC
");
$rows_st->execute($params);
$rows = $rows_st->fetchAll();

// Agrupar por día
$dias = [];
foreach ($rows as $r) {
    $f = $r['fecha'];
    if (!isset($dias[$f])) $dias[$f] = ['litros'=>0,'monto'=>0,'cuajada'=>0,'traslado'=>0,'prods'=>[],'detalle'=>[]];
    $dias[$f]['litros']   += (float)$r['litros'];
    $dias[$f]['monto']    += (float)$r['subtotal_usd'];
    $dias[$f]['cuajada']  += (float)$r['cuajada_kg'];
    $dias[$f]['traslado'] += (float)$r['gasto_traslado_usd'];
    $dias[$f]['prods'][$r['productor_id']] = $r['productor_nombre'];
    $dias[$f]['detalle'][] = $r;
}

// Agrupar por semana
$semanas = [];
foreach ($rows as $r) {
    $d   = new DateTime($r['fecha']);
    $dow = (int)$d->format('N');
    $lunes   = (clone $d)->modify('-'.($dow-1).' days')->format('Y-m-d');
    $viernes = (clone $d)->modify('+'.(7-$dow).' days')->format('Y-m-d');
    if (!isset($semanas[$lunes])) $semanas[$lunes] = ['lunes'=>$lunes,'viernes'=>$viernes,'litros'=>0,'monto'=>0,'cuajada'=>0,'traslado'=>0,'prods'=>[],'detalle'=>[]];
    $semanas[$lunes]['litros']   += (float)$r['litros'];
    $semanas[$lunes]['monto']    += (float)$r['subtotal_usd'];
    $semanas[$lunes]['cuajada']  += (float)$r['cuajada_kg'];
    $semanas[$lunes]['traslado'] += (float)$r['gasto_traslado_usd'];
    $semanas[$lunes]['prods'][$r['productor_id']] = $r['productor_nombre'];
    $semanas[$lunes]['detalle'][] = $r;
}
krsort($semanas);

$tot_litros   = array_sum(array_column($rows, 'litros'));
$tot_monto    = array_sum(array_column($rows, 'subtotal_usd'));
$tot_cuajada  = array_sum(array_column($rows, 'cuajada_kg'));
$tot_traslado = array_sum(array_column($rows, 'gasto_traslado_usd'));
$n_dias_con   = count($dias);
$prom_dia     = $n_dias_con ? $tot_litros / $n_dias_con : 0;

// ── Panel de Producción: costos del día ───────────────────────────────────────
$prod_fecha = $_GET['prod_fecha'] ?? date('Y-m-d');
$prod_fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $prod_fecha) ? $prod_fecha : date('Y-m-d');
$pl_litros = 0.0; $pl_costo = 0.0; $pl_traslado = 0.0;
try {
    $st = $db->prepare("SELECT COALESCE(SUM(e.litros),0) litros, COALESCE(SUM(e.subtotal_usd),0) costo, COALESCE(SUM(e.gasto_traslado_usd),0) traslado FROM productores_entregas e JOIN productores p ON p.id = e.productor_id WHERE e.fecha=? AND p.estado = true");
    $st->execute([$prod_fecha]);
    if ($r = $st->fetch()) { $pl_litros=(float)$r['litros']; $pl_costo=(float)$r['costo']; $pl_traslado=(float)$r['traslado']; }
} catch (Throwable $e) { /* sin datos */ }
// Leche procesada (la que realmente se convierte en queso), registrada en el Resumen Diario.
$pl_recibida = $pl_litros;
$pl_procesada = 0.0;
try {
    $pq = $db->prepare("SELECT COALESCE(SUM(monto),0) m FROM resumen_costos_diarios WHERE concepto='procesada_n' AND fecha=?");
    $pq->execute([$prod_fecha]);
    if ($pr = $pq->fetch()) $pl_procesada = (float)$pr['m'];
} catch (Throwable $e) { /* sin datos de leche procesada */ }
// Los litros son los realmente procesados; el costo de la leche y el traslado
// quedan en el TOTAL gastado ese día (datos de Control de Leche), sin recortar.
$pl_litros   = $pl_procesada;
// ── Leche restante = recibida − ventas − cortesías − vales − procesada ────────
// (mismo cálculo que Control de Leche → Resumen Diario: TOTAL LECHE RESTANTE)
$pl_ventas = 0.0; $pl_cortesias = 0.0; $pl_vales = 0.0;
try {
    $rq = $db->prepare("SELECT concepto, COALESCE(SUM(monto),0) m FROM resumen_costos_diarios WHERE fecha=? AND concepto IN ('ventas_prod','cortesias_n','vales_n') GROUP BY concepto");
    $rq->execute([$prod_fecha]);
    foreach ($rq->fetchAll() as $rr) {
        if ($rr['concepto']==='ventas_prod') $pl_ventas=(float)$rr['m'];
        elseif ($rr['concepto']==='cortesias_n') $pl_cortesias=(float)$rr['m'];
        elseif ($rr['concepto']==='vales_n') $pl_vales=(float)$rr['m'];
    }
} catch (Throwable $e) { /* sin datos */ }
$pl_restante = max(0, $pl_recibida - $pl_ventas - $pl_cortesias - $pl_vales - $pl_procesada);
$pl_nomina_sem = 0.0;
try {
    foreach ($db->query("SELECT salario_semanal, estado FROM empleados")->fetchAll() as $emp) {
        if (!isset($emp['estado']) || bool_estado($emp['estado'])) $pl_nomina_sem += (float)($emp['salario_semanal'] ?? 0);
    }
} catch (Throwable $e) { /* sin datos */ }
$pl_nomina_dia = $pl_nomina_sem / 7;

require_once __DIR__ . '/../layout.php';
layout_start('Control de Leche', 'control_leche');
?>

<style>
.tbl-leche th { background:#1a2e5a; color:#fff; position:sticky; top:0; z-index:1; white-space:nowrap; }
.tbl-leche td, .tbl-leche th { font-size:.85rem; vertical-align:middle; }
.tbl-leche tr:hover { background:#f0f4ff; }
.semana-header { background:#e8efff !important; font-weight:600; }
.badge-productor { font-size:.7rem; }
.kpi-mini { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:12px 16px; text-align:center; }
.kpi-mini .val { font-size:1.35rem; font-weight:700; color:#1a2e5a; }
.kpi-mini .lbl { font-size:.72rem; color:#6c757d; margin-top:2px; }
.factor-badge { font-size:.75rem; background:#e8efff; color:#1a2e5a; border-radius:20px; padding:2px 10px; font-weight:600; }
.diff-pos { color:#198754; font-weight:600; }
.diff-neg { color:#dc3545; font-weight:600; }
.btn-edit-row  { font-size:.7rem; padding:1px 6px; }
.costos-card{background:#fff;border:1px solid #e0d3ba;border-radius:10px;padding:18px 20px}
.cc-title{font-size:1.15rem;font-weight:700;color:hsl(24,38%,22%);margin:0 0 4px}
.cc-sub{font-size:13px;color:#6c5b45;margin:0 0 14px}
.cc-fecha{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f3ead9;border:1px solid #e0d3ba;border-radius:8px;padding:10px 14px;margin:0 0 16px}
.cc-fecha input[type=date]{border:1px solid #cdbfa8;border-radius:4px;padding:4px 8px;font-size:13px}
.cc-filtrar{background:hsl(24,38%,25%);color:#fff;border:none;border-radius:6px;padding:5px 18px;font-size:13px;cursor:pointer;font-weight:600}
.cc-filtrar:hover{background:hsl(24,38%,20%)}
.cc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
.cc-box{border:1px solid #ece3d6;border-radius:8px;overflow:hidden;background:#fff}
.cc-box-head{background:hsl(24,38%,25%);color:#fff;font-weight:600;font-size:13px;padding:8px 12px}
.cc-table{width:100%;border-collapse:collapse;font-size:13px}
.cc-table td{padding:6px 12px;border-bottom:1px solid #f0eae0;color:#4a3f30}
.cc-table td:last-child{text-align:right;white-space:nowrap}
.cc-table input{width:90px;text-align:right;border:1px solid #cdbfa8;border-radius:4px;padding:3px 6px;font-size:13px}
.cc-table input[readonly]{background:#efe6d5;color:#5b4a36;font-weight:700;border-color:#d8c8ad;cursor:not-allowed}
.cc-table tr.cc-auto td{font-weight:700;color:hsl(24,38%,25%);background:#f3ead9}
.cc-note{margin:6px 12px 10px;font-size:11px;color:#8a7d6c}
.cc-box-total{background:#fbf7ef}
.cc-total-day{margin:10px 12px;padding:16px;border-radius:8px;background:hsl(140,40%,22%);color:#fff;display:flex;flex-direction:column;gap:4px;align-items:center;text-align:center}
.cc-total-day span{font-size:12px;letter-spacing:.5px;font-weight:600;opacity:.9}
.cc-total-day strong{font-size:2rem}
.cc-price{width:100%;border-collapse:collapse;font-size:13px}
.cc-price th{background:hsl(24,38%,25%);color:#fff;padding:7px 10px;text-align:right;white-space:nowrap;font-weight:600}
.cc-price th:first-child{text-align:left}
.cc-price td{padding:6px 10px;border-bottom:1px solid #f0eae0;text-align:right;white-space:nowrap}
.cc-price td:first-child{text-align:left}
.cc-price input{border:1px solid #cdbfa8;border-radius:4px;padding:3px 6px;font-size:13px}
.cc-price input.txt{width:160px;text-align:left}
.cc-price input.num{width:72px;text-align:right}
.cc-price .out-costo{font-weight:600;color:#7a5b2e}
.cc-price .out-precio{font-weight:700;color:hsl(140,45%,30%);font-size:14px}
.cc-price .out-kg{font-weight:600;color:#5b4a36}
.cc-price tr:nth-child(even) td{background:#faf6ef}
.cc-fact{width:100%;max-width:440px;border-collapse:collapse;font-size:14px;margin-top:4px}
.cc-fact td{padding:8px 12px;border-bottom:1px solid #f0eae0}
.cc-fact td:last-child{text-align:right;font-weight:700;color:hsl(140,45%,30%)}
.cc-fact tr:first-child td{border-top:2px solid hsl(24,38%,25%)}
.cc-fact .cc-fact-empty{color:#8a7d6c;font-weight:400;text-align:left}
.cc-delrow{border:none;background:#f3d9d4;color:#b3402e;border-radius:4px;cursor:pointer;padding:3px 9px;font-weight:700}
.cc-addbtn{margin:12px;background:hsl(24,38%,25%);color:#fff;border:none;border-radius:6px;padding:8px 14px;font-size:13px;cursor:pointer;font-weight:600}
.cc-addbtn:hover{background:hsl(24,38%,20%)}
</style>

<!-- Cabecera -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0">🥛 Control de Leche</h5>
  <div class="d-flex gap-2">
    <?php if ($vista !== 'produccion'): ?>
    <button class="btn btn-success btn-sm" onclick="openEntregaModal(0)">+ Nueva Entrega</button>
    <?php endif ?>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $vista==='diaria'?'active':'' ?>"
       href="?p=control_leche&vista=diaria&desde=<?=$desde?>&hasta=<?=$hasta?>&pid=<?=$prod_id?>">📅 Vista Diaria</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $vista==='semanal'?'active':'' ?>"
       href="?p=control_leche&vista=semanal&desde=<?=$desde?>&hasta=<?=$hasta?>&pid=<?=$prod_id?>">📆 Vista Semanal</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $vista==='produccion'?'active':'' ?>"
       href="?p=control_leche&vista=produccion&desde=<?=$desde?>&hasta=<?=$hasta?>">🧀 Producción</a>
  </li>
</ul>

<!-- Filtros -->
<?php if ($vista !== 'produccion'): ?>
<form method="get" class="mb-3">
  <input type="hidden" name="p" value="control_leche">
  <input type="hidden" name="vista" value="<?= h($vista) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label form-label-sm mb-1">Desde</label>
      <input type="date" name="desde" class="form-control form-control-sm" value="<?= h($desde) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label form-label-sm mb-1">Hasta</label>
      <input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($hasta) ?>">
    </div>
    <?php if ($vista !== 'produccion'): ?>
    <div class="col-12 col-md-4">
      <label class="form-label form-label-sm mb-1">Productor</label>
      <select name="pid" class="form-select form-select-sm">
        <option value="0">— Todos los productores —</option>
        <?php foreach ($productores_lista as $pl): ?>
        <option value="<?= $pl['id'] ?>" <?= $prod_id==$pl['id']?'selected':'' ?>><?= h($pl['nombre']) ?></option>
        <?php endforeach ?>
      </select>
    </div>
    <?php endif ?>
    <div class="col-12 col-md-2">
      <button type="submit" class="btn btn-primary btn-sm w-100">🔍 Filtrar</button>
    </div>
  </div>
  <div class="mt-2 d-flex flex-wrap gap-1">
    <?php foreach ([
      'Hoy'          => [date('Y-m-d'), date('Y-m-d')],
      'Esta semana'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
      'Este mes'     => [date('Y-m-01'), date('Y-m-d')],
      'Mes anterior' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
      'Últimos 30 d' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
      'Últimos 90 d' => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')],
    ] as $lbl => [$d1,$d2]): ?>
    <a href="?p=control_leche&vista=<?=$vista?>&desde=<?=$d1?>&hasta=<?=$d2?>&pid=<?=$prod_id?>"
       class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.72rem"><?= $lbl ?></a>
    <?php endforeach ?>
  </div>
</form>
<?php endif ?>

<?php if ($vista === 'produccion'): ?>
<!-- ══ PRODUCCIÓN — COSTOS Y PRECIOS ════════════════════════════════════════ -->
<div class="costos-card">
  <h3 class="cc-title">Producción — Costos del día y precio de venta</h3>
  <p class="cc-sub">Los <strong>kg del día</strong> se calculan con los litros de <strong>leche recibida</strong> (la que hay ese día), y el <strong>costo total de la leche</strong> es lo realmente gastado ese día (de Control de Leche). Se le suma la nómina de los empleados y los gastos generales para saber <strong>cuánto se gasta diario en producción</strong> y <strong>a cómo vender cada tipo de queso</strong> sin perder. Todo automático pero editable.</p>

  <form method="get" class="cc-fecha">
    <input type="hidden" name="p" value="control_leche">
    <input type="hidden" name="vista" value="produccion">
    <label style="font-size:13px;font-weight:600;color:#5b4a36">Día de producción:</label>
    <input type="date" name="prod_fecha" value="<?= h($prod_fecha) ?>">
    <button type="submit" class="cc-filtrar">Filtrar</button>
    <span style="font-size:13px;color:#5b4a36">Leche procesada: <strong><?= number_format($pl_litros,1) ?> L</strong> · Costo total de la leche: <strong>$<?= number_format($pl_costo,2) ?></strong> · Recibida: <strong><?= number_format($pl_recibida,1) ?> L</strong></span>
  </form>

  <div class="cc-grid">
    <div class="cc-box">
      <div class="cc-box-head">Gastos del día</div>
      <input type="hidden" id="cc_litros" value="<?= number_format($pl_recibida,1,'.','') ?>">
      <table class="cc-table">
        <tr class="cc-auto"><td><strong>Total leche restante</strong> <span style="font-weight:400;color:#8a7d6c;font-size:11px">(recibida − ventas, cortesía, vales y procesada)</span></td><td><input type="number" id="cc_restante" value="<?= number_format($pl_restante,1,'.','') ?>" readonly> L</td></tr>
        <tr class="cc-auto"><td>Costo total de la leche <span style="font-weight:400;color:#8a7d6c;font-size:11px">(de Control de Leche)</span></td><td>$ <input type="number" id="cc_costo_leche" value="<?= number_format($pl_costo,2,'.','') ?>" readonly></td></tr>
        <tr class="cc-auto"><td><strong>Costo real por litro de leche</strong> <span style="font-weight:400;color:#8a7d6c;font-size:11px">(costo total ÷ litros recibidos)</span></td><td><strong id="cc_costo_litro" style="color:#7a4d12">$0,00</strong> / L</td></tr>
        <tr><td>Nómina (pago de empleados) <span style="font-weight:400;color:#8a7d6c;font-size:11px">(autom.)</span></td><td>$ <input type="number" id="cc_nomina" min="0" step="any" placeholder="0" value="<?= $pl_nomina_dia > 0 ? number_format($pl_nomina_dia,2,'.','') : '' ?>"></td></tr>
        <tr><td>Costo personal extra</td><td>$ <input type="number" id="cc_personal" min="0" step="any" placeholder="0" value=""></td></tr>
        <tr><td>Costo transporte <span style="font-weight:400;color:#8a7d6c;font-size:11px">(traslado autom.)</span></td><td>$ <input type="number" id="cc_transporte" min="0" step="any" placeholder="0" value="<?= $pl_traslado > 0 ? number_format($pl_traslado,2,'.','') : '' ?>"></td></tr>
        <tr><td>Costo operativo</td><td>$ <input type="number" id="cc_operativo" min="0" step="any" placeholder="0" value=""></td></tr>
        <tr><td>Otros gastos</td><td>$ <input type="number" id="cc_otros" min="0" step="any" placeholder="0" value=""></td></tr>
      </table>
      <p class="cc-note">La nómina diaria se calcula con los sueldos semanales de los empleados activos ÷ 7. Si trabajas otra cantidad de días, edita el monto.</p>
    </div>

    <div class="cc-box cc-box-total">
      <div class="cc-box-head">Resumen del día</div>
      <table class="cc-table">
        <tr><td>Margen de ganancia deseado</td><td><input type="number" id="cc_margen" min="0" max="99" step="any" value="40"> %</td></tr>
      </table>
      <div class="cc-total-day">
        <span>GASTO TOTAL DIARIO EN PRODUCCIÓN</span>
        <strong id="cc_gasto_total">$0,00</strong>
      </div>
      <p class="cc-note">Suma de leche + nómina + todos los gastos del día. Es lo que se gasta para producir.</p>
    </div>
  </div>

  <div class="cc-box" style="margin-top:16px">
    <div class="cc-box-head">Lista de precios por tipo de queso (margen <span id="cc_margen_lbl">40</span>%)</div>
    <p class="cc-note">Indica cuántos kg de queso salen por cada cierta cantidad de litros (ej.: por cada 10 L salen 8 kg de Guayanés). Además puedes registrar gastos propios de cada queso (<strong>sal</strong>, <strong>empaque</strong>, <strong>otros</strong>) como monto total del día; se suman al costo y se reparten entre los kilos de ese queso. El sistema calcula cuánto cuesta producir 1 kg y a cómo venderlo para ganar tu margen.</p>
    <div style="overflow-x:auto">
      <table class="cc-price">
        <thead>
          <tr>
            <th>Tipo de queso</th>
            <th>Por cada (L)</th>
            <th>Salen (kg)</th>
            <th>Sal ($)</th>
            <th>Empaque ($)</th>
            <th>Otros ($)</th>
            <th>Kg del día</th>
            <th>Costo por kg</th>
            <th>Precio de venta x kg</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="cc_price_body"></tbody>
      </table>
    </div>
    <button type="button" id="cc_add_row" class="cc-addbtn">+ Agregar tipo de queso</button>
  </div>

  <div class="cc-box" style="margin-top:16px">
    <div class="cc-box-head">Factura — precios de venta x kg</div>
    <div style="text-align:center;background:#fbf3e6;border:2px solid #d9b46a;border-radius:10px;padding:14px;margin-bottom:14px">
      <div style="font-size:13px;color:#8a7d6c;text-transform:uppercase;letter-spacing:1px">Margen de ganancia</div>
      <div style="font-size:44px;font-weight:800;color:#7a4d12;line-height:1.1"><span id="cc_margen_big">40</span>%</div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
      <button type="button" id="cc_guardar" class="cc-addbtn" style="background:#7a4d12;color:#fff;border-color:#7a4d12">Guardar todos los cambios</button>
      <button type="button" id="cc_imprimir" class="cc-addbtn">Imprimir factura</button>
      <span id="cc_guardar_msg" style="font-size:13px;font-weight:600"></span>
    </div>
    <p class="cc-note">Lista lista para imprimir o copiar: nombre del producto y su precio de venta por kg. Usa <strong>Guardar todos los cambios</strong> para que la lista y los precios se mantengan hasta que los actualices.</p>
    <table class="cc-fact"><tbody id="cc_factura"></tbody></table>
  </div>
</div>


<?php else: ?>
<!-- ══ KPIs ═══════════════════════════════════════════════════════════════════ -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="kpi-mini">
    <div class="val"><?= number_format($tot_litros,1) ?> L</div>
    <div class="lbl">Total litros período</div>
  </div></div>
  <div class="col-6 col-md-3"><div class="kpi-mini">
    <div class="val text-primary">$<?= number_format($tot_monto,2) ?></div>
    <div class="lbl">Monto total período</div>
  </div></div>
  <div class="col-6 col-md-3"><div class="kpi-mini">
    <div class="val"><?= $n_dias_con ?></div>
    <div class="lbl">Días con entregas</div>
  </div></div>
  <div class="col-6 col-md-3"><div class="kpi-mini">
    <div class="val"><?= number_format($prom_dia,1) ?> L</div>
    <div class="lbl">Promedio diario</div>
  </div></div>
</div>

<?php if (!$rows): ?>
<div class="alert alert-info text-center">No hay entregas en el período. Pulsa <strong>+ Nueva Entrega</strong> para agregar.</div>

<?php elseif ($vista === 'diaria'): ?>
<!-- ══ VISTA DIARIA ════════════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <strong>📅 Detalle diario de entregas</strong>
    <span class="text-muted small"><?= count($dias) ?> días | <?= count($rows) ?> registros</span>
  </div>
  <div class="card-body p-0" style="max-height:62vh;overflow-y:auto">
    <table class="table table-sm mb-0 tbl-leche">
      <thead>
        <tr>
          <th>Fecha</th><th class="text-end">Litros</th><th class="text-end">Monto $</th>
          <th class="text-end">Cuajada kg</th><th class="text-end">Traslado $</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($dias as $fecha => $d):
        $row_id = 'dia-'.str_replace('-','',$fecha);
      ?>
      <tr class="semana-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#<?=$row_id?>">
        <td><strong><?= date('d/m/Y (D)', strtotime($fecha)) ?></strong></td>
        <td class="text-end fw-bold text-primary"><?= number_format($d['litros'],3) ?></td>
        <td class="text-end fw-bold">$<?= number_format($d['monto'],2) ?></td>
        <td class="text-end"><?= $d['cuajada']>0 ? number_format($d['cuajada'],3) : '—' ?></td>
        <td class="text-end text-danger"><?= $d['traslado']>0 ? '$'.number_format($d['traslado'],2) : '—' ?></td>
        <td class="text-end"><span class="text-muted small">▾</span></td>
      </tr>
      <tr class="collapse" id="<?=$row_id?>">
        <td colspan="6" class="p-0">
          <table class="table table-sm mb-0 ms-3" style="width:calc(100% - 1.5rem)">
            <thead><tr style="background:#dde8ff">
              <th style="width:28%">Productor</th>
              <th class="text-end">Litros</th><th class="text-end">Precio/L</th>
              <th class="text-end">Subtotal $</th><th class="text-end">Cuajada kg</th>
              <th>Obs.</th><th style="width:100px"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($d['detalle'] as $dr): ?>
            <tr>
              <td><a href="?p=productor_detalle&id=<?=$dr['productor_id']?>&tab=control" class="text-decoration-none"><?= h($dr['productor_nombre']) ?></a></td>
              <td class="text-end text-primary fw-semibold"><?= number_format((float)$dr['litros'],3) ?></td>
              <td class="text-end text-muted">$<?= number_format((float)$dr['precio_litro_usd'],4) ?></td>
              <td class="text-end fw-semibold">$<?= number_format((float)$dr['subtotal_usd'],2) ?></td>
              <td class="text-end"><?= (float)$dr['cuajada_kg']>0 ? number_format((float)$dr['cuajada_kg'],3) : '—' ?></td>
              <td class="text-muted"><?= h($dr['observaciones']) ?: '—' ?></td>
              <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-primary btn-edit-row"
                  onclick="openEntregaModal(<?= $dr['entrega_id'] ?>,
                    '<?= h($dr['fecha']) ?>',<?= $dr['productor_id'] ?>,
                    <?= (float)$dr['litros'] ?>,<?= (float)$dr['precio_litro_usd'] ?>,
                    <?= (float)$dr['cuajada_kg'] ?>,<?= (float)$dr['gasto_traslado_usd'] ?>,
                    <?= json_encode($dr['observaciones'] ?? '') ?>,<?= (int)($dr['transportista_id'] ?? 0) ?>)">✏️</button>
                <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta entrega?')">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="del_entrega">
                  <input type="hidden" name="id" value="<?= $dr['entrega_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger btn-edit-row">✕</button>
                </form>
              </td>
            </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot><tr style="background:#eef3ff;font-weight:700">
              <td>TOTAL</td>
              <td class="text-end text-primary"><?= number_format($d['litros'],3) ?></td>
              <td></td>
              <td class="text-end">$<?= number_format($d['monto'],2) ?></td>
              <td class="text-end"><?= $d['cuajada']>0 ? number_format($d['cuajada'],3) : '—' ?></td>
              <td colspan="2"></td>
            </tr></tfoot>
          </table>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr style="background:#1a2e5a;color:#fff;font-weight:700">
          <td>TOTAL</td>
          <td class="text-end"><?= number_format($tot_litros,3) ?> L</td>
          <td class="text-end">$<?= number_format($tot_monto,2) ?></td>
          <td class="text-end"><?= number_format($tot_cuajada,3) ?> kg</td>
          <td class="text-end">$<?= number_format($tot_traslado,2) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php else: ?>
<!-- ══ VISTA SEMANAL ══════════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <strong>📆 Resumen semanal de entregas</strong>
    <span class="text-muted small"><?= count($semanas) ?> semanas | <?= number_format($tot_litros,1) ?> L totales</span>
  </div>
  <div class="card-body p-0" style="max-height:62vh;overflow-y:auto">
    <table class="table table-sm mb-0 tbl-leche">
      <thead>
        <tr>
          <th>Semana</th><th class="text-end">Litros</th><th class="text-end">Monto $</th>
          <th class="text-end">Cuajada kg</th><th class="text-end">Traslado $</th>
          <th class="text-end">Productores</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($semanas as $lunes => $sw):
        $row_id  = 'sem-'.str_replace('-','',$lunes);
        $n_prods = count($sw['prods']);
        $sem_dias = [];
        foreach ($sw['detalle'] as $dr) {
            $fd = $dr['fecha'];
            if (!isset($sem_dias[$fd])) $sem_dias[$fd] = ['litros'=>0,'monto'=>0,'prods'=>[]];
            $sem_dias[$fd]['litros'] += (float)$dr['litros'];
            $sem_dias[$fd]['monto']  += (float)$dr['subtotal_usd'];
            $sem_dias[$fd]['prods'][$dr['productor_id']] = $dr['productor_nombre'];
        }
        krsort($sem_dias);
      ?>
      <tr class="semana-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#<?=$row_id?>">
        <td><strong><?= date('d/m', strtotime($sw['lunes'])) ?> – <?= date('d/m/Y', strtotime($sw['viernes'])) ?></strong>
          <span class="text-muted small ms-1">(Sem. <?= date('W', strtotime($lunes)) ?>)</span></td>
        <td class="text-end fw-bold text-primary"><?= number_format($sw['litros'],3) ?> L</td>
        <td class="text-end fw-bold">$<?= number_format($sw['monto'],2) ?></td>
        <td class="text-end"><?= $sw['cuajada']>0 ? number_format($sw['cuajada'],3) : '—' ?></td>
        <td class="text-end text-danger"><?= $sw['traslado']>0 ? '$'.number_format($sw['traslado'],2) : '—' ?></td>
        <td class="text-end"><span class="badge bg-secondary"><?= $n_prods ?> prod<?= $n_prods!==1?'s':'' ?></span></td>
        <td class="text-end"><span class="text-muted small">▾</span></td>
      </tr>
      <tr class="collapse" id="<?=$row_id?>">
        <td colspan="6" class="p-0">
          <!-- Resumen por día de la semana -->
          <table class="table table-sm mb-0" style="margin-left:1.5rem;width:calc(100% - 1.5rem);background:#f8faff">
            <thead><tr style="background:#dde8ff">
              <th>Día</th><th class="text-end">Litros</th><th class="text-end">Monto $</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sem_dias as $fd => $sd): ?>
            <tr>
              <td><?= date('D d/m/Y', strtotime($fd)) ?></td>
              <td class="text-end text-primary fw-semibold"><?= number_format($sd['litros'],3) ?> L</td>
              <td class="text-end">$<?= number_format($sd['monto'],2) ?></td>
            </tr>
            <?php endforeach ?>
            </tbody>
          </table>
          <!-- Detalle por productor con edición -->
          <table class="table table-sm mb-0 ms-3" style="width:calc(100% - 1.5rem)">
            <thead><tr style="background:#c8d8ff">
              <th>Productor</th><th class="text-end">Litros</th><th class="text-end">Precio/L</th>
              <th class="text-end">Subtotal $</th><th class="text-end">Cuajada</th><th>Obs.</th><th style="width:100px"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($sw['detalle'] as $dr): ?>
            <tr>
              <td><a href="?p=productor_detalle&id=<?=$dr['productor_id']?>&tab=control" class="text-decoration-none"><?= h($dr['productor_nombre']) ?></a></td>
              <td class="text-end fw-semibold text-primary"><?= number_format((float)$dr['litros'],3) ?></td>
              <td class="text-end text-muted">$<?= number_format((float)$dr['precio_litro_usd'],4) ?></td>
              <td class="text-end fw-semibold">$<?= number_format((float)$dr['subtotal_usd'],2) ?></td>
              <td class="text-end"><?= (float)$dr['cuajada_kg']>0 ? number_format((float)$dr['cuajada_kg'],3) : '—' ?></td>
              <td class="text-muted"><?= h($dr['observaciones']) ?: '—' ?></td>
              <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-primary btn-edit-row"
                  onclick="openEntregaModal(<?= $dr['entrega_id'] ?>,
                    '<?= h($dr['fecha']) ?>',<?= $dr['productor_id'] ?>,
                    <?= (float)$dr['litros'] ?>,<?= (float)$dr['precio_litro_usd'] ?>,
                    <?= (float)$dr['cuajada_kg'] ?>,<?= (float)$dr['gasto_traslado_usd'] ?>,
                    <?= json_encode($dr['observaciones'] ?? '') ?>,<?= (int)($dr['transportista_id'] ?? 0) ?>)">✏️</button>
                <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta entrega?')">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="del_entrega">
                  <input type="hidden" name="id" value="<?= $dr['entrega_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger btn-edit-row">✕</button>
                </form>
              </td>
            </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot><tr style="background:#eef3ff;font-weight:700">
              <td>TOTAL</td>
              <td class="text-end text-primary"><?= number_format($sw['litros'],3) ?></td>
              <td></td>
              <td class="text-end">$<?= number_format($sw['monto'],2) ?></td>
              <td class="text-end"><?= $sw['cuajada']>0 ? number_format($sw['cuajada'],3) : '—' ?></td>
              <td colspan="2"></td>
            </tr></tfoot>
          </table>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr style="background:#1a2e5a;color:#fff;font-weight:700">
          <td>TOTAL PERÍODO</td>
          <td class="text-end"><?= number_format($tot_litros,3) ?> L</td>
          <td class="text-end">$<?= number_format($tot_monto,2) ?></td>
          <td class="text-end"><?= number_format($tot_cuajada,3) ?> kg</td>
          <td class="text-end">$<?= number_format($tot_traslado,2) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif ?>
<?php endif ?>

<!-- ══ MODAL EDITAR / NUEVA ENTREGA ═════════════════════════════════════════ -->
<div class="modal fade" id="modalEntrega" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#15803d;color:#fff">
        <h5 class="modal-title" id="modalEntregaTitle">🥛 Nueva Entrega de Leche</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="frmEntrega">
        <div class="modal-body">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_entrega">
          <input type="hidden" name="id" id="eid" value="0">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label form-label-sm">Productor</label>
              <select name="productor_id" id="e_pid" class="form-select form-select-sm" required>
                <option value="">— Seleccionar —</option>
                <?php foreach ($productores_lista as $pl): ?>
                <option value="<?= $pl['id'] ?>" data-precio="<?= number_format((float)$pl['precio_litro_usd'],4,'.','') ?>">
                  <?= h($pl['nombre']) ?>
                </option>
                <?php endforeach ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label form-label-sm">Fecha</label>
              <input type="date" name="fecha" id="e_fecha" class="form-control form-control-sm"
                     value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm">Litros</label>
              <div class="input-group input-group-sm">
                <input type="number" name="litros" id="e_litros" class="form-control" step="0.001" min="0"
                       placeholder="0.000" required oninput="calcEntrega()">
                <span class="input-group-text">L</span>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm">Precio / Litro $</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">$</span>
                <input type="number" name="precio_litro_usd" id="e_precio" class="form-control"
                       step="0.0001" min="0" placeholder="0.0000" required oninput="calcEntrega()">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm">Subtotal $ <span class="text-muted">(calculado)</span></label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">$</span>
                <input type="text" id="e_subtotal" class="form-control bg-light" readonly placeholder="0.00">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm">Cuajada kg <span class="text-muted">(opc.)</span></label>
              <div class="input-group input-group-sm">
                <input type="number" name="cuajada_kg" id="e_cuajada" class="form-control"
                       step="0.001" min="0" placeholder="0.000">
                <span class="input-group-text">kg</span>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm">Gasto traslado $ <span class="text-muted">(opc.)</span></label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">$</span>
                <input type="number" name="gasto_traslado_usd" id="e_traslado" class="form-control"
                       step="0.01" min="0" placeholder="0.00">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label form-label-sm">Transportista <span class="text-muted">(opc.)</span></label>
              <select name="transportista_id" id="e_transp" class="form-select form-select-sm">
                <option value="">— Ninguno —</option>
                <?php foreach ($transportistas_lista as $tl):
                  $tl_act = is_mysql() ? (int)$tl['estado'] === 1 : ($tl['estado'] === true || $tl['estado'] === 't' || $tl['estado'] === '1' || $tl['estado'] === 1);
                ?>
                <option value="<?= $tl['id'] ?>"><?= h($tl['nombre']) ?><?= $tl_act ? '' : ' (inactivo)' ?></option>
                <?php endforeach ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label form-label-sm">Observaciones</label>
              <input type="text" name="observaciones" id="e_obs" class="form-control form-control-sm" placeholder="Notas...">
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-sm btn-success">💾 Guardar Entrega</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Modal entrega ─────────────────────────────────────────────────────────────
function openEntregaModal(id, fecha, pid, litros, precio, cuajada, traslado, obs, transp) {
  document.getElementById('eid').value       = id || 0;
  document.getElementById('e_fecha').value   = fecha  || '<?= date('Y-m-d') ?>';
  document.getElementById('e_pid').value     = pid    || '';
  document.getElementById('e_litros').value  = litros > 0 ? litros : '';
  document.getElementById('e_precio').value  = precio > 0 ? precio : '';
  document.getElementById('e_cuajada').value = cuajada > 0 ? cuajada : '';
  document.getElementById('e_traslado').value= traslado > 0 ? traslado : '';
  document.getElementById('e_obs').value     = obs    || '';
  document.getElementById('e_transp').value  = transp > 0 ? transp : '';
  document.getElementById('modalEntregaTitle').textContent =
    id > 0 ? '✏️ Editar Entrega de Leche' : '🥛 Nueva Entrega de Leche';

  // Si no se pasó precio y hay productor seleccionado, usar el precio del productor
  if (!precio && pid) {
    var opt = document.querySelector('#e_pid option[value="'+pid+'"]');
    if (opt) document.getElementById('e_precio').value = opt.dataset.precio || '';
  }
  calcEntrega();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEntrega')).show();
}

// Al cambiar productor, autocompletar su precio
document.getElementById('e_pid').addEventListener('change', function() {
  var opt = this.options[this.selectedIndex];
  if (opt && opt.dataset.precio) {
    document.getElementById('e_precio').value = opt.dataset.precio;
    calcEntrega();
  }
});

function calcEntrega() {
  var l = parseFloat(document.getElementById('e_litros').value)  || 0;
  var p = parseFloat(document.getElementById('e_precio').value) || 0;
  document.getElementById('e_subtotal').value = (l * p).toFixed(2);
}

// ── Panel de producción (costos y precios) ─────────────────────────────────────
(function(){
  var body = document.getElementById('cc_price_body');
  if (!body) return;
  function v(id){ var e=document.getElementById(id); var n=e?parseFloat(e.value):0; return isFinite(n)?n:0; }
  function money(n){ if(!isFinite(n)) n=0; var s=Math.abs(n).toFixed(2).split('.'); s[0]=s[0].replace(/\B(?=(\d{3})+(?!\d))/g,'.'); return (n<0?'-':'')+'$'+s[0]+','+s[1]; }
  var defaults = [
    {n:'Queso Guayanés', l:10, k:8},
    {n:'Queso Blanco',   l:10, k:7},
    {n:'Queso de Mano',  l:10, k:6.5},
    {n:'Queso Telita',   l:10, k:7}
  ];
  function rowHtml(d){
    return '<td><input class="txt" type="text" value="'+(d.n||'')+'"></td>'+
           '<td><input class="num l" type="number" min="0" step="any" value="'+d.l+'"> L</td>'+
           '<td><input class="num k" type="number" min="0" step="any" value="'+d.k+'"> kg</td>'+
           '<td><input class="num c1" type="number" min="0" step="any" placeholder="0" value="'+(d.s||'')+'"></td>'+
           '<td><input class="num c2" type="number" min="0" step="any" placeholder="0" value="'+(d.e||'')+'"></td>'+
           '<td><input class="num c3" type="number" min="0" step="any" placeholder="0" value="'+(d.o||'')+'"></td>'+
           '<td class="out-kg">0 kg</td>'+
           '<td class="out-costo">$0,00</td>'+
           '<td class="out-precio">$0,00</td>'+
           '<td><button type="button" class="cc-delrow" title="Quitar">✕</button></td>';
  }
  function addRow(d){
    var tr=document.createElement('tr');
    tr.innerHTML=rowHtml(d||{n:'',l:10,k:8});
    body.appendChild(tr);
    tr.querySelectorAll('input').forEach(function(i){ i.addEventListener('input', calc); });
    tr.querySelector('.cc-delrow').addEventListener('click', function(){ tr.remove(); calc(); });
  }
  function calc(){
    var litros = v('cc_litros');
    var precioLitro = litros>0 ? v('cc_costo_leche')/litros : 0;
    var pl=document.getElementById('cc_costo_litro'); if(pl) pl.textContent=money(precioLitro);
    var gastoTotal = v('cc_costo_leche')+v('cc_nomina')+v('cc_personal')+v('cc_transporte')+v('cc_operativo')+v('cc_otros');
    var margen = v('cc_margen');
    document.getElementById('cc_gasto_total').textContent = money(gastoTotal);
    var ml=document.getElementById('cc_margen_lbl'); if(ml) ml.textContent=(isFinite(margen)?margen:0);
    var mb=document.getElementById('cc_margen_big'); if(mb) mb.textContent=(isFinite(margen)?margen:0);
    var fact='';
    body.querySelectorAll('tr').forEach(function(tr){
      var lref=parseFloat(tr.querySelector('.l').value)||0;
      var kref=parseFloat(tr.querySelector('.k').value)||0;
      var ratio=lref>0?kref/lref:0;
      var kgDia=litros*ratio;
      var extra=(parseFloat(tr.querySelector('.c1').value)||0)+(parseFloat(tr.querySelector('.c2').value)||0)+(parseFloat(tr.querySelector('.c3').value)||0);
      var costoKg=kgDia>0?(gastoTotal+extra)/kgDia:0;
      var precioKg=margen<100?costoKg/(1-margen/100):0;
      tr.querySelector('.out-kg').textContent=kgDia.toFixed(1)+' kg';
      tr.querySelector('.out-costo').textContent=money(costoKg);
      tr.querySelector('.out-precio').textContent=money(precioKg);
      var nm=(tr.querySelector('.txt').value||'').trim();
      if(nm && precioKg>0){ fact+='<tr><td>'+nm.replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</td><td>'+money(precioKg)+'</td></tr>'; }
    });
    var fb=document.getElementById('cc_factura');
    if(fb) fb.innerHTML = fact || '<tr><td class="cc-fact-empty" colspan="2">Sin productos con precio</td></tr>';
  }
  ['cc_nomina','cc_personal','cc_transporte','cc_operativo','cc_otros','cc_margen'].forEach(function(id){
    var e=document.getElementById(id);
    if(e){ e.addEventListener('input', calc); e.addEventListener('change', calc); }
  });
  document.getElementById('cc_add_row').addEventListener('click', function(){ addRow(); });
  var CC_SAVED = <?= json_encode($cc_cfg ?: null) ?>;
  var CC_CSRF = <?= json_encode(csrf_token()) ?>;
  if (CC_SAVED) {
    if (CC_SAVED.lista && CC_SAVED.lista.length) defaults = CC_SAVED.lista;
    if (CC_SAVED.margen != null) { var _em=document.getElementById('cc_margen'); if(_em) _em.value=CC_SAVED.margen; }
    if (CC_SAVED.gastos) {
      var _sv=function(id,val){ var e=document.getElementById(id); if(e && val!=null && +val!==0) e.value=val; };
      _sv('cc_personal',CC_SAVED.gastos.personal); _sv('cc_operativo',CC_SAVED.gastos.operativo); _sv('cc_otros',CC_SAVED.gastos.otros);
    }
  }
  defaults.forEach(addRow);
  function recolectar(){
    var lista=[];
    body.querySelectorAll('tr').forEach(function(tr){
      lista.push({
        n:(tr.querySelector('.txt').value||'').trim(),
        l:parseFloat(tr.querySelector('.l').value)||0,
        k:parseFloat(tr.querySelector('.k').value)||0,
        s:parseFloat(tr.querySelector('.c1').value)||0,
        e:parseFloat(tr.querySelector('.c2').value)||0,
        o:parseFloat(tr.querySelector('.c3').value)||0
      });
    });
    return { lista:lista, margen:v('cc_margen'), gastos:{ personal:v('cc_personal'), operativo:v('cc_operativo'), otros:v('cc_otros') } };
  }
  function guardar(){
    var btn=document.getElementById('cc_guardar');
    var msg=document.getElementById('cc_guardar_msg');
    if(btn){ btn.disabled=true; btn.textContent='Guardando...'; }
    var fd=new FormData();
    fd.append('accion','guardar_produccion');
    fd.append('_csrf', CC_CSRF);
    fd.append('config', JSON.stringify(recolectar()));
    fetch(window.location.href, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(btn){ btn.disabled=false; btn.textContent='Guardar todos los cambios'; }
        if(msg){ if(j&&j.ok){ msg.textContent='Cambios guardados'; msg.style.color='#1a7a3a'; } else { msg.textContent='No se pudo guardar'; msg.style.color='#b00020'; } }
      })
      .catch(function(){
        if(btn){ btn.disabled=false; btn.textContent='Guardar todos los cambios'; }
        if(msg){ msg.textContent='Error de conexion'; msg.style.color='#b00020'; }
      });
  }
  function imprimir(){
    var fb=document.getElementById('cc_factura');
    var rows=fb?fb.innerHTML:'';
    var margen=v('cc_margen');
    var fecha=new Date().toLocaleDateString('es-VE');
    var w=window.open('','_blank','width=440,height=660');
    if(!w){ alert('Permite las ventanas emergentes para imprimir.'); return; }
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Factura - precios de venta</title>'+
      '<style>body{font-family:Arial,Helvetica,sans-serif;color:#3a2f23;padding:22px}'+
      'h1{font-size:17px;margin:0 0 4px}.sub{color:#8a7d6c;font-size:12px;margin:0 0 16px}'+
      'table{width:100%;border-collapse:collapse}td{padding:9px 10px;border-bottom:1px solid #e7ddcf;font-size:14px}'+
      'td:last-child{text-align:right;font-weight:bold}</style></head><body>'+
      '<h1>El Castillo del Queso - Precios de venta x kg</h1>'+
      '<p class="sub">Margen de ganancia: '+margen+'%  -  '+fecha+'</p>'+
      '<table>'+rows+'</table></body></html>');
    w.document.close(); w.focus();
    setTimeout(function(){ try{ w.print(); }catch(e){} }, 300);
  }
  var _gb=document.getElementById('cc_guardar'); if(_gb) _gb.addEventListener('click', guardar);
  var _ib=document.getElementById('cc_imprimir'); if(_ib) _ib.addEventListener('click', imprimir);
  calc();
})();
</script>

<?php layout_end(); ?>
