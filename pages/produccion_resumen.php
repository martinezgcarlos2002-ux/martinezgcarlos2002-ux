<?php
declare(strict_types=1);

/**
 * @var array{
 *   total_mes: float|int|string,
 *   dias_con_produccion: int,
 *   promedio: float|int|string,
 *   mejor: ?array{fecha:string, total:float|int|string},
 *   peor: ?array{fecha:string, total:float|int|string},
 *   barras: list<array{etiqueta:string, monto:float|int|string}>,
 *   dias: list<array{fecha:string, producido:float|int|string, distribuido:float|int|string, pendiente:float|int|string, total:float|int|string}>,
 *   productos: list<array{nombre:string, cantidad:float|int|string, total:float|int|string}>
 * } $resumen
 * @var string $mes  YYYY-MM
 */
$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$mes = (isset($mes) && is_string($mes) && preg_match('/^\d{4}-\d{2}$/', $mes)) ? $mes : date('Y-m');
[$anio, $mesNum] = array_map('intval', explode('-', $mes));
$tituloMes = ($meses[$mesNum] ?? '') . ' ' . $anio;

$base = [
    'total_mes' => 0,
    'dias_con_produccion' => 0,
    'promedio' => 0,
    'mejor' => null,
    'peor' => null,
    'barras' => [],
    'dias' => [],
    'productos' => [],
];
$resumen = array_merge($base, $resumen ?? []);
$maxBarra = 0.0;
foreach ($resumen['barras'] as $b) {
    $maxBarra = max($maxBarra, (float) $b['monto']);
}

layout_start('Resumen mensual — Producción', 'produccion');
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h1 class="h4 mb-0">Resumen de producción — <?= e(ucfirst($tituloMes)) ?></h1>
  <form class="d-flex gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
    <input type="hidden" name="p" value="produccion_resumen">
    <input type="month" name="mes" class="form-control form-control-sm" value="<?= e($mes) ?>" aria-label="Mes">
    <button class="btn btn-sm btn-primary">Ver</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('produccion')) ?>">Día</a>
  </form>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card resumen-card h-100"><div class="card-body">
      <div class="etiqueta">Total del mes</div>
      <div class="cifra">$<?= e(num($resumen['total_mes'])) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card resumen-card h-100"><div class="card-body">
      <div class="etiqueta">Días con producción</div>
      <div class="cifra"><?= (int) $resumen['dias_con_produccion'] ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card resumen-card h-100"><div class="card-body">
      <div class="etiqueta">Promedio diario</div>
      <div class="cifra">$<?= e(num($resumen['promedio'])) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card resumen-card h-100"><div class="card-body">
      <div class="etiqueta">Mejor día</div>
      <?php if ($resumen['mejor']): ?>
        <div class="cifra">$<?= e(num($resumen['mejor']['total'])) ?></div>
        <div class="sub"><?= e($resumen['mejor']['fecha']) ?></div>
      <?php else: ?>
        <div class="cifra">—</div>
      <?php endif; ?>
      <div class="sub">Día más bajo: <?= $resumen['peor'] ? e($resumen['peor']['fecha'] . ' — $' . num($resumen['peor']['total'])) : '—' ?></div>
    </div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body chart-box">
    <div class="etiqueta mb-2">Producción por día ($)</div>
    <?php if ($resumen['barras'] === []): ?>
      <p class="text-muted mb-0">Sin producción registrada en este mes.</p>
    <?php else: ?>
      <div class="chart-bars">
        <?php foreach ($resumen['barras'] as $b):
            $paso = $maxBarra > 0 ? (int) (round(max(10, (float) $b['monto'] / $maxBarra * 140) / 10) * 10) : 10;
            $paso = min(140, max(10, $paso));
        ?>
          <div class="barra" title="$<?= e(num($b['monto'])) ?>">
            <i class="chart-h-<?= $paso ?>"></i>
            <span><?= e($b['etiqueta']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Días</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>Fecha</th><th class="text-end">Producido</th><th class="text-end">Distribuido</th><th class="text-end">Pendiente</th><th class="text-end">Total $</th><th></th></tr>
          </thead>
          <tbody>
          <?php if ($resumen['dias'] === []): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">Sin días con producción.</td></tr>
          <?php endif; ?>
          <?php foreach ($resumen['dias'] as $d): ?>
            <tr>
              <td><?= e($d['fecha']) ?></td>
              <td class="text-end"><?= e(num($d['producido'], 2)) ?></td>
              <td class="text-end"><?= e(num($d['distribuido'], 2)) ?></td>
              <td class="text-end text-warning"><?= e(num($d['pendiente'], 2)) ?></td>
              <td class="text-end fw-semibold"><?= e(num($d['total'])) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('produccion')) ?>">Ver</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Productos (de mayor a menor)</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>Producto</th><th class="text-end">Cantidad</th><th class="text-end">Total $</th></tr>
          </thead>
          <tbody>
          <?php if ($resumen['productos'] === []): ?>
            <tr><td colspan="3" class="text-center text-muted py-4">Sin productos en el mes.</td></tr>
          <?php endif; ?>
          <?php foreach ($resumen['productos'] as $p): ?>
            <tr>
              <td><?= e($p['nombre']) ?></td>
              <td class="text-end"><?= e(num($p['cantidad'], 2)) ?></td>
              <td class="text-end fw-semibold"><?= e(num($p['total'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
layout_end();
