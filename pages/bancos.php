<?php
declare(strict_types=1);

/** @var bool $puedeCrear */
/** @var bool $puedeEditar */
/** @var bool $puedeAnular */
/** @var list<array<string, mixed>> $cuentas */
/** @var list<array<string, mixed>> $cierres */
$puedeCrear = $puedeCrear ?? false;
$puedeEditar = $puedeEditar ?? false;
$puedeAnular = $puedeAnular ?? false;
$cuentas = $cuentas ?? [];
$cierres = $cierres ?? [];
$bs = static function (mixed $v): string {
    $n = (float) $v;
    $neg = $n < 0;
    return ($neg ? '-' : '') . 'Bs. ' . number_format(abs($n), 2, ',', '.');
};

$config = [
    'crear' => $puedeCrear,
    'editar' => $puedeEditar,
    'anular' => $puedeAnular,
    'url' => [
        'subir' => url('bancos_subir'),
        'plantilla' => url('bancos_plantilla'),
        'auditoria' => url('bancos_auditoria'),
        'cuentaCrear' => url('bancos_cuenta_crear'),
        'cuentaEditar' => url('bancos_cuenta_editar'),
        'cuentaOff' => url('bancos_cuenta_desactivar'),
        'cuentaOn' => url('bancos_cuenta_activar'),
    ],
    'cuentas' => $cuentas,
];

layout_start('Conciliación bancaria', 'bancos');
?>
<link rel="stylesheet" href="<?= e(asset_v('css/bancos.css')) ?>">

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div>
    <h5 class="mb-1">Conciliación bancaria</h5>
    <p class="text-muted small mb-0">Crea las cuentas y copia sus datos para WhatsApp. La plantilla trae siete hojas: el cuadre en Total Bancos y los movimientos en Clientes, Nómina, Productores, Transporte y Gastos Generales. La tasa del día y las listas viven en Parametros y en Total Bancos.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" href="<?= e(url('bancos_plantilla')) ?>">
      <i class="bi bi-download"></i> Plantilla modelo
    </a>
    <?php if ($puedeCrear): ?>
      <button type="button" class="btn btn-primary" id="btnSubir">
        <i class="bi bi-cloud-arrow-up"></i> Subir conciliación diaria
      </button>
      <input type="file" id="archivoConciliacion" accept=".xlsx,.xlsm,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-tabs mb-3" id="bancosTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="tab-operacion" data-bs-toggle="tab" data-bs-target="#paneOperacion" type="button" role="tab">Operación</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-auditoria" data-bs-toggle="tab" data-bs-target="#paneAuditoria" type="button" role="tab">Auditoría (Cruces)</button>
  </li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="paneOperacion" role="tabpanel">

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center"><div class="card-body py-3">
      <div class="kpi-num"><?= count($cuentas) ?></div>
      <div class="text-muted small">Cuentas</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center"><div class="card-body py-3">
      <div class="kpi-num"><?= count($cierres) ?></div>
      <div class="text-muted small">Cierres recientes</div>
    </div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <h6 class="section-title">Cuentas</h6>
    <p class="text-muted small">El alias es el que aparece en el Excel. Copiar datos arma el texto de Banco, cuenta, CI y teléfono para pegarlo en WhatsApp.</p>
    <?php if ($puedeCrear || $puedeEditar): ?>
      <form class="bancos-cuenta-form mb-3" id="formCuenta">
        <label class="bancos-campo"><span>Alias</span><input class="form-control" id="ctaAlias" maxlength="80" placeholder="Venezuela Carlos" required></label>
        <label class="bancos-campo"><span>Banco</span><input class="form-control" id="ctaBanco" maxlength="80" placeholder="Banco de Venezuela" required></label>
        <label class="bancos-campo"><span>Número</span><input class="form-control" id="ctaNumero" maxlength="30" required></label>
        <label class="bancos-campo"><span>Cédula</span><input class="form-control" id="ctaCedula" maxlength="24" required></label>
        <label class="bancos-campo"><span>Teléfono</span><input class="form-control" id="ctaTelefono" maxlength="24" required></label>
        <label class="bancos-campo"><span>Moneda</span>
          <select class="form-select" id="ctaMoneda">
            <option value="BS">BS</option>
            <option value="USD">USD</option>
          </select>
        </label>
        <div class="bancos-cuenta-acciones">
          <button class="btn btn-primary" type="submit" id="ctaGuardar"<?= $puedeCrear ? '' : ' hidden' ?>>Agregar</button>
          <button class="btn btn-outline-secondary" type="button" id="ctaCancelar" hidden>Cancelar</button>
        </div>
      </form>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" id="tablaCuentas">
        <thead><tr>
          <th>Alias</th><th>Banco</th><th>Número</th><th>CI</th><th>Teléfono</th><th>Moneda</th>
          <th class="text-end">Saldo</th><th class="text-end">Acciones</th>
        </tr></thead>
        <tbody>
        <?php if ($cuentas === []): ?>
          <tr><td colspan="8" class="text-muted">Todavía no hay cuentas. Agrégalas aquí antes de descargar la plantilla.</td></tr>
        <?php else: ?>
          <?php foreach ($cuentas as $cuenta): ?>
            <tr data-id="<?= (int) $cuenta['id'] ?>" class="<?= (int) $cuenta['activa'] === 1 ? '' : 'text-muted' ?>">
              <td><?= e((string) $cuenta['alias_cuenta']) ?></td>
              <td><?= e((string) ($cuenta['nombre_banco'] ?? '')) ?></td>
              <td><?= e((string) ($cuenta['numero_cuenta'] ?? '')) ?></td>
              <td><?= e((string) ($cuenta['cedula'] ?? '')) ?></td>
              <td><?= e((string) ($cuenta['telefono'] ?? '')) ?></td>
              <td><?= e((string) ($cuenta['moneda'] ?? 'BS')) ?></td>
              <td class="text-end"><?= e($bs($cuenta['saldo_actual_bs'])) ?></td>
              <td class="text-end">
                <div class="bancos-acciones">
                  <button type="button" class="btn btn-sm btn-outline-primary cuenta-copiar" data-copiar="<?= e((string) ($cuenta['texto_compartir'] ?? '')) ?>">Copiar datos</button>
                  <?php if ($puedeEditar && (int) $cuenta['activa'] === 1): ?><button type="button" class="btn btn-sm btn-outline-secondary cuenta-editar">Editar</button><?php endif; ?>
                  <?php if ($puedeAnular && (int) $cuenta['activa'] === 1): ?><button type="button" class="btn btn-sm btn-outline-danger cuenta-off">Desactivar</button><?php endif; ?>
                  <?php if ($puedeEditar && (int) $cuenta['activa'] !== 1): ?><button type="button" class="btn btn-sm btn-outline-secondary cuenta-on">Activar</button><?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h6 class="section-title">Cierres cuadrados</h6>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr>
          <th>Fecha</th><th>Cuenta</th>
          <th class="text-end">Inicial</th><th class="text-end">Ingresos</th>
          <th class="text-end">Egresos</th><th class="text-end">Comisiones</th>
          <th class="text-end">Final</th><th>Archivo</th>
        </tr></thead>
        <tbody>
        <?php if ($cierres === []): ?>
          <tr><td colspan="8" class="text-muted">Sin cierres.</td></tr>
        <?php else: ?>
          <?php foreach ($cierres as $cierre): ?>
            <?php $fecha = DateTimeImmutable::createFromFormat('Y-m-d', (string) $cierre['fecha']); ?>
            <tr>
              <td><?= e($fecha ? $fecha->format('d/m/Y') : (string) $cierre['fecha']) ?></td>
              <td><?= e((string) $cierre['alias_cuenta']) ?></td>
              <td class="text-end"><?= e($bs($cierre['saldo_inicio'])) ?></td>
              <td class="text-end"><?= e($bs($cierre['total_ingresos'])) ?></td>
              <td class="text-end"><?= e($bs($cierre['total_egresos'])) ?></td>
              <td class="text-end"><?= e($bs($cierre['total_comisiones'])) ?></td>
              <td class="text-end fw-semibold"><?= e($bs($cierre['saldo_final'])) ?></td>
              <td class="small text-muted"><?= e((string) ($cierre['archivo'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div>

<div class="tab-pane fade" id="paneAuditoria" role="tabpanel">
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-body">
          <h6 class="section-title">Movimientos huérfanos</h6>
          <p class="text-muted small">Están en el banco y faltan en ADARA. La carga no se detiene por esto.</p>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="tablaHuerfanos">
              <thead><tr><th>Fecha</th><th>Cuenta</th><th>Categoría</th><th>Referencia</th><th class="text-end">Monto</th></tr></thead>
              <tbody><tr><td colspan="5" class="text-muted">Cargando…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-body">
          <h6 class="section-title">Pagos fantasmas</h6>
          <p class="text-muted small">Están en ADARA y faltan en el banco. Últimos 90 días de clientes, productores, nómina, transporte y gas.</p>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="tablaFantasmas">
              <thead><tr><th>Fecha</th><th>Módulo</th><th>Entidad</th><th>Referencia</th><th class="text-end">Monto</th></tr></thead>
              <tbody><tr><td colspan="5" class="text-muted">Cargando…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</div>

<script type="application/json" id="bancosConfig" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.15.10/dist/sweetalert2.min.js" defer></script>
<script src="<?= e(asset_v('js/bancos.js')) ?>" defer></script>
<?php
layout_end();
