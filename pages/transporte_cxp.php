<?php
declare(strict_types=1);

/**
 * Reporte CxP de transporte (impresión / PDF). Sin style="".
 *
 * @var string $hoy
 * @var string $empresa
 * @var list<array<string, mixed>> $filas
 * @var array{bruto:string, pagado:string, deuda:string, litros:string} $totales
 */
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
$totales = $totales ?? ['bruto' => '0', 'pagado' => '0', 'deuda' => '0', 'litros' => '0'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>CxP transporte – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/transporte_pdf.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('transportistas')) ?>">Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir">Imprimir / Guardar PDF</button>
</div>
<section class="hoja">
  <header class="cab">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div>
      <h1><?= e($empresa) ?></h1>
      <p>Cuentas por pagar · Transporte · <?= e($dmy($hoy)) ?></p>
    </div>
  </header>
  <table class="grid">
    <thead>
      <tr>
        <th>Transportista</th>
        <th>Documento</th>
        <th>Modalidad</th>
        <th>Estado</th>
        <th>Litros</th>
        <th>Bruto</th>
        <th>Pagado</th>
        <th>Deuda CxP</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($filas === []): ?>
      <tr><td colspan="8" align="center">Sin movimientos.</td></tr>
    <?php endif; ?>
    <?php foreach ($filas as $f): ?>
      <tr>
        <td><?= e((string) $f['nombre']) ?></td>
        <td><?= e((string) ($f['documento'] ?: '—')) ?></td>
        <td><?= ($f['modalidad'] ?? '') === 'cooperativa' ? 'Cooperativa' : 'Individual' ?></td>
        <td><?= e((string) $f['estado']) ?></td>
        <td align="right"><?= e(num($f['litros'])) ?> L</td>
        <td align="right">$<?= e(num($f['bruto'])) ?></td>
        <td align="right">$<?= e(num($f['pagado'])) ?></td>
        <td align="right">$<?= e(num($f['deuda'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="4">Totales</th>
        <th align="right"><?= e(num($totales['litros'])) ?> L</th>
        <th align="right">$<?= e(num($totales['bruto'])) ?></th>
        <th align="right">$<?= e(num($totales['pagado'])) ?></th>
        <th align="right">$<?= e(num($totales['deuda'])) ?></th>
      </tr>
    </tfoot>
  </table>
</section>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
