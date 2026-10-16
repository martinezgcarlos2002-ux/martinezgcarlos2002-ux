<?php
declare(strict_types=1);

/**
 * Cuenta individual de un productor.
 * Pestañas: Ventas Diarias, Proyección, Perfil, Cierres Semanales, Historial.
 *
 * @var array  $productor
 * @var array  $cuenta             litros, suministrado_usd, pagado_usd, saldo_usd
 * @var string $hoy
 * @var string $semana             primer día del ciclo mostrado (según productores.dia_cierre)
 * @var string $semanaFin
 * @var string $semanaActual
 * @var array<string, array> $entregasSemana     indexado por fecha
 * @var array|null $cierreSemana
 * @var array  $resumenSemana
 * @var list<array> $pagosSemana
 * @var array<string, string> $semanasPago       semana_inicio => etiqueta
 * @var array<string, string> $tiposPago
 * @var array  $proyeccion
 * @var list<array> $cierres
 * @var array<string, array> $historialEntregas  indexado por fecha
 * @var list<array> $historialPagos
 * @var string $histDesde
 * @var string $histHasta
 * @var bool   $esAdmin
 * @var array|null $tasaVigente
 * @var array<string, string> $old
 * @var array<string, string> $errors
 */
$usd = static fn (mixed $v, int $d = 2): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v), $d);
$fecha = static fn (?string $f): string => $f ? (new DateTimeImmutable($f))->format('d/m/Y') : '—';
$fechaCorta = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m');

$user = current_user();
$puedeCrear = has_permiso($user, 'productores', 'crear');
$puedeEditar = has_permiso($user, 'productores', 'editar');

$pid = (int) $productor['id'];
$activo = (int) $productor['activo'] === 1;
$saldoGlobal = (float) $cuenta['saldo_usd'];

$origenForm = match (true) {
    isset($old['cierre_id']) => 'cierre',
    isset($old['tipo'])      => 'pago',
    isset($old['volver'])    => 'entrega',
    default                  => null,
};
$tabs = ['ventas', 'proyeccion', 'perfil', 'cierres', 'historial'];
$tab = $_GET['tab'] ?? '';
$tab = is_string($tab) && in_array($tab, $tabs, true) ? $tab : ($origenForm === 'cierre' ? 'cierres' : 'ventas');

$inicio = new DateTimeImmutable($semana);
$abreviaturas = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
$nombresDia = array_map(static fn (int $i): string => $abreviaturas[(int) $inicio->modify("+$i days")->format('N')], range(0, 6));
$diaCierre = CierreSemanal::diaCierreValido($productor['dia_cierre']);
$textoCiclo = mb_strtolower(CierreSemanal::DIAS[$diaCierre % 7 + 1]) . '–' . mb_strtolower(CierreSemanal::DIAS[$diaCierre]);
$semanaAnterior = $inicio->modify('-7 days')->format('Y-m-d');
$semanaSiguiente = $inicio->modify('+7 days')->format('Y-m-d');
$semanaCerrada = $cierreSemana !== null;
$puedeCargar = $puedeCrear && $activo && !$semanaCerrada;

$oldPago = $origenForm === 'pago' ? $old : [];
$semanaPagoSel = $oldPago['semana_inicio'] ?? $semana;
if (!isset($semanasPago[$semana])) {
    $semanasPago[$semana] = sprintf('Semana del %s al %s', $fechaCorta($semana), $fecha($semanaFin));
}

$estadosCierre = [
    'cerrada'    => ['Cerrada', 'text-bg-secondary'],
    'parcial'    => ['Parcial', 'text-bg-warning'],
    'pagada'     => ['Pagada', 'text-bg-success'],
    'arrastrada' => ['Arrastrada', 'text-bg-danger'],
];

$histLitros = 0.0;
$histFacturado = 0.0;
foreach ($historialEntregas as $e) {
    $histLitros += (float) $e['litros'];
    $histFacturado += (float) $e['subtotal_usd'];
}
$histPagado = 0.0;
foreach ($historialPagos as $pg) {
    if ($pg['anulado_en'] === null) {
        $histPagado += (float) $pg['monto_usd'];
    }
}

$totCierres = ['litros' => 0.0, 'bruto' => 0.0, 'pagado' => 0.0, 'pendiente' => $saldoGlobal];
foreach ($cierres as $c) {
    $totCierres['litros'] += (float) $c['litros'];
    $totCierres['bruto'] += (float) $c['bruto_usd'];
    $totCierres['pagado'] += (float) $c['pagado_usd'] + (float) $c['abonos_usd'];
}

layout_start('Control Productor: ' . $productor['nombre'], 'productores');
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productores')) ?>"><i class="bi bi-arrow-left"></i> Productores</a>
  <h5 class="mb-0 text-navy fw-bold"><i class="bi bi-droplet-fill"></i> <?= e($productor['nombre']) ?></h5>
  <span class="badge <?= $activo ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $activo ? 'Activo' : 'Archivado' ?></span>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num"><?= e(num($cuenta['litros'], 1)) ?></div>
        <div class="text-muted">Litros totales</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num kpi-info"><?= e($usd($cuenta['suministrado_usd'])) ?></div>
        <div class="text-muted">Total facturado</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num kpi-ok"><?= e($usd($cuenta['pagado_usd'])) ?></div>
        <div class="text-muted">Total pagado</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num <?= $saldoGlobal > 0 ? 'kpi-deuda' : 'kpi-ok' ?>"><?= e($usd($saldoGlobal)) ?></div>
        <div class="text-muted">Saldo global</div>
      </div>
    </div>
  </div>
</div>

<ul class="nav nav-tabs flex-wrap mb-3" role="tablist">
  <?php foreach ([
      'ventas'     => ['bi-calendar3', 'Ventas Diarias'],
      'proyeccion' => ['bi-graph-up', 'Proyección'],
      'perfil'     => ['bi-person', 'Perfil'],
      'cierres'    => ['bi-lock', 'Cierres Semanales'],
      'historial'  => ['bi-clock-history', 'Historial'],
  ] as $clave => [$icono, $etiqueta]): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab === $clave ? 'active' : '' ?>" id="tab-<?= $clave ?>-btn" data-bs-toggle="tab" data-bs-target="#tab-<?= $clave ?>" type="button" role="tab" aria-controls="tab-<?= $clave ?>" aria-selected="<?= $tab === $clave ? 'true' : 'false' ?>">
      <i class="bi <?= $icono ?>"></i> <?= e($etiqueta) ?>
    </button>
  </li>
  <?php endforeach; ?>
</ul>

<div class="tab-content">

  <!-- ========================================================
       PESTAÑA: Ventas Diarias
       ======================================================== -->
  <div class="tab-pane fade <?= $tab === 'ventas' ? 'show active' : '' ?>" id="tab-ventas" role="tabpanel" aria-labelledby="tab-ventas-btn">
    <div class="card mb-3">
      <div class="card-body py-2">
        <form class="d-flex align-items-center flex-wrap gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
          <input type="hidden" name="p" value="productor_control">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <strong class="text-navy"><i class="bi bi-calendar3"></i> Semana:</strong>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productor_control', ['id' => $pid, 'semana' => $semanaAnterior])) ?>" aria-label="Semana anterior"><i class="bi bi-chevron-left"></i></a>
          <input type="date" name="semana" class="form-control form-control-sm w-semana" value="<?= e($semana) ?>" max="<?= e($hoy) ?>" aria-label="Seleccionar semana">
          <?php if ($semanaSiguiente <= $semanaActual): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productor_control', ['id' => $pid, 'semana' => $semanaSiguiente])) ?>" aria-label="Semana siguiente"><i class="bi bi-chevron-right"></i></a>
          <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled aria-label="Semana siguiente"><i class="bi bi-chevron-right"></i></button>
          <?php endif; ?>
          <button type="submit" class="btn btn-sm btn-outline-primary">Ir</button>
          <span class="text-muted small"><?= e($fecha($semana)) ?> — <?= e($fecha($semanaFin)) ?></span>
          <?php if ($semanaCerrada): ?>
            <span class="badge text-bg-dark"><i class="bi bi-lock-fill"></i> Semana cerrada</span>
          <?php else: ?>
            <span class="badge text-bg-info">Abierta</span>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-dark ms-lg-auto" href="<?= e(url('productores_liquidacion', ['id' => $pid, 'semana' => $semana])) ?>"><i class="bi bi-file-earmark-pdf"></i> Liquidación</a>
          <span class="badge text-bg-secondary">Precio base: <?= e($usd($productor['precio_litro_usd'], 4)) ?>/L</span>
        </form>
      </div>
    </div>

    <?php if ($origenForm === 'entrega' && $errors): ?>
      <div class="alert alert-danger py-2">
        <?php foreach ($errors as $msg): ?><div><?= e($msg) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header bg-navy py-2">Entregas de Leche — Semana <?= e($fechaCorta($semana)) ?> al <?= e($fecha($semanaFin)) ?></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-navy">
              <thead>
                <tr>
                  <th>Día / Fecha</th>
                  <th>Litros</th>
                  <th>Precio $/L</th>
                  <th class="text-end">Total $</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($nombresDia as $i => $dia):
                  $f = $inicio->modify("+$i days")->format('Y-m-d');
                  $ent = $entregasSemana[$f] ?? null;
                  $esHoy = $f === $hoy;
              ?>
                <tr class="<?= $esHoy ? 'tr-hoy' : '' ?>">
                  <td>
                    <span class="fw-semibold"><?= e($dia) ?></span>
                    <span class="text-muted small"><?= e($fechaCorta($f)) ?></span>
                    <?php if ($esHoy): ?><span class="badge text-bg-warning">Hoy</span><?php endif; ?>
                  </td>
                  <td>
                    <?php if ($puedeCargar && $f <= $hoy): ?>
                      <form class="d-flex align-items-center gap-1" method="post" action="<?= e(url('productores_entrega')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="productor_id" value="<?= $pid ?>">
                        <input type="hidden" name="fecha" value="<?= e($f) ?>">
                        <input type="hidden" name="volver" value="productor_control">
                        <input type="number" name="litros" class="form-control form-control-sm inp-litros" min="0.001" max="100000" step="0.001" required placeholder="0.0"
                               value="<?= $ent !== null ? e((float) $ent['litros']) : '' ?>" aria-label="Litros <?= e($dia) ?>">
                        <button type="submit" class="btn btn-sm <?= $ent !== null ? 'btn-outline-success' : 'btn-success' ?>" aria-label="Guardar <?= e($dia) ?>">
                          <i class="bi bi-save"></i> <?= $ent !== null ? 'Actualizar' : 'Guardar' ?>
                        </button>
                      </form>
                    <?php elseif ($ent !== null): ?>
                      <span class="num fw-semibold"><?= e(num($ent['litros'], 1)) ?> L</span>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="num">
                    <?php if ($ent !== null): ?>
                      <?= e($usd($ent['precio_litro_usd'], 4)) ?>
                    <?php else: ?>
                      <span class="text-muted"><?= e($usd($productor['precio_litro_usd'], 4)) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end num"><?= e($usd($ent['subtotal_usd'] ?? 0)) ?></td>
                  <td>
                    <?php if ($ent !== null && $ent['cierre_id'] !== null): ?><i class="bi bi-lock-fill text-muted" title="Entrega de semana cerrada"></i><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="fila-total">
                  <td>Totales</td>
                  <td><?= e(num($resumenSemana['litros'], 1)) ?> L</td>
                  <td><?= $resumenSemana['precio_promedio'] !== null ? e($usd($resumenSemana['precio_promedio'], 4)) . ' <span class="small fw-normal">prom.</span>' : '' ?></td>
                  <td class="text-end"><?= e($usd($resumenSemana['bruto_usd'])) ?></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card mb-3">
          <div class="card-header bg-navy py-2">Resumen de Semana</div>
          <div class="card-body">
            <div class="d-flex justify-content-between border-bottom py-1">
              <span>Total de Litros</span><strong><?= e(num($resumenSemana['litros'], 1)) ?> L</strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-1 text-navy fw-bold">
              <span>Total Semanal $</span><strong><?= e($usd($resumenSemana['bruto_usd'])) ?></strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-1">
              <span>Saldo Pendiente <span class="text-muted small">(semanas previas)</span></span><strong><?= e($usd($resumenSemana['arrastre_usd'])) ?></strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-1 text-danger">
              <span>(-) Mercancía</span><strong>-<?= e($usd($resumenSemana['mercancia_usd'])) ?></strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-1 text-danger">
              <span>(-) Pago Móvil / Transferencia</span><strong>-<?= e($usd($resumenSemana['pm_trans_usd'])) ?></strong>
            </div>
            <div class="d-flex justify-content-between py-1 text-danger">
              <span>(-) Efectivo</span><strong>-<?= e($usd($resumenSemana['efectivo_usd'])) ?></strong>
            </div>
            <div class="d-flex justify-content-between bg-navy rounded px-3 py-2 mt-2">
              <span>RESTANTE</span><strong><?= e($usd($resumenSemana['restante_usd'])) ?></strong>
            </div>
          </div>
        </div>

        <?php if ($puedeCrear): ?>
        <div class="card mb-3">
          <div class="card-header py-2 fw-semibold">+ Registrar Pago</div>
          <div class="card-body">
            <?php if ($tasaVigente === null): ?>
              <div class="alert alert-warning py-2 small mb-3">
                <strong>No hay tasa BCV registrada.</strong> Los pagos en efectivo o PM/Transf. necesitan la tasa del día.
                <?php if ($esAdmin): ?>
                <form method="post" action="<?= e(url('productores_tasa_rapida')) ?>" class="d-flex gap-1 mt-2" autocomplete="off">
                  <?= csrf_field() ?>
                  <input type="hidden" name="productor_id" value="<?= $pid ?>">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">Bs/USD</span>
                    <input type="number" name="tasa" class="form-control" min="0.0001" step="0.0001" required placeholder="Ej. 857.00" aria-label="Tasa BCV de hoy">
                  </div>
                  <button type="submit" class="btn btn-sm btn-warning text-nowrap"><i class="bi bi-currency-exchange"></i> Fijar hoy</button>
                </form>
                <?php else: ?>
                  Pide a un administrador que la registre.
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php $errPago = $origenForm === 'pago' ? $errors : []; ?>
            <form method="post" action="<?= e(url('productores_pago')) ?>" autocomplete="off" novalidate>
              <?= csrf_field() ?>
              <input type="hidden" name="productor_id" value="<?= $pid ?>">
              <?php if (isset($errPago['productor_id'])): ?><div class="alert alert-danger py-1 small"><?= e($errPago['productor_id']) ?></div><?php endif; ?>
              <div class="mb-2">
                <label class="form-label" for="pagoTipo">Tipo</label>
                <select class="form-select form-select-sm <?= field_class($errPago, 'tipo') ?>" id="pagoTipo" name="tipo" required>
                  <?php foreach (PagoProductor::TIPOS as $valor => $etiqueta): ?>
                    <option value="<?= e($valor) ?>" <?= ($oldPago['tipo'] ?? 'efectivo') === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= field_error($errPago, 'tipo') ?>
              </div>
              <div class="mb-2">
                <label class="form-label" for="pagoMonto">Monto USD</label>
                <input type="number" class="form-control form-control-sm <?= field_class($errPago, 'monto') ?>" id="pagoMonto" name="monto" min="0.01" step="0.01" required value="<?= e($oldPago['monto'] ?? '') ?>" placeholder="0.00">
                <?= field_error($errPago, 'monto') ?>
              </div>
              <div class="mb-2">
                <label class="form-label" for="pagoFecha">Fecha del pago <span class="text-muted">(día en que se cobró)</span></label>
                <input type="date" class="form-control form-control-sm <?= field_class($errPago, 'fecha') ?>" id="pagoFecha" name="fecha" max="<?= e($hoy) ?>" required value="<?= e($oldPago['fecha'] ?? $hoy) ?>">
                <?= field_error($errPago, 'fecha') ?>
              </div>
              <div class="mb-2">
                <label class="form-label" for="pagoSemana">Semana del abono <span class="text-muted">(a cuál semana aplica)</span></label>
                <select class="form-select form-select-sm <?= field_class($errPago, 'semana_inicio') ?>" id="pagoSemana" name="semana_inicio" required>
                  <?php foreach ($semanasPago as $valor => $etiqueta): ?>
                    <option value="<?= e($valor) ?>" <?= $semanaPagoSel === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= field_error($errPago, 'semana_inicio') ?>
                <div class="form-text">Cambia esta semana si el pago corresponde a una semana anterior.</div>
              </div>
              <div class="mb-2">
                <label class="form-label" for="pagoReferencia">Referencia <span class="text-muted">(obligatoria en Pago Móvil y Transferencia)</span></label>
                <input type="text" class="form-control form-control-sm <?= field_class($errPago, 'referencia') ?>" id="pagoReferencia" name="referencia" maxlength="40" value="<?= e($oldPago['referencia'] ?? '') ?>">
                <?= field_error($errPago, 'referencia') ?>
              </div>
              <div class="mb-3">
                <label class="form-label" for="pagoDesc">Descripción</label>
                <input type="text" class="form-control form-control-sm" id="pagoDesc" name="descripcion" placeholder="Opcional" maxlength="255" value="<?= e($oldPago['descripcion'] ?? '') ?>">
              </div>
              <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save"></i> Guardar Pago</button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-header py-2 fw-semibold">Pagos aplicados a esta semana</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <tbody>
              <?php if ($pagosSemana === []): ?>
                <tr><td class="text-muted small text-center py-3">Sin pagos registrados.</td></tr>
              <?php endif; ?>
              <?php foreach ($pagosSemana as $pg): $anulado = $pg['anulado_en'] !== null; ?>
                <tr class="<?= $anulado ? 'text-decoration-line-through text-muted' : '' ?>">
                  <td class="small"><?= e($fecha($pg['fecha'])) ?></td>
                  <td><span class="badge text-bg-info"><?= e($tiposPago[$pg['tipo']] ?? $pg['tipo']) ?></span></td>
                  <td class="text-end num fw-semibold"><?= e($usd($pg['monto_usd'])) ?></td>
                  <td class="text-end">
                    <?php if ($anulado): ?>
                      <span class="badge text-bg-secondary" title="<?= e($pg['motivo_anulacion']) ?>">Anulado</span>
                    <?php elseif ($esAdmin): ?>
                      <button type="button" class="btn btn-sm btn-outline-danger py-0"
                              data-bs-toggle="modal" data-bs-target="#modalAnularPago"
                              data-id="<?= (int) $pg['id'] ?>"
                              data-desc="<?= e(($tiposPago[$pg['tipo']] ?? $pg['tipo']) . ' ' . $usd($pg['monto_usd']) . ' del ' . $fecha($pg['fecha'])) ?>"
                              aria-label="Anular pago"><i class="bi bi-x-lg"></i></button>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php if ($pg['referencia'] || $pg['descripcion']): ?>
                <tr class="<?= $anulado ? 'text-muted' : '' ?>"><td colspan="4" class="small text-muted border-top-0 pt-0"><?= e(trim(($pg['referencia'] ? 'Ref. ' . $pg['referencia'] . ' ' : '') . ($pg['descripcion'] ?? ''))) ?></td></tr>
                <?php endif; ?>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================================================
       PESTAÑA: Proyección
       ======================================================== -->
  <div class="tab-pane fade <?= $tab === 'proyeccion' ? 'show active' : '' ?>" id="tab-proyeccion" role="tabpanel" aria-labelledby="tab-proyeccion-btn">
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header bg-navy py-2">Proyección de Producción <span class="fw-normal small">basada en las últimas 8 semanas</span></div>
          <div class="card-body">
            <div class="d-flex justify-content-between border-bottom py-2"><span>Precio base acordado</span><strong><?= e($usd($proyeccion['precio_vigente'], 4)) ?>/L</strong></div>
            <div class="d-flex justify-content-between border-bottom py-2"><span>Promedio litros/semana</span><strong><?= e(num($proyeccion['promedio_litros'], 1)) ?> L</strong></div>
            <div class="d-flex justify-content-between border-bottom py-2"><span>Promedio ingreso/semana</span><strong class="kpi-info"><?= e($usd($proyeccion['promedio_ingreso_usd'])) ?></strong></div>
            <div class="d-flex justify-content-between py-2 text-navy fw-bold"><span>Proyección mensual (litros)</span><strong><?= e(num($proyeccion['litros_mes'], 1)) ?> L</strong></div>
            <div class="d-flex justify-content-between bg-navy rounded px-3 py-2 mt-2">
              <span>INGRESO PROYECTADO/MES</span><strong><?= e($usd($proyeccion['ingreso_mes_usd'])) ?></strong>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header py-2 fw-semibold">Historial de últimas semanas</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Ciclo (inicio)</th>
                  <th class="text-end">Litros</th>
                  <th class="text-end">Monto $</th>
                  <th class="text-end">Días</th>
                </tr>
              </thead>
              <tbody>
              <?php if ($proyeccion['semanas'] === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Sin entregas en las últimas 8 semanas.</td></tr>
              <?php endif; ?>
              <?php foreach ($proyeccion['semanas'] as $s): ?>
                <tr>
                  <td><?= e($fecha($s['semana_inicio'])) ?></td>
                  <td class="text-end num"><?= e(num($s['litros'], 1)) ?></td>
                  <td class="text-end num kpi-info fw-semibold"><?= e($usd($s['monto_usd'])) ?></td>
                  <td class="text-end"><?= (int) $s['dias'] ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================================================
       PESTAÑA: Perfil
       ======================================================== -->
  <div class="tab-pane fade <?= $tab === 'perfil' ? 'show active' : '' ?>" id="tab-perfil" role="tabpanel" aria-labelledby="tab-perfil-btn">
    <div class="row g-3">
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header bg-navy py-2">Datos del Productor</div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <tbody>
                <tr><th class="text-muted fw-normal ps-3">Nombre</th><td class="fw-semibold"><?= e($productor['nombre']) ?></td></tr>
                <tr><th class="text-muted fw-normal ps-3">Documento</th><td><?= e($productor['documento'] ?: '—') ?></td></tr>
                <tr><th class="text-muted fw-normal ps-3">Teléfono</th><td><?= e($productor['telefono'] ?: '—') ?></td></tr>
                <tr><th class="text-muted fw-normal ps-3">Correo</th><td><?= e($productor['correo'] ?: '—') ?></td></tr>
                <tr><th class="text-muted fw-normal ps-3">Dirección</th><td><?= e($productor['direccion'] ?: '—') ?></td></tr>
                <tr><th class="text-muted fw-normal ps-3">Precio/L</th><td class="fw-semibold kpi-info"><?= e($usd($productor['precio_litro_usd'], 4)) ?>/L</td></tr>
                <tr><th class="text-muted fw-normal ps-3">Crédito</th><td><?= (int) $productor['credito_dias'] ?> días</td></tr>
                <tr><th class="text-muted fw-normal ps-3">Estado</th><td><span class="badge <?= $activo ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $activo ? 'Activo' : 'Archivado' ?></span></td></tr>
              </tbody>
            </table>
          </div>
          <div class="card-footer bg-white border-0 d-flex gap-2">
            <?php if ($puedeEditar): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('productores_editar', ['id' => $pid])) ?>"><i class="bi bi-pencil"></i> Editar datos</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productor_control', ['id' => $pid, 'tab' => 'cierres'])) ?>"><i class="bi bi-file-earmark-text"></i> Reporte semanal</a>
          </div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="row g-3">
          <div class="col-6">
            <div class="card text-center h-100"><div class="card-body"><div class="kpi-num"><?= e(num($cuenta['litros'], 1)) ?></div><div class="text-muted">Litros totales entregados</div></div></div>
          </div>
          <div class="col-6">
            <div class="card text-center h-100"><div class="card-body"><div class="kpi-num kpi-info"><?= e($usd($cuenta['suministrado_usd'])) ?></div><div class="text-muted">Total facturado</div></div></div>
          </div>
          <div class="col-6">
            <div class="card text-center h-100"><div class="card-body"><div class="kpi-num kpi-ok"><?= e($usd($cuenta['pagado_usd'])) ?></div><div class="text-muted">Total pagado</div></div></div>
          </div>
          <div class="col-6">
            <div class="card text-center h-100"><div class="card-body"><div class="kpi-num <?= $saldoGlobal > 0 ? 'kpi-deuda' : 'kpi-ok' ?>"><?= e($usd($saldoGlobal)) ?></div><div class="text-muted">Saldo global pendiente</div></div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================================================
       PESTAÑA: Cierres Semanales
       ======================================================== -->
  <div class="tab-pane fade <?= $tab === 'cierres' ? 'show active' : '' ?>" id="tab-cierres" role="tabpanel" aria-labelledby="tab-cierres-btn">
    <?php if ($origenForm === 'cierre' && $errors): ?>
      <div class="alert alert-danger py-2">
        <?php foreach ($errors as $msg): ?><div><?= e($msg) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($saldoGlobal > 0): ?>
      <div class="alert alert-warning">Se le deben <strong><?= e($usd($saldoGlobal)) ?></strong> a este productor.</div>
    <?php elseif ($saldoGlobal < 0): ?>
      <div class="alert alert-info">El productor tiene un saldo a favor de la empresa de <strong><?= e($usd(abs($saldoGlobal))) ?></strong>; se descuenta en la próxima semana.</div>
    <?php else: ?>
      <div class="alert alert-success">No se le debe nada. Todas las semanas están saldadas.</div>
    <?php endif; ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
      <span class="flex-grow-1">
        Los ciclos de este productor (<?= e($textoCiclo) ?>) ya terminados se cierran congelando litros y factura; el saldo pendiente o a favor se arrastra al siguiente.
        El ciclo en curso sigue <span class="badge text-bg-info">Abierto</span> hasta el <?= e(mb_strtolower(CierreSemanal::DIAS[$diaCierre])) ?>.
        <?php if ($esAdmin): ?>Puedes corregir cualquier cierre con el botón <span class="badge text-bg-primary">Editar</span>.<?php endif; ?>
      </span>
      <?php if ($puedeCrear): ?>
      <form method="post" action="<?= e(url('productores_cerrar_semanas')) ?>" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="productor_id" value="<?= $pid ?>">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-lock"></i> Cerrar semanas terminadas</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-3"><div class="card text-center"><div class="card-body py-2"><div class="kpi-num fs-4"><?= e(num($totCierres['litros'], 1)) ?></div><div class="text-muted small">Litros cerrados</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card text-center"><div class="card-body py-2"><div class="kpi-num kpi-info fs-4"><?= e($usd($totCierres['bruto'])) ?></div><div class="text-muted small">Facturado</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card text-center"><div class="card-body py-2"><div class="kpi-num kpi-ok fs-4"><?= e($usd($totCierres['pagado'])) ?></div><div class="text-muted small">Pagado</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card text-center"><div class="card-body py-2"><div class="kpi-num <?= $saldoGlobal > 0 ? 'kpi-deuda' : 'kpi-ok' ?> fs-4"><?= e($usd($saldoGlobal)) ?></div><div class="text-muted small">Pendiente</div></div></div></div>
    </div>

    <div class="card mb-3">
      <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="get" action="<?= e(base_path() . 'index.php') ?>">
          <input type="hidden" name="p" value="productor_control">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="tab" value="cierres">
          <div class="col-md-3">
            <label class="form-label mb-1" for="cierreDesde">Desde</label>
            <input type="date" class="form-control form-control-sm" id="cierreDesde" name="cierre_desde" value="<?= e(is_string($_GET['cierre_desde'] ?? null) ? $_GET['cierre_desde'] : '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label mb-1" for="cierreHasta">Hasta</label>
            <input type="date" class="form-control form-control-sm" id="cierreHasta" name="cierre_hasta" value="<?= e(is_string($_GET['cierre_hasta'] ?? null) ? $_GET['cierre_hasta'] : '') ?>">
          </div>
          <div class="col-md-6 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Buscar</button>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productor_control', ['id' => $pid, 'tab' => 'cierres'])) ?>">Limpiar</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header bg-navy py-2">Historial semana por semana</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Semana</th>
              <th class="text-end">Litros</th>
              <th class="text-end">$/L</th>
              <th class="text-end">Factura</th>
              <th class="text-end">Arrastre</th>
              <th class="text-end">Abonos</th>
              <th class="text-end">Pagado</th>
              <th class="text-end">¿Se le debe?</th>
              <th>Estado</th>
              <th>Notas</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($cierres === []): ?>
            <tr><td colspan="11" class="text-center text-muted py-4">No hay semanas cerradas.</td></tr>
          <?php endif; ?>
          <?php foreach ($cierres as $c):
              $arrastre = (float) $c['arrastre_anterior_usd'];
              $pendiente = (float) $c['pendiente_usd'];
              [$estadoTxt, $estadoCls] = $estadosCierre[$c['estado']] ?? [ucfirst($c['estado']), 'text-bg-secondary'];
          ?>
            <tr>
              <td><span class="fw-semibold text-navy"><?= e($fecha($c['semana_inicio'])) ?></span><div class="text-muted small">al <?= e($fecha($c['semana_fin'])) ?></div></td>
              <td class="text-end num"><?= e(num($c['litros'], 1)) ?></td>
              <td class="text-end num"><?= $c['precio_promedio'] !== null ? e($usd($c['precio_promedio'], 4)) : '—' ?></td>
              <td class="text-end num"><?= e($usd($c['bruto_usd'])) ?></td>
              <?php if ($arrastre < 0): ?>
                <td class="text-end num text-warning fw-semibold"><?= e($usd($arrastre)) ?><div class="nota-arrastre">a descuento por saldo a favor</div></td>
              <?php elseif ($arrastre > 0): ?>
                <td class="text-end num text-warning fw-semibold"><?= e($usd($arrastre)) ?><div class="nota-arrastre">viene de semana previa</div></td>
              <?php else: ?>
                <td class="text-end num text-muted"><?= e($usd(0)) ?></td>
              <?php endif; ?>
              <td class="text-end num text-success"><?= e($usd($c['abonos_usd'])) ?></td>
              <td class="text-end num text-success"><?= e($usd($c['pagado_usd'])) ?></td>
              <td class="text-end">
                <?php if ($c['estado'] === 'arrastrada'): ?>
                  <span class="nota-arrastre"><?= e($usd($pendiente)) ?> Arrastrada</span>
                <?php elseif ($pendiente > 0): ?>
                  <span class="text-danger fw-bold"><?= e($usd($pendiente)) ?></span>
                <?php elseif ($pendiente < 0): ?>
                  <span class="nota-favor">A favor <?= e($usd(abs($pendiente))) ?></span>
                <?php else: ?>
                  <span class="text-success fw-bold">✓</span>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= e($estadoCls) ?>"><?= e($estadoTxt) ?></span></td>
              <td class="small text-muted">
                <?= e($c['notas'] ?? '') ?>
                <?php if ($c['estado'] === 'arrastrada'): ?><div class="nota-arrastre">Pago consolidado en semana más reciente</div><?php endif; ?>
              </td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productor_control', ['id' => $pid, 'semana' => $c['semana_inicio']])) ?>">Pagos</a>
                <a class="btn btn-sm btn-outline-dark" href="<?= e(url('productores_liquidacion', ['id' => $pid, 'semana' => $c['semana_inicio']])) ?>" title="Liquidación de la semana"><i class="bi bi-file-earmark-pdf"></i></a>
                <?php if ($esAdmin): ?>
                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#modalReabrir"
                        data-id="<?= (int) $c['id'] ?>"
                        data-semana="<?= e($fecha($c['semana_inicio']) . ' al ' . $fecha($c['semana_fin'])) ?>">Reabrir Semana</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditarCierre"
                        data-id="<?= (int) $c['id'] ?>"
                        data-semana="<?= e($fecha($c['semana_inicio'])) ?>"
                        data-litros="<?= e($c['litros']) ?>"
                        data-precio="<?= e($c['precio_promedio'] ?? '') ?>"
                        data-factura="<?= e($c['bruto_usd']) ?>"
                        data-notas="<?= e($c['notas'] ?? '') ?>">Editar</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ========================================================
       PESTAÑA: Historial
       ======================================================== -->
  <div class="tab-pane fade <?= $tab === 'historial' ? 'show active' : '' ?>" id="tab-historial" role="tabpanel" aria-labelledby="tab-historial-btn">
    <div class="card mb-3">
      <div class="card-body py-3">
        <form class="d-flex align-items-end flex-wrap gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
          <input type="hidden" name="p" value="productor_control">
          <input type="hidden" name="id" value="<?= $pid ?>">
          <input type="hidden" name="tab" value="historial">
          <div>
            <label class="form-label mb-1" for="histDesde">Desde</label>
            <input type="date" class="form-control form-control-sm" id="histDesde" name="desde" value="<?= e($histDesde) ?>" max="<?= e($hoy) ?>">
          </div>
          <div>
            <label class="form-label mb-1" for="histHasta">Hasta</label>
            <input type="date" class="form-control form-control-sm" id="histHasta" name="hasta" value="<?= e($histHasta) ?>" max="<?= e($hoy) ?>">
          </div>
          <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Filtrar</button>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productor_control', ['id' => $pid, 'tab' => 'historial', 'desde' => date('Y-m-01'), 'hasta' => $hoy])) ?>">Este mes</a>
          <a class="btn btn-sm btn-primary ms-lg-auto" href="<?= e(url('productores_liquidacion', ['id' => $pid, 'desde' => $histDesde, 'hasta' => $histHasta])) ?>"><i class="bi bi-receipt"></i> Generar Factura</a>
        </form>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-lg-3"><div class="card text-center"><div class="card-body py-2"><div class="kpi-num fs-4"><?= e(num($histLitros, 1)) ?></div><div class="text-muted small">Litros período</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card text-center"><div class="card-body py-2"><div class="kpi-num kpi-info fs-4"><?= e($usd($histFacturado)) ?></div><div class="text-muted small">Facturado</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card text-center"><div class="card-body py-2"><div class="kpi-num kpi-ok fs-4"><?= e($usd($histPagado)) ?></div><div class="text-muted small">Pagado</div></div></div></div>
      <div class="col-6 col-lg-3"><div class="card text-center"><div class="card-body py-2"><div class="kpi-num <?= $histFacturado - $histPagado > 0 ? 'kpi-deuda' : 'kpi-ok' ?> fs-4"><?= e($usd($histFacturado - $histPagado)) ?></div><div class="text-muted small">Saldo período</div></div></div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card h-100">
          <div class="card-header py-2 fw-semibold">Entregas del período</div>
          <div class="panel-scroll">
            <table class="table table-sm mb-0">
              <thead>
                <tr><th>Fecha</th><th class="text-end">Litros</th><th class="text-end">Precio</th><th class="text-end">Total</th></tr>
              </thead>
              <tbody>
              <?php if ($historialEntregas === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Sin entregas en el período.</td></tr>
              <?php endif; ?>
              <?php foreach ($historialEntregas as $f => $e): ?>
                <tr>
                  <td><?= e($fecha((string) $f)) ?></td>
                  <td class="text-end num"><?= e(num($e['litros'], 1)) ?></td>
                  <td class="text-end num"><?= e($usd($e['precio_litro_usd'], 4)) ?></td>
                  <td class="text-end num fw-semibold"><?= e($usd($e['subtotal_usd'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card h-100">
          <div class="card-header py-2 fw-semibold">Pagos del período</div>
          <div class="panel-scroll">
            <table class="table table-sm mb-0">
              <thead>
                <tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th class="text-end">Monto</th></tr>
              </thead>
              <tbody>
              <?php if ($historialPagos === []): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Sin pagos en el período.</td></tr>
              <?php endif; ?>
              <?php foreach ($historialPagos as $pg): $anulado = $pg['anulado_en'] !== null; ?>
                <tr class="<?= $anulado ? 'text-muted' : '' ?>">
                  <td><?= e($fecha($pg['fecha'])) ?></td>
                  <td>
                    <span class="badge text-bg-info"><?= e($tiposPago[$pg['tipo']] ?? $pg['tipo']) ?></span>
                    <?php if ($anulado): ?><span class="badge text-bg-secondary">Anulado</span><?php endif; ?>
                  </td>
                  <td class="small text-muted"><?= e(trim(($pg['referencia'] ? 'Ref. ' . $pg['referencia'] . ' ' : '') . ($pg['descripcion'] ?? ''))) ?: '—' ?></td>
                  <td class="text-end num fw-semibold <?= $anulado ? 'text-decoration-line-through' : '' ?>"><?= e($usd($pg['monto_usd'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($esAdmin): ?>
<!-- ============================================================
     MODAL: Editar Cierre (solo administrador)
     ============================================================ -->
<div class="modal fade" id="modalEditarCierre" tabindex="-1" aria-labelledby="tituloCierre" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-navy">
      <div class="modal-header">
        <h6 class="modal-title" id="tituloCierre">Editar cierre — Semana <span id="ecSemana"></span></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form method="post" action="<?= e(url('productores_cierre_editar')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="cierre_id" id="ecId" value="">
        <div class="modal-body">
          <div class="alert alert-info py-2 small">
            Corrige los datos de esta semana ya cerrada. Si cambias litros o precio, la factura se recalcula sola (o puedes escribirla a mano).
            Los saldos de las semanas siguientes se recalculan y el cambio queda en auditoría.
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label" for="ecLitros">Litros</label>
              <input type="number" class="form-control form-control-sm" id="ecLitros" name="litros" min="0" step="0.001" required>
            </div>
            <div class="col-6">
              <label class="form-label" for="ecPrecio">Precio $/L</label>
              <input type="number" class="form-control form-control-sm" id="ecPrecio" name="precio" min="0" step="0.0001">
            </div>
          </div>
          <div class="mt-2 mb-2">
            <label class="form-label" for="ecFactura">Factura total $ <span class="text-muted">(litros × precio)</span></label>
            <input type="number" class="form-control form-control-sm" id="ecFactura" name="total_factura" min="0" step="0.01">
          </div>
          <div class="mb-2">
            <label class="form-label" for="ecNotas">Notas <span class="text-muted">(máx. 500)</span></label>
            <textarea class="form-control form-control-sm" id="ecNotas" name="notas" rows="2" maxlength="500"></textarea>
          </div>
          <div>
            <label class="form-label" for="ecMotivo">Motivo de la corrección <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm" id="ecMotivo" name="motivo" minlength="5" maxlength="255" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: Reabrir semana (solo administrador)
     ============================================================ -->
<div class="modal fade" id="modalReabrir" tabindex="-1" aria-labelledby="tituloReabrir" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="<?= e(url('productores_reabrir_semana')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="cierre_id" id="rsId" value="">
        <div class="modal-header">
          <h6 class="modal-title text-danger" id="tituloReabrir"><i class="bi bi-unlock"></i> Reabrir semana <span id="rsSemana"></span></h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="small mb-2">La semana vuelve a estar <strong>Abierta</strong>: sus entregas se podrán corregir y el saldo que arrastraba se quita de las semanas siguientes hasta que se vuelva a cerrar.</p>
          <p class="text-muted small">Los pagos registrados se conservan y los movimientos de caja no se modifican. La reapertura queda en auditoría.</p>
          <label class="form-label" for="rsMotivo">Motivo <span class="text-danger">*</span></label>
          <input type="text" class="form-control form-control-sm" id="rsMotivo" name="motivo" minlength="5" maxlength="255" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-unlock"></i> Reabrir Semana</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: Anular pago (solo administrador)
     ============================================================ -->
<div class="modal fade" id="modalAnularPago" tabindex="-1" aria-labelledby="tituloAnular" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="<?= e(url('productores_pago_eliminar')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="apId" value="">
        <div class="modal-header">
          <h6 class="modal-title" id="tituloAnular"><i class="bi bi-x-octagon"></i> Anular pago</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">¿Anular <strong id="apDesc"></strong>?</p>
          <p class="text-muted small">El pago no se borra: queda anulado y, si movió caja, se registra un contra-asiento.</p>
          <label class="form-label" for="apMotivo">Motivo <span class="text-danger">*</span></label>
          <input type="text" class="form-control form-control-sm" id="apMotivo" name="motivo" minlength="5" maxlength="255" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-lg"></i> Anular pago</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var cierre = document.getElementById('modalEditarCierre');
  if (cierre) {
    var litros = document.getElementById('ecLitros');
    var precio = document.getElementById('ecPrecio');
    var factura = document.getElementById('ecFactura');
    var recalcular = function () {
      var l = parseFloat(litros.value), p = parseFloat(precio.value);
      if (!isNaN(l) && !isNaN(p)) factura.value = (Math.round(l * p * 100) / 100).toFixed(2);
    };
    litros.addEventListener('input', recalcular);
    precio.addEventListener('input', recalcular);
    cierre.addEventListener('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      if (!btn) return;
      document.getElementById('ecId').value = btn.getAttribute('data-id') || '';
      document.getElementById('ecSemana').textContent = btn.getAttribute('data-semana') || '';
      litros.value = btn.getAttribute('data-litros') || '';
      precio.value = btn.getAttribute('data-precio') || '';
      factura.value = btn.getAttribute('data-factura') || '';
      document.getElementById('ecNotas').value = btn.getAttribute('data-notas') || '';
      document.getElementById('ecMotivo').value = '';
    });
  }

  var reabrir = document.getElementById('modalReabrir');
  if (reabrir) {
    reabrir.addEventListener('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      if (!btn) return;
      document.getElementById('rsId').value = btn.getAttribute('data-id') || '';
      document.getElementById('rsSemana').textContent = btn.getAttribute('data-semana') || '';
      document.getElementById('rsMotivo').value = '';
    });
  }

  var anular = document.getElementById('modalAnularPago');
  if (anular) {
    anular.addEventListener('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      if (!btn) return;
      document.getElementById('apId').value = btn.getAttribute('data-id') || '';
      document.getElementById('apDesc').textContent = btn.getAttribute('data-desc') || 'este pago';
      document.getElementById('apMotivo').value = '';
    });
  }
})();
</script>
<?php
layout_end();
