<?php
declare(strict_types=1);

/**
 * Directorio de transportistas (impresión / PDF). Sin style="".
 *
 * @var string $hoy
 * @var string $empresa
 * @var list<array<string, mixed>> $transportistas
 */
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Directorio transportistas – <?= e(APP_NAME) ?></title>
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
      <p>Directorio de transportistas · <?= e($dmy($hoy)) ?></p>
    </div>
  </header>
  <table class="grid">
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Documento</th>
        <th>Teléfono</th>
        <th>Modalidad</th>
        <th>Flotilla</th>
        <th>Placas</th>
        <th>Estado</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($transportistas === []): ?>
      <tr><td colspan="7" align="center">Sin transportistas.</td></tr>
    <?php endif; ?>
    <?php foreach ($transportistas as $t): ?>
      <?php
        $veh = $t['vehiculos'] ?? [];
        $placas = [];
        foreach ($veh as $v) {
            $p = trim((string) ($v['placa'] ?? ''));
            if ($p !== '') {
                $placas[] = $p;
            }
        }
      ?>
      <tr>
        <td><?= e((string) $t['nombre']) ?></td>
        <td><?= e((string) ($t['documento'] ?: '—')) ?></td>
        <td><?= e((string) ($t['telefono'] ?: '—')) ?></td>
        <td><?= ($t['modalidad'] ?? '') === 'cooperativa' ? 'Cooperativa' : 'Individual' ?></td>
        <td align="right"><?= count($veh) ?></td>
        <td><?= e($placas !== [] ? implode(', ', $placas) : '—') ?></td>
        <td><?= e((string) $t['estado']) ?></td>
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
