<?php
declare(strict_types=1);

/**
 * @var list<array<string, mixed>> $suscripciones
 * @var list<array<string, mixed>> $pagosSuscripcion
 * @var list<array<string, mixed>> $clientes
 * @var string|null $avisoSuscripcion
 * @var array<string, string> $errores
 * @var array<string, string> $form
 * @var array<string, mixed>|null $suscripcionEditar
 */
$suscripciones = $suscripciones ?? [];
$pagosSuscripcion = $pagosSuscripcion ?? [];
$avisoSuscripcion = $avisoSuscripcion ?? null;
$suscripcionEditar = $suscripcionEditar ?? null;
if (is_array($suscripcionEditar) && ($form['sus_tarifa'] ?? '') === '') {
    $form['sus_id'] = (string) $suscripcionEditar['id'];
    $form['sus_cliente'] = (string) $suscripcionEditar['id_cliente'];
    $form['sus_tarifa'] = (string) $suscripcionEditar['tarifa_usd'];
    $form['sus_monto'] = (string) $suscripcionEditar['monto_pagado'];
    $form['sus_inicio'] = (string) $suscripcionEditar['dia_inicio'];
    $form['sus_corte'] = (string) $suscripcionEditar['dia_corte'];
    $form['sus_modo'] = (string) $suscripcionEditar['modo_pago'];
    $form['sus_referencia'] = (string) ($suscripcionEditar['referencia'] ?? '');
    $form['sus_descripcion'] = (string) ($suscripcionEditar['descripcion_pago'] ?? '');
    $form['sus_pago'] = (string) $suscripcionEditar['fecha_pago'];
    $form['sus_tasa'] = (string) ($suscripcionEditar['tasa_bcv_dia'] ?? '');
}
$editando = ($form['sus_id'] ?? '') !== '';
$err = static function (string $campo) use ($errores): string {
    return isset($errores[$campo]) ? '<div class="invalid-feedback d-block">' . e($errores[$campo]) . '</div>' : '';
};
$val = static fn (string $campo): string => e($form[$campo] ?? '');
$hoy = date('Y-m-d');
$corteSug = date('Y-m-d', strtotime('+30 days'));
?>
<section class="mb-5" id="suscripciones">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
      <h4 class="mb-0">Suscripciones</h4>
      <small class="text-body-secondary">Verde: más de 7 días. Amarillo: 7 o menos. Rojo: vence hoy o ya venció. El color avisa. Suspender el servicio es un botón aparte, en la ficha de la empresa.</small>
    </div>
  </div>

  <?php if ($avisoSuscripcion !== null): ?>
  <div class="alert alert-warning" role="alert"><?= e($avisoSuscripcion) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card card-body">
        <h5 class="mb-3"><?= $editando ? 'Corregir pago' : 'Registrar o renovar pago' ?></h5>
        <form method="post" action="<?= e(url('master_suscripcion_guardar')) ?>">
          <?= csrf_field() ?>
          <?php if ($editando): ?><input type="hidden" name="sus_id" value="<?= e($form['sus_id']) ?>"><?php endif; ?>
          <div class="mb-3">
            <label class="form-label" for="sus_cliente">Empresa</label>
            <select class="form-select" id="sus_cliente" name="sus_cliente"<?= $editando ? ' disabled' : ' required' ?>>
              <option value="">Selecciona</option>
              <?php foreach ($clientes as $c): ?>
              <option value="<?= (int) $c['id'] ?>" data-saldo="<?= e(number_format((float) ($c['saldo_a_favor'] ?? 0), 2, '.', '')) ?>"<?= (string) $c['id'] === ($form['sus_cliente'] ?? '') ? ' selected' : '' ?>><?= e((string) $c['nombre_empresa']) ?> · saldo $<?= e(number_format((float) ($c['saldo_a_favor'] ?? 0), 2, '.', '')) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($editando): ?><input type="hidden" name="sus_cliente" value="<?= e($form['sus_cliente']) ?>"><?php endif; ?>
            <?= $err('sus_cliente') ?>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="sus_tarifa">Tarifa USD</label>
              <input class="form-control" id="sus_tarifa" name="sus_tarifa" inputmode="decimal" required value="<?= $val('sus_tarifa') ?>">
              <?= $err('sus_tarifa') ?>
            </div>
            <div class="col-6">
              <label class="form-label" for="sus_monto">Monto pagado</label>
              <input class="form-control" id="sus_monto" name="sus_monto" inputmode="decimal" value="<?= $val('sus_monto') ?>" placeholder="Igual a la tarifa">
              <?= $err('sus_monto') ?>
            </div>
          </div>
          <?php if (!$editando): ?>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="sus_usar_saldo" name="sus_usar_saldo"<?= ($form['sus_usar_saldo'] ?? '') === '1' ? ' checked' : '' ?>>
            <label class="form-check-label" for="sus_usar_saldo">Descontar la tarifa del saldo a favor. Si el monto pagado queda vacío, se cobra solo el faltante.</label>
          </div>
          <?php endif; ?>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="sus_inicio">Día de inicio</label>
              <input class="form-control" type="date" id="sus_inicio" name="sus_inicio" required value="<?= e($form['sus_inicio'] ?? $hoy) ?>">
              <?= $err('sus_inicio') ?>
            </div>
            <div class="col-6">
              <label class="form-label" for="sus_corte">Día de corte</label>
              <input class="form-control" type="date" id="sus_corte" name="sus_corte" required value="<?= e($form['sus_corte'] ?? $corteSug) ?>">
              <?= $err('sus_corte') ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sus_modo">Modo de pago</label>
            <select class="form-select" id="sus_modo" name="sus_modo" required>
              <?php foreach (SaasComercial::MODOS_PAGO as $codigo => $etiqueta): ?>
              <option value="<?= e($codigo) ?>"<?= ($form['sus_modo'] ?? 'transferencia') === $codigo ? ' selected' : '' ?>><?= e($etiqueta) ?></option>
              <?php endforeach; ?>
            </select>
            <?= $err('sus_modo') ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sus_referencia">Referencia</label>
            <input class="form-control" id="sus_referencia" name="sus_referencia" maxlength="80" value="<?= $val('sus_referencia') ?>">
            <?= $err('sus_referencia') ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="sus_descripcion">Detalle del pago</label>
            <textarea class="form-control" id="sus_descripcion" name="sus_descripcion" rows="2" maxlength="500"><?= $val('sus_descripcion') ?></textarea>
            <?= $err('sus_descripcion') ?>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="sus_pago">Fecha de pago</label>
              <input class="form-control" type="date" id="sus_pago" name="sus_pago" required value="<?= e($form['sus_pago'] ?? $hoy) ?>">
              <?= $err('sus_pago') ?>
            </div>
            <div class="col-6">
              <label class="form-label" for="sus_tasa">Tasa BCV del día</label>
              <input class="form-control" id="sus_tasa" name="sus_tasa" inputmode="decimal" value="<?= $val('sus_tasa') ?>">
              <?= $err('sus_tasa') ?>
            </div>
          </div>
          <button class="btn btn-primary" type="submit"><?= $editando ? 'Guardar corrección' : 'Guardar pago' ?></button>
          <?php if ($editando): ?><a class="btn btn-link" href="<?= e(url('master')) ?>#suscripciones">Cancelar</a><?php endif; ?>
        </form>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Empresa</th>
                <th>Tarifa</th>
                <th>Saldo a favor</th>
                <th>Corte</th>
                <th>Quedan</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($suscripciones === []): ?>
              <tr><td colspan="5" class="text-center text-body-secondary py-4">No hay empresas para mostrar.</td></tr>
            <?php endif; ?>
            <?php foreach ($suscripciones as $s): ?>
              <tr>
                <td class="fw-semibold">
                  <?= e((string) $s['nombre_empresa']) ?>
                  <?php if (($s['estado_suscripcion'] ?? '') === 'Suspendido'): ?><span class="badge text-bg-danger">Suspendido</span><?php endif; ?>
                </td>
                <td><?= $s['tarifa_usd'] === null ? '—' : '$' . e(number_format((float) $s['tarifa_usd'], 2, '.', ',')) ?></td>
                <td>$<?= e(number_format((float) ($s['saldo_a_favor'] ?? 0), 2, '.', ',')) ?></td>
                <td class="small"><?= $s['dia_corte'] === null ? '—' : e(date('d/m/Y', strtotime((string) $s['dia_corte']))) ?></td>
                <td>
                  <span class="badge text-bg-<?= e((string) $s['color']) ?> js-cronometro"<?= $s['dia_corte'] !== null ? ' data-corte="' . e((string) $s['dia_corte']) . '"' : '' ?>>
                    <?= e(SaasComercial::textoDias($s['dias'] === null ? null : (int) $s['dias'])) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pagosSuscripcion !== []): ?>
      <div class="card mt-3">
        <div class="card-body pb-0"><h6 class="mb-0">Últimos pagos</h6></div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th>Empresa</th><th>Período</th><th>Pago</th><th>Detalle</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($pagosSuscripcion as $p): ?>
              <tr>
                <td><?= e((string) $p['nombre_empresa']) ?></td>
                <td class="small"><?= e(date('d/m/Y', strtotime((string) $p['dia_inicio']))) ?> – <?= e(date('d/m/Y', strtotime((string) $p['dia_corte']))) ?></td>
                <td class="small">$<?= e(number_format((float) ($p['monto_pagado'] ?? $p['tarifa_usd']), 2, '.', ',')) ?> · <?= e(SaasComercial::MODOS_PAGO[(string) $p['modo_pago']] ?? (string) $p['modo_pago']) ?></td>
                <td class="small"><?= e((string) (($p['descripcion_pago'] ?? '') !== '' ? $p['descripcion_pago'] : ($p['referencia'] ?? '—'))) ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('master', ['suscripcion' => (int) $p['id']])) ?>#suscripciones">Editar</a>
                  <form method="post" action="<?= e(url('master_suscripcion_borrar')) ?>" class="d-inline js-confirmar" data-confirmar="¿Borrar este pago? El saldo a favor se revierte si todavía alcanza.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Borrar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<script src="<?= e(asset_v('js/suscripcion_cronometro.js')) ?>"></script>
