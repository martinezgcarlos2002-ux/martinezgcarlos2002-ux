<?php
declare(strict_types=1);

/**
 * Factura de servicio del contratista (diseño corporativo + desglose de pagos).
 *
 * @var string $hoy
 * @var string $empresa
 * @var array<string, mixed> $pago
 * @var array<string, mixed> $transportista
 * @var list<array<string, mixed>> $fletes
 * @var list<array<string, mixed>> $rutas
 * @var list<array<string, mixed>> $adelantos
 * @var string $bruto
 * @var string $pagado
 * @var string $neto
 * @var array<string, string> $tiposPago
 */
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
$usd = static fn (mixed $v): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v));
$rutas = $rutas ?? [];
$adelantos = $adelantos ?? [];
$bruto = $bruto ?? ($pago['total_pagar'] ?? '0');
$pagado = $pagado ?? '0';
$neto = $neto ?? $bruto;
$tiposPago = $tiposPago ?? TransporteService::TIPOS_PAGO;
$vehiculos = $transportista['vehiculos'] ?? [];
$nVeh = count($vehiculos);
$placas = [];
foreach ($vehiculos as $v) {
    $p = trim((string) ($v['placa'] ?? ''));
    if ($p !== '') {
        $placas[] = $p;
    }
}
$netoCls = (float) $neto > 0 ? 'neto-deuda' : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Factura transporte <?= (int) $pago['id'] ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/transporte_pdf.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('transportistas')) ?>">Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir">Imprimir / Guardar PDF</button>
</div>
<section class="hoja">
  <header class="doc-header">
    <div class="brand">
      <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
      <div>
        <h1><?= e($empresa) ?></h1>
        <p>Servicio de transporte · Rutas y recolecta</p>
      </div>
    </div>
    <div>
      <div class="doc-title">FACTURA</div>
      <div class="doc-num">Cierre #<?= (int) $pago['id'] ?></div>
      <div class="doc-num">Emitida: <?= e($dmy($hoy)) ?></div>
    </div>
  </header>

  <div class="doc-body">
    <table class="info-row" width="100%">
      <tr>
        <td class="info-box" width="50%">
          <div class="label">Transportista</div>
          <div class="value"><?= e((string) $transportista['nombre']) ?></div>
          <div class="sub"><?= e((string) ($transportista['documento'] ?? '—')) ?></div>
          <div class="sub"><?= e((string) ($transportista['modalidad'] ?? 'individual') === 'cooperativa' ? 'Cooperativa' : 'Individual') ?>
            · <?= $nVeh ?> vehículo<?= $nVeh === 1 ? '' : 's' ?>
            · <?= e($placas !== [] ? implode(', ', $placas) : 'sin placa') ?></div>
        </td>
        <td class="info-box" width="50%">
          <div class="label">Período del cierre</div>
          <div class="value"><?= e($dmy((string) $pago['fecha_inicio'])) ?> — <?= e($dmy((string) $pago['fecha_fin'])) ?></div>
          <div class="sub">Estado: <?= $pago['estado'] === 'pagado' ? 'Pagado' : 'Pendiente' ?></div>
          <div class="sub">Litros recolectados: <?= e(num($pago['total_litros'] ?? 0, 1)) ?> L</div>
        </td>
      </tr>
    </table>

    <div class="section-title">Detalle de rutas y flete</div>
    <table class="grid">
      <thead>
        <tr>
          <th>Ruta</th>
          <th class="text-end">Precio / veh.</th>
          <th class="text-end">Vehículos</th>
          <th class="text-end">Costo semanal</th>
          <th class="text-end">Litros</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rutas === []): ?>
        <tr><td colspan="5" class="text-center">Sin rutas en este cierre.</td></tr>
      <?php endif; ?>
      <?php foreach ($rutas as $r): ?>
        <tr>
          <td><?= e((string) $r['nombre_zona']) ?></td>
          <td class="text-end"><?= e($usd($r['precio_viaje'])) ?></td>
          <td class="text-end"><?= (int) ($r['vehiculos_asignados'] ?? 1) ?></td>
          <td class="text-end"><?= e($usd($r['costo_semanal'] ?? $r['precio_viaje'])) ?></td>
          <td class="text-end"><?= e(num($r['litros'])) ?> L</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="section-title">Detalle de Pagos y Adelantos</div>
    <table class="grid">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>Descripción</th>
          <th class="text-end">Monto</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($adelantos === []): ?>
        <tr><td colspan="4" class="text-center">Sin pagos ni adelantos en esta semana.</td></tr>
      <?php endif; ?>
      <?php foreach ($adelantos as $a): ?>
        <tr>
          <td><?= e($dmy((string) $a['fecha'])) ?></td>
          <td><?= e($tiposPago[(string) $a['tipo']] ?? (string) $a['tipo']) ?></td>
          <td><?= e((string) (($a['descripcion_mercancia'] ?? '') !== '' ? $a['descripcion_mercancia'] : '—')) ?></td>
          <td class="text-end"><?= e($usd($a['monto'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($adelantos !== []): ?>
      <tfoot>
        <tr>
          <td colspan="3">TOTAL ADELANTOS / PAGOS</td>
          <td class="text-end"><?= e($usd($pagado)) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>

    <div class="resumen-box">
      <div class="resumen-row"><span>Total bruto del flete</span><span class="fw-semibold"><?= e($usd($bruto)) ?></span></div>
      <div class="resumen-row"><span>Total de adelantos / pagos</span><span class="pagado-verde">− <?= e($usd($pagado)) ?></span></div>
      <div class="resumen-row neto-row <?= e($netoCls) ?>"><span>Total neto a pagar</span><span><?= e($usd($neto)) ?></span></div>
    </div>
  </div>
</section>
<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
