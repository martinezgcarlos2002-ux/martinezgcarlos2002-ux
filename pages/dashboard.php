<?php
declare(strict_types=1);

/** @var DateTimeImmutable $semanaInicio */
/** @var DateTimeImmutable $semanaFin */
/** @var list<array{label:string, icon:string, p:string, valor:int}> $operacion */
/** @var array{ingresos:array, egresos:array, balance:array}|null $finanzas */
/** @var array<string, array> $indicadores */

$usd = static fn (array $m): string => '$' . num($m['usd']);
$bs = static fn (array $m): string => 'Bs. ' . ($m['bs'] === null ? '—' : num($m['bs']));

layout_start('Dashboard', 'dashboard');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
  <div>
    <h4 class="dash-title">Resumen operativo</h4>
    <div class="text-muted small">Una vista rápida del movimiento de El Castillo del Queso.</div>
  </div>
  <span class="badge rounded-pill text-bg-light border fw-normal px-3 py-2">
    <i class="bi bi-calendar3"></i>
    Semana activa: <?= e($semanaInicio->format('d/m')) ?> - <?= e($semanaFin->format('d/m/Y')) ?>
  </span>
</div>

<?php if ($operacion): ?>
<h6 class="section-title"><i class="bi bi-grid-3x3-gap-fill"></i> Inventario y operación</h6>
<div class="row g-3 mb-4">
  <?php foreach ($operacion as $t): ?>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card stat-card text-center">
      <div class="card-body">
        <div class="stat-icon mb-2"><i class="bi <?= e($t['icon']) ?>"></i></div>
        <div class="stat-val"><?= e(num($t['valor'], 0)) ?></div>
        <div class="stat-label"><?= e($t['label']) ?></div>
        <a href="<?= e(url($t['p'])) ?>" class="stretched-link stat-link">Ver módulo <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($finanzas !== null): ?>
<h6 class="section-title"><i class="bi bi-cash-stack"></i> Finanzas</h6>
<div class="row g-3 mb-4">
  <?php foreach ([
      ['Ingresos acumulados', 'bi-arrow-down-circle-fill', '',            'text-success', $finanzas['ingresos']],
      ['Egresos acumulados',  'bi-arrow-up-circle-fill',   'accent-red',  'text-danger',  $finanzas['egresos']],
      ['Balance de caja',     'bi-graph-up-arrow',         'accent-navy', $finanzas['balance']['usd'] >= 0 ? 'text-success' : 'text-danger', $finanzas['balance']],
  ] as [$label, $icon, $accent, $tono, $monto]): ?>
  <div class="col-md-4">
    <div class="card stat-card text-center <?= e($accent) ?>">
      <div class="card-body">
        <div class="stat-icon mb-2"><i class="bi <?= e($icon) ?>"></i></div>
        <div class="stat-val <?= e($tono) ?>"><?= e($usd($monto)) ?></div>
        <div class="stat-val-bs"><?= e($bs($monto)) ?></div>
        <div class="stat-label"><?= e($label) ?></div>
        <a href="<?= e(url(modulos()['finanzas']['p'])) ?>" class="stretched-link stat-link">Ver módulo <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($indicadores): ?>
<h6 class="section-title"><i class="bi bi-activity"></i> Indicadores principales</h6>
<div class="row g-3 mb-4">
  <?php if (isset($indicadores['facturas'])): $i = $indicadores['facturas']; ?>
  <div class="col-md-6 col-lg-3">
    <div class="card stat-card text-center accent-amber">
      <div class="card-body">
        <div class="stat-icon mb-2"><i class="bi bi-file-earmark-text-fill"></i></div>
        <div class="stat-label">Facturas por cobrar</div>
        <div class="stat-val"><?= e($usd($i['monto'])) ?></div>
        <div class="stat-val-bs"><?= e($bs($i['monto'])) ?></div>
        <div class="stat-note mt-1"><?= e($i['nota']) ?></div>
        <a href="<?= e(url(modulos()['facturas']['p'])) ?>" class="stretched-link stat-link">Ver módulo <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (isset($indicadores['leche_ayer'])): $i = $indicadores['leche_ayer']; ?>
  <div class="col-md-6 col-lg-3">
    <div class="card stat-card text-center accent-sky">
      <div class="card-body">
        <div class="stat-icon mb-2"><i class="bi bi-droplet-half"></i></div>
        <div class="stat-label">Leche recogida ayer</div>
        <div class="stat-val"><?= e(num($i['litros'], 1)) ?> <small class="fs-6 text-muted">L</small></div>
        <div class="stat-note mt-1"><?= e($i['nota']) ?></div>
        <a href="<?= e(url(modulos()['control_leche']['p'])) ?>" class="stretched-link stat-link">Ver módulo <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (isset($indicadores['gasto_leche'])): $i = $indicadores['gasto_leche']; ?>
  <div class="col-md-6 col-lg-3">
    <div class="card stat-card text-center">
      <div class="card-body">
        <div class="stat-icon mb-2"><i class="bi bi-bar-chart-fill"></i></div>
        <div class="stat-label">Gasto de leche esta semana</div>
        <div class="stat-val"><?= e($usd($i['monto'])) ?></div>
        <div class="stat-val-bs"><?= e($bs($i['monto'])) ?></div>
        <div class="stat-note mt-1"><?= e($i['nota']) ?></div>
        <a href="<?= e(url(modulos()['productores']['p'])) ?>" class="stretched-link stat-link">Ver módulo <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (isset($indicadores['presupuesto'])): $i = $indicadores['presupuesto']; ?>
  <div class="col-md-6 col-lg-3">
    <div class="card stat-card text-center accent-violet">
      <div class="card-body">
        <div class="stat-icon mb-2"><i class="bi bi-person-vcard-fill"></i></div>
        <div class="stat-label">Presupuesto semanal de empleados</div>
        <div class="stat-val"><?= e($usd($i['monto'])) ?></div>
        <div class="stat-val-bs"><?= e($bs($i['monto'])) ?></div>
        <div class="stat-note mt-1"><?= e($i['nota']) ?></div>
        <div class="stat-note fw-semibold">
          Costo diario estimado: <?= e($usd($i['diario'])) ?> · <?= e($bs($i['diario'])) ?>
        </div>
        <a href="<?= e(url(modulos()['empleados']['p'])) ?>" class="stretched-link stat-link">Ver módulo <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
layout_end();
