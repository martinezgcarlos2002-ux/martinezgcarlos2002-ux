<?php
require_login();
$db    = get_db();
$id    = (int)($_GET['id']    ?? 0);
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

if (!$id) { header('Location: ?p=proveedores'); exit; }

$prov = $db->prepare('SELECT * FROM proveedores WHERE id=?');
$prov->execute([$id]);
$prov = $prov->fetch();
if (!$prov) { flash('Proveedor no encontrado.', 'error'); header('Location: ?p=proveedores'); exit; }

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta))  $hasta = date('Y-m-d');

$ent_st = $db->prepare('SELECT * FROM proveedores_entregas WHERE proveedor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha ASC');
$ent_st->execute([$id, $desde, $hasta]);
$entregas = $ent_st->fetchAll();

$pag_st = $db->prepare('SELECT * FROM proveedores_pagos WHERE proveedor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha ASC');
$pag_st->execute([$id, $desde, $hasta]);
$pagos = $pag_st->fetchAll();

$tot_cantidad = array_sum(array_column($entregas, 'cantidad'));
$tot_factura  = array_sum(array_column($entregas, 'subtotal_usd'));
$tot_pagado   = array_sum(array_column($pagos,    'monto_usd'));
$saldo        = $tot_factura - $tot_pagado;

$tasa = get_tasa();

$tipo_labels = ['efectivo'=>'Efectivo','mercancia'=>'Mercancía','pm_trans'=>'PM/Trans','cortesia'=>'Cortesía','vale'=>'Vale'];

$num_fact = str_pad($id, 4, '0', STR_PAD_LEFT) . '-' . date('Ymd');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Factura Proveedor — <?= h($prov['nombre']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  @page { size: Letter portrait; margin: 10mm; }
  :root { --navy:#1a2e5a; --orange:#f97316; }
  body { background:#f0f4fa; font-family:'Segoe UI',system-ui,sans-serif; }
  .toolbar { max-width:860px; margin:20px auto 12px; display:flex; gap:10px; flex-wrap:wrap; }
  .toolbar .btn { font-size:.875rem; }
  .doc { max-width:860px; margin:0 auto 40px; background:#fff;
         border-radius:14px; box-shadow:0 4px 28px rgba(0,0,0,.12); overflow:hidden; }
  .doc-header {
    background: linear-gradient(135deg, var(--navy) 0%, #2a4a8a 100%);
    color:#fff; padding:28px 40px 22px;
  }
  .logo-box { height:88px; width:88px; object-fit:contain;
              background:#fff; border-radius:10px; padding:5px; }
  .empresa-nombre { font-size:1.7rem; font-weight:800; margin:0; letter-spacing:-.5px; }
  .empresa-sub { font-size:.8rem; opacity:.75; margin:2px 0 0; }
  .doc-title { font-size:2.2rem; font-weight:800; opacity:.95; letter-spacing:-.5px; }
  .doc-num   { font-size:.9rem; opacity:.75; }
  .periodo-badge {
    display:inline-block; background:rgba(255,255,255,.18);
    border:1px solid rgba(255,255,255,.35);
    border-radius:20px; padding:4px 16px; font-size:.8rem;
    margin-top:8px;
  }
  .doc-body { padding:32px 40px; }
  .info-box { background:#f4f7fc; border-radius:10px; padding:16px 20px; }
  .info-box .label { font-size:.7rem; text-transform:uppercase; letter-spacing:.07em; color:#64748b; font-weight:600; margin-bottom:4px; }
  .info-box .value { font-size:1rem; font-weight:700; color:var(--navy); }
  .info-box .sub-value { font-size:.78rem; color:#64748b; }
  .section-title { font-size:.75rem; text-transform:uppercase; letter-spacing:.08em;
                   color:var(--navy); font-weight:700; border-bottom:2px solid var(--navy);
                   padding-bottom:4px; margin-bottom:0; }
  table { width:100%; border-collapse:collapse; font-size:.865rem; }
  table thead th { background:var(--navy); color:#fff; padding:8px 12px; font-size:.75rem;
                   text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
  table tbody td { padding:7px 12px; border-bottom:1px solid #e8edf5; }
  table tbody tr:last-child td { border-bottom:none; }
  table tbody tr:nth-child(even) { background:#f8fafc; }
  table tfoot td { background:#eef2ff; padding:9px 12px; font-weight:700; color:var(--navy); }
  .resumen-box { background:#f4f7fc; border-radius:10px; padding:20px 24px; }
  .resumen-row { display:flex; justify-content:space-between; align-items:center;
                 padding:.4rem 0; border-bottom:1px solid #dee2e6; font-size:.9rem; }
  .resumen-row:last-child { border-bottom:none; }
  .resumen-row.total-row { font-size:1.05rem; font-weight:700; color:var(--navy); }
  .resumen-row.saldo-row {
    background: var(--navy); color:#fff; border-radius:8px;
    padding:.6rem 1rem; margin-top:.5rem; font-size:1.1rem; font-weight:800;
  }
  .saldo-positivo { background:#dc2626 !important; }
  .saldo-cero     { background:#16a34a !important; }
  .firma-line { border-top:1.5px solid #b0b8c8; padding-top:6px; margin-top:44px;
                font-size:.8rem; color:#64748b; text-align:center; }
  .doc-footer { text-align:center; padding:16px 40px 22px;
                border-top:1px solid #e8edf5; font-size:.75rem; color:#94a3b8; }
  .doc-footer strong { color:#64748b; }
  @media print {
    body { background:#fff; margin:0; }
    .toolbar { display:none !important; }
    .doc { box-shadow:none; border-radius:0; margin:0; max-width:100%; }
    .doc-body { padding:18px 22px; } .doc-header { padding:18px 22px; }
    .doc-header { border-radius:0; }
    table tbody tr:nth-child(even) { background:#f8fafc !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table thead th { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .doc-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .saldo-row, .saldo-positivo, .saldo-cero { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>

<div class="toolbar no-print">
  <button onclick="window.print()" class="btn btn-primary" style="background:var(--navy);border-color:var(--navy)">
    🖨 Imprimir
  </button>
  <button onclick="exportarPDF()" class="btn btn-danger">
    📄 Exportar PDF
  </button>
  <a href="?p=proveedor_detalle&id=<?= $id ?>&tab=historial&h_desde=<?= urlencode($desde) ?>&h_hasta=<?= urlencode($hasta) ?>"
     class="btn btn-outline-secondary">
    ← Volver
  </a>
</div>

<div class="doc" id="docFactura">

  <div class="doc-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
      <div class="d-flex align-items-center gap-3">
        <img src="public/logo.jpg" class="logo-box" alt="Logo El Castillo del Queso">
        <div>
          <p class="empresa-nombre">El Castillo del Queso</p>
          <p class="empresa-sub">Productora Artesanal de Quesos Venezolanos</p>
          <p class="empresa-sub">RIF: V-286013356</p>
          <div class="periodo-badge">
            Período: <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?>
          </div>
        </div>
      </div>
      <div class="text-end">
        <div class="doc-title">FACTURA</div>
        <div class="doc-num">Proveedor #<?= $num_fact ?></div>
        <div class="doc-num mt-1">Emitida: <?= date('d/m/Y') ?></div>
        <?php if ($tasa > 1): ?>
        <div class="doc-num mt-1" style="color:rgba(255,255,255,.8)">
          Tasa: 1 USD = Bs. <?= number_format($tasa, 2) ?>
        </div>
        <?php endif ?>
      </div>
    </div>
  </div>

  <div class="doc-body">

    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <div class="info-box">
          <div class="label">Proveedor</div>
          <div class="value"><?= h($prov['nombre']) ?></div>
          <?php if (!empty($prov['contacto'])): ?>
          <div class="sub-value">Contacto: <?= h($prov['contacto']) ?></div>
          <?php endif ?>
          <?php if (!empty($prov['telefono'])): ?>
          <div class="sub-value">Tel: <?= h($prov['telefono']) ?></div>
          <?php endif ?>
          <?php if (!empty($prov['email'])): ?>
          <div class="sub-value">Email: <?= h($prov['email']) ?></div>
          <?php endif ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="info-box">
          <div class="label">Resumen del Período</div>
          <div class="row g-0 mt-1" style="font-size:.85rem">
            <div class="col-7 text-muted">Total suministros:</div>
            <div class="col-5 fw-semibold"><?= count($entregas) ?> registros</div>
            <div class="col-7 text-muted">Cantidad total:</div>
            <div class="col-5 fw-semibold"><?= number_format($tot_cantidad, 3) ?></div>
            <div class="col-7 text-muted">Total pagos:</div>
            <div class="col-5 fw-semibold"><?= count($pagos) ?> registros</div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($entregas): ?>
    <div class="mb-4">
      <div class="section-title mb-2">Detalle de Suministros</div>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Fecha</th>
            <th>Descripción</th>
            <th class="text-end">Cantidad</th>
            <th class="text-end">Precio USD</th>
            <th class="text-end">Subtotal USD</th>
            <?php if ($tasa > 1): ?><th class="text-end">Subtotal Bs.</th><?php endif ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entregas as $i => $e): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><?= date('d/m/Y', strtotime($e['fecha'])) ?></td>
            <td class="text-muted small"><?= h($e['descripcion']) ?></td>
            <td class="text-end"><?= number_format((float)$e['cantidad'], 3) ?></td>
            <td class="text-end">$<?= number_format((float)$e['precio_usd'], 4) ?></td>
            <td class="text-end fw-semibold">$<?= number_format((float)$e['subtotal_usd'], 2) ?></td>
            <?php if ($tasa > 1): ?>
            <td class="text-end text-muted">Bs. <?= number_format((float)$e['subtotal_usd'] * $tasa, 2, '.', ',') ?></td>
            <?php endif ?>
          </tr>
          <?php endforeach ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3"><strong>TOTALES</strong></td>
            <td class="text-end"><?= number_format($tot_cantidad, 3) ?></td>
            <td></td>
            <td class="text-end">$<?= number_format($tot_factura, 2) ?></td>
            <?php if ($tasa > 1): ?>
            <td class="text-end">Bs. <?= number_format($tot_factura * $tasa, 2, '.', ',') ?></td>
            <?php endif ?>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php else: ?>
    <div class="alert alert-warning mb-4">Sin suministros registrados en este período.</div>
    <?php endif ?>

    <?php if ($pagos): ?>
    <div class="mb-4">
      <div class="section-title mb-2">Pagos del Período</div>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Fecha</th>
            <th>Tipo</th>
            <th class="text-end">Monto USD</th>
            <?php if ($tasa > 1): ?><th class="text-end">Monto Bs.</th><?php endif ?>
            <?php if (array_filter($pagos, fn($p) => !empty($p['descripcion']))): ?>
            <th>Descripción</th>
            <?php endif ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pagos as $i => $p): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
            <td><?= h($tipo_labels[$p['tipo']] ?? $p['tipo']) ?></td>
            <td class="text-end fw-semibold">$<?= number_format((float)$p['monto_usd'], 2) ?></td>
            <?php if ($tasa > 1): ?>
            <td class="text-end text-muted">Bs. <?= number_format((float)$p['monto_usd'] * $tasa, 2, '.', ',') ?></td>
            <?php endif ?>
            <?php if (array_filter($pagos, fn($p2) => !empty($p2['descripcion']))): ?>
            <td class="text-muted small"><?= h($p['descripcion'] ?? '') ?></td>
            <?php endif ?>
          </tr>
          <?php endforeach ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3"><strong>TOTAL PAGADO</strong></td>
            <td class="text-end">$<?= number_format($tot_pagado, 2) ?></td>
            <?php if ($tasa > 1): ?>
            <td class="text-end">Bs. <?= number_format($tot_pagado * $tasa, 2, '.', ',') ?></td>
            <?php endif ?>
            <?php if (array_filter($pagos, fn($p) => !empty($p['descripcion']))): ?><td></td><?php endif ?>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif ?>

    <div class="row g-3 mb-4">
      <div class="col-md-6 offset-md-6">
        <div class="resumen-box">
          <div class="resumen-row">
            <span>Total facturado</span>
            <span class="fw-semibold">$<?= number_format($tot_factura, 2) ?></span>
          </div>
          <div class="resumen-row">
            <span>Total pagado</span>
            <span class="fw-semibold text-success">$<?= number_format($tot_pagado, 2) ?></span>
          </div>
          <?php if ($tasa > 1): ?>
          <div class="resumen-row total-row">
            <span>Saldo (USD)</span>
            <span>$<?= number_format($saldo, 2) ?></span>
          </div>
          <div class="resumen-row saldo-row <?= $saldo > 0 ? 'saldo-positivo' : ($saldo == 0 ? 'saldo-cero' : '') ?>">
            <span>Saldo en Bs.</span>
            <span>Bs. <?= number_format($saldo * $tasa, 2, '.', ',') ?></span>
          </div>
          <?php else: ?>
          <div class="resumen-row saldo-row <?= $saldo > 0 ? 'saldo-positivo' : ($saldo == 0 ? 'saldo-cero' : '') ?>">
            <span>Saldo pendiente</span>
            <span>$<?= number_format($saldo, 2) ?></span>
          </div>
          <?php endif ?>
        </div>
      </div>
    </div>

    <div class="row g-4 mt-3">
      <div class="col-4 text-center">
        <div class="firma-line">Firma Emisor<br><small style="color:#aaa">El Castillo del Queso</small></div>
      </div>
      <div class="col-4 text-center">
        <img src="public/sello.png" alt="Sello"
             style="max-height:80px;max-width:100%;object-fit:contain;opacity:.82;mix-blend-mode:multiply;">
      </div>
      <div class="col-4 text-center">
        <div class="firma-line">Firma Proveedor<br><small style="color:#aaa"><?= h($prov['nombre']) ?></small></div>
      </div>
    </div>

  </div>

  <div class="doc-footer">
    <strong>El Castillo del Queso</strong> · RIF: V-286013356 · Quesos Artesanales Venezolanos<br>
    Documento generado por ASERP el <?= date('d/m/Y H:i') ?> · Período <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?>
  </div>

</div>

<script>
function exportarPDF() {
  const el  = document.getElementById('docFactura');
  const opt = {
    margin:      [0.4, 0.4],
    filename:    'factura-proveedor-<?= $id ?>-<?= str_replace('-','',substr($desde,0,10)) ?>-<?= str_replace('-','',substr($hasta,0,10)) ?>.pdf',
    image:       { type:'jpeg', quality:0.97 },
    html2canvas: { scale:2, useCORS:true, logging:false },
    jsPDF:       { unit:'in', format:'letter', orientation:'portrait' }
  };
  html2pdf().set(opt).from(el).save();
}
</script>
</body>
</html>
