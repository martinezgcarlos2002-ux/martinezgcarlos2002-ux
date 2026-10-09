<?php
require_login();
$db  = get_db();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ?p=ventas'); exit; }

$factura = $db->prepare('SELECT * FROM facturas WHERE id=?');
$factura->execute([$id]);
$factura = $factura->fetch();
if (!$factura) { header('Location: ?p=ventas'); exit; }

$items_st = $db->prepare('SELECT d.*, p.nombre as producto_nombre, p.unidad_medida, p.codigo FROM detalle_factura d JOIN productos p ON p.id=d.producto_id WHERE d.factura_id=?');
$items_st->execute([$id]);
$raw_items = $items_st->fetchAll();
// Agrupar por producto+precio (consolida varios almacenes en una sola línea de factura)
$grp = [];
foreach ($raw_items as $it) {
    $k = (int)$it['producto_id'] . '|' . (string)$it['precio'];
    if (!isset($grp[$k])) { $grp[$k] = $it; $grp[$k]['cantidad'] = 0; }
    $grp[$k]['cantidad'] += (float)$it['cantidad'];
}
$items = array_values($grp);

$moneda = $factura['moneda'];
$sym    = $moneda === 'VES' ? 'Bs.' : '$';
function fmoney($v, $sym) { return $sym . ' ' . number_format((float)$v, 2, '.', ','); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Factura #<?= $id ?> — El Castillo del Queso</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  @page { size: Letter portrait; margin: 10mm; }
  body { background:#f5f5f5; font-family: 'Segoe UI', sans-serif; }
  .factura-wrap { max-width:800px; margin:24px auto; background:#fff; border-radius:12px; box-shadow:0 2px 20px rgba(0,0,0,.1); }
  .factura-header { background:linear-gradient(135deg,#1a2e5a 0%,#2a4a8a 100%); color:#fff; padding:24px 40px 20px; border-radius:12px 12px 0 0; }
  .logo-img { height:90px; width:90px; object-fit:contain; border-radius:8px; background:#fff; padding:4px; }
  .empresa-nombre { font-size:1.8rem; font-weight:700; margin:0; }
  .empresa-sub { font-size:.85rem; opacity:.8; margin:0; }
  .factura-numero { font-size:2rem; font-weight:700; opacity:.95; }
  .factura-body { padding:32px 40px; }
  .info-box { background:#f8f9fc; border-radius:8px; padding:16px 20px; }
  table thead th { background:#1a2e5a; color:#fff; }
  tfoot td { background:#f0f4ff; font-weight:700; }
  .total-grande { font-size:1.6rem; font-weight:700; color:#1a2e5a; }
  .badge-moneda { background:#f97316; color:#fff; border-radius:6px; padding:3px 10px; font-size:.8rem; }
  .pie { text-align:center; padding:20px 40px 28px; color:#999; font-size:.8rem; border-top:1px solid #eee; }
  .no-print { margin:20px auto; max-width:800px; display:flex; gap:10px; }
  @media print {
    body { background:#fff; margin:0; }
    .no-print { display:none !important; }
    .factura-wrap { box-shadow:none; border-radius:0; margin:0; max-width:100%; }
    .factura-header { border-radius:0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table thead th { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>

<!-- Botones de acción (no se imprimen) -->
<div class="no-print">
  <button onclick="window.print()" class="btn btn-primary">🖨 Imprimir</button>
  <button onclick="exportPDF()" class="btn btn-outline-danger">📄 Exportar PDF</button>
  <a href="?p=ventas" class="btn btn-outline-secondary">← Volver</a>
</div>

<div class="factura-wrap" id="facturaDoc">
  <!-- ENCABEZADO -->
  <div class="factura-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-3">
      <img src="public/logo.jpg" class="logo-img" alt="Logo El Castillo del Queso">
      <div>
        <p class="empresa-nombre">El Castillo del Queso</p>
        <p class="empresa-sub">Productora Artesanal de Quesos Venezolanos</p>
        <p class="empresa-sub mt-1">RIF: V-286013356 · Artesanal</p>
      </div>
    </div>
    <div class="text-end">
      <div class="factura-numero">FACTURA</div>
      <div style="font-size:1.1rem;opacity:.85">#<?= str_pad($id, 6, '0', STR_PAD_LEFT) ?></div>
      <div class="mt-2"><span class="badge-moneda"><?= h($moneda) ?></span></div>
    </div>
  </div>

  <!-- CUERPO -->
  <div class="factura-body">
    <!-- Info cliente / fechas -->
    <div class="row g-3 mb-4">
      <div class="col-md-7">
        <div class="info-box">
          <div class="text-muted small mb-1">FACTURADO A</div>
          <div class="fw-bold fs-5"><?= h($factura['cliente']) ?></div>
        </div>
      </div>
      <div class="col-md-5">
        <div class="info-box">
          <div class="row g-1 small">
            <div class="col-6 text-muted">Fecha:</div>
            <div class="col-6 fw-semibold"><?= date('d/m/Y', strtotime($factura['fecha'])) ?></div>
            <div class="col-6 text-muted">Moneda:</div>
            <div class="col-6 fw-semibold"><?= h($moneda) ?></div>
            <?php if ($factura['tasa_cambio'] && $factura['tasa_cambio'] != 1): ?>
            <div class="col-6 text-muted">Tasa Bs/USD:</div>
            <div class="col-6 fw-semibold"><?= number_format((float)$factura['tasa_cambio'], 2) ?></div>
            <?php endif ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabla de productos -->
    <table class="table table-bordered mb-4">
      <thead>
        <tr>
          <th>#</th>
          <th>Producto</th>
          <th class="text-center">Cantidad</th>
          <th class="text-center">Unidad</th>
          <th class="text-end">Precio unit.</th>
          <th class="text-end">Subtotal</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $i => $it): ?>
      <tr>
        <td class="text-muted"><?= $i+1 ?></td>
        <td>
          <?= h($it['producto_nombre']) ?>
          <?php if ($it['codigo']): ?><br><small class="text-muted"><?= h($it['codigo']) ?></small><?php endif ?>
        </td>
        <td class="text-center"><?= number_format((float)$it['cantidad'], 2) ?></td>
        <td class="text-center"><?= h($it['unidad_medida']) ?></td>
        <td class="text-end"><?= fmoney($it['precio'], $sym) ?></td>
        <td class="text-end"><?= fmoney($it['precio'] * $it['cantidad'], $sym) ?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5" class="text-end">TOTAL</td>
          <td class="text-end total-grande"><?= fmoney($factura['total'], $sym) ?></td>
        </tr>
        <?php if ($moneda === 'USD' && $factura['tasa_cambio'] > 1): ?>
        <tr>
          <td colspan="5" class="text-end text-muted small">Equivalente en Bolívares (tasa <?= number_format((float)$factura['tasa_cambio'],2) ?>):</td>
          <td class="text-end text-muted small">Bs. <?= number_format($factura['total'] * $factura['tasa_cambio'], 2, '.', ',') ?></td>
        </tr>
        <?php endif ?>
      </tfoot>
    </table>

    <!-- Nota -->
    <div class="text-muted small mb-4">
      <strong>Nota:</strong> Gracias por su preferencia. Esta factura es emitida por El Castillo del Queso.
    </div>

    <!-- Firmas y sello -->
    <div class="row g-4 mt-2">
      <div class="col-4 text-center">
        <div style="border-top:1.5px solid #999;padding-top:6px;margin-top:40px;font-size:.8rem;color:#555">
          Firma Emisor
        </div>
      </div>
      <div class="col-4 text-center">
        <img src="public/sello.png" alt="Sello El Castillo del Queso"
             style="max-height:90px;max-width:100%;object-fit:contain;opacity:.85;mix-blend-mode:multiply;">
      </div>
      <div class="col-4 text-center">
        <div style="border-top:1.5px solid #999;padding-top:6px;margin-top:40px;font-size:.8rem;color:#555">
          Firma Cliente
        </div>
      </div>
    </div>
  </div>

  <!-- PIE -->
  <div class="pie">
    El Castillo del Queso · RIF: V-286013356 · Quesos Artesanales Venezolanos<br>
    Documento generado por ASERP · <?= date('d/m/Y H:i') ?>
  </div>
</div>

<script>
function exportPDF() {
  const el = document.getElementById('facturaDoc');
  const opt = {
    margin:       0.5,
    filename:     'factura-<?= str_pad($id,6,'0',STR_PAD_LEFT) ?>.pdf',
    image:        { type: 'jpeg', quality: 0.98 },
    html2canvas:  { scale: 2, useCORS: true },
    jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
  };
  html2pdf().set(opt).from(el).save();
}
</script>
</body>
</html>
