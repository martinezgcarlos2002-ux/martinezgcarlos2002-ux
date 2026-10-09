<?php
require_login();
$db = get_db();
ensure_almacenes_schema();

$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');

$almacenes = $db->query("SELECT * FROM almacenes WHERE " . (is_mysql() ? "activo=1" : "activo=TRUE") . " ORDER BY orden, id")->fetchAll();

// Producción del día
$st = $db->prepare("
    SELECT pd.producto_produccion_id, pd.almacen_id, pd.cantidad, pd.precio_unit, pd.total,
           pp.nombre AS pp_nom,
           COALESCE(pr.codigo, '') AS codigo
    FROM produccion_diaria pd
    JOIN productos_produccion pp ON pp.id = pd.producto_produccion_id
    LEFT JOIN productos pr ON pr.id = pp.producto_id
    WHERE pd.fecha = ?
    ORDER BY pp.orden, pp.id
");
$st->execute([$fecha]);
$rows = $st->fetchAll();

// Agrupar por producto, con desglose por almacén
$prods = []; // pp_id => ['nombre','codigo','precio','by_alm'=>[aid=>cant], 'total_uds','total_dinero']
foreach ($rows as $r) {
    $pid = (int)$r['producto_produccion_id'];
    if (!isset($prods[$pid])) {
        $prods[$pid] = ['nombre'=>$r['pp_nom'], 'codigo'=>$r['codigo'], 'precio'=>(float)$r['precio_unit'], 'by_alm'=>[], 'total_uds'=>0.0, 'total_dinero'=>0.0];
    }
    $aid  = (int)($r['almacen_id'] ?? 0);
    $cant = (float)$r['cantidad'];
    if ($aid > 0) $prods[$pid]['by_alm'][$aid] = ($prods[$pid]['by_alm'][$aid] ?? 0) + $cant;
    $prods[$pid]['total_uds']    += $cant;
    $prods[$pid]['total_dinero'] += (float)$r['total'];
}

// Totales por almacén y gran total
$tot_alm = []; foreach ($almacenes as $a) $tot_alm[(int)$a['id']] = 0.0;
$gran_uds = 0.0; $gran_din = 0.0;
foreach ($prods as $p) {
    foreach ($p['by_alm'] as $aid => $c) if (isset($tot_alm[$aid])) $tot_alm[$aid] += $c;
    $gran_uds += $p['total_uds'];
    $gran_din += $p['total_dinero'];
}
$colors = ['#d1e7dd','#cfe2ff','#f8d7da','#fff3cd','#e2d4f0'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Producción del <?= h($fecha) ?> — El Castillo del Queso</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  @page { size: Letter landscape; margin: 8mm; }
  body { background:#f5f5f5; font-family:'Segoe UI', sans-serif; }
  .doc { max-width:1100px; margin:24px auto; background:#fff; border-radius:12px; box-shadow:0 2px 20px rgba(0,0,0,.1); }
  .doc-header { background:linear-gradient(135deg,#5a3a1a 0%,#8a5a2a 100%); color:#fff; padding:20px 32px; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center; }
  .logo-img { height:70px; width:70px; object-fit:contain; border-radius:8px; background:#fff; padding:4px; }
  .doc-body { padding:24px 32px 32px; }
  table thead th { background:#5a3a1a; color:#fff; vertical-align:middle; text-align:center; font-size:.85rem; }
  table tbody td { font-size:.85rem; vertical-align:middle; }
  .col-tot { background:#fff8d6; font-weight:700; text-align:right; }
  .col-din { background:#d1e7dd; font-weight:700; text-align:right; color:#0f5132; }
  tfoot td { background:#5a3a1a; color:#fff; font-weight:700; }
  .pie { text-align:center; padding:14px 32px 22px; color:#999; font-size:.78rem; border-top:1px solid #eee; }
  .no-print { margin:20px auto; max-width:1100px; display:flex; gap:10px; }
  @media print {
    body { background:#fff; margin:0; }
    .no-print { display:none !important; }
    .doc { box-shadow:none; border-radius:0; margin:0; max-width:100%; }
    .doc-header, table thead th, tfoot td, .col-tot, .col-din { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>

<div class="no-print">
  <button onclick="window.print()" class="btn btn-primary">🖨 Imprimir</button>
  <button onclick="exportPDF()" class="btn btn-danger">📄 Exportar PDF</button>
  <a href="?p=produccion_diaria&fecha=<?= h($fecha) ?>" class="btn btn-outline-secondary">← Volver</a>
</div>

<div class="doc" id="docReporte">
  <div class="doc-header">
    <div class="d-flex align-items-center gap-3">
      <img src="public/logo.jpg" class="logo-img" alt="Logo">
      <div>
        <div style="font-size:1.5rem;font-weight:700">El Castillo del Queso</div>
        <div style="font-size:.85rem;opacity:.85">Reporte de Producción Diaria</div>
      </div>
    </div>
    <div class="text-end">
      <div style="font-size:1.1rem;opacity:.85">FECHA</div>
      <div style="font-size:1.8rem;font-weight:700"><?= date('d/m/Y', strtotime($fecha)) ?></div>
    </div>
  </div>

  <div class="doc-body">
    <?php if (empty($prods)): ?>
      <div class="alert alert-warning text-center">No hay producción registrada para esta fecha.</div>
    <?php else: ?>
    <table class="table table-bordered mb-3">
      <thead>
        <tr>
          <th rowspan="2" style="min-width:200px;text-align:left">Producto</th>
          <th colspan="<?= count($almacenes) ?>" style="background:#7a4f24">Cantidad por almacén</th>
          <th rowspan="2">Total uds</th>
          <th rowspan="2">Precio unit.</th>
          <th rowspan="2">Total $</th>
        </tr>
        <tr>
          <?php foreach ($almacenes as $i => $a):
            $bg = $colors[$i % count($colors)]; ?>
            <th style="background:<?= $bg ?>;color:#222"><?= h($a['nombre']) ?></th>
          <?php endforeach ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($prods as $p): ?>
        <tr>
          <td><strong><?= h($p['nombre']) ?></strong><?php if ($p['codigo']): ?><br><small class="text-muted"><?= h($p['codigo']) ?></small><?php endif ?></td>
          <?php foreach ($almacenes as $a):
            $v = $p['by_alm'][(int)$a['id']] ?? 0; ?>
            <td class="text-center"><?= $v > 0 ? number_format($v, 2) : '—' ?></td>
          <?php endforeach ?>
          <td class="col-tot"><?= number_format($p['total_uds'], 2) ?></td>
          <td class="text-end">$<?= number_format($p['precio'], 2) ?></td>
          <td class="col-din">$<?= number_format($p['total_dinero'], 2) ?></td>
        </tr>
        <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr>
          <td>TOTALES</td>
          <?php foreach ($almacenes as $a): ?>
            <td class="text-center"><?= number_format($tot_alm[(int)$a['id']], 2) ?></td>
          <?php endforeach ?>
          <td class="text-end"><?= number_format($gran_uds, 2) ?></td>
          <td></td>
          <td class="text-end">$<?= number_format($gran_din, 2) ?></td>
        </tr>
      </tfoot>
    </table>

    <div class="row g-3 mt-2">
      <?php foreach ($almacenes as $i => $a):
        $bg = $colors[$i % count($colors)]; ?>
        <div class="col">
          <div style="background:<?= $bg ?>;padding:12px;border-radius:8px;text-align:center">
            <div style="font-size:.75rem;color:#555;font-weight:600"><?= h($a['nombre']) ?></div>
            <div style="font-size:1.8rem;font-weight:800;color:#222"><?= number_format($tot_alm[(int)$a['id']], 2) ?></div>
            <div style="font-size:.7rem;color:#666">unidades producidas</div>
          </div>
        </div>
      <?php endforeach ?>
      <div class="col">
        <div style="background:#5a3a1a;color:#fff;padding:12px;border-radius:8px;text-align:center">
          <div style="font-size:.75rem;opacity:.85;font-weight:600">TOTAL GENERAL</div>
          <div style="font-size:1.8rem;font-weight:800">$<?= number_format($gran_din, 2) ?></div>
          <div style="font-size:.7rem;opacity:.85"><?= number_format($gran_uds, 2) ?> unidades</div>
        </div>
      </div>
    </div>
    <?php endif ?>
  </div>

  <div class="pie">
    El Castillo del Queso · Reporte generado por ASERP · <?= date('d/m/Y H:i') ?>
  </div>
</div>

<script>
function exportPDF() {
  const el = document.getElementById('docReporte');
  html2pdf().set({
    margin: 0.3,
    filename: 'produccion-<?= h($fecha) ?>.pdf',
    image: { type:'jpeg', quality:0.98 },
    html2canvas: { scale:2, useCORS:true },
    jsPDF: { unit:'in', format:'letter', orientation:'landscape' }
  }).from(el).save();
}
</script>
</body>
</html>
