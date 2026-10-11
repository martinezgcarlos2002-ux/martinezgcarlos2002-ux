<?php
declare(strict_types=1);

/**
 * Planilla física de asistencia. Sin menú del ERP.
 *
 * @var list<array<string, mixed>> $empleados
 */
$empleados = $empleados ?? [];
$dias = ['LUN', 'MAR', 'MIE', 'JUE', 'VIE', 'SAB', 'DOM'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Control de asistencia semanal – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_v('css/planilla_asistencia.css')) ?>">
</head>
<body>
<div class="barra no-print">
  <button type="button" id="btnImprimir">Imprimir</button>
  <a href="<?= e(url('empleados')) ?>">Volver a Recursos Humanos</a>
</div>
<section class="hoja">
  <header class="cabecera">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="El Castillo del Queso" class="logo">
    <h1>CONTROL DE ASISTENCIA SEMANAL</h1>
    <div class="semana">Semana del: ___/___/___ al ___/___/___</div>
  </header>
  <table class="grid">
    <colgroup>
      <col class="c-n">
      <col class="c-nom">
      <col class="c-cargo">
      <?php foreach ($dias as $dia): ?><col class="c-dia"><?php endforeach; ?>
      <col class="c-total">
      <col class="c-firma">
    </colgroup>
    <thead>
      <tr>
        <th class="c-n">N°</th>
        <th class="c-nom">NOMBRE Y APELLIDO</th>
        <th class="c-cargo">CARGO</th>
        <?php foreach ($dias as $dia): ?><th class="c-dia"><?= e($dia) ?></th><?php endforeach; ?>
        <th class="c-total">TOTAL DÍAS</th>
        <th class="c-firma">FIRMA</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($empleados === []): ?>
      <tr><td colspan="12">No hay empleados activos.</td></tr>
    <?php endif; ?>
    <?php foreach ($empleados as $i => $emp): ?>
      <tr>
        <td class="num"><?= $i + 1 ?></td>
        <td class="nombre"><?= e((string) $emp['nombre']) ?></td>
        <td><?= e((string) $emp['cargo']) ?></td>
        <?php foreach ($dias as $dia): ?><td class="dia">&nbsp;</td><?php endforeach; ?>
        <td class="total">&nbsp;</td>
        <td class="firma">&nbsp;</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
