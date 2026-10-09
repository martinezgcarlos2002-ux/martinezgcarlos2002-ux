<?php
declare(strict_types=1);

/**
 * Resumen operativo imprimible. Sin style="".
 *
 * @var string $inicio
 * @var string $fin
 * @var string $empresa
 * @var array<string, mixed> $datos
 */
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
$k = $datos['kpis'] ?? [];
$usd = static fn (mixed $n): string => '$' . num($n);
$qty = static function (mixed $n, int $dec = 3): string {
    $t = num($n, $dec);
    $t = rtrim(rtrim($t, '0'), ',');
    return $t === '' ? '0' : $t;
};
$rend = (float) ($k['rendimiento'] ?? 0);
$pl = $datos['pl'] ?? [];
$bal = $datos['balances'] ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Estado de Resultados <?= e($dmy($inicio)) ?> – <?= e($dmy($fin)) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/resumen_pdf.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('resumen', ['inicio' => $inicio, 'fin' => $fin])) ?>">Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir">Imprimir / Guardar PDF</button>
</div>
<section class="hoja">
  <header class="cab">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo" width="48" height="48">
    <div>
      <h1><?= e($empresa) ?></h1>
      <p>Estado de Resultados y balances CxC/CxP · <?= e($dmy($inicio)) ?> al <?= e($dmy($fin)) ?></p>
    </div>
  </header>

  <table class="kpis gerencial" width="100%">
    <colgroup>
      <col width="20%"><col width="20%"><col width="20%"><col width="20%"><col width="20%">
    </colgroup>
    <thead>
      <tr>
        <th>Litros recibidos</th>
        <th>Utilidad</th>
        <th>CxC cartera</th>
        <th>CxP total</th>
        <th>Costo leche</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td align="right"><?= e($qty($k['litros'] ?? 0)) ?> L</td>
        <td align="right"><?= e($usd($pl['utilidad'] ?? $k['utilidad'] ?? 0)) ?></td>
        <td align="right"><?= e($usd($bal['cxc_cartera'] ?? $k['cxc_cartera'] ?? 0)) ?></td>
        <td align="right"><?= e($usd($bal['cxp_total'] ?? $k['cxp_total'] ?? 0)) ?></td>
        <td align="right"><?= e($usd($pl['costo_leche'] ?? $k['costo_leche'] ?? 0)) ?></td>
      </tr>
    </tbody>
  </table>
  <p class="nota">
    Rendimiento (L / kg): <?= e(num($rend, 2)) ?>
    · Margen: <?= e(num($pl['margen_pct'] ?? $k['margen_pct'] ?? 0, 2)) ?>%
    · KG <?= e($qty($k['kg'] ?? 0)) ?>
    · LTS <?= e($qty($k['lts'] ?? 0)) ?>
    · UND <?= e($qty($k['und'] ?? 0, 0)) ?>
    · Valor producción: <?= e($usd($k['valor_produccion'] ?? 0)) ?>
  </p>

  <h2>1. Estado de Resultados</h2>
  <table class="grid" width="100%">
    <thead><tr><th align="left">Concepto</th><th align="right">Monto</th></tr></thead>
    <tbody>
      <tr><td>Ventas facturadas</td><td align="right"><?= e($usd($pl['ingresos_brutos'] ?? $k['facturado'] ?? 0)) ?></td></tr>
      <tr><td>(-) Devoluciones</td><td align="right"><?= e($usd($pl['devoluciones'] ?? $k['devoluciones'] ?? 0)) ?></td></tr>
      <tr><th align="left">Ingresos netos</th><th align="right"><?= e($usd($pl['ingresos_netos'] ?? 0)) ?></th></tr>
      <tr><td>Costo de leche</td><td align="right"><?= e($usd($pl['costo_leche'] ?? 0)) ?></td></tr>
      <tr><td>Fletes / transporte</td><td align="right"><?= e($usd($pl['costo_fletes'] ?? $k['transporte'] ?? 0)) ?></td></tr>
      <tr><th align="left">Total costos operativos</th><th align="right"><?= e($usd($pl['costos_operativos'] ?? 0)) ?></th></tr>
      <tr><th align="left">Utilidad del período</th><th align="right"><?= e($usd($pl['utilidad'] ?? 0)) ?></th></tr>
      <tr><td>Margen de ganancia</td><td align="right"><?= e(num($pl['margen_pct'] ?? $k['margen_pct'] ?? 0, 2)) ?> %</td></tr>
    </tbody>
  </table>

  <h2>2. Balance CxC / CxP</h2>
  <table class="grid" width="100%">
    <thead><tr><th align="left">Cuenta</th><th align="right">Saldo</th></tr></thead>
    <tbody>
      <tr><td>CxC cartera pendiente</td><td align="right"><?= e($usd($bal['cxc_cartera'] ?? 0)) ?></td></tr>
      <tr><td>CxC cartera vencida</td><td align="right"><?= e($usd($bal['cxc_vencida'] ?? 0)) ?></td></tr>
      <tr><td>CxC cobrado en el período</td><td align="right"><?= e($usd($bal['cxc_cobrado_periodo'] ?? $k['cobrado'] ?? 0)) ?></td></tr>
      <tr><td>CxP productores</td><td align="right"><?= e($usd($bal['cxp_productores'] ?? 0)) ?></td></tr>
      <tr><td>CxP fletes sin pagar</td><td align="right"><?= e($usd($bal['cxp_fletes'] ?? 0)) ?></td></tr>
      <tr><th align="left">Total cuentas por pagar</th><th align="right"><?= e($usd($bal['cxp_total'] ?? 0)) ?></th></tr>
    </tbody>
  </table>

  <h2>3. Control de leche</h2>
  <table class="grid" width="100%">
    <thead><tr><th align="left">Indicador</th><th align="right">Valor</th></tr></thead>
    <tbody>
      <tr><td>Litros recibidos (productores)</td><td align="right"><?= e($qty($k['litros'] ?? 0)) ?> L</td></tr>
      <tr><td>Costo de la leche</td><td align="right"><?= e($usd($pl['costo_leche'] ?? 0)) ?></td></tr>
    </tbody>
  </table>

  <h2>4. Producción</h2>
  <table class="grid" width="100%">
    <thead>
      <tr>
        <th align="left">Producto</th>
        <th align="right">Cantidad</th>
        <th align="left">UoM</th>
        <th align="right">Total $</th>
      </tr>
    </thead>
    <tbody>
    <?php if (($datos['produccion'] ?? []) === []): ?>
      <tr><td colspan="4" align="center">Sin producción en el período.</td></tr>
    <?php endif; ?>
    <?php foreach ($datos['produccion'] ?? [] as $p): ?>
      <tr>
        <td><?= e((string) $p['nombre']) ?></td>
        <td align="right"><?= e($qty($p['cantidad'] ?? 0)) ?></td>
        <td><?= e((string) ($p['unidad_medida'] ?? 'KG')) ?></td>
        <td align="right"><?= e($usd($p['total_usd'] ?? 0)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th align="right">Valor producción</th>
        <th align="right" colspan="2"><?= e($qty($k['kg'] ?? 0)) ?> KG</th>
        <th align="right"><?= e($usd($k['valor_produccion'] ?? 0)) ?></th>
      </tr>
    </tfoot>
  </table>

  <h2>5. Ventas y cobranzas</h2>
  <table class="grid" width="100%">
    <thead><tr><th align="left">Concepto</th><th align="right">Monto</th></tr></thead>
    <tbody>
      <tr><td>Facturado</td><td align="right"><?= e($usd($k['facturado'] ?? 0)) ?></td></tr>
      <tr><td>Cobrado</td><td align="right"><?= e($usd($k['cobrado'] ?? 0)) ?></td></tr>
      <tr><td>Devoluciones</td><td align="right"><?= e($usd($k['devoluciones'] ?? 0)) ?></td></tr>
    </tbody>
  </table>
  <table class="grid" width="100%">
    <thead>
      <tr>
        <th align="left">Método de pago</th>
        <th align="right">Movimientos</th>
        <th align="right">Total $</th>
      </tr>
    </thead>
    <tbody>
    <?php if (($datos['cobranzas'] ?? []) === []): ?>
      <tr><td colspan="3" align="center">Sin cobranzas en el período.</td></tr>
    <?php endif; ?>
    <?php foreach ($datos['cobranzas'] ?? [] as $c): ?>
      <tr>
        <td><?= e((string) $c['etiqueta']) ?></td>
        <td align="right"><?= (int) ($c['n'] ?? 0) ?></td>
        <td align="right"><?= e($usd($c['total'] ?? 0)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>6. Logística (fletes)</h2>
  <table class="grid" width="100%">
    <thead><tr><th align="left">Indicador</th><th align="right">Valor</th></tr></thead>
    <tbody>
      <tr><td>Gasto en fletes</td><td align="right"><?= e($usd($k['transporte'] ?? 0)) ?></td></tr>
      <tr><td>Litros recolectados (flete)</td><td align="right"><?= e($qty($k['litros_flete'] ?? 0, 2)) ?> L</td></tr>
    </tbody>
  </table>

  <div class="firmas">
    <div class="firma">
      <div class="linea"></div>
      <div>Gerencia</div>
    </div>
    <div class="firma">
      <div class="linea"></div>
      <div>Auditoría</div>
    </div>
  </div>
</section>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
