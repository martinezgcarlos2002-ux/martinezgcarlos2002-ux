<?php
declare(strict_types=1);

/**
 * Doble reporte imprimible (A4 horizontal):
 *   Hoja 1 · Nómina completa oficial.
 *   Hoja 2 · Pago neto para cajeros: neto menos cuotas y cobranzas a terceros de la semana.
 * @var array{inicio:string, fin:string, tasa:?string, filas:list<array<string, mixed>>, totales:array<string, string>, estado:string} $datos
 * @var string $empresa
 * @var array<string, array{0:string, 1:string}> $columnas
 * @var list<array{nombre:string, cedula:string, total:string, total_bs:?string}> $pago
 */
$celda = static function (mixed $v, string $formato): string {
    return match ($formato) {
        'usd'   => ((float) $v < 0 ? '-$' : '$') . num(abs((float) $v)),
        'usd4'  => '$' . num($v, 4),
        'bs'    => $v === null ? '—' : 'Bs ' . num($v),
        'dias'  => num($v, 1),
        'fecha' => $v !== '' ? date('d/m/Y', strtotime((string) $v)) : '',
        default => (string) $v,
    };
};
$derecha = ['usd', 'usd4', 'bs', 'dias'];
$anchoBase = ['fecha' => 4.6, 'texto' => 5.2, 'usd' => 4.3, 'usd4' => 4.6, 'bs' => 6.4, 'dias' => 3.6];
$anchoCampo = ['nombres' => 8.4, 'apellidos' => 8.4, 'cargo' => 6.6];
$anchos = ['n' => 2.2];
foreach ($columnas as $campo => [, $formato]) {
    $anchos[$campo] = $anchoCampo[$campo] ?? $anchoBase[$formato];
}
$escala = 100 / array_sum($anchos);
$periodo = date('d/m/Y', strtotime($datos['inicio'])) . ' al ' . date('d/m/Y', strtotime($datos['fin']));
$estado = Nomina::ESTADOS[$datos['estado']] ?? $datos['estado'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nómina <?= e(date('d/m/Y', strtotime($datos['inicio']))) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/nomina_pdf.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('empleados', ['tab' => 'nomina', 'semana' => $datos['inicio']])) ?>"><i class="bi bi-arrow-left"></i> Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir"><i class="bi bi-printer"></i> Imprimir / Guardar PDF</button>
</div>

<section class="hoja">
  <header class="d-flex align-items-center gap-3 mb-2">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div class="flex-grow-1">
      <h1 class="h6 mb-0"><?= e($empresa) ?></h1>
      <div class="small">A · Nómina completa · Semana del <?= e($periodo) ?></div>
    </div>
    <div class="small text-end">Tasa BCV: <?= $datos['tasa'] !== null ? e(num($datos['tasa'])) : '—' ?><br>Estado: <?= e($estado) ?></div>
  </header>
  <table class="nomina">
    <colgroup>
      <?php foreach ($anchos as $w): ?><col width="<?= number_format($w * $escala, 3, '.', '') ?>%"><?php endforeach; ?>
    </colgroup>
    <thead>
      <tr>
        <th>N</th>
        <?php foreach ($columnas as [$titulo]): ?><th><?= e($titulo) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($datos['filas'] as $i => $f): ?>
      <tr>
        <td class="text-center"><?= $i + 1 ?></td>
        <?php foreach ($columnas as $campo => [, $formato]): ?>
          <td<?= in_array($formato, $derecha, true) ? ' class="text-end"' : '' ?>><?= e($celda($f[$campo] ?? '', $formato)) ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if ($datos['filas'] === []): ?>
      <tr><td colspan="<?= count($columnas) + 1 ?>" class="text-center">Sin empleados en esta semana.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="6" class="text-end">TOTALES</th>
        <?php foreach (array_slice($columnas, 5, null, true) as $campo => [, $formato]): ?>
          <th class="text-end"><?= e(isset($datos['totales'][$campo]) ? $celda($datos['totales'][$campo], $formato) : '') ?></th>
        <?php endforeach; ?>
      </tr>
    </tfoot>
  </table>
</section>

<section class="hoja salto">
  <header class="d-flex align-items-center gap-3 mb-2">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div class="flex-grow-1">
      <h1 class="h6 mb-0"><?= e($empresa) ?></h1>
      <div class="small">B · Pago neto en caja · Semana del <?= e($periodo) ?></div>
    </div>
    <div class="small text-end">Neto menos cuotas de préstamo<br>y cobranzas a terceros de la semana</div>
  </header>
  <table class="nomina pago mx-auto">
    <colgroup><col width="8%"><col width="50%"><col width="20%"><col width="22%"></colgroup>
    <thead>
      <tr><th>N</th><th>Nombres</th><th>Cédula</th><th>Total a Pagar Neto</th></tr>
    </thead>
    <tbody>
    <?php foreach ($pago as $i => $p): ?>
      <tr>
        <td class="text-center"><?= $i + 1 ?></td>
        <td><?= e($p['nombre']) ?></td>
        <td><?= e($p['cedula']) ?></td>
        <td class="text-end fw-semibold<?= (float) $p['total'] < 0 ? ' text-danger' : '' ?>"><?= e($celda($p['total'], 'usd')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pago === []): ?>
      <tr><td colspan="4" class="text-center">Sin empleados en esta semana.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="3" class="text-end">TOTAL A PAGAR</th><th class="text-end"><?= e($celda($datos['totales']['resto_usd'], 'usd')) ?></th></tr>
    </tfoot>
  </table>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
