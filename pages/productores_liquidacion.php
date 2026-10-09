<?php
declare(strict_types=1);

/**
 * Liquidación de productores (uno o varios documentos) lista para imprimir o exportar a PDF.
 * @var list<array> $documentos
 * @var string $desde
 * @var string $hasta
 * @var string|null $tasa     Bs por USD vigente al emitir
 * @var string $emitida
 */
$empresaRif = 'V-286013356';
$html2pdf = [
    'src'       => 'https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js',
    'integrity' => 'sha384-Yv5O+t3uE3hunW8uyrbpPW3iw6/5/Y7HitWJBLgqfMoA36NogMmy+8wWZMpn3HWc',
];

$fecha = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
$dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
$tiposPago = PagoProductor::ETIQUETAS;
$conTasa = $tasa !== null && (float) $tasa > 0;
$bs = static fn (mixed $usd): string => 'Bs. ' . num((float) $usd * (float) $tasa);
$usd = static fn (mixed $v, int $d = 2): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v), $d);

$titulo = count($documentos) === 1
    ? 'Liquidación — ' . $documentos[0]['productor']['nombre']
    : sprintf('Liquidaciones (%d productores)', count($documentos));
$archivo = 'liquidacion-' . (count($documentos) === 1 ? $documentos[0]['numero'] : 'productores') . '-' . str_replace('-', '', $desde) . '-' . str_replace('-', '', $hasta) . '.pdf';
$volver = count($documentos) === 1
    ? url('productor_control', ['id' => (int) $documentos[0]['productor']['id'], 'tab' => 'historial', 'desde' => $desde, 'hasta' => $hasta])
    : url('productores_facturas_masivas', ['desde' => $desde, 'hasta' => $hasta]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($titulo) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/reportes.css')) ?>">
</head>
<body class="reporte">

<div class="toolbar no-print">
  <button type="button" class="btn btn-primary" id="btnImprimir">🖨 Imprimir</button>
  <button type="button" class="btn btn-danger" id="btnPdf" data-archivo="<?= e($archivo) ?>">📄 Exportar PDF</button>
  <a class="btn btn-outline-secondary" href="<?= e($volver) ?>">← Volver</a>
</div>

<div id="docsLiquidacion">
<?php foreach ($documentos as $doc):
    require ADARA_ROOT . '/pages/partials/liquidacion_doc.php';
endforeach; ?>
</div>

<script src="<?= e($html2pdf['src']) ?>" integrity="<?= e($html2pdf['integrity']) ?>" crossorigin="anonymous"></script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
  var pdf = document.getElementById('btnPdf');
  pdf.addEventListener('click', function () {
    if (typeof html2pdf === 'undefined') { window.print(); return; }
    html2pdf().set({
      margin: [0.4, 0.4],
      filename: pdf.getAttribute('data-archivo'),
      image: { type: 'jpeg', quality: 0.97 },
      html2canvas: { scale: 2, useCORS: true, logging: false },
      jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' },
      pagebreak: { mode: ['css', 'legacy'] }
    }).from(document.getElementById('docsLiquidacion')).save();
  });
})();
</script>
</body>
</html>
