<?php
require_login();
$db  = get_db();
$pid = (int)($_GET['id'] ?? 0);
if (!$pid) { header('Location: ?p=productores'); exit; }

$prod = $db->prepare('SELECT * FROM productores WHERE id=?');
$prod->execute([$pid]);
$prod = $prod->fetch();
if (!$prod) { flash('Productor no encontrado.', 'danger'); header('Location: ?p=productores'); exit; }

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
$we       = (clone $ws)->modify('+6 days');
$ws_str   = $ws->format('Y-m-d');
$we_str   = $we->format('Y-m-d');
$iso      = $ws->format('o-\WW');
$prev_iso = (clone $ws)->modify('-7 days')->format('o-\WW');
$next_iso = (clone $ws)->modify('+7 days')->format('o-\WW');

// ── Entregas de la semana ──────────────────────────────────────────────────
$ents = $db->prepare(
    'SELECT fecha, litros, precio_litro_usd, subtotal_usd, gasto_traslado_usd, cuajada_kg, observaciones
     FROM productores_entregas
     WHERE productor_id = ? AND fecha BETWEEN ? AND ?
     ORDER BY fecha'
);
$ents->execute([$pid, $ws_str, $we_str]);
$entregas_raw = $ents->fetchAll();
$por_fecha = [];
foreach ($entregas_raw as $e) { $por_fecha[$e['fecha']] = $e; }

$dias_es = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
$semana  = [];
for ($i = 0; $i < 7; $i++) {
    $d     = (clone $ws)->modify("+$i days");
    $fecha = $d->format('Y-m-d');
    $ent   = $por_fecha[$fecha] ?? null;
    $semana[] = [
        'dia'       => $dias_es[$i],
        'fecha'     => $fecha,
        'corto'     => $d->format('d/m'),
        'litros'    => $ent ? (float)$ent['litros']            : 0,
        'precio'    => $ent ? (float)$ent['precio_litro_usd']  : (float)$prod['precio_litro_usd'],
        'subtotal'  => $ent ? (float)$ent['subtotal_usd']      : 0,
        'traslado'  => $ent ? (float)$ent['gasto_traslado_usd']: 0,
        'cuajada'   => $ent ? (float)$ent['cuajada_kg']        : 0,
        'nota'      => $ent ? ($ent['observaciones'] ?? '')     : '',
    ];
}

$total_litros   = array_sum(array_column($semana, 'litros'));
$total_usd      = array_sum(array_column($semana, 'subtotal'));
$total_traslado = array_sum(array_column($semana, 'traslado'));
$total_cuajada  = array_sum(array_column($semana, 'cuajada'));
$neto_usd       = $total_usd - $total_traslado;

// Abonos de la semana
$abonos_st = $db->prepare(
    'SELECT fecha, tipo, monto_usd, descripcion FROM productores_abonos
     WHERE productor_id = ? AND fecha BETWEEN ? AND ? ORDER BY fecha'
);
$abonos_st->execute([$pid, $ws_str, $we_str]);
$abonos        = $abonos_st->fetchAll();
$total_abonado = array_sum(array_column($abonos, 'monto_usd'));
$saldo         = $neto_usd - $total_abonado;

// Deuda histórica acumulada
$h1 = $db->prepare('SELECT COALESCE(SUM(subtotal_usd - gasto_traslado_usd),0) FROM productores_entregas WHERE productor_id=?');
$h1->execute([$pid]); $hist_ent = (float)$h1->fetchColumn();
$h2 = $db->prepare('SELECT COALESCE(SUM(monto_usd),0) FROM productores_abonos WHERE productor_id=?');
$h2->execute([$pid]); $hist_pag = (float)$h2->fetchColumn();
$deuda_total = $hist_ent - $hist_pag;

$tasa    = get_tasa();
$safenom = preg_replace('/[^a-z0-9]/i', '_', $prod['nombre']);

// ── Exportar CSV ───────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte-' . $safenom . '-' . $ws_str . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['REPORTE SEMANAL DE ENTREGAS — PRODUCTOR'], ';');
    fputcsv($out, ['Empresa:', 'El Castillo del Queso', 'RIF:', 'V-286013356'], ';');
    fputcsv($out, ['Productor:', $prod['nombre']], ';');
    if ($prod['documento']) fputcsv($out, ['C.I./RIF:', $prod['documento']], ';');
    if ($prod['telefono'])  fputcsv($out, ['Teléfono:', $prod['telefono']], ';');
    fputcsv($out, ['Semana:', $ws->format('d/m/Y') . ' al ' . $we->format('d/m/Y')], ';');
    fputcsv($out, ['Precio acordado (USD/L):', number_format((float)$prod['precio_litro_usd'],4,'.','')], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Día','Fecha','Litros','Precio/L','Bruto USD','Traslado USD','Neto USD','Neto Bs','Cuajada kg','Nota'], ';');
    foreach ($semana as $d) {
        $neto = $d['subtotal'] - $d['traslado'];
        fputcsv($out, [
            $d['dia'], $d['corto'],
            $d['litros']   > 0 ? number_format($d['litros'],3,'.',''): '—',
            $d['precio']   > 0 ? number_format($d['precio'],4,'.',''): '—',
            $d['subtotal'] > 0 ? number_format($d['subtotal'],2,'.','') : '—',
            $d['traslado'] > 0 ? number_format($d['traslado'],2,'.','') : '—',
            $neto          > 0 ? number_format($neto,2,'.','') : '—',
            $neto          > 0 ? number_format($neto*$tasa,2,'.','') : '—',
            $d['cuajada']  > 0 ? number_format($d['cuajada'],3,'.',''): '—',
            $d['nota'],
        ], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['TOTALES','',
        number_format($total_litros,3,'.',','),  '',
        number_format($total_usd,2,'.',','),
        number_format($total_traslado,2,'.',','),
        number_format($neto_usd,2,'.',','),
        number_format($neto_usd*$tasa,2,'.',','),
        number_format($total_cuajada,3,'.',','),
    ], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['ABONOS / PAGOS DE LA SEMANA'], ';');
    fputcsv($out, ['Fecha','Tipo','Monto USD','Descripción'], ';');
    foreach ($abonos as $ab) {
        fputcsv($out, [date('d/m/Y',strtotime($ab['fecha'])), $ab['tipo'],
            number_format((float)$ab['monto_usd'],2,'.',','), $ab['descripcion']], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['Total abonado semana USD:', number_format($total_abonado,2,'.',',')], ';');
    fputcsv($out, ['Saldo pendiente semana USD:', number_format($saldo,2,'.',',')], ';');
    fputcsv($out, ['Deuda histórica total USD:', number_format($deuda_total,2,'.',',')], ';');
    fputcsv($out, ['Tasa Bs/USD:', number_format($tasa,2,'.',',')], ';');
    fputcsv($out, ['Generado:', date('d/m/Y H:i')], ';');
    fclose($out);
    exit;
}

// ── Modo impresión / PDF ───────────────────────────────────────────────────
$print_mode = ($_GET['print'] ?? '') === '1';
if ($print_mode): ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte Productor – <?= h($prod['nombre']) ?></title>
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
  .rep-body { padding:26px 32px; }
  table thead th { background:#1a2e5a; color:#fff; font-size:.77rem; }
  .totrow td { background:#e8f0fe; font-weight:700; }
  .info-box { background:#f8f9fc; border-radius:8px; padding:14px 18px; margin-bottom:16px; }
  .firma-row { display:flex; gap:24px; margin-top:32px; }
  .firma-box { flex:1; text-align:center; }
  .firma-line { border-top:1.5px solid #999; margin-top:48px; padding-top:6px; font-size:.8rem; color:#555; }
  .sello-box { flex:1; border:2px dashed #bbb; border-radius:8px; height:80px;
               display:flex; align-items:center; justify-content:center; color:#aaa; font-size:.8rem; }
  .pie { text-align:center; font-size:.75rem; color:#aaa; padding:14px 32px 20px; border-top:1px solid #eee; }
  .no-print { max-width:820px; margin:16px auto; display:flex; gap:10px; }
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
  <a href="?p=productor_reporte&id=<?= $pid ?>&semana=<?= h($iso) ?>&export=csv" class="btn btn-outline-success">📊 Excel / CSV</a>
  <a href="?p=productor_reporte&id=<?= $pid ?>&semana=<?= h($iso) ?>" class="btn btn-outline-secondary">← Volver</a>
</div>

<div class="rep-wrap" id="repDoc">
  <div class="rep-header">
    <div class="d-flex align-items-center gap-3">
      <img src="public/logo.jpg" class="logo-img" alt="Logo El Castillo del Queso">
      <div>
        <div style="font-size:1.3rem;font-weight:700">El Castillo del Queso</div>
        <div style="font-size:.8rem;opacity:.8">Productora Artesanal · RIF: V-286013356</div>
      </div>
    </div>
    <div class="text-end">
      <div style="font-size:.9rem;opacity:.85">Reporte Semanal — Productor</div>
      <div style="font-size:1.4rem;font-weight:700"><?= $ws->format('d/m') ?> – <?= $we->format('d/m/Y') ?></div>
    </div>
  </div>

  <div class="rep-body">
    <!-- Datos productor -->
    <div class="info-box d-flex justify-content-between flex-wrap gap-2">
      <div>
        <div class="text-muted small">PRODUCTOR</div>
        <div class="fw-bold fs-5"><?= h($prod['nombre']) ?></div>
        <?php if ($prod['documento']): ?><div class="small text-muted">C.I./RIF: <?= h($prod['documento']) ?></div><?php endif ?>
        <?php if ($prod['telefono']):  ?><div class="small text-muted">Tel: <?= h($prod['telefono']) ?></div><?php endif ?>
      </div>
      <div class="text-center">
        <div class="text-muted small">Precio acordado</div>
        <div class="fw-bold text-success fs-5">$<?= number_format((float)$prod['precio_litro_usd'],4,'.',',') ?>/L</div>
        <div class="small text-muted">Bs. <?= number_format((float)$prod['precio_litro_usd']*$tasa,4,'.',',') ?>/L</div>
      </div>
      <div class="text-end">
        <div class="text-muted small">Tasa Bs/USD</div>
        <div class="fw-bold"><?= number_format($tasa,2,'.',',') ?></div>
        <div class="small text-muted"><?= date('d/m/Y') ?></div>
      </div>
    </div>

    <!-- Tabla diaria -->
    <h6 class="fw-bold mb-2" style="color:#1a2e5a">🥛 Entregas de Leche — Semana</h6>
    <table class="table table-bordered table-sm mb-3">
      <thead>
        <tr>
          <th>Día</th><th class="text-center">Fecha</th>
          <th class="text-center">Litros</th>
          <th class="text-end">Precio/L</th>
          <th class="text-end">Bruto USD</th>
          <th class="text-end">Traslado</th>
          <th class="text-end">Neto USD</th>
          <th class="text-end">Neto Bs.</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($semana as $d):
        $neto_d = $d['subtotal'] - $d['traslado'];
      ?>
      <tr <?= $d['litros']>0 ? '' : 'class="text-muted"' ?>>
        <td class="fw-semibold"><?= $d['dia'] ?></td>
        <td class="text-center"><?= $d['corto'] ?></td>
        <td class="text-center"><?= $d['litros']>0   ? number_format($d['litros'],3,'.',',').' L' : '—' ?></td>
        <td class="text-end"><?=   $d['litros']>0   ? '$'.number_format($d['precio'],4,'.',',')   : '—' ?></td>
        <td class="text-end"><?=   $d['subtotal']>0 ? '$'.number_format($d['subtotal'],2,'.',',') : '—' ?></td>
        <td class="text-end text-danger"><?= $d['traslado']>0 ? '-$'.number_format($d['traslado'],2,'.',',') : '—' ?></td>
        <td class="text-end fw-semibold"><?= $d['litros']>0 ? '$'.number_format($neto_d,2,'.',',') : '—' ?></td>
        <td class="text-end"><?= $d['litros']>0 ? 'Bs. '.number_format($neto_d*$tasa,2,'.',',') : '—' ?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr class="totrow">
          <td colspan="2" class="text-end">TOTAL</td>
          <td class="text-center"><?= number_format($total_litros,3,'.',',') ?> L</td>
          <td></td>
          <td class="text-end">$<?= number_format($total_usd,2,'.',',') ?></td>
          <td class="text-end text-danger">-$<?= number_format($total_traslado,2,'.',',') ?></td>
          <td class="text-end">$<?= number_format($neto_usd,2,'.',',') ?></td>
          <td class="text-end">Bs. <?= number_format($neto_usd*$tasa,2,'.',',') ?></td>
        </tr>
      </tfoot>
    </table>

    <!-- Abonos -->
    <h6 class="fw-bold mb-2" style="color:#1a2e5a">💳 Abonos / Pagos de la Semana</h6>
    <?php if ($abonos): ?>
    <table class="table table-bordered table-sm mb-3">
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th class="text-end">Monto USD</th><th class="text-end">Monto Bs.</th></tr></thead>
      <tbody>
      <?php foreach ($abonos as $ab): ?>
      <tr>
        <td><?= date('d/m/Y',strtotime($ab['fecha'])) ?></td>
        <td><span class="badge bg-success"><?= h($ab['tipo']) ?></span></td>
        <td><?= h($ab['descripcion']) ?></td>
        <td class="text-end text-success fw-semibold">$<?= number_format((float)$ab['monto_usd'],2,'.',',') ?></td>
        <td class="text-end text-success">Bs. <?= number_format((float)$ab['monto_usd']*$tasa,2,'.',',') ?></td>
      </tr>
      <?php endforeach ?>
      <tr class="totrow">
        <td colspan="3" class="text-end">Total abonado</td>
        <td class="text-end">$<?= number_format($total_abonado,2,'.',',') ?></td>
        <td class="text-end">Bs. <?= number_format($total_abonado*$tasa,2,'.',',') ?></td>
      </tr>
      </tbody>
    </table>
    <?php else: ?>
    <p class="text-muted small mb-3">Sin abonos registrados esta semana.</p>
    <?php endif ?>

    <!-- Resumen -->
    <div class="row g-2 mb-3">
      <div class="col-3"><div class="text-center p-2" style="background:#f0f4ff;border-radius:8px">
        <div class="text-muted small">Leche (bruto)</div>
        <div class="fw-bold">$<?= number_format($total_usd,2,'.',',') ?></div>
        <div class="small text-muted">Bs. <?= number_format($total_usd*$tasa,2,'.',',') ?></div>
      </div></div>
      <div class="col-3"><div class="text-center p-2" style="background:#fff5f5;border-radius:8px">
        <div class="text-muted small">Traslado</div>
        <div class="fw-bold text-danger">-$<?= number_format($total_traslado,2,'.',',') ?></div>
        <div class="small text-muted">Bs. -<?= number_format($total_traslado*$tasa,2,'.',',') ?></div>
      </div></div>
      <div class="col-3"><div class="text-center p-2" style="background:#f0fff4;border-radius:8px">
        <div class="text-muted small">Neto a pagar</div>
        <div class="fw-bold text-primary">$<?= number_format($neto_usd,2,'.',',') ?></div>
        <div class="small text-muted">Bs. <?= number_format($neto_usd*$tasa,2,'.',',') ?></div>
      </div></div>
      <div class="col-3"><div class="text-center p-2" style="background:<?= $saldo>0?'#fff5f5':'#f0fff4' ?>;border-radius:8px">
        <div class="text-muted small">Saldo semana</div>
        <div class="fw-bold <?= $saldo>0?'text-danger':'text-success' ?>">
          $<?= number_format(abs($saldo),2,'.',',') ?> <?= $saldo>0?'debe':'✓' ?>
        </div>
        <div class="small text-muted">Bs. <?= number_format(abs($saldo)*$tasa,2,'.',',') ?></div>
      </div></div>
    </div>

    <?php if (abs($deuda_total) > 0.01): ?>
    <div class="alert <?= $deuda_total>0?'alert-warning':'alert-success' ?> py-2 small mb-3">
      <strong>Deuda histórica acumulada:</strong>
      $<?= number_format(abs($deuda_total),2,'.',',') ?> USD /
      Bs. <?= number_format(abs($deuda_total)*$tasa,2,'.',',') ?>
      <?= $deuda_total>0 ? '(pendiente)' : '(saldo a favor)' ?>
    </div>
    <?php endif ?>

    <!-- Firmas -->
    <div class="firma-row">
      <div class="firma-box"><div class="firma-line">Firma Empresa<br><small>El Castillo del Queso</small></div></div>
      <div class="sello-box" style="border:none;padding:0">
        <img src="public/sello.png" alt="Sello"
             style="max-height:80px;max-width:100%;object-fit:contain;opacity:.85;mix-blend-mode:multiply;">
      </div>
      <div class="firma-box"><div class="firma-line">Firma Productor<br><small><?= h($prod['nombre']) ?></small></div></div>
    </div>
  </div>

  <div class="pie">
    El Castillo del Queso · RIF V-286013356 · Quesos Artesanales Venezolanos<br>
    Reporte generado por ASERP · <?= date('d/m/Y H:i') ?>
  </div>
</div>

<script>
function exportarPDF() {
  html2pdf().set({
    margin: 0.4,
    filename: 'reporte-<?= $safenom ?>-<?= $ws_str ?>.pdf',
    image:    { type:'jpeg', quality:0.98 },
    html2canvas: { scale:2, useCORS:true },
    jsPDF:    { unit:'in', format:'letter', orientation:'portrait' }
  }).from(document.getElementById('repDoc')).save();
}
</script>
</body>
</html>
<?php exit; endif;

// ── Vista normal dentro del layout ────────────────────────────────────────
layout_start('Reporte Semanal · ' . $prod['nombre'], 'productores');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="d-flex gap-2">
    <a href="?p=productor_detalle&id=<?= $pid ?>" class="btn btn-sm btn-outline-secondary">← Detalle</a>
    <a href="?p=productores" class="btn btn-sm btn-outline-secondary">← Productores</a>
  </div>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <a href="?p=productor_reporte&id=<?= $pid ?>&semana=<?= h($prev_iso) ?>" class="btn btn-sm btn-outline-primary">‹ Anterior</a>
    <span class="btn btn-sm btn-light border fw-semibold"><?= $ws->format('d/m') ?> – <?= $we->format('d/m/Y') ?></span>
    <a href="?p=productor_reporte&id=<?= $pid ?>&semana=<?= h($next_iso) ?>" class="btn btn-sm btn-outline-primary">Siguiente ›</a>
    <div class="vr"></div>
    <a href="?p=productor_reporte&id=<?= $pid ?>&semana=<?= h($iso) ?>&print=1" target="_blank" class="btn btn-sm btn-primary">🖨 PDF</a>
    <a href="?p=productor_reporte&id=<?= $pid ?>&semana=<?= h($iso) ?>&export=csv" class="btn btn-sm btn-success">📊 Excel</a>
  </div>
</div>

<!-- Tarjetas resumen -->
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
      <div class="stat-val text-primary"><?= fmt_dual($neto_usd) ?></div>
      <div class="stat-label">Neto a pagar</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card text-center">
      <div class="stat-icon">✅</div>
      <div class="stat-val text-success"><?= fmt_dual($total_abonado) ?></div>
      <div class="stat-label">Abonado esta semana</div>
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

<!-- Info productor -->
<div class="card mb-3">
  <div class="card-body py-2 d-flex gap-4 flex-wrap align-items-center">
    <div><span class="text-muted small">Productor:</span> <strong><?= h($prod['nombre']) ?></strong></div>
    <?php if ($prod['documento']): ?><div><span class="text-muted small">C.I./RIF:</span> <?= h($prod['documento']) ?></div><?php endif ?>
    <?php if ($prod['telefono']):  ?><div><span class="text-muted small">Tel:</span> <?= h($prod['telefono']) ?></div><?php endif ?>
    <div><span class="text-muted small">Precio/L:</span> <strong class="text-success">$<?= number_format((float)$prod['precio_litro_usd'],4,'.',',') ?></strong></div>
    <div><span class="text-muted small">Deuda histórica:</span>
      <strong class="<?= $deuda_total>0?'text-danger':'text-success' ?>">$<?= number_format(abs($deuda_total),2,'.',',') ?></strong>
    </div>
  </div>
</div>

<!-- Tabla entregas -->
<div class="card mb-3">
  <div class="card-header fw-semibold">🥛 Entregas diarias</div>
  <div class="card-body p-0">
    <table class="table table-bordered table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>Día</th><th class="text-center">Fecha</th>
          <th class="text-center">Litros</th>
          <th class="text-end">Precio/L</th>
          <th class="text-end">Bruto USD</th>
          <th class="text-end">Traslado</th>
          <th class="text-end">Neto USD</th>
          <th class="text-end">Neto Bs.</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($semana as $d):
        $neto_d = $d['subtotal'] - $d['traslado'];
      ?>
      <tr class="<?= $d['litros']>0 ? '' : 'text-muted' ?>">
        <td class="fw-semibold"><?= $d['dia'] ?></td>
        <td class="text-center"><?= date('d/m', strtotime($d['fecha'])) ?></td>
        <td class="text-center"><?= $d['litros']>0   ? number_format($d['litros'],3,'.',',').' L' : '—' ?></td>
        <td class="text-end"><?=   $d['litros']>0   ? '$'.number_format($d['precio'],4,'.',',') : '—' ?></td>
        <td class="text-end"><?=   $d['subtotal']>0 ? '$'.number_format($d['subtotal'],2,'.',',') : '—' ?></td>
        <td class="text-end text-danger"><?= $d['traslado']>0 ? '-$'.number_format($d['traslado'],2,'.',',') : '—' ?></td>
        <td class="text-end fw-semibold"><?= $d['litros']>0 ? '$'.number_format($neto_d,2,'.',',') : '—' ?></td>
        <td class="text-end"><?= $d['litros']>0 ? 'Bs. '.number_format($neto_d*$tasa,2,'.',',') : '—' ?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr style="background:#e8f0fe;font-weight:700">
          <td colspan="2" class="text-end">TOTAL</td>
          <td class="text-center"><?= number_format($total_litros,3,'.',',') ?> L</td>
          <td></td>
          <td class="text-end">$<?= number_format($total_usd,2,'.',',') ?></td>
          <td class="text-end text-danger">-$<?= number_format($total_traslado,2,'.',',') ?></td>
          <td class="text-end">$<?= number_format($neto_usd,2,'.',',') ?></td>
          <td class="text-end">Bs. <?= number_format($neto_usd*$tasa,2,'.',',') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- Abonos -->
<div class="card">
  <div class="card-header fw-semibold">💳 Abonos registrados esta semana</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th class="text-end">Monto USD</th><th class="text-end">Monto Bs.</th></tr></thead>
      <tbody>
      <?php foreach ($abonos as $ab): ?>
      <tr>
        <td><?= date('d/m/Y',strtotime($ab['fecha'])) ?></td>
        <td><span class="badge bg-success"><?= h($ab['tipo']) ?></span></td>
        <td><?= h($ab['descripcion']) ?></td>
        <td class="text-end text-success fw-semibold">$<?= number_format((float)$ab['monto_usd'],2,'.',',') ?></td>
        <td class="text-end text-success">Bs. <?= number_format((float)$ab['monto_usd']*$tasa,2,'.',',') ?></td>
      </tr>
      <?php endforeach ?>
      <?php if (!$abonos): ?>
      <tr><td colspan="5" class="text-center text-muted py-3">Sin abonos esta semana</td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_end(); ?>
