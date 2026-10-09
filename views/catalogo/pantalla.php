<?php
declare(strict_types=1);

/**
 * Pantalla pública tipo menú (TV). Sin layout del ERP.
 *
 * @var string $empresa
 * @var string|null $tasa
 * @var string $revision
 * @var list<array<string, mixed>> $items
 * @var string $poll_url
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Menú — <?= e($empresa) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/catalogo_pantalla.css')) ?>">
</head>
<body class="menu-tv">
<header class="menu-tv-top d-flex justify-content-between align-items-center">
  <div>
    <div class="menu-tv-marca"><?= e($empresa) ?></div>
    <div class="menu-tv-sub">Quesos artesanales</div>
  </div>
  <div class="menu-tv-tasa" id="tvTasa"><?= $tasa !== null ? '1 USD = Bs. ' . e(num($tasa)) : 'Tasa no definida' ?></div>
</header>

<main class="container-fluid menu-tv-grid">
  <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3" id="tvGrid"></div>
  <p class="menu-tv-vacio d-none" id="tvVacio">No hay productos visibles en este momento.</p>
</main>

<script type="application/json" id="tvDatos"><?= json_encode([
    'revision' => $revision,
    'tasa'     => $tasa !== null ? (float) $tasa : null,
    'items'    => $items,
    'poll'     => $poll_url,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= e(asset_v('js/catalogo_pantalla.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
</body>
</html>
