<?php
declare(strict_types=1);

/**
 * Planilla ASERP para imprimir (misma tabla que la vista diaria).
 * @var string $fecha
 * @var string $empresa
 */
$fechaHoja = $fechaHoja ?? $fecha;
$filasHoja = $filasHoja ?? [];
$seccionesHoja = $seccionesHoja ?? [];
$mermaHoja = $mermaHoja ?? [];
$totalRecibido = $totalRecibido ?? 0.0;
$puedeEditarHoja = false;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Control diario de leche <?= e((new DateTimeImmutable($fechaHoja))->format('d/m/Y')) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/reportes.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('css/control_diario.css')) ?>">
</head>
<body>
<div class="toolbar no-print">
  <a class="btn-adara-volver js-btn-volver" href="<?= e(url('control_leche', ['vista' => 'diaria', 'fecha' => $fechaHoja, 'desde' => $fechaHoja, 'hasta' => $fechaHoja])) ?>"><i class="bi bi-arrow-left"></i> Volver</a>
  <button type="button" class="btn-adara-print" id="btnImprimir"><i class="bi bi-printer-fill"></i> Imprimir / Guardar PDF</button>
</div>
<?php require ADARA_ROOT . '/pages/partials/control_diario_hoja.php'; ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
document.querySelectorAll('.js-btn-volver').forEach(function (a) {
  a.addEventListener('click', function (ev) {
    if (window.opener && !window.opener.closed && window.history.length <= 1) {
      ev.preventDefault();
      window.opener.focus();
      window.close();
    }
  });
});
</script>
</body>
</html>
