<?php
declare(strict_types=1);

/**
 * Lista de precios tipo menú (imprimir / PDF). Sin style="".
 *
 * @var string $hoy
 * @var string $empresa
 * @var string|null $tasa
 * @var list<array<string, mixed>> $items
 */
$dmy = date('d/m/Y', strtotime($hoy));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lista de precios — <?= e($empresa) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/catalogo_pdf.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('catalogo')) ?>"><i class="bi bi-arrow-left"></i> Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir">Imprimir / Guardar PDF</button>
</div>
<section class="hoja">
  <header class="cab">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div>
      <h1><?= e($empresa) ?></h1>
      <p>Lista de precios · <?= e($dmy) ?><?= $tasa !== null ? ' · 1 USD = Bs. ' . e(num($tasa)) : '' ?></p>
    </div>
  </header>
  <div class="rejilla">
    <?php foreach ($items as $it): ?>
    <article class="celda">
      <?php if (($it['foto_url'] ?? '') !== ''): ?>
      <img src="<?= e((string) $it['foto_url']) ?>" alt="" class="foto">
      <?php else: ?>
      <div class="foto foto-vacia">Sin foto</div>
      <?php endif; ?>
      <h2><?= e((string) $it['nombre']) ?></h2>
      <p class="usd">$<?= e(num($it['precio_usd'])) ?></p>
      <p class="bs"><?= $it['precio_bs'] !== null ? 'Bs. ' . e(num($it['precio_bs'])) : '—' ?></p>
    </article>
    <?php endforeach; ?>
    <?php if ($items === []): ?>
    <p class="vacio">No hay productos con precio para mostrar.</p>
    <?php endif; ?>
  </div>
</section>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
