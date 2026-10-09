<?php
declare(strict_types=1);

/**
 * Reporte de producción diaria (imprimir / PDF).
 * Sin atributos style="": CSP style-src no permite inline.
 *
 * @var string $fecha
 * @var array{inicio:string, fin:string} $semana
 * @var string $empresa
 * @var list<array<string, mixed>> $filas
 */
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
$qty = static function (mixed $n): string {
    $t = num($n, 3);
    $t = rtrim(rtrim($t, '0'), ',');
    return $t === '' ? '0' : $t;
};
$tHoy = 0.0;
$tSem = 0.0;
$tStk = 0.0;
foreach ($filas as $f) {
    $tHoy += (float) ($f['prod_hoy'] ?? 0);
    $tSem += (float) ($f['prod_semana'] ?? 0);
    $tStk += (float) ($f['stock'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Producción diaria <?= e($dmy($fecha)) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/produccion_pdf.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('produccion', ['fecha' => $fecha])) ?>">Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir">Imprimir / Guardar PDF</button>
</div>
<section class="hoja">
  <header class="cab">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo" width="48" height="48">
    <div>
      <h1><?= e($empresa) ?></h1>
      <p>Producción diaria · <?= e($dmy($fecha)) ?> · Semana <?= e($dmy($semana['inicio'])) ?> al <?= e($dmy($semana['fin'])) ?></p>
    </div>
  </header>
  <table class="grid" width="100%">
    <colgroup>
      <col width="40%"><col width="20%"><col width="20%"><col width="20%">
    </colgroup>
    <thead>
      <tr>
        <th align="left">Producto</th>
        <th align="right">Prod. Hoy</th>
        <th align="right">Prod. Semana</th>
        <th align="right">Stock Actual Almacén</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($filas === []): ?>
      <tr><td colspan="4" align="center">Sin productos en el catálogo de producción.</td></tr>
    <?php endif; ?>
    <?php foreach ($filas as $f): ?>
      <tr>
        <td><?= e((string) $f['nombre']) ?> <span class="text-muted"><?= e((string) ($f['unidad_medida'] ?? 'KG')) ?></span></td>
        <td align="right"><?= e($qty($f['prod_hoy'] ?? 0)) ?></td>
        <td align="right"><?= e($qty($f['prod_semana'] ?? 0)) ?></td>
        <td align="right"><?= e($qty($f['stock'] ?? 0)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th align="right">Totales</th>
        <th align="right"><?= e($qty($tHoy)) ?></th>
        <th align="right"><?= e($qty($tSem)) ?></th>
        <th align="right"><?= e($qty($tStk)) ?></th>
      </tr>
    </tfoot>
  </table>
</section>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
