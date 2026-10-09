<?php
require_login();
$db = get_db();

// Helper: run query safely, return 0/empty on error
function safe_scalar(PDO $db, string $sql): mixed {
    try { return $db->query($sql)->fetchColumn(); } catch (Exception $e) { return 0; }
}
function safe_all(PDO $db, string $sql): array {
    try { return $db->query($sql)->fetchAll(); } catch (Exception $e) { return []; }
}

$stats = [];
$stats['productos']   = safe_scalar($db, 'SELECT COUNT(*) FROM productos');
$stats['lotes']       = safe_scalar($db, 'SELECT COUNT(*) FROM lotes');
$stats['ventas']      = safe_scalar($db, 'SELECT COUNT(*) FROM facturas');
$stats['empleados']   = safe_scalar($db, 'SELECT COUNT(*) FROM empleados WHERE estado=true');
$stats['proveedores'] = safe_scalar($db, 'SELECT COUNT(*) FROM proveedores');
$stats['productores'] = safe_scalar($db, 'SELECT COUNT(*) FROM productores WHERE estado=true');

$ingresos = safe_scalar($db, "SELECT COALESCE(SUM(monto),0) FROM movimiento_caja WHERE tipo='ingreso'");
$egresos  = safe_scalar($db, "SELECT COALESCE(SUM(monto),0) FROM movimiento_caja WHERE tipo='egreso'");
$balance  = (float)$ingresos - (float)$egresos;

$tasa = safe_scalar($db, 'SELECT tasa FROM tasa_cambio ORDER BY fecha DESC LIMIT 1');

$ventas_rec = safe_all($db,
    "SELECT f.id, f.cliente, f.total, f.moneda, f.fecha FROM facturas f ORDER BY f.fecha DESC LIMIT 5"
);

$alertas = safe_all($db,
    "SELECT nombre, stock, umbral_alerta FROM productos WHERE stock <= umbral_alerta ORDER BY stock ASC LIMIT 8"
);

// ── Rentabilidad: costo de la leche vs. costo de transportarla (por productor) ─
// Últimos 90 días. El costo de transporte usa la tarifa por litro del
// transportista asignado a cada entrega.
ensure_transportistas_schema();
$rent_desde = date('Y-m-d', strtotime('-90 days'));
$rentab = safe_all($db, "
    SELECT p.nombre AS productor,
           COALESCE(SUM(e.litros),0)                                   AS litros,
           COALESCE(SUM(e.subtotal_usd),0)                             AS costo_leche,
           COALESCE(SUM(e.litros * COALESCE(t.tarifa_litro_usd,0)),0)  AS costo_transporte
    FROM productores_entregas e
    JOIN productores p ON p.id = e.productor_id
    LEFT JOIN transportistas t ON t.id = e.transportista_id
    WHERE e.fecha >= '" . $rent_desde . "'
    GROUP BY p.id, p.nombre
    HAVING COALESCE(SUM(e.litros),0) > 0
    ORDER BY SUM(e.litros) DESC
    LIMIT 10
");
// Convertir a costo por litro para comparar de forma justa entre productores.
$rent_labels = []; $rent_leche = []; $rent_transp = [];
foreach ($rentab as $r) {
    $litros = (float)$r['litros'];
    if ($litros <= 0) continue;
    $rent_labels[] = $r['productor'];
    $rent_leche[]  = round((float)$r['costo_leche'] / $litros, 4);
    $rent_transp[] = round((float)$r['costo_transporte'] / $litros, 4);
}

layout_start('Dashboard', 'dashboard');
?>
<div class="row g-3 mb-4">
  <?php foreach ([
    ['📦','Productos',   $stats['productos'],   'inventario'],
    ['🗂️','Lotes',       $stats['lotes'],       'lotes'],
    ['🧾','Ventas',      $stats['ventas'],      'ventas'],
    ['👷','Empleados',   $stats['empleados'],   'empleados'],
    ['🏭','Proveedores', $stats['proveedores'], 'proveedores'],
    ['🥛','Productores', $stats['productores'], 'productores'],
  ] as [$icon, $label, $val, $pg]):
    if (!has_permiso($pg)) continue; // solo mostrar tarjetas con acceso
  ?>
  <div class="col-6 col-md-4 col-lg-2">
    <a href="?p=<?= $pg ?>" class="text-decoration-none">
      <div class="stat-card text-center">
        <div class="stat-icon"><?= $icon ?></div>
        <div class="stat-val"><?= $val ?></div>
        <div class="stat-label"><?= $label ?></div>
      </div>
    </a>
  </div>
  <?php endforeach ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card text-center">
      <div class="stat-icon">💰</div>
      <div class="stat-val text-success"><?= fmt_dual($ingresos) ?></div>
      <div class="stat-label">Ingresos totales</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card text-center">
      <div class="stat-icon">📤</div>
      <div class="stat-val text-danger"><?= fmt_dual($egresos) ?></div>
      <div class="stat-label">Egresos totales</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card text-center">
      <div class="stat-icon">📈</div>
      <div class="stat-val <?= $balance >= 0 ? 'text-success' : 'text-danger' ?>"><?= fmt_dual($balance) ?></div>
      <div class="stat-label">Balance</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-7">
    <div class="card">
      <div class="card-header">🧾 Ventas recientes</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>#</th><th>Cliente</th><th>Total</th><th>Fecha</th></tr></thead>
          <tbody>
          <?php foreach ($ventas_rec as $v): ?>
          <tr>
            <td><?= h($v['id']) ?></td>
            <td><?= h($v['cliente']) ?></td>
            <td><?= fmt_dual($v['total'], $v['moneda'], true) ?></td>
            <td><?= fmt_date($v['fecha']) ?></td>
          </tr>
          <?php endforeach ?>
          <?php if (!$ventas_rec): ?><tr><td colspan="4" class="text-center text-muted py-3">Sin ventas</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card">
      <div class="card-header">⚠️ Alertas de stock bajo</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Producto</th><th>Stock</th><th>Mín.</th></tr></thead>
          <tbody>
          <?php foreach ($alertas as $a): ?>
          <tr class="table-warning">
            <td><?= h($a['nombre']) ?></td>
            <td><?= h($a['stock']) ?></td>
            <td><?= h($a['umbral_alerta']) ?></td>
          </tr>
          <?php endforeach ?>
          <?php if (!$alertas): ?><tr><td colspan="3" class="text-center text-muted py-3">Sin alertas</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if ($tasa): ?>
    <div class="stat-card mt-3 text-center">
      <div class="stat-icon">💱</div>
      <div class="stat-val"><?= number_format((float)$tasa, 2) ?></div>
      <div class="stat-label">Tasa de cambio Bs/USD</div>
    </div>
    <?php endif ?>
  </div>
</div>

<?php if (has_permiso('productores') && $rent_labels): ?>
<div class="card mt-4">
  <div class="card-header">📊 Rentabilidad por productor — Costo de la leche vs. costo de transportarla (por litro, últimos 90 días)</div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Cada barra muestra cuánto cuesta cada litro de leche de ese productor: en <strong style="color:#f97316">naranja</strong> lo que se paga por la leche y en <strong style="color:#1a2e5a">azul</strong> lo que cuesta transportarla.
      Mientras <strong>más bajo el total</strong>, más conviene ese productor. El transporte requiere que la entrega tenga un transportista asignado en Control de Leche.
    </p>
    <div style="position:relative;height:340px">
      <canvas id="chartRentab"></canvas>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function(){
  var ctx = document.getElementById('chartRentab');
  if (!ctx || typeof Chart === 'undefined') return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($rent_labels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        { label: 'Costo leche $/L',      data: <?= json_encode($rent_leche) ?>,  backgroundColor: '#f97316' },
        { label: 'Costo transporte $/L', data: <?= json_encode($rent_transp) ?>, backgroundColor: '#1a2e5a' }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom' },
        tooltip: {
          callbacks: {
            label: function(c){ return c.dataset.label + ': $' + Number(c.parsed.y).toFixed(4) + '/L'; },
            footer: function(items){
              var tot = items.reduce(function(s,i){ return s + i.parsed.y; }, 0);
              return 'Costo total: $' + tot.toFixed(4) + '/L';
            }
          }
        }
      },
      scales: {
        x: { stacked: true },
        y: { stacked: true, beginAtZero: true, ticks: { callback: function(v){ return '$' + v; } } }
      }
    }
  });
})();
</script>
<?php endif ?>
<?php layout_end(); ?>
