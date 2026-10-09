<?php
require_login();
$db  = get_db();
$pid = (int)($_GET['id'] ?? 0);
if (!$pid) { header('Location: ?p=proveedores'); exit; }

$prov = $db->prepare('SELECT * FROM proveedores WHERE id=?');
$prov->execute([$pid]);
$prov = $prov->fetch();
if (!$prov) { flash('Proveedor no encontrado.', 'danger'); header('Location: ?p=proveedores'); exit; }

// ── Semana activa ──────────────────────────────────────────────────────────
$semana_raw = $_GET['semana'] ?? '';
if ($semana_raw && preg_match('/^\d{4}-W\d{2}$/', $semana_raw)) {
    [$yr, $wk] = explode('-W', $semana_raw);
    $ws = new DateTime(); $ws->setISODate((int)$yr, (int)$wk, 1);
} else {
    $ws  = new DateTime();
    $dow = (int)$ws->format('N');
    $ws->modify('-' . ($dow - 1) . ' days');
}
$we        = (clone $ws)->modify('+6 days');
$ws_str    = $ws->format('Y-m-d');
$we_str    = $we->format('Y-m-d');
$iso       = $ws->format('o-\WW');
$prev_iso  = (clone $ws)->modify('-7 days')->format('o-\WW');
$next_iso  = (clone $ws)->modify('+7 days')->format('o-\WW');

// ── Entregas de la semana ──────────────────────────────────────────────────
$ents = $db->prepare(
    'SELECT fecha, cantidad, precio_usd, subtotal_usd, descripcion
     FROM proveedores_entregas
     WHERE proveedor_id = ? AND fecha BETWEEN ? AND ?
     ORDER BY fecha'
);
$ents->execute([$pid, $ws_str, $we_str]);
$entregas_raw = $ents->fetchAll();
// Indexar por fecha
$por_fecha = [];
foreach ($entregas_raw as $e) { $por_fecha[$e['fecha']] = $e; }

// Construir tabla Mon-Sun
$dias_es = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
$semana  = [];
for ($i = 0; $i < 7; $i++) {
    $d     = (clone $ws)->modify("+$i days");
    $fecha = $d->format('Y-m-d');
    $ent   = $por_fecha[$fecha] ?? null;
    $semana[] = [
        'dia'      => $dias_es[$i],
        'fecha'    => $fecha,
        'corto'    => $d->format('d/m'),
        'cantidad' => $ent ? (float)$ent['cantidad']    : 0,
        'precio'   => $ent ? (float)$ent['precio_usd']  : (float)$prov['precio_litro_usd'],
        'subtotal' => $ent ? (float)$ent['subtotal_usd']: 0,
        'nota'     => $ent ? $ent['descripcion']         : '',
    ];
}

$total_litros = array_sum(array_column($semana, 'cantidad'));
$total_usd    = array_sum(array_column($semana, 'subtotal'));

// Pagos de la semana
$pagos_st = $db->prepare(
    'SELECT fecha, monto_usd, descripcion FROM proveedores_pagos
     WHERE proveedor_id = ? AND fecha BETWEEN ? AND ? ORDER BY fecha'
);
$pagos_st->execute([$pid, $ws_str, $we_str]);
$pagos        = $pagos_st->fetchAll();
$total_pagado = array_sum(array_column($pagos, 'monto_usd'));
$saldo        = $total_usd - $total_pagado;

// Acumulado hasta hoy (deuda histórica)
$hist = $db->prepare(
    'SELECT COALESCE(SUM(subtotal_usd),0) AS t FROM proveedores_entregas WHERE proveedor_id=?'
);
$hist->execute([$pid]); $hist_ent = (float)$hist->fetchColumn();
$hist2 = $db->prepare(
    'SELECT COALESCE(SUM(monto_usd),0) AS t FROM proveedores_pagos WHERE proveedor_id=?'
);
$hist2->execute([$pid]); $hist_pag = (float)$hist2->fetchColumn();
$deuda_total = $hist_ent - $hist_pag;

$tasa = get_tasa();

// ── Exportar CSV (Excel) ───────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $fname = 'reporte-' . preg_replace('/[^a-z0-9]/i','_',$prov['nombre'])
           . '-' . $ws_str . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo "\xEF\xBB\xBF"; // BOM para Excel

    $out = fopen('php://output', 'w');
    fputcsv($out, ['REPORTE SEMANAL DE ENTREGAS DE LECHE'], ';');
    fputcsv($out, ['Empresa:', 'El Castillo del Queso', 'RIF:', 'V-286013356'], ';');
    fputcsv($out, ['Proveedor:', $prov['nombre']], ';');
    fputcsv($out, ['Contacto:', $prov['contacto'] ?: '—', 'Teléfono:', $prov['telefono'] ?: '—'], ';');
    fputcsv($out, ['Semana:', $ws->format('d/m/Y') . ' al ' . $we->format('d/m/Y')], ';');
    fputcsv($out, ['Precio acordado (USD/L):', number_format((float)$prov['precio_litro_usd'],4,'.','')], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Día','Fecha','Litros','Precio USD/L','Subtotal USD','Subtotal Bs','Nota'], ';');
    foreach ($semana as $d) {
        $ves_sub = $d['subtotal'] * $tasa;
        fputcsv($out, [
            $d['dia'],
            $d['corto'],
            $d['cantidad'] > 0 ? number_format($d['cantidad'],3,'.','') : '—',
            $d['precio']   > 0 ? number_format($d['precio'],4,'.','')   : '—',
            $d['subtotal'] > 0 ? number_format($d['subtotal'],2,'.','') : '—',
            $d['subtotal'] > 0 ? number_format($ves_sub,2,'.','')       : '—',
            $d['nota'],
        ], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['TOTAL LITROS', '', number_format($total_litros,3,'.',''), '', number_format($total_usd,2,'.',''), number_format($total_usd*$tasa,2,'.','')], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['PAGOS DE LA SEMANA'], ';');
    fputcsv($out, ['Fecha','Monto USD','Descripción'], ';');
    foreach ($pagos as $p) {
        fputcsv($out, [date('d/m/Y', strtotime($p['fecha'])), number_format((float)$p['monto_usd'],2,'.',''), $p['descripcion']], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['Total pagado semana USD:', number_format($total_pagado,2,'.','')], ';');
    fputcsv($out, ['Saldo pendiente semana USD:', number_format($saldo,2,'.','')], ';');
    fputcsv($out, ['Deuda histórica total USD:', number_format($deuda_total,2,'.','')], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Tasa Bs/USD utilizada:', number_format($tasa,2,'.','')], ';');
    fputcsv($out, ['Generado:', date('d/m/Y H:i')], ';');
    fclose($out);
    exit;
}

// ── Modo impresión (solo HTML del reporte, para PDF) ──────────────────────
$print_mode = ($_GET['print'] ?? '') === '1';

if ($print_mode): ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte Semanal – <?= h($prov['nombre']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  @page { size: Letter portrait; margin: 10mm; }
  body { background:#f5f5f5; font-family:'Segoe UI',sans-serif; }
  .rep-wrap { max-width:820px; margin:20px auto; background:#fff; border-radius:12px;
              box-shadow:0 2px 20px rgba(0,0,0,.12); overflow:hidden; }
  .rep-header { background:linear-gradient(135deg,#1a2e5a,#2a4a8a); color:#fff;
                padding:22px 32px; display:flex; justify-content:space-between; align-items:center; }
  .logo-img { height:80px; width:80px; object-fit:contain; background:#fff;
              border-radius:8px; padding:3px; }
  .rep-body { padding:28px 32px; }
  .week-badge { background:#f97316; color:#fff; border-radius:6px; padding:4px 12px;
                font-size:.85rem; font-weight:600; }
  table thead th { background:#1a2e5a; color:#fff; font-size:.78rem; }
  .totrow td { background:#e8f0fe; font-weight:700; }
  .saldo-pos { color:#16a34a; font-weight:700; }
  .saldo-neg { color:#dc2626; font-weight:700; }
  .no-print { max-width:820px; margin:16px auto; display:flex; gap:10px; }
  .firma-row { display:flex; gap:24px; margin-top:32px; }
  .firma-box { flex:1; text-align:center; }
  .firma-line { border-top:1.5px solid #999; margin-top:48px; padding-top:6px;
                font-size:.8rem; color:#555; }
  .sello-box { flex:1; border:2px dashed #bbb; border-radius:8px; height:80px;
               display:flex; align-items:center; justify-content:center;
               color:#aaa; font-size:.8rem; }
  .pie { text-align:center; font-size:.75rem; color:#aaa; padding:14px 32px 20px;
         border-top:1px solid #eee; }
  @media print {
    body { background:#fff; margin:0; }
    .no-print { display:none !important; }
    .rep-wrap { box-shadow:none; border-radius:0; margin:0; max-width:100%; }
    .rep-header, table thead th, .totrow td { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()" class="btn btn-primary">🖨 Imprimir</button>
  <button onclick="exportarPDF()" class="btn btn-outline-danger">📄 Exportar PDF</button>
  <a href="?p=proveedor_reporte&id=<?= $pid ?>&semana=<?= h($iso) ?>&export=csv" class="btn btn-outline-success">📊 Excel / CSV</a>
  <a href="?p=proveedor_reporte&id=<?= $pid ?>&semana=<?= h($iso) ?>" class="btn btn-outline-secondary">← Volver</a>
</div>

<div class="rep-wrap" id="repDoc">
  <div class="rep-header">
    <div class="d-flex align-items-center gap-3">
      <img src="public/logo.jpg" class="logo-img" alt="Logo">
      <div>
        <div style="font-size:1.3rem;font-weight:700">El Castillo del Queso</div>
        <div style="font-size:.8rem;opacity:.8">Productora Artesanal · RIF: V-286013356</div>
      </div>
    </div>
    <div class="text-end">
      <div style="font-size:1rem;opacity:.85">Reporte Semanal</div>
      <div style="font-size:1.4rem;font-weight:700"><?= $ws->format('d/m') ?> – <?= $we->format('d/m/Y') ?></div>
    </div>
  </div>

  <div class="rep-body">
    <!-- Datos proveedor -->
    <div class="row g-2 mb-3 p-3" style="background:#f8f9fc;border-radius:8px">
      <div class="col-6">
        <div class="text-muted small">PROVEEDOR</div>
        <div class="fw-bold fs-5"><?= h($prov['nombre']) ?></div>
        <?php if ($prov['contacto']): ?><div class="small"><?= h($prov['contacto']) ?></div><?php endif ?>
        <?php if ($prov['telefono']): ?><div class="small text-muted"><?= h($prov['telefono']) ?></div><?php endif ?>
      </div>
      <div class="col-3 text-center">
        <div class="text-muted small">Precio acordado</div>
        <div class="fw-bold text-success fs-5">$<?= number_format((float)$prov['precio_litro_usd'],4,'.',',') ?>/L</div>
        <div class="small text-muted">Bs. <?= number_format((float)$prov['precio_litro_usd']*$tasa,4,'.',',') ?>/L</div>
      </div>
      <div class="col-3 text-end">
        <div class="text-muted small">Tasa Bs/USD</div>
        <div class="fw-bold"><?= number_format($tasa,2,'.',',') ?></div>
        <div class="small text-muted">Generado: <?= date('d/m/Y') ?></div>
      </div>
    </div>

    <!-- Tabla diaria -->
    <h6 class="fw-bold mb-2" style="color:#1a2e5a">📦 Entregas de la Semana</h6>
    <table class="table table-bordered table-sm mb-3">
      <thead>
        <tr>
          <th>Día</th><th class="text-center">Fecha</th>
          <th class="text-center">Litros</th>
          <th class="text-end">Precio/L</th>
          <th class="text-end">Subtotal USD</th>
          <th class="text-end">Subtotal Bs.</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($semana as $d): ?>
      <tr <?= $d['cantidad']>0 ? '' : 'class="text-muted"' ?>>
        <td class="fw-semibold"><?= $d['dia'] ?></td>
        <td class="text-center"><?= $d['corto'] ?></td>
        <td class="text-center"><?= $d['cantidad']>0 ? number_format($d['cantidad'],3,'.',',') . ' L' : '—' ?></td>
        <td class="text-end"><?= $d['cantidad']>0 ? '$'.number_format($d['precio'],4,'.',',') : '—' ?></td>
        <td class="text-end"><?= $d['cantidad']>0 ? '$'.number_format($d['subtotal'],2,'.',',') : '—' ?></td>
        <td class="text-end"><?= $d['cantidad']>0 ? 'Bs. '.number_format($d['subtotal']*$tasa,2,'.',',') : '—' ?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr class="totrow">
          <td colspan="2" class="text-end">TOTAL SEMANA</td>
          <td class="text-center"><?= number_format($total_litros,3,'.',',') ?> L</td>
          <td></td>
          <td class="text-end">$<?= number_format($total_usd,2,'.',',') ?></td>
          <td class="text-end">Bs. <?= number_format($total_usd*$tasa,2,'.',',') ?></td>
        </tr>
      </tfoot>
    </table>

    <!-- Pagos -->
    <h6 class="fw-bold mb-2" style="color:#1a2e5a">💳 Pagos de la Semana</h6>
    <?php if ($pagos): ?>
    <table class="table table-bordered table-sm mb-3">
      <thead><tr><th>Fecha</th><th>Descripción</th><th class="text-end">Monto USD</th><th class="text-end">Monto Bs.</th></tr></thead>
      <tbody>
      <?php foreach ($pagos as $pg): ?>
      <tr>
        <td><?= date('d/m/Y', strtotime($pg['fecha'])) ?></td>
        <td><?= h($pg['descripcion']) ?></td>
        <td class="text-end text-success">$<?= number_format((float)$pg['monto_usd'],2,'.',',') ?></td>
        <td class="text-end text-success">Bs. <?= number_format((float)$pg['monto_usd']*$tasa,2,'.',',') ?></td>
      </tr>
      <?php endforeach ?>
      <tr class="totrow">
        <td colspan="2" class="text-end">Total pagado</td>
        <td class="text-end">$<?= number_format($total_pagado,2,'.',',') ?></td>
        <td class="text-end">Bs. <?= number_format($total_pagado*$tasa,2,'.',',') ?></td>
      </tr>
      </tbody>
    </table>
    <?php else: ?>
    <p class="text-muted small mb-3">Sin pagos registrados esta semana.</p>
    <?php endif ?>

    <!-- Resumen -->
    <div class="row g-3 mb-4">
      <div class="col-4">
        <div style="background:#f0f4ff;border-radius:8px;padding:14px;text-align:center">
          <div class="text-muted small">Total entregado</div>
          <div class="fw-bold fs-5 text-primary">$<?= number_format($total_usd,2,'.',',') ?></div>
          <div class="small text-muted">Bs. <?= number_format($total_usd*$tasa,2,'.',',') ?></div>
        </div>
      </div>
      <div class="col-4">
        <div style="background:#f0fff4;border-radius:8px;padding:14px;text-align:center">
          <div class="text-muted small">Total pagado</div>
          <div class="fw-bold fs-5 text-success">$<?= number_format($total_pagado,2,'.',',') ?></div>
          <div class="small text-muted">Bs. <?= number_format($total_pagado*$tasa,2,'.',',') ?></div>
        </div>
      </div>
      <div class="col-4">
        <div style="background:<?= $saldo>0?'#fff5f5':'#f0fff4' ?>;border-radius:8px;padding:14px;text-align:center">
          <div class="text-muted small">Saldo pendiente semana</div>
          <div class="fw-bold fs-5 <?= $saldo>0?'text-danger':'text-success' ?>">
            $<?= number_format(abs($saldo),2,'.',',') ?> <?= $saldo>0?'debe':'a favor' ?>
          </div>
          <div class="small text-muted">Bs. <?= number_format(abs($saldo)*$tasa,2,'.',',') ?></div>
        </div>
      </div>
    </div>

    <?php if ($deuda_total != 0): ?>
    <div class="alert <?= $deuda_total>0?'alert-warning':'alert-success' ?> py-2 small">
      <strong>Deuda histórica acumulada:</strong>
      $<?= number_format(abs($deuda_total),2,'.',',') ?> USD
      / Bs. <?= number_format(abs($deuda_total)*$tasa,2,'.',',') ?>
      <?= $deuda_total>0 ? '(pendiente de pago)' : '(saldo a favor del proveedor)' ?>
    </div>
    <?php endif ?>

    <!-- Firmas -->
    <div class="firma-row mt-4">
      <div class="firma-box">
        <div class="firma-line">Firma Empresa<br><small>El Castillo del Queso</small></div>
      </div>
      <div class="sello-box" style="border:none;padding:0">
        <img src="public/sello.png" alt="Sello"
             style="max-height:80px;max-width:100%;object-fit:contain;opacity:.85;mix-blend-mode:multiply;">
      </div>
      <div class="firma-box">
        <div class="firma-line">Firma Proveedor<br><small><?= h($prov['nombre']) ?></small></div>
      </div>
    </div>
  </div>

  <div class="pie">
    El Castillo del Queso · RIF V-286013356 · Quesos Artesanales Venezolanos<br>
    Reporte generado por ASERP · <?= date('d/m/Y H:i') ?>
  </div>
</div>

<script>
function exportarPDF() {
  const el  = document.getElementById('repDoc');
  const opt = {
    margin: 0.4,
    filename: 'reporte-<?= preg_replace('/[^a-z0-9]/i','_',$prov['nombre']) ?>-<?= $ws_str ?>.pdf',
    image:    { type:'jpeg', quality:0.98 },
    html2canvas: { scale:2, useCORS:true },
    jsPDF:    { unit:'in', format:'letter', orientation:'portrait' }
  };
  html2pdf().set(opt).from(el).save();
}
</script>
</body>
</html>
<?php exit; endif; // fin print_mode

// ── Vista normal dentro del layout ────────────────────────────────────────
layout_start('Reporte Semanal · ' . $prov['nombre'], 'proveedores');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <a href="?p=proveedor_detalle&id=<?= $pid ?>" class="btn btn-sm btn-outline-secondary">← Detalle</a>
    <a href="?p=proveedores" class="btn btn-sm btn-outline-secondary">← Proveedores</a>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="?p=proveedor_reporte&id=<?= $pid ?>&semana=<?= h($prev_iso) ?>" class="btn btn-sm btn-outline-primary">‹ Semana anterior</a>
    <span class="btn btn-sm btn-light border fw-semibold"><?= $ws->format('d/m') ?> – <?= $we->format('d/m/Y') ?></span>
    <a href="?p=proveedor_reporte&id=<?= $pid ?>&semana=<?= h($next_iso) ?>" class="btn btn-sm btn-outline-primary">Semana siguiente ›</a>
    <div class="vr"></div>
    <a href="?p=proveedor_reporte&id=<?= $pid ?>&semana=<?= h($iso) ?>&print=1" target="_blank" class="btn btn-sm btn-primary">🖨 PDF</a>
    <a href="?p=proveedor_reporte&id=<?= $pid ?>&semana=<?= h($iso) ?>&export=csv" class="btn btn-sm btn-success">📊 Excel</a>
  </div>
</div>

<!-- Resumen tarjetas -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon">🥛</div>
      <div class="stat-val"><?= number_format($total_litros,1,'.',',') ?> L</div>
      <div class="stat-label">Litros esta semana</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon">💵</div>
      <div class="stat-val text-primary"><?= fmt_dual($total_usd) ?></div>
      <div class="stat-label">Total a pagar</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon">✅</div>
      <div class="stat-val text-success"><?= fmt_dual($total_pagado) ?></div>
      <div class="stat-label">Pagado esta semana</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon"><?= $saldo>0?'⚠️':'✔️' ?></div>
      <div class="stat-val <?= $saldo>0?'text-danger':'text-success' ?>"><?= fmt_dual(abs($saldo)) ?></div>
      <div class="stat-label"><?= $saldo>0?'Saldo pendiente':'Saldo a favor' ?></div>
    </div>
  </div>
</div>

<!-- Proveedor info -->
<div class="card mb-3">
  <div class="card-body py-2 d-flex gap-4 flex-wrap align-items-center">
    <div><span class="text-muted small">Proveedor:</span> <strong><?= h($prov['nombre']) ?></strong></div>
    <?php if ($prov['contacto']): ?><div><span class="text-muted small">Contacto:</span> <?= h($prov['contacto']) ?></div><?php endif ?>
    <?php if ($prov['telefono']): ?><div><span class="text-muted small">Tel:</span> <?= h($prov['telefono']) ?></div><?php endif ?>
    <div><span class="text-muted small">Precio/L:</span> <strong class="text-success">$<?= number_format((float)$prov['precio_litro_usd'],4,'.',',') ?></strong></div>
    <div><span class="text-muted small">Deuda histórica:</span>
      <strong class="<?= $deuda_total>0?'text-danger':'text-success' ?>">$<?= number_format(abs($deuda_total),2,'.',',') ?></strong>
    </div>
  </div>
</div>

<!-- Tabla entregas -->
<div class="card mb-3">
  <div class="card-header fw-semibold">📦 Entregas diarias</div>
  <div class="card-body p-0">
    <table class="table table-bordered table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>Día</th><th class="text-center">Fecha</th>
          <th class="text-center">Litros</th>
          <th class="text-end">Precio/L</th>
          <th class="text-end">Subtotal USD</th>
          <th class="text-end">Subtotal Bs.</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($semana as $d): ?>
      <tr class="<?= $d['cantidad']>0 ? '' : 'text-muted' ?>">
        <td class="fw-semibold"><?= $d['dia'] ?></td>
        <td class="text-center"><?= date('d/m', strtotime($d['fecha'])) ?></td>
        <td class="text-center"><?= $d['cantidad']>0 ? number_format($d['cantidad'],3,'.',',').' L' : '—' ?></td>
        <td class="text-end"><?= $d['cantidad']>0 ? '$'.number_format($d['precio'],4,'.',',') : '—' ?></td>
        <td class="text-end fw-semibold"><?= $d['cantidad']>0 ? '$'.number_format($d['subtotal'],2,'.',',') : '—' ?></td>
        <td class="text-end"><?= $d['cantidad']>0 ? 'Bs. '.number_format($d['subtotal']*$tasa,2,'.',',') : '—' ?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr style="background:#e8f0fe;font-weight:700">
          <td colspan="2" class="text-end">TOTAL</td>
          <td class="text-center"><?= number_format($total_litros,3,'.',',') ?> L</td>
          <td></td>
          <td class="text-end">$<?= number_format($total_usd,2,'.',',') ?></td>
          <td class="text-end">Bs. <?= number_format($total_usd*$tasa,2,'.',',') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- Pagos -->
<div class="card">
  <div class="card-header fw-semibold">💳 Pagos registrados esta semana</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Fecha</th><th>Descripción</th><th class="text-end">Monto USD</th><th class="text-end">Monto Bs.</th></tr></thead>
      <tbody>
      <?php foreach ($pagos as $pg): ?>
      <tr>
        <td><?= date('d/m/Y', strtotime($pg['fecha'])) ?></td>
        <td><?= h($pg['descripcion']) ?></td>
        <td class="text-end text-success fw-semibold">$<?= number_format((float)$pg['monto_usd'],2,'.',',') ?></td>
        <td class="text-end text-success">Bs. <?= number_format((float)$pg['monto_usd']*$tasa,2,'.',',') ?></td>
      </tr>
      <?php endforeach ?>
      <?php if (!$pagos): ?>
      <tr><td colspan="4" class="text-center text-muted py-3">Sin pagos esta semana</td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_end(); ?>
