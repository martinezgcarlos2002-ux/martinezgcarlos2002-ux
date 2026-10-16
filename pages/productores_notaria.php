<?php
declare(strict_types=1);

/**
 * Hoja de notaría: matriz vacía para firmar/anotar entregas de la semana operativa.
 * @var string $semana
 * @var string $semanaFin
 * @var list<string> $dias          7 fechas Y-m-d (sábado a viernes)
 * @var list<array> $productores   activos, en orden alfabético
 * @var string $impreso
 */
$nombresDia = [1 => 'LUNES', 2 => 'MARTES', 3 => 'MIÉRCOLES', 4 => 'JUEVES', 5 => 'VIERNES', 6 => 'SÁBADO', 7 => 'DOMINGO'];
$fecha = static fn (string $f, string $fmt = 'd/m/Y'): string => (new DateTimeImmutable($f))->format($fmt);
$filas = max(count($productores) + 3, 30);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notaría Productores <?= e($fecha($impreso)) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/reportes.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('css/notaria_print.css')) ?>">
</head>
<body class="notaria">

<div class="toolbar toolbar-ancha no-print">
  <a class="btn-adara-volver js-btn-volver" href="<?= e(url('productores')) ?>"><i class="bi bi-arrow-left"></i> Volver</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productores_notaria', ['semana' => (new DateTimeImmutable($semana))->modify('-7 days')->format('Y-m-d')])) ?>">← Semana anterior</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productores_notaria', ['semana' => (new DateTimeImmutable($semana))->modify('+7 days')->format('Y-m-d')])) ?>">Semana siguiente →</a>
  <button type="button" class="btn-adara-print" id="btnImprimir"><i class="bi bi-printer-fill"></i> Imprimir / Guardar PDF</button>
</div>

<div class="titulo-logo"><img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt=""> EL CASTILLO DEL QUESO</div>
<div class="titulo">NOTARÍA — PRODUCTORES</div>
<div class="subt">Semana del <?= e($fecha($semana, 'd/m/y')) ?> al <?= e($fecha($semanaFin, 'd/m/y')) ?> &nbsp;&nbsp;&nbsp; Impreso: <?= e($fecha($impreso)) ?></div>

<table>
  <colgroup>
    <col class="c-n"><col class="c-nom">
    <?php foreach ($dias as $unused): ?><col class="c-d"><?php endforeach; ?>
    <col class="c-obs">
  </colgroup>
  <thead>
    <tr>
      <th>N</th>
      <th>NOMBRE DEL PRODUCTOR</th>
      <?php foreach ($dias as $d): ?>
        <th><?= e($nombresDia[(int) $fecha($d, 'N')]) ?><span class="fecha"><?= e($fecha($d, 'd/m')) ?></span></th>
      <?php endforeach; ?>
      <th>OBSERVACIONES</th>
    </tr>
  </thead>
  <tbody>
    <?php for ($i = 0; $i < $filas; $i++): $p = $productores[$i] ?? null; ?>
    <tr>
      <td class="n"><?= $i + 1 ?></td>
      <td class="nom <?= $p === null ? 'vacia' : '' ?>"><?= $p === null ? '' : e($p['nombre']) ?></td>
      <?php foreach ($dias as $unused): ?><td class="vacia"></td><?php endforeach; ?>
      <td class="vacia"></td>
    </tr>
    <?php endfor; ?>
  </tbody>
</table>

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
