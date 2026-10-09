<?php
declare(strict_types=1);

/**
 * Estado de cuenta vertical (libro mayor) de un cliente. Sin style="".
 *
 * @var string $hoy
 * @var string $empresa
 * @var string $firmante
 * @var array{id:int, nombre:string, documento:string, telefono:string, domicilio:string, credito:float} $cliente
 * @var list<array{fecha:string, descripcion:string, debito:string, credito:string, saldo:string}> $asientos
 */
$usd = static fn (mixed $n): string => ((float) $n === 0.0 ? '' : '$' . num($n));
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
$saldoFinal = $asientos !== [] ? $asientos[array_key_last($asientos)]['saldo'] : '0.00';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Estado de cuenta <?= e($cliente['nombre']) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/facturas_estado_cuenta.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('css/cxc_firmas.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('facturas')) ?>"><i class="bi bi-arrow-left"></i> Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir"><i class="bi bi-printer"></i> Imprimir / Guardar PDF</button>
</div>

<section class="hoja">
  <header class="d-flex align-items-center gap-3 mb-3">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div class="flex-grow-1">
      <h1 class="h6 mb-0"><?= e($empresa) ?></h1>
      <div class="small">Estado de cuenta · <?= e($dmy($hoy)) ?></div>
    </div>
    <div class="small text-end">
      <?= e($cliente['nombre']) ?><br>
      <?= e($cliente['documento'] !== '' ? $cliente['documento'] : 'Sin documento') ?>
    </div>
  </header>
  <p class="small mb-2">
    Teléfono: <?= e($cliente['telefono'] !== '' ? $cliente['telefono'] : '—') ?>
    · Domicilio: <?= e($cliente['domicilio'] !== '' ? $cliente['domicilio'] : '—') ?>
    · Saldo a favor: <?= e('$' . num($cliente['credito'])) ?>
  </p>

  <table class="mayor">
    <colgroup>
      <col width="14%"><col width="42%"><col width="14%"><col width="14%"><col width="16%">
    </colgroup>
    <thead>
      <tr>
        <th>Fecha</th><th>Descripción / Ref.</th><th>Débito</th><th>Crédito</th><th>Saldo</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($asientos as $a): ?>
      <tr>
        <td align="center"><?= e($dmy($a['fecha'])) ?></td>
        <td><?= e($a['descripcion']) ?></td>
        <td align="right" class="num"><?= e($usd($a['debito'])) ?></td>
        <td align="right" class="num"><?= e($usd($a['credito'])) ?></td>
        <td align="right" class="num"><?= e('$' . num($a['saldo'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($asientos === []): ?>
      <tr><td colspan="5" align="center">Sin movimientos.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="4" align="right">Saldo</th>
        <th align="right"><?= e('$' . num($saldoFinal)) ?></th>
      </tr>
    </tfoot>
  </table>

  <table class="firmas" width="100%">
    <colgroup><col width="38%"><col width="24%"><col width="38%"></colgroup>
    <tr>
      <td align="center">
        <div class="firma-linea"></div>
        <div class="firma-lbl">Firma del Cliente</div>
        <div class="firma-sub"><?= e($cliente['nombre']) ?><?= $cliente['documento'] !== '' ? ' · ' . e($cliente['documento']) : '' ?></div>
      </td>
      <td align="center">
        <div class="sello">
          <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="sello-img">
          <div class="sello-txt">Sello</div>
          <div class="sello-txt"><?= e(APP_NAME) ?></div>
        </div>
      </td>
      <td align="center">
        <div class="firma-linea"></div>
        <div class="firma-lbl">Emitido por / Firma Autorizada</div>
        <div class="firma-sub"><?= e($firmante) ?> · <?= e($dmy($hoy)) ?></div>
      </td>
    </tr>
  </table>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
