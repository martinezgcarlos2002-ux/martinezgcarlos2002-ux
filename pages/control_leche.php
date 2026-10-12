<?php
declare(strict_types=1);

/**
 * Control de Leche: consolidado global diario/semanal (ControlLecheController::index) y costos de producción.
 * Los costos se calculan en el servidor (control_leche_costos_calcular) y se guardan con control_leche_costos_guardar.
 *
 * @var 'diaria'|'semanal'|'produccion' $vista
 * @var string $desde
 * @var string $hasta
 * @var int $productorId
 * @var list<array{id:int, nombre:string, precio_litro_usd:string}> $productores
 * @var list<array{id:int, nombre:string}> $transportistas
 * @var array{litros:float, monto:float, dias:int, promedio:float, registros:int} $kpis
 * @var list<array<string, mixed>> $dias
 * @var list<array<string, mixed>> $semanas
 * @var array<string, mixed> $costos
 * @var list<array{nombre:string, litros_ref:string, kg_ref:string, litros_dia:string, sal:string, empaque:string, otros:string}> $formulas
 * @var bool $puedeCrear
 * @var bool $puedeEditar
 */
$usd = static fn (mixed $v, int $d = 2): string => '$' . num($v, $d);
$guion = static fn (mixed $v, int $d = 3): string => (float) $v > 0 ? num($v, $d) : '—';
$guionUsd = static fn (mixed $v): string => (float) $v > 0 ? '$' . num($v) : '—';

$enlace = static function (string $vistaDestino, ?string $d = null, ?string $h = null) use ($desde, $hasta, $productorId): string {
    return url('control_leche', [
        'vista' => $vistaDestino,
        'desde' => $d ?? $desde,
        'hasta' => $h ?? $hasta,
        'pid'   => $productorId,
    ]);
};

$hoy = new DateTimeImmutable('today');
$iso = (int) $hoy->format('N');
$lunes = $hoy->modify('-' . ($iso - 1) . ' days');
$atajos = [
    'Hoy'          => [$hoy->format('Y-m-d'), $hoy->format('Y-m-d')],
    'Esta semana'  => [$lunes->format('Y-m-d'), $lunes->modify('+6 days')->format('Y-m-d')],
    'Este mes'     => [$hoy->modify('first day of this month')->format('Y-m-d'), $hoy->format('Y-m-d')],
    'Mes anterior' => [
        $hoy->modify('first day of last month')->format('Y-m-d'),
        $hoy->modify('last day of last month')->format('Y-m-d'),
    ],
    'Últimos 30 d' => [$hoy->modify('-30 days')->format('Y-m-d'), $hoy->format('Y-m-d')],
    'Últimos 90 d' => [$hoy->modify('-90 days')->format('Y-m-d'), $hoy->format('Y-m-d')],
];

$recibidos = (float) $costos['litros_recibidos'];
$costoLeche = (float) $costos['costo_leche'];
$costoLitro = $recibidos > 0 ? $costoLeche / $recibidos : 0.0;
$gastoInicial = 0.0;

$detalleTabla = static function (array $detalle, bool $conFecha) use ($usd, $guion, $puedeEditar): void {
    ?>
    <div class="table-responsive">
    <table class="table table-sm table-navy mb-0">
      <thead>
        <tr>
          <?php if ($conFecha): ?><th>Fecha</th><?php endif; ?>
          <th>Productor</th>
          <th class="text-end">Litros</th>
          <th class="text-end">Precio/L</th>
          <th class="text-end">Subtotal $</th>
          <th class="text-end">Cuajada kg</th>
          <th>Obs.</th>
          <?php if ($puedeEditar): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($detalle as $dr):
          $fdt = new DateTimeImmutable((string) $dr['fecha']);
      ?>
        <tr>
          <?php if ($conFecha): ?><td><?= e($fdt->format('d/m/Y')) ?></td><?php endif; ?>
          <td class="fw-semibold"><?= e($dr['productor']) ?></td>
          <td class="text-end num text-primary fw-semibold"><?= e(num($dr['litros'], 3)) ?></td>
          <td class="text-end num text-muted"><?= e($usd($dr['precio'], 4)) ?></td>
          <td class="text-end num fw-semibold"><?= e($usd($dr['subtotal'])) ?></td>
          <td class="text-end num"><?= e($guion($dr['cuajada'])) ?></td>
          <td class="text-muted"><?= e($dr['observaciones'] ?: '—') ?></td>
          <?php if ($puedeEditar): ?>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-outline-warning"
                    data-bs-toggle="modal" data-bs-target="#modalEntrega"
                    data-id="<?= (int) $dr['id'] ?>"
                    data-fecha="<?= e((string) $dr['fecha']) ?>"
                    data-productor="<?= (int) $dr['productor_id'] ?>"
                    data-litros="<?= e((string) $dr['litros']) ?>"
                    data-precio="<?= e((string) $dr['precio']) ?>"
                    data-cuajada="<?= e((string) $dr['cuajada']) ?>"
                    data-traslado="<?= e((string) $dr['traslado']) ?>"
                    data-obs="<?= e((string) ($dr['observaciones'] ?? '')) ?>"
                    aria-label="Editar entrega de <?= e($dr['productor']) ?>">
              <i class="bi bi-pencil"></i>
            </button>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php
};

layout_start('Control de Leche', 'control_leche');
?>
<link rel="stylesheet" href="<?= e(asset_v('css/control_diario.css')) ?>">
<?php
$fechaHoja = $fechaHoja ?? $hasta;
$filasHoja = $filasHoja ?? [];
$seccionesHoja = $seccionesHoja ?? [];
$mermaHoja = $mermaHoja ?? [];
$totalRecibido = $totalRecibido ?? 0.0;
$puedeEditarHoja = $puedeCrear ?? false;
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h5 class="mb-0 text-navy"><i class="bi bi-droplet-half"></i> Control de Leche</h5>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-planilla" target="_blank" rel="noopener"
       href="<?= e(url('control_leche_planilla', ['fecha' => $hasta])) ?>">
      <i class="bi bi-printer"></i> Imprimir Planilla de Recolección
    </a>
    <?php if ($puedeCrear): ?>
    <button type="button" class="btn btn-semanal" data-bs-toggle="modal" data-bs-target="#modalEntrega" data-modo="nuevo">
      <i class="bi bi-plus-lg"></i> Nueva Entrega
    </button>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-tabs nav-leche mb-3">
  <li class="nav-item">
    <a class="nav-link<?= $vista === 'diaria' ? ' active' : '' ?>" href="<?= e($enlace('diaria')) ?>">
      <i class="bi bi-calendar-day"></i> Vista Diaria
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?= $vista === 'semanal' ? ' active' : '' ?>" href="<?= e($enlace('semanal')) ?>">
      <i class="bi bi-calendar-week"></i> Vista Semanal
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link<?= $vista === 'produccion' ? ' active' : '' ?>" href="<?= e($enlace('produccion')) ?>">
      <i class="bi bi-gear"></i> Producción
    </a>
  </li>
</ul>

<?php if ($vista !== 'produccion'): ?>
<form class="mb-3" method="get" action="<?= e(base_path() . 'index.php') ?>">
  <input type="hidden" name="p" value="control_leche">
  <input type="hidden" name="vista" value="<?= e($vista) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label mb-1" for="fDesde">Desde</label>
      <input type="date" class="form-control" id="fDesde" name="desde" value="<?= e($desde) ?>" required>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label mb-1" for="fHasta">Hasta</label>
      <input type="date" class="form-control" id="fHasta" name="hasta" value="<?= e($hasta) ?>" required>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label mb-1" for="fProductor">Productor</label>
      <select class="form-select" id="fProductor" name="pid">
        <option value="0">— Todos los productores —</option>
        <?php foreach ($productores as $pl): ?>
        <option value="<?= (int) $pl['id'] ?>"<?= $productorId === (int) $pl['id'] ? ' selected' : '' ?>><?= e($pl['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-2">
      <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrar</button>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-1 mt-2">
    <?php foreach ($atajos as $etiqueta => [$d1, $d2]): ?>
    <a class="btn btn-sm <?= $desde === $d1 && $hasta === $d2 ? 'btn-secondary' : 'btn-outline-secondary' ?>"
       href="<?= e($enlace($vista, $d1, $d2)) ?>"><?= e($etiqueta) ?></a>
    <?php endforeach; ?>
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card kpi-leche h-100 text-center"><div class="card-body py-3">
      <div class="val"><?= e(num($kpis['litros'], 1)) ?> L</div>
      <div class="lbl">Total litros período</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card kpi-leche h-100 text-center"><div class="card-body py-3">
      <div class="val monto"><?= e($usd($kpis['monto'])) ?></div>
      <div class="lbl">Monto total período</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card kpi-leche h-100 text-center"><div class="card-body py-3">
      <div class="val"><?= (int) $kpis['dias'] ?></div>
      <div class="lbl">Días con entregas</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card kpi-leche h-100 text-center"><div class="card-body py-3">
      <div class="val"><?= e(num($kpis['promedio'], 1)) ?> L</div>
      <div class="lbl">Promedio diario</div>
    </div></div>
  </div>
</div>

<?php if ($vista === 'diaria'): ?>
<form class="d-flex flex-wrap align-items-end gap-2 mb-2 no-print" method="get" action="<?= e(base_path() . 'index.php') ?>">
  <input type="hidden" name="p" value="control_leche">
  <input type="hidden" name="vista" value="diaria">
  <div>
    <label class="form-label mb-1" for="fechaHoja">Día de la planilla</label>
    <input type="date" class="form-control" id="fechaHoja" name="fecha" value="<?= e($fechaHoja) ?>" required>
  </div>
  <input type="hidden" name="desde" id="hojaDesde" value="<?= e($fechaHoja) ?>">
  <input type="hidden" name="hasta" id="hojaHasta" value="<?= e($fechaHoja) ?>">
  <button type="submit" class="btn btn-outline-primary">Ver día</button>
  <a class="btn btn-planilla" target="_blank" rel="noopener" href="<?= e(url('control_leche_planilla', ['fecha' => $fechaHoja])) ?>">
    <i class="bi bi-printer"></i> Imprimir planilla
  </a>
  <?php if ($puedeEditarHoja): ?>
  <button type="submit" class="btn btn-success" form="formHojaDia">💾 Guardar Cambios</button>
  <?php endif; ?>
</form>
<form id="formHojaDia" method="post" action="<?= e(url('control_leche_diario')) ?>" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="fecha" value="<?= e($fechaHoja) ?>">
  <?php require ADARA_ROOT . '/pages/partials/control_diario_hoja.php'; ?>
  <?php if ($puedeEditarHoja): ?>
  <div class="d-flex justify-content-end mb-3 no-print">
    <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Guardar resumen del día</button>
  </div>
  <?php endif; ?>
</form>

<?php else: ?>
<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-calendar-week"></i> Resumen semanal de entregas</strong>
    <span class="text-muted small"><?= count($semanas) ?> semanas | <?= e(num($kpis['litros'], 1)) ?> L totales</span>
  </div>
  <div class="table-responsive panel-leche">
    <table class="table table-sm table-navy align-middle mb-0">
      <thead>
        <tr>
          <th>Semana</th>
          <th class="text-end">Litros</th>
          <th class="text-end">Monto $</th>
          <th class="text-end">Cuajada kg</th>
          <th class="text-end">Traslado $</th>
          <th class="text-end">Productores</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($semanas as $sem):
          $rowId = 'sem-' . str_replace('-', '', (string) $sem['inicio']);
          $nProds = count($sem['productores']);
      ?>
        <tr class="fila-grupo" data-bs-toggle="collapse" data-bs-target="#<?= e($rowId) ?>" aria-expanded="false">
          <td>
            <strong><?= e($sem['etiqueta']) ?></strong>
            <span class="text-muted small">(Sem. <?= (int) $sem['numero'] ?>)</span>
          </td>
          <td class="text-end num fw-bold text-primary"><?= e(num($sem['litros'], 3)) ?> L</td>
          <td class="text-end num fw-bold"><?= e($usd($sem['monto'])) ?></td>
          <td class="text-end num"><?= e($guion($sem['cuajada'])) ?></td>
          <td class="text-end num text-danger"><?= e($guionUsd($sem['traslado'])) ?></td>
          <td class="text-end js-dropdown">
            <select class="form-select form-select-sm d-inline-block w-auto" aria-label="Productores de la semana">
              <option value=""><?= $nProds ?> prod<?= $nProds === 1 ? '' : 's' ?></option>
              <?php foreach ($sem['productores'] as $nombre): ?>
              <option><?= e($nombre) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="text-end"><i class="bi bi-chevron-down"></i></td>
        </tr>
        <tr class="collapse" id="<?= e($rowId) ?>">
          <td colspan="7" class="p-0"><?php $detalleTabla($sem['detalle'], true); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td>TOTAL PERÍODO</td>
          <td class="text-end"><?= e(num($kpis['litros'], 3)) ?> L</td>
          <td class="text-end"><?= e($usd($kpis['monto'])) ?></td>
          <td class="text-end"><?= e(num(array_sum(array_column($semanas, 'cuajada')), 3)) ?> kg</td>
          <td class="text-end"><?= e($usd(array_sum(array_column($semanas, 'traslado')))) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card costos-card">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
      <h5 class="cc-title mb-1">Producción — Costos del día y precio de venta</h5>
      <?php if ($costos['guardado']): ?>
      <span class="badge text-bg-success"><i class="bi bi-check2-circle"></i> Fotografía guardada <?= e(date('d/m/Y H:i', strtotime((string) $costos['guardado_en']))) ?></span>
      <?php else: ?>
      <span class="badge text-bg-secondary">Sin guardar para este día</span>
      <?php endif; ?>
    </div>
    <p class="cc-note mb-3">El costo de la leche es la leche procesada al precio promedio ponderado del día (costo de las entregas ÷ litros recibidos). Se suma a los gastos del día y el total se reparte entre los kg esperados de cada queso según su rendimiento. El cálculo lo hace el servidor.</p>
    <div class="alert alert-danger d-none py-2 small" id="cc_errores" role="alert"></div>

    <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
      <form class="d-flex flex-wrap align-items-end gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
        <input type="hidden" name="p" value="control_leche">
        <input type="hidden" name="vista" value="produccion">
        <div>
          <label class="form-label mb-1" for="prodFecha">Día de producción</label>
          <input type="date" class="form-control" id="prodFecha" name="prod_fecha" value="<?= e((string) $costos['fecha']) ?>" required>
        </div>
        <button type="submit" class="btn btn-costo-sec">Filtrar</button>
      </form>
      <div>
        <label class="form-label mb-1" for="cc_procesada">Leche procesada</label>
        <div class="input-group">
          <input type="number" class="form-control inp-cc" id="cc_procesada" min="0.001" step="0.001" value="<?= e((string) (float) $costos['litros_procesada']) ?>">
          <span class="input-group-text">L</span>
        </div>
        <?php if (isset($costos['litros_neta'])): ?>
        <div class="form-text">Neta del control semanal: <?= e(num($costos['litros_neta'], 1)) ?> L (recibida menos ventas, cortesías, vales y faltante).</div>
        <?php endif; ?>
      </div>
      <div>
        <label class="form-label mb-1" for="cc_costo_leche">Costo total de la leche</label>
        <div class="input-group">
          <span class="input-group-text">$</span>
          <input type="number" class="form-control inp-cc" id="cc_costo_leche" name="costo_leche" min="0" step="0.01" value="<?= e(number_format($costoLeche, 2, '.', '')) ?>" readonly>
        </div>
      </div>
      <span class="cc-note pb-2">Recibida: <strong><?= e(num($recibidos, 1)) ?> L</strong></span>
      <input type="hidden" id="cc_recibida" value="<?= e(number_format($recibidos, 3, '.', '')) ?>">
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header cc-head">Gastos del día</div>
          <div class="table-responsive">
            <table class="table mb-0 align-middle">
              <tbody>
                <tr class="cc-auto">
                  <td>Total leche restante <span class="fw-normal cc-note">(recibida − procesada)</span></td>
                  <td class="text-end text-nowrap">
                    <input type="number" class="form-control form-control-sm d-inline-block inp-cc text-end" id="cc_restante" value="<?= e(number_format((float) $costos['restante'], 1, '.', '')) ?>" readonly> L
                  </td>
                </tr>
                <tr class="cc-auto">
                  <td>Costo de la leche procesada <span class="fw-normal cc-note">(procesada × costo por litro)</span></td>
                  <td class="text-end"><strong id="cc_costo_procesada">—</strong></td>
                </tr>
                <tr class="cc-auto">
                  <td>Costo real por litro de leche</td>
                  <td class="text-end"><strong id="cc_costo_litro"><?= e($usd($costoLitro)) ?></strong> / L</td>
                </tr>
                <tr>
                  <td>Nómina (pago de empleados) <span class="cc-note">(autom.)</span></td>
                  <td class="text-end"><div class="input-group input-group-sm justify-content-end"><span class="input-group-text">$</span><input type="number" class="form-control inp-cc js-gasto" id="cc_nomina" name="nomina" min="0" step="0.01" value="<?= e((string) $costos['nomina']) ?>" placeholder="0"></div></td>
                </tr>
                <tr>
                  <td>Costo personal extra</td>
                  <td class="text-end"><div class="input-group input-group-sm justify-content-end"><span class="input-group-text">$</span><input type="number" class="form-control inp-cc js-gasto" id="cc_personal" name="personal_extra" min="0" step="0.01" value="<?= e((string) $costos['personal']) ?>" placeholder="0"></div></td>
                </tr>
                <tr>
                  <td>Costo transporte <span class="cc-note">(traslado autom.)</span></td>
                  <td class="text-end"><div class="input-group input-group-sm justify-content-end"><span class="input-group-text">$</span><input type="number" class="form-control inp-cc js-gasto" id="cc_transporte" name="transporte" min="0" step="0.01" value="<?= (float) $costos['traslado'] > 0 ? e(number_format((float) $costos['traslado'], 2, '.', '')) : '' ?>" placeholder="0"></div></td>
                </tr>
                <tr>
                  <td>Costo operativo</td>
                  <td class="text-end"><div class="input-group input-group-sm justify-content-end"><span class="input-group-text">$</span><input type="number" class="form-control inp-cc js-gasto" id="cc_operativo" name="operativo" min="0" step="0.01" value="<?= e((string) $costos['operativo']) ?>" placeholder="0"></div></td>
                </tr>
                <tr>
                  <td>Otros gastos</td>
                  <td class="text-end"><div class="input-group input-group-sm justify-content-end"><span class="input-group-text">$</span><input type="number" class="form-control inp-cc js-gasto" id="cc_otros" name="otros" min="0" step="0.01" value="<?= e((string) $costos['otros']) ?>" placeholder="0"></div></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="cc-note px-3 py-2 mb-0">La nómina diaria se calcula con los sueldos semanales de los empleados activos ÷ 7. Si trabajas otra cantidad de días, edita el monto.</p>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header cc-head">Resumen del día</div>
          <div class="card-body">
            <label class="form-label" for="cc_margen">Margen de ganancia deseado (%)</label>
            <div class="input-group mb-3">
              <input type="number" class="form-control" id="cc_margen" min="0" max="<?= CostoProduccion::MARGEN_MAX ?>" step="0.01" value="<?= e((string) (float) $costos['margen']) ?>">
              <span class="input-group-text">%</span>
            </div>
            <div class="cc-total">
              <span>GASTO TOTAL DIARIO EN PRODUCCIÓN</span>
              <strong id="cc_gasto_total"><?= e($usd($gastoInicial)) ?></strong>
            </div>
            <p class="cc-note mt-2 mb-0">Leche procesada + nómina + todos los gastos del día.</p>
            <div class="d-flex justify-content-between small mt-2"><span class="text-muted">Kg esperados</span><strong id="cc_kg_total">—</strong></div>
            <div class="d-flex justify-content-between small"><span class="text-muted">Costo común por kg</span><strong id="cc_costo_comun">—</strong></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header cc-head d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>Lista de precios por tipo de queso (margen <span id="cc_margen_lbl"><?= e((string) $costos['margen']) ?></span>%)</span>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalFormulas">📖 Asistente de Fórmulas Técnicas</button>
      </div>
      <div class="card-body">
        <p class="cc-note">Rendimiento: cuántos kg salen por cada cantidad de litros. Litros del día: cuánta leche procesada va a ese queso (vacío en todos = reparto parejo; si lo llenas, llénalo en todos y que sume la leche procesada). Sal, empaque y otros son montos del día propios de ese queso. Precio de venta = costo por kg × (1 + margen).</p>
        <div class="table-responsive">
          <table class="table table-sm table-precio align-middle mb-2">
            <thead>
              <tr>
                <th>Tipo de queso</th>
                <th>Por cada (L)</th>
                <th>Salen (kg)</th>
                <th>Litros del día</th>
                <th>Sal ($)</th>
                <th>Empaque ($)</th>
                <th>Otros ($)</th>
                <th>Kg del día</th>
                <th>Costo por kg</th>
                <th>Precio de venta x kg</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="cc_price_body">
            <?php foreach ($formulas as $f):
                $v = static fn (string $k): string => (float) $f[$k] > 0 ? (string) (float) $f[$k] : '';
            ?>
              <tr>
                <td><input type="text" class="form-control form-control-sm" data-campo="nombre" value="<?= e($f['nombre']) ?>" maxlength="80" aria-label="Tipo de queso"></td>
                <td><div class="input-group input-group-sm"><input type="number" class="form-control" data-campo="litros_ref" min="0.001" step="0.001" value="<?= e($v('litros_ref')) ?>" aria-label="Litros de referencia"><span class="input-group-text">L</span></div></td>
                <td><div class="input-group input-group-sm"><input type="number" class="form-control" data-campo="kg_ref" min="0.001" step="0.001" value="<?= e($v('kg_ref')) ?>" aria-label="Kg de referencia"><span class="input-group-text">kg</span></div></td>
                <td><div class="input-group input-group-sm"><input type="number" class="form-control" data-campo="litros_dia" min="0.001" step="0.001" placeholder="parejo" value="<?= e($v('litros_dia')) ?>" aria-label="Litros del día"><span class="input-group-text">L</span></div></td>
                <td><input type="number" class="form-control form-control-sm" data-campo="sal" min="0" step="0.01" placeholder="0" value="<?= e($v('sal')) ?>" aria-label="Sal"></td>
                <td><input type="number" class="form-control form-control-sm" data-campo="empaque" min="0" step="0.01" placeholder="0" value="<?= e($v('empaque')) ?>" aria-label="Empaque"></td>
                <td><input type="number" class="form-control form-control-sm" data-campo="otros" min="0" step="0.01" placeholder="0" value="<?= e($v('otros')) ?>" aria-label="Otros"></td>
                <td class="out-kg text-nowrap">0 kg</td>
                <td class="out-costo text-nowrap">$0,00</td>
                <td class="out-precio text-nowrap">$0,00</td>
                <td><button type="button" class="btn btn-sm btn-outline-danger cc-delrow" aria-label="Quitar tipo de queso"><i class="bi bi-x-lg"></i></button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="button" class="btn btn-sm btn-costo-sec" id="cc_add_row"><i class="bi bi-plus-lg"></i> Agregar tipo de queso</button>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header cc-head">Factura — precios de venta x kg</div>
      <div class="card-body">
        <div class="cc-fact-box mb-3">
          <div class="text-uppercase small cc-note">Margen de ganancia</div>
          <div class="pct"><span id="cc_margen_big"><?= e((string) $costos['margen']) ?></span>%</div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
          <?php if ($puedeCrear): ?>
          <button type="button" class="btn btn-costo" id="cc_guardar"><i class="bi bi-save"></i> Guardar fotografía del día</button>
          <?php endif; ?>
          <button type="button" class="btn btn-costo-sec" id="cc_imprimir"><i class="bi bi-printer"></i> Imprimir factura</button>
          <span class="small fw-semibold" id="cc_guardar_msg"></span>
        </div>
        <p class="cc-note">Lista para imprimir: nombre del producto y su precio de venta por kg.</p>
        <table class="table mb-0">
          <tbody id="cc_factura">
            <tr><td class="text-muted">Sin productos con precio</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<template id="tplQueso">
  <tr>
    <td><input type="text" class="form-control form-control-sm" data-campo="nombre" value="" maxlength="80" aria-label="Tipo de queso"></td>
    <td><div class="input-group input-group-sm"><input type="number" class="form-control" data-campo="litros_ref" min="0.001" step="0.001" value="10" aria-label="Litros de referencia"><span class="input-group-text">L</span></div></td>
    <td><div class="input-group input-group-sm"><input type="number" class="form-control" data-campo="kg_ref" min="0.001" step="0.001" value="1" aria-label="Kg de referencia"><span class="input-group-text">kg</span></div></td>
    <td><div class="input-group input-group-sm"><input type="number" class="form-control" data-campo="litros_dia" min="0.001" step="0.001" placeholder="parejo" aria-label="Litros del día"><span class="input-group-text">L</span></div></td>
    <td><input type="number" class="form-control form-control-sm" data-campo="sal" min="0" step="0.01" placeholder="0" aria-label="Sal"></td>
    <td><input type="number" class="form-control form-control-sm" data-campo="empaque" min="0" step="0.01" placeholder="0" aria-label="Empaque"></td>
    <td><input type="number" class="form-control form-control-sm" data-campo="otros" min="0" step="0.01" placeholder="0" aria-label="Otros"></td>
    <td class="out-kg text-nowrap">0 kg</td>
    <td class="out-costo text-nowrap">$0,00</td>
    <td class="out-precio text-nowrap">$0,00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger cc-delrow" aria-label="Quitar tipo de queso"><i class="bi bi-x-lg"></i></button></td>
  </tr>
</template>

<div class="modal fade" id="modalFormulas" tabindex="-1" aria-labelledby="tituloFormulas" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloFormulas">📖 Asistente de Fórmulas Técnicas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">Guía de solo lectura. Aplicar referencia copia litros y kg en la lista de precios cuando el manual los indica. No guarda nada: puedes corregir los números antes de guardar la fotografía del día.</p>
        <p class="small fw-semibold mb-2" id="formulaAviso" hidden></p>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Queso</th>
                <th>Referencia técnica</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="fw-semibold">Queso Blanco Llanero</td>
                <td>Rendimiento: 20 L = 3 kg. Cuajo líquido: 40 ml × 100 L. Cloruro de calcio: 20 g × 100 L.</td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary btn-aplicar-ref" data-clave="llanero" data-nombre="Queso Blanco Llanero" data-litros="20" data-kg="3">Aplicar referencia</button></td>
              </tr>
              <tr>
                <td class="fw-semibold">Queso Guayanés / Telita</td>
                <td>Rendimiento estandarizado. Cuajo: 8–10 ml × 100 L. pH de corte: 5.2 – 5.3.</td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary btn-aplicar-ref" data-clave="guayanes" data-nombre="Queso Guayanés / Telita">Aplicar referencia</button></td>
              </tr>
              <tr>
                <td class="fw-semibold">Ricotta</td>
                <td>1 L de vinagre por cada 100 L de suero, a 85–90 °C.</td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary btn-aplicar-ref" data-clave="ricotta" data-nombre="Ricotta">Aplicar referencia</button></td>
              </tr>
              <tr>
                <td class="fw-semibold">Mozzarella</td>
                <td>Cultivo TPF: 0.8 g por cada 20 L de leche.</td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary btn-aplicar-ref" data-clave="mozzarella" data-nombre="Mozzarella">Aplicar referencia</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalEntrega" tabindex="-1" aria-labelledby="tituloEntrega" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content modal-entrega">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloEntrega"><i class="bi bi-droplet-fill"></i> Nueva Entrega de Leche</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form id="frmEntrega" method="post" action="<?= e(url('control_leche_entrega')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="volver" value="control_leche">
        <div class="modal-body">
          <p class="small text-muted mb-3" id="eAyuda">Si el productor ya tiene entrega ese día, se sobrescriben los litros. El precio se congela con el vigente del productor.</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="eProductor">Productor</label>
              <select class="form-select" id="eProductor" name="productor_id" required>
                <option value="">— Seleccionar —</option>
                <?php foreach ($productores as $pl): ?>
                <option value="<?= (int) $pl['id'] ?>" data-precio="<?= e((string) $pl['precio_litro_usd']) ?>"><?= e($pl['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="eFecha">Fecha</label>
              <input type="date" class="form-control" id="eFecha" name="fecha" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="eLitros">Litros</label>
              <div class="input-group">
                <input type="number" class="form-control" id="eLitros" name="litros" min="0.001" max="100000" step="0.001" required>
                <span class="input-group-text">L</span>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="ePrecio">Precio / Litro $ <span class="text-muted">(del productor)</span></label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" class="form-control" id="ePrecio" step="0.0001" readonly tabindex="-1">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="eSubtotal">Subtotal $ <span class="text-muted">(calculado)</span></label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="text" class="form-control" id="eSubtotal" value="0.00" readonly>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="eCuajada">Cuajada kg <span class="text-muted">(opc.)</span></label>
              <div class="input-group">
                <input type="number" class="form-control" id="eCuajada" name="cuajada_kg" min="0" step="0.001" placeholder="0.000">
                <span class="input-group-text">kg</span>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="eTraslado">Gasto traslado $ <span class="text-muted">(opc.)</span></label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" class="form-control" id="eTraslado" name="traslado_usd" min="0" step="0.01" placeholder="0.00">
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="eObs">Observaciones</label>
              <input type="text" class="form-control" id="eObs" name="observaciones" maxlength="255" placeholder="Notas...">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button>
          <button type="submit" class="btn btn-semanal"><i class="bi bi-save"></i> Guardar Entrega</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var fh = document.getElementById('fechaHoja');
  if (fh) {
    fh.addEventListener('change', function () {
      var d = document.getElementById('hojaDesde');
      var h = document.getElementById('hojaHasta');
      if (d) d.value = fh.value;
      if (h) h.value = fh.value;
    });
  }
  document.querySelectorAll('.js-dropdown').forEach(function (el) {
    el.addEventListener('click', function (ev) { ev.stopPropagation(); });
  });

  var modal = document.getElementById('modalEntrega');
  var form = document.getElementById('frmEntrega');
  function num(id) { return document.getElementById(id); }
  function calcEntrega() {
    var l = parseFloat(num('eLitros').value) || 0;
    var p = parseFloat(num('ePrecio').value) || 0;
    num('eSubtotal').value = (l * p).toFixed(2);
  }
  num('eLitros').addEventListener('input', calcEntrega);
  num('eProductor').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    num('ePrecio').value = opt && opt.dataset.precio ? opt.dataset.precio : '';
    calcEntrega();
  });
  if (modal) {
    modal.addEventListener('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      var editar = !!btn && !!btn.getAttribute('data-id');
      var dato = function (k) { return editar ? (btn.getAttribute('data-' + k) || '') : ''; };
      form.reset();
      num('eFecha').value = editar ? dato('fecha') : '<?= e(date('Y-m-d')) ?>';
      num('eProductor').value = dato('productor');
      num('eLitros').value = dato('litros');
      num('ePrecio').value = dato('precio');
      num('eCuajada').value = dato('cuajada');
      num('eTraslado').value = dato('traslado');
      num('eObs').value = dato('obs');
      num('eFecha').readOnly = editar;
      num('eProductor').classList.toggle('pe-none', editar);
      num('eProductor').tabIndex = editar ? -1 : 0;
      if (!editar && num('eProductor').value) {
        num('eProductor').dispatchEvent(new Event('change'));
      }
      document.getElementById('tituloEntrega').innerHTML = editar
        ? '<i class="bi bi-pencil"></i> Editar Entrega de Leche'
        : '<i class="bi bi-droplet-fill"></i> Nueva Entrega de Leche';
      calcEntrega();
    });
  }

  var body = document.getElementById('cc_price_body');
  if (!body) return;

  var URL_CALCULAR = <?= json_encode(url('control_leche_costos_calcular'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  var URL_GUARDAR = <?= json_encode(url('control_leche_costos_guardar'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  var CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>;
  var errores = document.getElementById('cc_errores');
  var msg = document.getElementById('cc_guardar_msg');

  function money(v, dec) {
    var n = parseFloat(v);
    if (!isFinite(n)) n = 0;
    var p = Math.abs(n).toFixed(dec || 2).split('.');
    p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return (n < 0 ? '-' : '') + '$' + p[0] + ',' + p[1];
  }
  function kg(v) { return (parseFloat(v) || 0).toFixed(1).replace('.', ',') + ' kg'; }
  function escapar(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function valor(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }

  function datos() {
    var fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('fecha', valor('prodFecha'));
    fd.append('litros_procesados', valor('cc_procesada'));
    fd.append('margen', valor('cc_margen'));
    fd.append('nomina', valor('cc_nomina'));
    fd.append('personal_extra', valor('cc_personal'));
    fd.append('transporte', valor('cc_transporte'));
    fd.append('operativo', valor('cc_operativo'));
    fd.append('otros', valor('cc_otros'));
    body.querySelectorAll('tr').forEach(function (tr, i) {
      tr.querySelectorAll('[data-campo]').forEach(function (inp) {
        fd.append('formulas[' + i + '][' + inp.dataset.campo + ']', inp.value.trim());
      });
    });
    return fd;
  }

  function mostrarErrores(lista) {
    var textos = Object.keys(lista || {}).map(function (k) { return escapar(lista[k]); });
    errores.innerHTML = textos.join('<br>');
    errores.classList.toggle('d-none', textos.length === 0);
  }

  function pintar(r) {
    var recibida = parseFloat(r.litros_recibidos) || 0;
    var procesada = parseFloat(r.litros_procesados) || 0;
    document.getElementById('cc_restante').value = Math.max(0, recibida - procesada).toFixed(1);
    document.getElementById('cc_costo_litro').textContent = money(r.precio_litro_usd, 4);
    document.getElementById('cc_costo_procesada').textContent = money(r.costo_leche_usd);
    document.getElementById('cc_gasto_total').textContent = money(r.gasto_total_usd);
    document.getElementById('cc_kg_total').textContent = kg(r.kg_total);
    document.getElementById('cc_costo_comun').textContent = money(r.costo_comun_kg_usd, 4) + ' / kg';
    var margen = String(parseFloat(r.margen_pct));
    document.getElementById('cc_margen_lbl').textContent = margen;
    document.getElementById('cc_margen_big').textContent = margen;

    var filas = body.querySelectorAll('tr');
    var fact = '';
    r.quesos.forEach(function (q, i) {
      var tr = filas[i];
      if (tr) {
        tr.querySelector('.out-kg').textContent = kg(q.kg_dia);
        tr.querySelector('.out-costo').textContent = money(q.costo_kg_usd, 4);
        tr.querySelector('.out-precio').textContent = money(q.precio_venta_kg_usd, 4);
      }
      fact += '<tr><td>' + escapar(q.nombre) + '</td><td class="text-end fw-bold">' + money(q.precio_venta_kg_usd) + '</td></tr>';
    });
    document.getElementById('cc_factura').innerHTML = fact || '<tr><td class="text-muted">Sin productos con precio</td></tr>';
  }

  function limpiarSalidas() {
    body.querySelectorAll('.out-kg, .out-costo, .out-precio').forEach(function (td) { td.textContent = '—'; });
    ['cc_gasto_total', 'cc_kg_total', 'cc_costo_comun', 'cc_costo_procesada'].forEach(function (id) {
      document.getElementById(id).textContent = '—';
    });
    document.getElementById('cc_factura').innerHTML = '<tr><td class="text-muted">Sin productos con precio</td></tr>';
  }

  function enviar(url) {
    return fetch(url, { method: 'POST', body: datos(), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        var tipo = res.headers.get('Content-Type') || '';
        if (tipo.indexOf('application/json') === -1) {
          throw new Error('La sesión expiró o el formulario ya no es válido. Recarga la página.');
        }
        return res.json();
      });
  }

  var turno = 0;
  var espera = null;
  function calcular() {
    clearTimeout(espera);
    espera = setTimeout(function () {
      var mio = ++turno;
      enviar(URL_CALCULAR).then(function (j) {
        if (mio !== turno) return;
        mostrarErrores(j.ok ? {} : j.errores);
        if (j.ok) { pintar(j.resultado); } else { limpiarSalidas(); }
      }).catch(function (e) {
        if (mio === turno) mostrarErrores({ red: e.message });
      });
    }, 350);
  }

  ['cc_procesada', 'cc_nomina', 'cc_personal', 'cc_transporte', 'cc_operativo', 'cc_otros', 'cc_margen'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', calcular);
  });
  body.addEventListener('input', calcular);
  body.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.cc-delrow');
    if (!btn) return;
    if (body.querySelectorAll('tr').length > 1) btn.closest('tr').remove();
    calcular();
  });
  var add = document.getElementById('cc_add_row');
  var tpl = document.getElementById('tplQueso');
  if (add && tpl) {
    add.addEventListener('click', function () {
      body.appendChild(tpl.content.cloneNode(true));
      calcular();
    });
  }

  function sinAcento(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }
  function coincideQueso(clave, nombre) {
    var n = sinAcento(nombre);
    if (clave === 'llanero') return n.indexOf('llanero') !== -1 || (n.indexOf('blanco') !== -1 && n.indexOf('guayan') === -1 && n.indexOf('telita') === -1);
    if (clave === 'guayanes') return n.indexOf('guayan') !== -1 || n.indexOf('telita') !== -1;
    if (clave === 'ricotta') return n.indexOf('ricot') !== -1;
    if (clave === 'mozzarella') return n.indexOf('mozza') !== -1 || n.indexOf('muzza') !== -1;
    return false;
  }
  document.querySelectorAll('.btn-aplicar-ref').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var clave = btn.getAttribute('data-clave') || '';
      var nombre = btn.getAttribute('data-nombre') || '';
      var litros = btn.getAttribute('data-litros');
      var kgRef = btn.getAttribute('data-kg');
      var fila = null;
      body.querySelectorAll('tr').forEach(function (tr) {
        if (fila) return;
        var inp = tr.querySelector('[data-campo="nombre"]');
        if (inp && coincideQueso(clave, inp.value)) fila = tr;
      });
      if (!fila && tpl) {
        body.appendChild(tpl.content.cloneNode(true));
        fila = body.querySelector('tr:last-child');
        var nom = fila.querySelector('[data-campo="nombre"]');
        if (nom) nom.value = nombre;
      }
      var aviso = document.getElementById('formulaAviso');
      if (fila && litros && kgRef) {
        var inL = fila.querySelector('[data-campo="litros_ref"]');
        var inK = fila.querySelector('[data-campo="kg_ref"]');
        if (inL) inL.value = litros;
        if (inK) inK.value = kgRef;
        if (aviso) {
          aviso.hidden = false;
          aviso.textContent = 'Se copiaron ' + litros + ' L y ' + kgRef + ' kg en «' + nombre + '». Puedes cambiarlos antes de guardar.';
        }
      } else if (aviso) {
        aviso.hidden = false;
        aviso.textContent = '«' + nombre + '» no trae litros ni kg en el manual. El rendimiento de la lista no se modificó.';
      }
      calcular();
    });
  });
  var guardar = document.getElementById('cc_guardar');
  if (guardar) {
    guardar.addEventListener('click', function () {
      guardar.disabled = true;
      msg.textContent = 'Guardando…';
      msg.className = 'small fw-semibold text-muted';
      enviar(URL_GUARDAR).then(function (j) {
        mostrarErrores(j.ok ? {} : j.errores);
        if (j.ok) {
          pintar(j.resultado);
          msg.textContent = 'Fotografía del día guardada.';
          msg.className = 'small fw-semibold text-success';
        } else {
          msg.textContent = 'No se guardó: revisa los datos.';
          msg.className = 'small fw-semibold text-danger';
        }
      }).catch(function (e) {
        mostrarErrores({ red: e.message });
        msg.textContent = '';
      }).then(function () { guardar.disabled = false; });
    });
  }
  var imprimir = document.getElementById('cc_imprimir');
  if (imprimir) {
    imprimir.addEventListener('click', function () {
      var w = window.open('', '_blank', 'width=440,height=660');
      if (!w) return;
      var fecha = document.getElementById('prodFecha').value || '';
      w.document.write('<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Precios de venta</title></head><body><h1>El Castillo del Queso — Precios de venta x kg</h1><p>Margen ' + escapar(document.getElementById('cc_margen_big').textContent) + '% · ' + escapar(fecha) + '</p><table>' + document.getElementById('cc_factura').innerHTML + '</table></body></html>');
      w.document.close();
      w.focus();
      setTimeout(function () { w.print(); }, 300);
    });
  }
  calcular();
})();
</script>
<?php
layout_end();
