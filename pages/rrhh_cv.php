<?php
declare(strict_types=1);

/**
 * Currículum / hoja de vida del empleado (carta, impresión).
 * @var array<string, mixed> $emp
 * @var string $empresa
 * @var string $fotoPerfil
 * @var string $fotoDoc
 */
$fmtFecha = static function (string $iso): string {
    $dt = $iso !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10)) : false;
    return $dt instanceof DateTimeImmutable ? $dt->format('d/m/Y') : '—';
};
$nombre = trim((string) $emp['nombres'] . ' ' . (string) $emp['apellidos']);
$domicilio = (string) ($emp['direccion_domicilio'] !== '' ? $emp['direccion_domicilio'] : $emp['direccion']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Currículum — <?= e($nombre) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/rrhh_cv.css')) ?>">
</head>
<body class="cv">
<div class="toolbar no-print">
  <button type="button" class="btn btn-primary" id="btnImprimir">🖨 Imprimir / PDF</button>
  <a class="btn btn-outline-secondary" href="<?= e(url('empleados', ['tab' => 'directorio'])) ?>">← Volver al directorio</a>
</div>

<article class="hoja-cv">
  <header class="cv-head">
    <div class="cv-foto">
      <?php if ($fotoPerfil !== ''): ?>
        <img src="<?= e($fotoPerfil) ?>" alt="Foto de <?= e($nombre) ?>">
      <?php else: ?>
        <div class="cv-foto-vacia">Sin foto</div>
      <?php endif; ?>
    </div>
    <div class="cv-id">
      <p class="cv-empresa"><?= e($empresa) ?></p>
      <h1><?= e($nombre) ?></h1>
      <p class="cv-cargo"><?= e($emp['cargo'] !== '' ? $emp['cargo'] : 'Empleado') ?></p>
      <?php if ($emp['profesion'] !== ''): ?><p class="cv-prof">Profesión: <?= e($emp['profesion']) ?></p><?php endif; ?>
    </div>
  </header>

  <section>
    <h2>Datos personales</h2>
    <dl class="cv-dl">
      <div><dt>Cédula</dt><dd><?= e($emp['cedula'] !== '' ? $emp['cedula'] : '—') ?></dd></div>
      <div><dt>Fecha de nacimiento</dt><dd><?= e($fmtFecha((string) $emp['fecha_nacimiento'])) ?></dd></div>
      <div><dt>Teléfono</dt><dd><?= e($emp['telefono'] !== '' ? $emp['telefono'] : '—') ?></dd></div>
      <div><dt>Domicilio</dt><dd><?= e($domicilio !== '' ? $domicilio : '—') ?></dd></div>
    </dl>
  </section>

  <section>
    <h2>Información laboral</h2>
    <dl class="cv-dl">
      <div><dt>Cargo en la empresa</dt><dd><?= e($emp['cargo'] !== '' ? $emp['cargo'] : '—') ?></dd></div>
      <div><dt>Fecha de ingreso</dt><dd><?= e($fmtFecha((string) $emp['fecha_ingreso'])) ?></dd></div>
      <div><dt>Fecha de salida</dt><dd><?= e($fmtFecha((string) $emp['fecha_salida'])) ?></dd></div>
      <div><dt>Estado</dt><dd><?= $emp['activo'] ? 'Activo' : 'Inactivo' ?></dd></div>
    </dl>
  </section>

  <section class="cv-doc">
    <h2>Documento de identidad</h2>
    <?php if ($fotoDoc !== ''): ?>
      <img src="<?= e($fotoDoc) ?>" alt="Foto de cédula de <?= e($nombre) ?>">
    <?php else: ?>
      <p class="text-muted">No hay foto de cédula / identidad adjunta.</p>
    <?php endif; ?>
  </section>
</article>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
