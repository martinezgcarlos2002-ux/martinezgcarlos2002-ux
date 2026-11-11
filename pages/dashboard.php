<?php
declare(strict_types=1);

/** @var DateTimeImmutable $semanaInicio */
/** @var DateTimeImmutable $semanaFin */
/** @var list<array{label:string, icon:string, p:string, valor:int}> $operacion */
/** @var array{ingresos:array, egresos:array, balance:array}|null $finanzas */
/** @var array<string, array> $indicadores */
/** @var array{categorias:list<string>, semanas:list<array>, totales:array<string, array{cuentas:int, usd:float}>, total:array{cuentas:int, usd:float}}|null $deudasArrastre */

$enlacesDeuda = [
    'Productores' => modulos()['productores']['p'],
    'Nómina'      => modulos()['empleados']['p'],
    'Transporte'  => modulos()['transportistas']['p'],
];

$usd = static fn (array $m): string => '$' . num($m['usd']);
$bs = static fn (array $m): string => 'Bs. ' . ($m['bs'] === null ? '—' : num($m['bs']));

layout_start('Dashboard', 'dashboard');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
  <div>
    <h4 class="dash-title">Resumen operativo</h4>
    <div class="text-muted small">Una vista rápida del movimiento de <?= e(marca()['nombre_empresa']) ?>.</div>
  </div>
  <span class="badge rounded-pill text-bg-light border fw-normal px-3 py-2">
    <i class="bi bi-calendar3"></i>
    Semana activa: <?= e($semanaInicio->format('d/m')) ?> - <?= e($semanaFin->format('d/m/Y')) ?>
  </span>
</div>

<?php if ($deudasArrastre !== null):
    $alerta = $deudasArrastre['total']['usd'] > 0;
    $maxSemana = 0.0;
    foreach ($deudasArrastre['semanas'] as $s) {
        $maxSemana = max($maxSemana, $s['usd']);
    }
?>
<section class="card deudas-arrastre <?= $alerta ? 'deudas-arrastre-alerta' : 'deudas-arrastre-ok' ?> mb-4">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h6 class="section-title mb-1"><i class="bi bi-exclamation-octagon-fill"></i> Deudas arrastradas de semanas cerradas</h6>
        <div class="text-muted small">
          Semanas anteriores al <?= e($semanaInicio->format('d/m/Y')) ?>.
          En productores el monto es el saldo vivo del último ciclo: lo arrastrado no se suma otra vez.
        </div>
      </div>
      <div class="text-end">
        <div class="deudas-total <?= $alerta ? 'deudas-rojo' : 'text-success' ?>"><?= e($usd(['usd' => $deudasArrastre['total']['usd'], 'bs' => null])) ?></div>
        <div class="small text-muted"><?= e((string) $deudasArrastre['total']['cuentas']) ?> cuenta<?= $deudasArrastre['total']['cuentas'] === 1 ? '' : 's' ?> con saldo</div>
      </div>
    </div>

    <div class="row g-2 mb-3">
      <?php foreach ($deudasArrastre['categorias'] as $cat): $t = $deudasArrastre['totales'][$cat]; ?>
      <div class="col-md-4">
        <a class="deudas-chip <?= $t['usd'] > 0 ? 'deudas-chip-alerta' : '' ?>" href="<?= e(url($enlacesDeuda[$cat])) ?>">
          <span><?= e($cat) ?></span>
          <strong class="<?= $t['usd'] > 0 ? 'deudas-rojo' : '' ?>"><?= e($usd(['usd' => $t['usd'], 'bs' => null])) ?></strong>
          <small><?= e((string) $t['cuentas']) ?> cuenta<?= $t['cuentas'] === 1 ? '' : 's' ?></small>
        </a>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($deudasArrastre['semanas'] === []): ?>
      <div class="deudas-vacio"><i class="bi bi-check-circle"></i> No hay saldos pendientes de semanas ya cerradas.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm deudas-tabla mb-0">
        <thead>
          <tr>
            <th>Semana cerrada</th>
            <?php foreach ($deudasArrastre['categorias'] as $cat): ?><th class="text-end"><?= e($cat) ?></th><?php endforeach; ?>
            <th class="text-end">Total</th>
            <th class="deudas-barra-col">Peso</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deudasArrastre['semanas'] as $s): ?>
          <tr>
            <td>
              <?= e($s['etiqueta']) ?>
              <div class="small text-muted"><?= e((string) $s['cuentas']) ?> cuenta<?= $s['cuentas'] === 1 ? '' : 's' ?></div>
            </td>
            <?php foreach ($deudasArrastre['categorias'] as $cat): $c = $s['celdas'][$cat]; ?>
            <td class="text-end <?= $c['usd'] > 0 ? 'deudas-rojo' : 'text-muted' ?>">
              <?= e($usd(['usd' => $c['usd'], 'bs' => null])) ?>
              <?php if ($c['cuentas'] > 0): ?><div class="small"><?= e((string) $c['cuentas']) ?></div><?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td class="text-end fw-semibold <?= $s['usd'] > 0 ? 'deudas-rojo' : '' ?>"><?= e($usd(['usd' => $s['usd'], 'bs' => null])) ?></td>
            <td class="deudas-barra-col">
              <div class="deuda-barra" role="img" aria-label="<?= e($s['usd'] . ' de ' . $maxSemana) ?>">
                <span style="width: <?= e((string) ($maxSemana > 0 ? round($s['usd'] / $maxSemana * 100, 1) : 0)) ?>%"></span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

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
