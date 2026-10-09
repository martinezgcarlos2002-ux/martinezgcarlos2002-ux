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
<link rel="stylesheet" href="<?= e(asset_v('css/control_diario.css')) ?>">
</head>
<body>
<div class="barra no-print p-2 d-flex gap-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('control_leche', ['vista' => 'diaria', 'fecha' => $fechaHoja, 'desde' => $fechaHoja, 'hasta' => $fechaHoja])) ?>">← Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir">🖨 Imprimir</button>
</div>
<?php require ADARA_ROOT . '/pages/partials/control_diario_hoja.php'; ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
