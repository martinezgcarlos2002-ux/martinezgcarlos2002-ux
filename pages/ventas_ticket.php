<?php
declare(strict_types=1);

/**
 * Ticket POS / SENIAT (80 mm).
 *
 * @var array<string, mixed> $venta
 * @var string $empresa
 * @var string $rif
 * @var string $logo
 * @var array<string, string> $metodos
 */
$v = $venta;
$items = $v['items'] ?? [];
$metodos = $metodos ?? Venta::METODOS_PAGO;
$cliente = trim((string) (($v['cliente_nombre'] ?? '') !== '' ? $v['cliente_nombre'] : ($v['entidad_nombre'] ?? 'CONSUMIDOR FINAL')));
$metodo = $metodos[$v['metodo_pago'] ?? ''] ?? (string) ($v['metodo_pago'] ?? '—');
$fecha = (new DateTimeImmutable((string) $v['fecha']))->format('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($v['numero']) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_v('css/ventas_ticket.css')) ?>">
</head>
<body class="ticket-pos">
<div class="toolbar no-print">
  <button type="button" onclick="window.print()">Imprimir / PDF</button>
  <a href="<?= e(url('ventas_nueva')) ?>">Nueva venta</a>
</div>
<div class="ticket">
  <div class="ticket-head">
    <?php if ($logo !== ''): ?>
      <img src="<?= e($logo) ?>" alt="">
    <?php endif; ?>
    <div class="ticket-emp"><?= e($empresa) ?></div>
    <div class="ticket-rif">RIF: <?= e($rif) ?></div>
    <div>FACTURA DE VENTA</div>
  </div>
  <hr class="ticket-sep">
  <div>Fecha: <?= e($fecha) ?></div>
  <div>Nro.: <?= e($v['numero']) ?></div>
  <div>Cliente: <?= e($cliente !== '' ? $cliente : 'CONSUMIDOR FINAL') ?></div>
  <div>Pago: <?= e($metodo) ?> · <?= e((string) $v['moneda_pago']) ?></div>
  <hr class="ticket-sep">
  <table>
    <thead>
      <tr>
        <th>Cant</th>
        <th>Descripción</th>
        <th class="num">P.U.</th>
        <th class="num">Total</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $it):
        $uSec = Producto::unidadSecundaria(isset($it['unidad_secundaria']) ? (string) $it['unidad_secundaria'] : null);
        $emp = (int) ($it['cantidad_secundaria'] ?? 0);
        $bruto = $it['peso_bruto'] ?? null;
        $taraT = $it['tara_total'] ?? null;
        $catchW = $uSec !== null && $emp > 0;
    ?>
      <?php if ($catchW): ?>
      <tr class="item-cw">
        <td colspan="2">
          <div class="cw-nom"><?= e($it['nombre']) ?></div>
          <div class="cw-det"><?= $emp ?> <?= e($uSec) ?> | Bruto: <?= e(num($bruto !== null && $bruto !== '' ? $bruto : $it['cantidad'], 3)) ?>kg<?php
            if ($taraT !== null && $taraT !== '' && Producto::aMilesimas((string) $taraT) > 0):
          ?> | Tara: <?= e(num($taraT, 3)) ?>kg<?php endif; ?></div>
          <div class="cw-neto">Neto facturado: <?= e(num($it['cantidad'], 3)) ?>kg</div>
        </td>
        <td class="num"><?= e(num($it['precio_unit_usd'])) ?></td>
        <td class="num"><?= e(num($it['subtotal_usd'])) ?></td>
      </tr>
      <?php else: ?>
      <tr>
        <td><?= e(num($it['cantidad'], 3)) ?></td>
        <td><?= e($it['nombre']) ?></td>
        <td class="num"><?= e(num($it['precio_unit_usd'])) ?></td>
        <td class="num"><?= e(num($it['subtotal_usd'])) ?></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <hr class="ticket-sep">
  <div class="ticket-tot">TOTAL USD: $<?= e(num($v['total_usd'])) ?></div>
  <div class="ticket-tot">TOTAL Bs.: <?= e(num($v['total_bs'])) ?></div>
  <div>Tasa: 1 USD = Bs. <?= e(num($v['tasa_bs_por_usd'], 4)) ?></div>
  <hr class="ticket-sep">
  <div class="ticket-foot">
    Gracias por su compra<br>
    Documento emitido por <?= e(APP_NAME) ?><br>
    Operación de lácteos artesanales · Conserve este ticket
  </div>
</div>
</body>
</html>
