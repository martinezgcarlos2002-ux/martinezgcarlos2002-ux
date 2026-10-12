<?php
declare(strict_types=1);
/**
 * Cuerpo HTML/CSS de una liquidación (sin imágenes de plantilla ni canvas).
 * @var array<string, mixed> $doc
 * @var string $desde
 * @var string $hasta
 * @var string|null $tasa
 * @var string $emitida
 * @var string $empresaRif
 */
$fecha = $fecha ?? static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
$dias = $dias ?? [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
$tiposPago = $tiposPago ?? PagoProductor::ETIQUETAS;
$conTasa = $tasa !== null && (float) $tasa > 0;
$usd = $usd ?? static fn (mixed $v, int $d = 2): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v), $d);
$bs = $bs ?? static fn (mixed $usdV): string => 'Bs. ' . num((float) $usdV * (float) $tasa);
$p = $doc['productor'];
$pagosDoc = $doc['pagos'] ?? [];
$saldo = (float) $doc['saldo_usd'];
$saldoCls = $saldo > 0 ? 'saldo-positivo' : ($saldo == 0.0 ? 'saldo-cero' : '');
$logoSrc = $logoSrc ?? asset_v(LOGO_EMPRESA);
?>
<div class="doc">
  <div class="doc-header">
    <table class="doc-head-table" width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td class="doc-head-brand">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td width="100" valign="middle">
                <img src="<?= e($logoSrc) ?>" class="logo-box" alt="Logo El Castillo del Queso" width="88" height="88">
              </td>
              <td valign="middle">
                <p class="empresa-nombre">El Castillo del Queso</p>
                <p class="empresa-sub">Productora Artesanal de Quesos Venezolanos</p>
                <p class="empresa-sub">RIF: <?= e($empresaRif) ?></p>
                <div class="periodo-badge">Período: <?= e($fecha($desde)) ?> — <?= e($fecha($hasta)) ?></div>
              </td>
            </tr>
          </table>
        </td>
        <td class="doc-head-meta">
          <div class="doc-title">LIQUIDACIÓN</div>
          <div class="doc-num">Productor #<?= e($doc['numero']) ?></div>
          <div class="doc-num">Emitida: <?= e($fecha(substr($emitida, 0, 10))) ?></div>
          <?php if ($conTasa): ?>
            <div class="doc-num">Tasa: 1 USD = Bs. <?= e(num($tasa)) ?></div>
          <?php endif; ?>
        </td>
      </tr>
    </table>
  </div>

  <div class="doc-body">
    <table class="info-row" width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td width="50%" class="info-box">
          <div class="label">Productor</div>
          <div class="value"><?= e($p['nombre']) ?></div>
          <?php if (!empty($p['documento'])): ?><div class="sub-value">C.I. / RIF: <?= e($p['documento']) ?></div><?php endif; ?>
          <?php if (!empty($p['telefono'])): ?><div class="sub-value">Tel: <?= e($p['telefono']) ?></div><?php endif; ?>
          <?php if (!empty($p['direccion'])): ?><div class="sub-value">Dir: <?= e($p['direccion']) ?></div><?php endif; ?>
        </td>
        <td width="50%" class="info-box">
          <div class="label">Resumen del Período</div>
          <div class="resumen">
            <div>Total entregas: <strong><?= count($doc['entregas']) ?> registros</strong></div>
            <div>Litros suministrados: <strong><?= e(num($doc['litros'], 1)) ?> L</strong></div>
            <div>Precio promedio: <strong><?= $doc['precio_promedio'] !== null ? e($usd($doc['precio_promedio'], 4)) . '/L' : '—' ?></strong></div>
          </div>
        </td>
      </tr>
    </table>

    <?php if ($doc['entregas']): ?>
    <div class="section-title">Detalle de Entregas de Leche</div>
    <table class="tabla-doc" width="100%" cellpadding="0" cellspacing="0">
      <thead>
        <tr>
          <th>#</th>
          <th>Día</th>
          <th>Fecha</th>
          <th class="text-end">Litros</th>
          <th class="text-end">Precio/L</th>
          <th class="text-end">Subtotal USD</th>
          <?php if ($conTasa): ?><th class="text-end">Subtotal Bs.</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($doc['entregas'] as $i => $e): $d = new DateTimeImmutable((string) $e['fecha']); ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td class="dia"><?= e($dias[(int) $d->format('N')]) ?></td>
          <td><?= e($d->format('d/m/Y')) ?></td>
          <td class="text-end"><?= e(num($e['litros'], 1)) ?></td>
          <td class="text-end"><?= e($usd($e['precio_litro_usd'], 4)) ?></td>
          <td class="text-end fw-semibold"><?= e($usd($e['subtotal_usd'])) ?></td>
          <?php if ($conTasa): ?><td class="text-end text-muted"><?= e($bs($e['subtotal_usd'])) ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3">TOTALES</td>
          <td class="text-end"><?= e(num($doc['litros'], 1)) ?> L</td>
          <td></td>
          <td class="text-end"><?= e($usd($doc['facturado_usd'])) ?></td>
          <?php if ($conTasa): ?><td class="text-end"><?= e($bs($doc['facturado_usd'])) ?></td><?php endif; ?>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>

    <?php if ($pagosDoc !== []): ?>
    <div class="section-title">Detalle de Pagos y Deducciones</div>
    <table class="tabla-doc" width="100%" cellpadding="0" cellspacing="0">
      <thead>
        <tr>
          <th>#</th>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>Referencia / descripción</th>
          <th class="text-end">Monto USD</th>
          <?php if ($conTasa): ?><th class="text-end">Monto Bs.</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pagosDoc as $i => $pago): ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= e($fecha((string) $pago['fecha'])) ?></td>
          <td><?= e($tiposPago[(string) $pago['tipo']] ?? (string) $pago['tipo']) ?></td>
          <td><?= e(trim((string) (($pago['referencia'] ?? '') !== '' ? $pago['referencia'] : ($pago['descripcion'] ?? ''))) ?: '—') ?></td>
          <td class="text-end fw-semibold"><?= e($usd($pago['monto_usd'])) ?></td>
          <?php if ($conTasa): ?><td class="text-end text-muted"><?= e($bs($pago['monto_usd'])) ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4">TOTAL PAGADO</td>
          <td class="text-end"><?= e($usd($doc['pagado_usd'])) ?></td>
          <?php if ($conTasa): ?><td class="text-end"><?= e($bs($doc['pagado_usd'])) ?></td><?php endif; ?>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>

    <table class="resumen-wrap" width="100%" cellpadding="0" cellspacing="0"><tr><td width="50%"></td><td width="50%">
      <table class="resumen-box" width="100%" cellpadding="0" cellspacing="0">
        <tr class="resumen-row">
          <td>Total facturado</td>
          <td class="text-end fw-semibold"><?= e($usd($doc['facturado_usd'])) ?></td>
        </tr>
        <tr class="resumen-row">
          <td>Total pagado</td>
          <td class="text-end fw-semibold pagado-verde"><?= e($usd($doc['pagado_usd'])) ?></td>
        </tr>
        <tr class="resumen-row saldo-row <?= e($saldoCls) ?>">
          <td>Saldo (USD)</td>
          <td class="text-end"><?= e($usd($saldo)) ?></td>
        </tr>
      </table>
    </td></tr></table>

    <div class="doc-footer">
      <strong>El Castillo del Queso</strong> · RIF: <?= e($empresaRif) ?> · Quesos Artesanales Venezolanos<br>
      Documento generado por <?= e(APP_NAME) ?> el <?= e((new DateTimeImmutable($emitida))->format('d/m/Y H:i')) ?> · Período <?= e($fecha($desde)) ?> al <?= e($fecha($hasta)) ?>
    </div>
  </div>
</div>
