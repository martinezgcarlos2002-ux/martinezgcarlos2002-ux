<?php
declare(strict_types=1);

/**
 * Liquidación de productores (uno o varios) en HTML/CSS plano.
 * Sin html2canvas, html2pdf ni capturas: solo impresión nativa.
 *
 * @var list<array> $documentos
 * @var string $desde
 * @var string $hasta
 * @var string|null $tasa
 * @var string $emitida
 * @var bool $autoprint
 */
$autoprint = !empty($autoprint);
$empresaRif = defined('APP_RIF') ? APP_RIF : 'V-286013356';

$fecha = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
$dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
$tiposPago = PagoProductor::ETIQUETAS;
$conTasa = $tasa !== null && (float) $tasa > 0;
$bs = static fn (mixed $usd): string => 'Bs. ' . num((float) $usd * (float) $tasa);
$usd = static fn (mixed $v, int $d = 2): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v), $d);

$titulo = count($documentos) === 1
    ? 'Liquidación — ' . $documentos[0]['productor']['nombre']
    : sprintf('Liquidaciones (%d productores)', count($documentos));
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
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/reportes.css')) ?>">
</head>
<body class="reporte">

<div class="toolbar no-print">
  <a class="btn-adara-volver js-btn-volver" href="<?= e($volver) ?>"><i class="bi bi-arrow-left"></i> Volver</a>
  <button type="button" class="btn-adara-print" id="btnImprimir"><i class="bi bi-printer-fill"></i> Imprimir<?= count($documentos) > 1 ? ' todas' : ' / Guardar PDF' ?></button>
</div>

<div id="docsLiquidacion">
<?php foreach ($documentos as $doc): ?>
  <div class="salto-pagina">
    <?php require ADARA_ROOT . '/pages/partials/liquidacion_doc.php'; ?>
  </div>
<?php endforeach; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var btn = document.getElementById('btnImprimir');
  if (btn) btn.addEventListener('click', function () { window.print(); });
  document.querySelectorAll('.js-btn-volver').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      if (window.opener && !window.opener.closed && window.history.length <= 1) {
        ev.preventDefault();
        window.opener.focus();
        window.close();
      }
    });
  });
  <?php if ($autoprint): ?>
  window.addEventListener('load', function () { window.print(); });
  <?php endif; ?>
})();
</script>
</body>
</html>
