<?php
declare(strict_types=1);

/**
 * Notaría de empleados — A4 horizontal, blanco y negro.
 *
 * @var string $empresa
 * @var string $inicio
 * @var string $fin
 * @var list<array{nombre:string, cargo:string, vales:string, mercancia:string, prestamo:string, observaciones:string}> $filas
 */
$periodo = date('d/m/Y', strtotime($inicio)) . ' al ' . date('d/m/Y', strtotime($fin));
$usd = static fn (string $n): string => (float) $n === 0.0 ? '' : '$' . num($n);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notaría de empleados <?= e($periodo) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/empleados_notaria.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('empleados', ['tab' => 'nomina', 'semana' => $inicio])) ?>">
    <i class="bi bi-arrow-left"></i> Volver
  </a>
  <button type="button" class="btn btn-dark btn-sm" id="btnImprimir"><i class="bi bi-printer"></i> Imprimir</button>
</div>

<section class="hoja">
  <header class="d-flex align-items-center gap-3 mb-2">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div class="flex-grow-1 titulo-notaria">NOTARÍA</div>
  </header>
  <div class="small text-center mb-2"><?= e($empresa) ?> · Semana <?= e($periodo) ?></div>

  <table class="notaria">
    <colgroup>
      <col width="4%"><col width="24%"><col width="13%"><col width="17%">
      <col width="8%"><col width="9%"><col width="9%"><col width="16%">
    </colgroup>
    <thead>
      <tr>
        <th>N</th>
        <th>Nombres y Apellidos</th>
        <th>Cargo</th>
        <th>Firma</th>
        <th>Vales ($)</th>
        <th>Mercancía ($)</th>
        <th>Préstamos ($)</th>
        <th>Observaciones</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $i => $f): ?>
      <tr>
        <td class="text-center"><?= $i + 1 ?></td>
        <td><?= e($f['nombre']) ?></td>
        <td><?= e($f['cargo']) ?></td>
        <td></td>
        <td class="text-end"><?= e($usd($f['vales'])) ?></td>
        <td class="text-end"><?= e($usd($f['mercancia'])) ?></td>
        <td class="text-end"><?= e($usd($f['prestamo'])) ?></td>
        <td><?= e($f['observaciones']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($filas === []): ?>
      <tr><td colspan="8" class="text-center">Sin empleados activos en esta semana.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
