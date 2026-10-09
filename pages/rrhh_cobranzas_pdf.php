<?php
declare(strict_types=1);

/**
 * Reporte CxP de cobranzas a terceros. Sin style="".
 *
 * @var string $hoy
 * @var string $empresa
 * @var list<array<string, mixed>> $filas
 * @var array{pendiente:string, descontado:string, cobrado_historico:string, por_cobrar:string} $totales
 */
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cobranzas a terceros – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/transporte_pdf.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('empleados', ['tab' => 'cobranzas'])) ?>">Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir">Imprimir / Guardar PDF</button>
</div>
<section class="hoja">
  <header class="cab">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div>
      <h1><?= e($empresa) ?></h1>
      <p>Cobranzas a terceros · <?= e($dmy($hoy)) ?></p>
    </div>
  </header>
  <p>
    <strong>Total cobrado histórico:</strong> $<?= e(num($totales['cobrado_historico'])) ?>
    · <strong>Total por cobrar:</strong> $<?= e(num($totales['por_cobrar'])) ?>
  </p>
  <table class="grid">
    <thead>
      <tr>
        <th>Empleado</th>
        <th>Cédula</th>
        <th>Monto original</th>
        <th>Descontado en nómina</th>
        <th>Saldo pendiente</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($filas === []): ?>
      <tr><td colspan="5" align="center">Sin cobranzas registradas.</td></tr>
    <?php endif; ?>
    <?php foreach ($filas as $f): ?>
      <tr>
        <td><?= e((string) $f['empleado']) ?></td>
        <td><?= e((string) $f['cedula']) ?></td>
        <td align="right">$<?= e(num($f['original'])) ?></td>
        <td align="right">$<?= e(num($f['descontado'])) ?></td>
        <td align="right">$<?= e(num($f['pendiente'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="2">Totales</th>
        <th align="right">$<?= e(num(array_sum(array_map(static fn (array $f): float => (float) $f['original'], $filas)))) ?></th>
        <th align="right">$<?= e(num($totales['cobrado_historico'])) ?></th>
        <th align="right">$<?= e(num($totales['por_cobrar'])) ?></th>
      </tr>
    </tfoot>
  </table>
</section>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
