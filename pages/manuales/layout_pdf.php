<?php
declare(strict_types=1);

/**
 * Manual de usuario. CSS mínimo: DomPDF no aplica flex, grid ni position:fixed.
 *
 * @var string $version
 * @var string $fecha
 * @var string $logo HTML del logo (data URI) o cadena vacía
 * @var list<string> $capitulos archivos de pages/manuales/
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Manual de Usuario ADARA v<?= e($version) ?></title>
<style>
  body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 12px; line-height: 1.5; }
  h1 { color: #1A2E5A; font-size: 22px; margin: 0 0 8px; }
  h2 { color: #1A2E5A; font-size: 15px; margin: 16px 0 6px; }
  p { margin: 0 0 8px; }
  ul { margin: 0 0 10px; padding-left: 18px; }
  li { margin-bottom: 3px; }
  .portada { text-align: center; padding-top: 160px; }
  .franja { height: 6px; background: #F97316; width: 120px; margin: 14px auto; }
  .meta { color: #64748b; font-size: 11px; }
  .indice { page-break-before: always; }
  .capitulo { page-break-before: always; }
  .indice ol { font-size: 14px; line-height: 1.8; }
</style>
</head>
<body>
  <div class="portada">
    <?= $logo ?>
    <h1>Manual de Usuario v<?= e($version) ?></h1>
    <div class="franja"></div>
    <p>ADARA ERP para la operación quesera y agroindustrial</p>
    <p class="meta">Generado el <?= e($fecha) ?> desde el Panel Maestro</p>
  </div>

  <div class="indice">
    <h1>Contenido</h1>
    <p>Cada capítulo empieza en una hoja nueva. El número de página va al pie del documento.</p>
    <ol>
      <li>Introducción y accesos</li>
      <li>Productores y control de leche</li>
      <li>Producción e inventario</li>
      <li>Ventas y finanzas</li>
      <li>Gas y transporte</li>
    </ol>
  </div>

  <?php foreach ($capitulos as $archivo): ?>
    <?php require ADARA_ROOT . '/pages/manuales/' . $archivo; ?>
  <?php endforeach; ?>
</body>
</html>
