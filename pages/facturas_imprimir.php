<?php
declare(strict_types=1);

/**
 * Factura individual imprimible (A4 vertical). Sin style="".
 *
 * @var string $hoy
 * @var string $empresa
 * @var string $firmante
 * @var array<string, mixed> $factura
 * @var string $observaciones
 * @var array<string, mixed>|null $cliente
 * @var list<array<string, mixed>> $renglones
 * @var list<array<string, mixed>> $pagos
 */
$usd = static fn (mixed $n): string => '$' . num($n);
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
$estados = ['pagada' => 'Pagada', 'vencida' => 'Vencida', 'por_vencer' => 'Por vencer', 'vigente' => 'Vigente', 'anulada' => 'Anulada'];
$cli = $cliente ?? ['nombre' => $factura['cliente'], 'documento' => '', 'telefono' => '', 'domicilio' => $factura['domicilio'] ?? ''];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Factura <?= e((string) $factura['numero']) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/facturas_imprimir.css')) ?>">
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
      <div class="small">Factura <?= e((string) $factura['numero']) ?> · <?= e($estados[$factura['estado']] ?? (string) $factura['estado']) ?></div>
    </div>
    <div class="small text-end">
      Emisión: <?= e($dmy((string) $factura['emision'])) ?><br>
      Vence: <?= e($dmy((string) $factura['vence'])) ?>
    </div>
  </header>

  <table class="meta">
    <tr><td><strong>Cliente:</strong> <?= e((string) $cli['nombre']) ?></td></tr>
    <tr><td><strong>Documento:</strong> <?= e(($cli['documento'] ?? '') !== '' ? (string) $cli['documento'] : '—') ?>
      · <strong>Teléfono:</strong> <?= e(($cli['telefono'] ?? '') !== '' ? (string) $cli['telefono'] : '—') ?></td></tr>
    <tr><td><strong>Domicilio:</strong> <?= e((string) (($factura['domicilio'] ?? '') !== '' ? $factura['domicilio'] : ($cli['domicilio'] ?? '—'))) ?></td></tr>
    <?php if ($observaciones !== ''): ?>
    <tr><td><strong>Observaciones:</strong> <?= e($observaciones) ?></td></tr>
    <?php endif; ?>
  </table>

  <table class="fac">
    <colgroup>
      <col width="12%"><col width="16%"><col width="16%"><col width="56%">
    </colgroup>
    <thead>
      <tr><th>Cant.</th><th>Precio</th><th>Subtotal</th><th>Descripción</th></tr>
    </thead>
    <tbody>
    <?php foreach ($renglones as $r): ?>
      <tr>
        <td align="center" class="num"><?= e(num($r['cantidad'])) ?></td>
        <td align="right" class="num"><?= e($usd($r['precio_unitario'])) ?></td>
        <td align="right" class="num"><?= e($usd($r['subtotal'])) ?></td>
        <td><?= e((string) ($r['descripcion'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($renglones === []): ?>
      <tr><td colspan="4" align="center">Monto capturado sin desglose de productos.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="2" align="right">Total</th><th align="right"><?= e($usd($factura['monto'])) ?></th><th></th></tr>
      <tr><th colspan="2" align="right">Pagado</th><th align="right"><?= e($usd($factura['pagado'])) ?></th><th></th></tr>
      <tr><th colspan="2" align="right">Devuelto</th><th align="right"><?= e($usd($factura['devuelto'])) ?></th><th></th></tr>
      <tr><th colspan="2" align="right">Saldo</th><th align="right"><?= e($usd($factura['saldo'])) ?></th><th></th></tr>
    </tfoot>
  </table>

  <?php if ($pagos !== []): ?>
  <h2 class="h6 mt-3">Pagos</h2>
  <table class="fac">
    <colgroup><col width="18%"><col width="22%"><col width="30%"><col width="15%"><col width="15%"></colgroup>
    <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th>Monto</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach ($pagos as $p): ?>
      <tr>
        <td align="center"><?= e($dmy((string) $p['fecha'])) ?></td>
        <td><?= e((string) $p['metodo']) ?></td>
        <td><?= e((string) $p['referencia']) ?></td>
        <td align="right" class="num"><?= e($usd($p['monto'])) ?></td>
        <td align="center"><?= $p['estado'] === 'anulado' ? 'Anulado' : 'Aplicado' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <table class="firmas" width="100%">
    <colgroup><col width="38%"><col width="24%"><col width="38%"></colgroup>
    <tr>
      <td align="center">
        <div class="firma-linea"></div>
        <div class="firma-lbl">Firma del Cliente</div>
        <div class="firma-sub"><?= e((string) $cli['nombre']) ?><?= ($cli['documento'] ?? '') !== '' ? ' · ' . e((string) $cli['documento']) : '' ?></div>
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
