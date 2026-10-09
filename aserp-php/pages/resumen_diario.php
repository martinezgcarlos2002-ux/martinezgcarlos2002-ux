<?php
require_login();
$db = get_db();

$hoy  = date('Y-m-d');
$desde = $_GET['desde'] ?? $hoy;
$hasta = $_GET['hasta'] ?? $hoy;
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ? $desde : $hoy;
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) ? $hasta : $hoy;

// ── Ventas / Facturas ────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT f.fecha,
           COUNT(*)                  AS n_facturas,
           COALESCE(SUM(f.total),0)  AS total_usd,
           COUNT(DISTINCT f.cliente) AS n_clientes
    FROM facturas f
    WHERE f.fecha BETWEEN ? AND ?
    GROUP BY f.fecha
    ORDER BY f.fecha DESC
");
$stmt->execute([$desde, $hasta]);
$ventas_dia = [];
foreach ($stmt->fetchAll() as $r) { $ventas_dia[$r['fecha']] = $r; }

// ── Abonos por tipo (vales, cortesías, mercancía, efectivo, pm_trans) ────────
$stmt2 = $db->prepare("
    SELECT a.fecha,
           a.tipo,
           COUNT(*)               AS n,
           COALESCE(SUM(a.monto_usd),0) AS total_usd
    FROM productores_abonos a
    WHERE a.fecha BETWEEN ? AND ?
    GROUP BY a.fecha, a.tipo
    ORDER BY a.fecha DESC, a.tipo
");
$stmt2->execute([$desde, $hasta]);
$abonos_dia = [];
foreach ($stmt2->fetchAll() as $r) {
    $abonos_dia[$r['fecha']][$r['tipo']] = $r;
}

// ── Entregas de leche ─────────────────────────────────────────────────────────
$stmt3 = $db->prepare("
    SELECT e.fecha,
           COUNT(*)                       AS n_productores,
           COALESCE(SUM(e.litros),0)      AS litros,
           COALESCE(SUM(e.subtotal_usd),0) AS monto_usd
    FROM productores_entregas e
    WHERE e.fecha BETWEEN ? AND ?
    GROUP BY e.fecha
    ORDER BY e.fecha DESC
");
$stmt3->execute([$desde, $hasta]);
$entregas_dia = [];
foreach ($stmt3->fetchAll() as $r) { $entregas_dia[$r['fecha']] = $r; }

// ── Conjunto de fechas únicas ─────────────────────────────────────────────────
$fechas = array_unique(array_merge(
    array_keys($ventas_dia),
    array_keys($abonos_dia),
    array_keys($entregas_dia)
));
rsort($fechas);

// ── Totales globales del período ──────────────────────────────────────────────
$tot_facturas  = array_sum(array_column($ventas_dia,   'n_facturas'));
$tot_venta_usd = array_sum(array_column($ventas_dia,   'total_usd'));
$tot_litros    = array_sum(array_column($entregas_dia, 'litros'));
$tot_leche_usd = array_sum(array_column($entregas_dia, 'monto_usd'));

$tot_tipos = [];
foreach ($abonos_dia as $f => $tipos) {
    foreach ($tipos as $tipo => $d) {
        $tot_tipos[$tipo]['n']   = ($tot_tipos[$tipo]['n']   ?? 0) + $d['n'];
        $tot_tipos[$tipo]['usd'] = ($tot_tipos[$tipo]['usd'] ?? 0) + $d['total_usd'];
    }
}

require_once __DIR__ . '/../layout.php';
layout_start('Resumen Diario', 'resumen_diario');

$tipo_labels = [
    'vale'      => ['label' => 'Vales',      'color' => '#7c3aed', 'bg' => '#ede9fe', 'icon' => '📜'],
    'cortesia'  => ['label' => 'Cortesías',  'color' => '#0891b2', 'bg' => '#e0f2fe', 'icon' => '🎁'],
    'mercancia' => ['label' => 'Mercancía',  'color' => '#b45309', 'bg' => '#fef3c7', 'icon' => '🛒'],
    'efectivo'  => ['label' => 'Efectivo',   'color' => '#15803d', 'bg' => '#dcfce7', 'icon' => '💵'],
    'pm_trans'  => ['label' => 'PM/Trans',   'color' => '#1d4ed8', 'bg' => '#dbeafe', 'icon' => '🚚'],
];
?>

<style>
.dia-card { border-radius:12px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:1.25rem; overflow:hidden; }
.dia-header { padding:.65rem 1rem; font-weight:700; font-size:.9rem; color:#fff; display:flex; align-items:center; justify-content:space-between; background:#1a2e5a; }
.dia-header .fecha-lbl { font-size:1rem; }
.dia-header .dia-semana { font-size:.72rem; font-weight:400; opacity:.8; }
.dia-body { padding:.85rem 1rem; display:flex; flex-wrap:wrap; gap:.6rem; }
.dia-pill { display:flex; align-items:center; gap:.45rem; border-radius:20px; padding:.3rem .8rem; font-size:.8rem; font-weight:600; border:1px solid transparent; min-width:130px; }
.dia-pill .pill-count { font-size:.7rem; font-weight:400; opacity:.75; }
.dia-pill .pill-val { margin-left:auto; font-weight:700; }
.kpi-top { border-radius:10px; border:1px solid #e2e8f0; background:#fff; padding:.9rem 1rem; text-align:center; }
.kpi-top .kv { font-size:1.3rem; font-weight:700; color:#1a2e5a; }
.kpi-top .kl { font-size:.72rem; color:#64748b; margin-top:2px; }
.no-datos { text-align:center; color:#94a3b8; padding:3rem 1rem; font-size:.95rem; }
</style>

<!-- Header + filtros -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0">📅 Resumen Diario</h5>
</div>

<form method="get" class="mb-4">
  <input type="hidden" name="p" value="resumen_diario">
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
  </div>
  <div class="mt-2 d-flex flex-wrap gap-1">
    <?php foreach ([
      'Hoy'          => [date('Y-m-d'), date('Y-m-d')],
      'Ayer'         => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
      'Esta semana'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
      'Este mes'     => [date('Y-m-01'), date('Y-m-d')],
      'Mes anterior' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
      'Últimos 7 d'  => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
      'Últimos 30 d' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
    ] as $lbl => [$d1,$d2]): ?>
    <a href="?p=resumen_diario&desde=<?=$d1?>&hasta=<?=$d2?>"
       class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.72rem"><?= $lbl ?></a>
    <?php endforeach ?>
  </div>
</form>

<!-- KPIs del período -->
<div class="row g-2 mb-4">
  <div class="col-6 col-md-2">
    <div class="kpi-top">
      <div class="kv"><?= $tot_facturas ?></div>
      <div class="kl">🧾 Ventas</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="kpi-top">
      <div class="kv text-success">$<?= number_format($tot_venta_usd,2) ?></div>
      <div class="kl">Monto ventas</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="kpi-top">
      <div class="kv" style="color:#7c3aed"><?= $tot_tipos['vale']['n'] ?? 0 ?></div>
      <div class="kl">📜 Vales</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="kpi-top">
      <div class="kv" style="color:#0891b2"><?= $tot_tipos['cortesia']['n'] ?? 0 ?></div>
      <div class="kl">🎁 Cortesías</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="kpi-top">
      <div class="kv text-primary"><?= number_format($tot_litros,1) ?> L</div>
      <div class="kl">🥛 Leche recibida</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="kpi-top">
      <div class="kv" style="color:#b45309"><?= $tot_tipos['mercancia']['n'] ?? 0 ?></div>
      <div class="kl">🛒 Mercancías</div>
    </div>
  </div>
</div>

<!-- Tarjetas por día -->
<?php if (!$fechas): ?>
<div class="no-datos">No hay actividad registrada en el período seleccionado.</div>
<?php else: ?>
<?php foreach ($fechas as $fecha):
    $v  = $ventas_dia[$fecha]   ?? null;
    $e  = $entregas_dia[$fecha] ?? null;
    $ab = $abonos_dia[$fecha]   ?? [];

    $dia_semana_arr = ['lunes','martes','miércoles','jueves','viernes','sábado','domingo'];
    $dow = (int)(new DateTime($fecha))->format('N') - 1;
    $dia_nombre = ucfirst($dia_semana_arr[$dow]);
    $fecha_fmt  = (new DateTime($fecha))->format('d/m/Y');
    $es_hoy = ($fecha === $hoy);
?>
<div class="dia-card <?= $es_hoy ? 'border-warning' : '' ?>">
  <div class="dia-header" style="<?= $es_hoy ? 'background:#f97316' : '' ?>">
    <div>
      <span class="dia-semana"><?= $dia_nombre ?></span>
      <div class="fecha-lbl"><?= $fecha_fmt ?></div>
    </div>
    <?php if ($es_hoy): ?>
    <span class="badge bg-light text-dark" style="font-size:.72rem">HOY</span>
    <?php endif ?>
  </div>

  <div class="dia-body">

    <?php if ($v): ?>
    <!-- Ventas -->
    <div class="dia-pill" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d">
      <span>🧾</span>
      <span>Ventas</span>
      <span class="pill-count">(<?= $v['n_facturas'] ?>)</span>
      <span class="pill-val">$<?= number_format($v['total_usd'],2) ?></span>
    </div>
    <?php endif ?>

    <?php if ($e): ?>
    <!-- Leche recibida -->
    <div class="dia-pill" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8">
      <span>🥛</span>
      <span>Leche</span>
      <span class="pill-count">(<?= $e['n_productores'] ?> prod.)</span>
      <span class="pill-val"><?= number_format($e['litros'],1) ?> L</span>
    </div>
    <?php endif ?>

    <?php foreach ($tipo_labels as $tipo => $meta):
        if (!isset($ab[$tipo])) continue;
        $td = $ab[$tipo];
    ?>
    <div class="dia-pill" style="background:<?= $meta['bg'] ?>;border-color:<?= $meta['color'] ?>33;color:<?= $meta['color'] ?>">
      <span><?= $meta['icon'] ?></span>
      <span><?= $meta['label'] ?></span>
      <span class="pill-count">(<?= $td['n'] ?>)</span>
      <span class="pill-val">$<?= number_format($td['total_usd'],2) ?></span>
    </div>
    <?php endforeach ?>

    <?php if (!$v && !$e && !$ab): ?>
    <span class="text-muted" style="font-size:.8rem">Sin movimientos</span>
    <?php endif ?>

  </div>
</div>
<?php endforeach ?>
<?php endif ?>

<?php layout_end(); ?>
