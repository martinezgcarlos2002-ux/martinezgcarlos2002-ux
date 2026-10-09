<?php
declare(strict_types=1);

/**
 * Finanzas / Caja.
 * @var array{q:string, tipo:?string, desde:?string, hasta:?string} $filtros
 * @var array $totales       ingresos/egresos del filtro
 * @var array $global        balance total del libro
 * @var array<string, array<string, string>> $saldos   [cuenta][moneda]
 * @var list<array> $movimientos
 * @var array{id:int, tasa:float, vigente_desde:string}|null $tasa
 * @var array<string, string> $cuentas
 * @var array<string, string> $origenes
 * @var bool $puedeCrear
 * @var bool $puedeEditar
 * @var array<string, string> $old
 * @var array<string, string> $errors
 */
$usd = static fn (mixed $v): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v));
$bs = static fn (mixed $v): string => ((float) $v < 0 ? '-' : '') . 'Bs. ' . num(abs((float) $v));
$balancePositivo = (float) $global['balance_usd'] >= 0;
$hayFiltro = $filtros['q'] !== '' || $filtros['tipo'] !== null || $filtros['desde'] !== null || $filtros['hasta'] !== null;

$origenForm = isset($old['descripcion']) ? 'movimiento' : (isset($old['tasa']) ? 'tasa' : null);
$errMov = $origenForm === 'movimiento' ? $errors : [];
$errTasa = $origenForm === 'tasa' ? $errors : [];
$oldMov = $origenForm === 'movimiento' ? $old : [];

layout_start('Finanzas / Caja', 'finanzas');
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num kpi-ok"><?= e($usd($totales['ingresos_usd'])) ?></div>
        <div class="fs-5 fw-semibold text-secondary"><?= e($bs($totales['ingresos_bs'])) ?></div>
        <div class="text-muted small mt-1">Ingresos <?= $hayFiltro ? '(filtro)' : '' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num kpi-deuda"><?= e($usd($totales['egresos_usd'])) ?></div>
        <div class="fs-5 fw-semibold text-secondary"><?= e($bs($totales['egresos_bs'])) ?></div>
        <div class="text-muted small mt-1">Egresos <?= $hayFiltro ? '(filtro)' : '' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num <?= $balancePositivo ? 'kpi-ok' : 'kpi-deuda' ?>"><?= e($usd($global['balance_usd'])) ?></div>
        <div class="fs-5 fw-semibold text-secondary"><?= e($bs($global['balance_bs'])) ?></div>
        <div class="text-muted small mt-1">Balance</div>
        <div class="d-flex justify-content-center gap-2 small mt-1">
          <?php foreach ($cuentas as $clave => $etiqueta): $s = (float) $saldos[$clave]['USD']; ?>
            <span class="badge <?= $s < 0 ? 'text-bg-danger' : 'text-bg-light border' ?>" title="<?= e($etiqueta) ?>: <?= e($bs($saldos[$clave]['VES'])) ?> en bolívares">
              <?= e($clave === 'caja' ? 'Caja' : 'Banco') ?> <?= e($usd($s)) ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <?php if ($tasa !== null): ?>
          <div class="kpi-num"><?= e(num($tasa['tasa'])) ?></div>
          <div class="text-muted small">Tasa cambio Bs/USD (<?= e((new DateTimeImmutable($tasa['vigente_desde']))->format('d/m/Y')) ?>)</div>
        <?php else: ?>
          <div class="kpi-num text-muted">—</div>
          <div class="text-danger small">Sin tasa BCV registrada</div>
        <?php endif; ?>
        <?php if ($puedeEditar): ?>
          <button type="button" class="btn btn-sm btn-outline-warning mt-2" data-bs-toggle="modal" data-bs-target="#modalTasa">
            <i class="bi bi-arrow-repeat"></i> Actualizar
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <form class="d-flex flex-wrap align-items-center gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
    <input type="hidden" name="p" value="finanzas">
    <input type="search" name="q" class="form-control form-control-sm w-filtro" placeholder="Descripción..." maxlength="160" value="<?= e($filtros['q']) ?>" aria-label="Descripción">
    <select name="tipo" class="form-select form-select-sm w-estado" aria-label="Tipo">
      <option value="">Todos</option>
      <option value="ingreso" <?= $filtros['tipo'] === 'ingreso' ? 'selected' : '' ?>>Ingreso</option>
      <option value="egreso" <?= $filtros['tipo'] === 'egreso' ? 'selected' : '' ?>>Egreso</option>
    </select>
    <input type="date" name="desde" class="form-control form-control-sm w-fecha" value="<?= e($filtros['desde'] ?? '') ?>" aria-label="Desde">
    <input type="date" name="hasta" class="form-control form-control-sm w-fecha" value="<?= e($filtros['hasta'] ?? '') ?>" aria-label="Hasta">
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel"></i> Filtrar</button>
    <?php if ($hayFiltro): ?>
      <a class="btn btn-sm btn-link" href="<?= e(url('finanzas')) ?>">Limpiar</a>
    <?php endif; ?>
  </form>
  <?php if ($puedeCrear): ?>
  <button type="button" class="btn btn-primary ms-lg-auto" data-bs-toggle="modal" data-bs-target="#modalMovimiento" <?= $tasa === null ? 'disabled title="Registra primero la tasa BCV"' : '' ?>>
    <i class="bi bi-plus-lg"></i> Movimiento
  </button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr class="small text-uppercase text-muted">
          <th>Tipo</th>
          <th>Descripción</th>
          <th class="text-end">Monto</th>
          <th>Moneda</th>
          <th>Origen</th>
          <th>Fecha</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($movimientos === []): ?>
        <tr>
          <td colspan="7" class="text-center text-muted py-4">Sin movimientos</td>
        </tr>
      <?php endif; ?>
      <?php foreach ($movimientos as $m): $ingreso = $m['tipo'] === 'ingreso'; ?>
        <tr>
          <td>
            <span class="badge <?= $ingreso ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $ingreso ? 'Ingreso' : 'Egreso' ?></span>
            <div class="small text-muted"><?= e($m['cuenta'] === 'banco' ? 'Banco' : 'Caja') ?></div>
          </td>
          <td>
            <?= e($m['concepto']) ?>
            <div class="small text-muted">Por <?= e($m['registrado_por']) ?></div>
          </td>
          <td class="text-end num fw-semibold <?= $ingreso ? 'text-success' : 'text-danger' ?>">
            <?= $ingreso ? '' : '-' ?><?= e($m['moneda'] === 'USD' ? $usd($m['monto']) : $bs($m['monto'])) ?>
            <?php if ($m['moneda'] === 'VES'): ?><div class="small text-muted fw-normal">≈ <?= e($usd($m['monto_usd'])) ?></div><?php endif; ?>
          </td>
          <td><?= e($m['moneda']) ?></td>
          <td>
            <span class="badge text-bg-light border"><?= e($origenes[$m['origen']] ?? $m['origen']) ?></span>
            <?php if ($m['revierte_id'] !== null): ?><div class="small text-muted">revierte #<?= (int) $m['revierte_id'] ?></div><?php endif; ?>
          </td>
          <td class="small"><?= e((new DateTimeImmutable($m['fecha']))->format('d/m/Y H:i')) ?></td>
          <td class="small text-muted">#<?= (int) $m['id'] ?> · <?= e(num($m['tasa_bs_por_usd'])) ?> Bs/$</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($puedeCrear && $tasa !== null): ?>
<!-- ============================================================
     MODAL: Nuevo Movimiento
     ============================================================ -->
<div class="modal fade" id="modalMovimiento" tabindex="-1" aria-labelledby="tituloMovimiento" aria-hidden="true" data-abrir="<?= $errMov ? '1' : '0' ?>">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloMovimiento"><i class="bi bi-plus-lg"></i> Nuevo Movimiento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form id="formMovimiento" method="post" action="<?= e(url('finanzas_movimiento')) ?>" autocomplete="off" novalidate>
        <?= csrf_field() ?>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label fw-semibold" for="movTipo">Tipo</label>
              <select class="form-select <?= field_class($errMov, 'tipo') ?>" id="movTipo" name="tipo" required>
                <option value="ingreso" <?= ($oldMov['tipo'] ?? 'ingreso') === 'ingreso' ? 'selected' : '' ?>>Ingreso</option>
                <option value="egreso" <?= ($oldMov['tipo'] ?? '') === 'egreso' ? 'selected' : '' ?>>Egreso</option>
              </select>
              <?= field_error($errMov, 'tipo') ?>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold" for="movCuenta">Cuenta</label>
              <select class="form-select <?= field_class($errMov, 'cuenta') ?>" id="movCuenta" name="cuenta" required>
                <?php foreach ($cuentas as $clave => $etiqueta): ?>
                  <option value="<?= e($clave) ?>" <?= ($oldMov['cuenta'] ?? 'caja') === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                <?php endforeach; ?>
              </select>
              <?= field_error($errMov, 'cuenta') ?>
            </div>
            <div class="col-7">
              <label class="form-label fw-semibold" for="movMonto">Monto</label>
              <input type="number" class="form-control <?= field_class($errMov, 'monto') ?>" id="movMonto" name="monto" min="0.01" step="0.01" required placeholder="0.00" value="<?= e($oldMov['monto'] ?? '') ?>">
              <?= field_error($errMov, 'monto') ?>
            </div>
            <div class="col-5">
              <label class="form-label fw-semibold" for="movMoneda">Moneda</label>
              <select class="form-select <?= field_class($errMov, 'moneda') ?>" id="movMoneda" name="moneda" required>
                <option value="USD" <?= ($oldMov['moneda'] ?? 'USD') === 'USD' ? 'selected' : '' ?>>USD</option>
                <option value="VES" <?= ($oldMov['moneda'] ?? '') === 'VES' ? 'selected' : '' ?>>VES</option>
              </select>
              <?= field_error($errMov, 'moneda') ?>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold" for="movDescripcion">Descripción <span class="text-danger">*</span></label>
              <input type="text" class="form-control <?= field_class($errMov, 'descripcion') ?>" id="movDescripcion" name="descripcion" minlength="3" maxlength="160" required
                     placeholder="Ej. Saldo inicial, Inyección de capital" value="<?= e($oldMov['descripcion'] ?? '') ?>">
              <?= field_error($errMov, 'descripcion') ?>
            </div>
          </div>
          <div class="form-text mt-2">La tasa vigente (<?= e(num($tasa['tasa'])) ?> Bs/$) queda congelada en el asiento. La caja de efectivo no puede quedar en negativo; el banco admite sobregiro.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($puedeEditar): ?>
<!-- ============================================================
     MODAL: Actualizar Tasa BCV
     ============================================================ -->
<div class="modal fade" id="modalTasa" tabindex="-1" aria-labelledby="tituloTasa" aria-hidden="true" data-abrir="<?= $errTasa ? '1' : '0' ?>">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloTasa"><i class="bi bi-currency-exchange"></i> Actualizar Tasa BCV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form id="formTasa" method="post" action="<?= e(url('finanzas_tasa')) ?>" autocomplete="off" novalidate>
        <?= csrf_field() ?>
        <div class="modal-body">
          <label class="form-label fw-semibold" for="tasaNueva">Nueva tasa (Bs por 1 USD)</label>
          <div class="input-group has-validation">
            <span class="input-group-text">Bs.</span>
            <input type="number" class="form-control <?= field_class($errTasa, 'tasa') ?>" id="tasaNueva" name="tasa" min="0.0001" step="0.0001" required
                   value="<?= e($old['tasa'] ?? ($tasa !== null ? number_format($tasa['tasa'], 4, '.', '') : '')) ?>" placeholder="857.00">
            <?= field_error($errTasa, 'tasa') ?>
          </div>
          <div class="form-text">Rige desde este momento. Ventas y pagos ya registrados conservan la tasa con la que se asentaron.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.modal[data-abrir="1"]').forEach(function (el) {
    bootstrap.Modal.getOrCreateInstance(el).show();
  });
});
</script>
<?php
layout_end();
