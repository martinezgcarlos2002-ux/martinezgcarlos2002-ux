<?php
declare(strict_types=1);

/**
 * Directorio de productores.
 * @var list<array> $productores
 * @var array{productores:int, suministrado_usd:string, pagado_usd:string, saldo_usd:string} $kpis
 * @var string $q
 * @var 'activos'|'inactivos'|'todos' $estado
 * @var 'nombre'|'id' $orden
 * @var int|null $diaCierre  filtro ?dia_cierre=1..7
 * @var bool $puedeCrear
 * @var bool $puedeEditar
 * @var bool $puedeArchivar
 */
$usd = static fn (mixed $v, int $d = 2): string => ((float) $v < 0 ? '-' : '') . '$' . num(abs((float) $v), $d);
$diaCierreFiltro = $diaCierre;
/** Clave enviada como tolerancia_pago => [etiqueta, días de gracia, clase de color] */
$tolerancias = [
    'bajo'  => ['Bajo', Productor::TOLERANCIAS['bajo'], 'danger'],
    'medio' => ['Medio', Productor::TOLERANCIAS['medio'], 'warning'],
    'alto'  => ['Alto', Productor::TOLERANCIAS['alto'], 'success'],
];

layout_start('Productores y Proveedores', 'productores');
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num"><?= e(num($kpis['productores_activos'], 0)) ?></div>
        <div class="text-muted">Total Suministradores</div>
        <div class="small text-muted">activos de <?= e(num($kpis['productores_total'], 0)) ?> registrados</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="text-muted text-center mb-1">Total Suministrado</div>
        <div class="d-flex justify-content-between small border-bottom py-1">
          <span class="text-muted">Litros históricos</span><strong class="num"><?= e(num($kpis['litros'], 1)) ?> L</strong>
        </div>
        <div class="d-flex justify-content-between small border-bottom py-1">
          <span class="text-muted">Precio promedio ponderado</span><strong class="num"><?= $kpis['precio_promedio'] !== null ? e($usd($kpis['precio_promedio'], 4)) . '/L' : '—' ?></strong>
        </div>
        <div class="d-flex justify-content-between align-items-baseline pt-1">
          <span class="text-muted small">Inversión total</span><span class="kpi-num kpi-info fs-5"><?= e($usd($kpis['suministrado_usd'])) ?></span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num kpi-ok"><?= e($usd($kpis['pagado_usd'])) ?></div>
        <div class="text-muted">Total Pagado</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="kpi-num <?= (float) $kpis['saldo_usd'] > 0 ? 'kpi-deuda' : 'kpi-ok' ?>"><?= e($usd($kpis['saldo_usd'])) ?></div>
        <div class="text-muted">Saldo Pendiente</div>
      </div>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
  <form class="d-flex flex-wrap gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
    <input type="hidden" name="p" value="productores">
    <input type="search" name="q" class="form-control form-control-sm w-filtro" placeholder="Buscar..." maxlength="60" value="<?= e($q) ?>" aria-label="Buscar productor">
    <select name="tipo" class="form-select form-select-sm w-tipo" aria-label="Tipo">
      <option value="">Todos (<?= count($productores) ?>)</option>
      <option value="leche">Leche</option>
    </select>
    <select name="estado" class="form-select form-select-sm w-estado" aria-label="Estado">
      <option value="" <?= $estado === 'todos' ? 'selected' : '' ?>>Estado</option>
      <option value="activo" <?= $estado === 'activos' ? 'selected' : '' ?>>Activos</option>
      <option value="inactivo" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
    </select>
    <select name="dia_cierre" class="form-select form-select-sm w-estado" aria-label="Día de Cierre">
      <option value="">Día de Cierre: Todos</option>
      <?php foreach (CierreSemanal::DIAS as $n => $dia): ?>
      <option value="<?= $n ?>" <?= $diaCierreFiltro === $n ? 'selected' : '' ?>><?= e($dia) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="orden" class="form-select form-select-sm w-estado" aria-label="Ordenar por">
      <option value="nombre" <?= $orden === 'nombre' ? 'selected' : '' ?>>Nombre (A-Z)</option>
      <option value="id" <?= $orden === 'id' ? 'selected' : '' ?>>N° de registro</option>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <?php if ($q !== '' || $estado !== 'todos' || $orden !== 'nombre' || $diaCierreFiltro !== null): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productores')) ?>"><i class="bi bi-x-lg"></i> Limpiar</a>
    <?php endif; ?>
  </form>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-semanal" href="<?= e(url('productores_control_semanal')) ?>"><i class="bi bi-table"></i> Control Semanal</a>
    <a class="btn btn-sm btn-facturas" href="<?= e(url('productores_facturas_masivas')) ?>"><i class="bi bi-files"></i> Facturas Masivas</a>
    <button type="button" class="btn btn-sm btn-importar"><i class="bi bi-upload"></i> Importar Pagos</button>
    <a class="btn btn-sm btn-notaria" target="_blank" rel="noopener" href="<?= e(url('productores_notaria')) ?>"><i class="bi bi-journal-text"></i> Notaría</a>
    <?php if ($puedeCrear): ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalProductor" data-modo="nuevo">
        <i class="bi bi-plus-lg"></i> Nuevo Productor
      </button>
    <?php endif; ?>
  </div>
</div>

<p class="text-muted small mb-2"><i class="bi bi-hand-index"></i> Toca un productor para abrir toda su información</p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Tipo</th>
          <th>Teléfono</th>
          <th class="text-end">Precio/L</th>
          <th class="text-end">Suministrado</th>
          <th class="text-end">Pagado</th>
          <th class="text-end">Saldo</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($productores === []): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No hay productores que coincidan con el filtro.</td></tr>
      <?php endif; ?>
      <?php foreach ($productores as $p):
          $pid = (int) $p['id'];
          $saldo = (float) $p['saldo_usd'];
          $activo = (int) $p['activo'] === 1;
      ?>
        <tr>
          <td>
            <a class="link-productor" href="<?= e(url('productor_control', ['id' => $pid])) ?>"><?= e($p['nombre']) ?></a>
            <?php if ($p['documento']): ?><div class="text-muted small"><?= e($p['documento']) ?></div><?php endif; ?>
          </td>
          <td><span class="badge badge-leche"><i class="bi bi-droplet"></i> Leche</span></td>
          <td class="text-muted"><?= e($p['telefono'] ?: '—') ?></td>
          <td class="text-end num"><?= e($usd($p['precio_litro_usd'], 4)) ?></td>
          <td class="text-end num fw-semibold"><?= e($usd($p['suministrado_usd'])) ?></td>
          <td class="text-end num text-success"><?= e($usd($p['pagado_usd'])) ?></td>
          <td class="text-end num fw-bold <?= $saldo > 0 ? 'text-danger' : 'text-success' ?>"><?= e($usd($saldo)) ?></td>
          <td>
            <span class="badge <?= $activo ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $activo ? 'Activo' : 'Archivado' ?></span>
          </td>
          <td>
            <div class="d-flex gap-1">
              <a class="btn btn-sm btn-outline-success" href="<?= e(url('productor_control', ['id' => $pid])) ?>"><i class="bi bi-eye"></i> Ver</a>
              <?php if ($puedeEditar): ?>
              <button type="button" class="btn btn-sm btn-outline-warning"
                      data-bs-toggle="modal" data-bs-target="#modalProductor"
                      data-modo="editar"
                      data-action="<?= e(url('productores_editar', ['id' => $pid])) ?>"
                      data-nombre="<?= e($p['nombre']) ?>"
                      data-documento="<?= e($p['documento'] ?? '') ?>"
                      data-telefono="<?= e($p['telefono'] ?? '') ?>"
                      data-correo="<?= e($p['correo'] ?? '') ?>"
                      data-direccion="<?= e($p['direccion'] ?? '') ?>"
                      data-precio="<?= e($p['precio_litro_usd']) ?>"
                      data-credito="<?= e($p['credito_dias']) ?>"
                      data-cierre="<?= e((string) $p['dia_cierre']) ?>"
                      data-tolerancia="<?= e((string) $p['tolerancia_pago']) ?>"
                      data-estado="<?= $activo ? 'activo' : 'inactivo' ?>">
                <i class="bi bi-pencil"></i> Editar
              </button>
              <?php endif; ?>
              <?php if ($puedeArchivar && $activo): ?>
              <button type="button" class="btn btn-sm btn-outline-danger"
                      data-bs-toggle="modal" data-bs-target="#modalEliminar"
                      data-id="<?= $pid ?>"
                      data-nombre="<?= e($p['nombre']) ?>"
                      aria-label="Archivar <?= e($p['nombre']) ?>">
                <i class="bi bi-trash"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($puedeCrear || $puedeEditar): ?>
<!-- ============================================================
     MODAL: Nuevo / Editar Productor
     ============================================================ -->
<div class="modal fade" id="modalProductor" tabindex="-1" aria-labelledby="tituloProductor" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content modal-navy">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloProductor"><i class="bi bi-droplet-fill"></i> Nuevo Productor de Leche</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form id="formProductor" method="post" action="<?= e(url('productores_nuevo')) ?>" data-action-nuevo="<?= e(url('productores_nuevo')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold" for="prodNombre">Nombre <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="prodNombre" name="nombre" required minlength="2" maxlength="120">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="prodDocumentoNum">Documento</label>
              <?php input_group_documento(['name' => 'documento', 'id' => 'prodDocumento']); ?>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="prodTelefonoNum">Teléfono</label>
              <?php input_group_telefono(['name' => 'telefono', 'id' => 'prodTelefono']); ?>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="prodCorreo">Correo</label>
              <input type="email" class="form-control" id="prodCorreo" name="correo" maxlength="100">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold" for="prodDireccion">Dirección</label>
              <textarea class="form-control" id="prodDireccion" name="direccion" rows="2" maxlength="160"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="prodPrecio">Precio/L (USD) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" class="form-control" id="prodPrecio" name="precio_litro_usd" min="0.0001" max="100" step="0.0001" value="0.6000" required>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="prodCredito">Crédito (días)</label>
              <input type="number" class="form-control" id="prodCredito" name="credito_dias" min="0" max="90" step="1" value="7" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" for="prodCierre">Día de Cierre de Semana <span class="text-danger">*</span></label>
              <select class="form-select" id="prodCierre" name="dia_cierre" required>
                <?php foreach (CierreSemanal::DIAS as $n => $dia): ?>
                <option value="<?= $n ?>"<?= $n === CierreSemanal::DIA_CIERRE_DEFAULT ? ' selected' : '' ?>><?= e($dia) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold d-flex align-items-center gap-2" for="prodTolerancia">
                Tolerancia de Pago <span class="text-danger">*</span>
                <span class="badge rounded-pill text-bg-secondary" id="badgeTolerancia">—</span>
              </label>
              <select class="form-select" id="prodTolerancia" name="tolerancia_pago" required>
                <option value="" selected disabled>— Seleccionar —</option>
                <?php foreach ($tolerancias as $clave => [$etiqueta, $dias, $color]): ?>
                <option value="<?= e($clave) ?>" class="text-<?= $color ?>-emphasis" data-color="<?= $color ?>" data-dias="<?= $dias ?>"><?= e("$etiqueta ($dias días)") ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Días de gracia después del cierre antes de marcar el pago como atrasado.</div>
            </div>
            <div class="col-md-4 solo-editar d-none">
              <label class="form-label fw-semibold" for="prodEstado">Estado</label>
              <select class="form-select" id="prodEstado" name="estado" disabled>
                <option value="activo" selected>Activo</option>
                <option value="inactivo">Inactivo</option>
              </select>
            </div>
            <div class="col-12 solo-editar d-none">
              <label class="form-label fw-semibold" for="prodMotivo">Motivo del cambio de precio <span class="text-muted">(si aplica)</span></label>
              <input type="text" class="form-control" id="prodMotivo" name="motivo_precio" maxlength="160" disabled>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($puedeArchivar): ?>
<!-- ============================================================
     MODAL: Archivar / eliminar productor
     ============================================================ -->
<div class="modal fade" id="modalEliminar" tabindex="-1" aria-labelledby="tituloEliminar" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="<?= e(url('productores_archivar')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="eliminarId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloEliminar"><i class="bi bi-trash"></i> Archivar productor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          ¿Archivar a <strong id="eliminarNombre">este productor</strong>?
          <div class="text-muted small mt-2">Si no tiene entregas, pagos ni cierres se elimina. Si tiene historial se desactiva y se conserva. No se puede archivar con saldo abierto.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Archivar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var modal = document.getElementById('modalProductor');
  var selTolerancia = document.getElementById('prodTolerancia');
  var badgeTolerancia = document.getElementById('badgeTolerancia');
  function pintarTolerancia() {
    var opt = selTolerancia.options[selTolerancia.selectedIndex];
    var color = opt && opt.dataset.color ? opt.dataset.color : 'secondary';
    badgeTolerancia.className = 'badge rounded-pill text-bg-' + color;
    badgeTolerancia.textContent = opt && opt.dataset.dias ? opt.dataset.dias + ' días' : '—';
    ['danger', 'warning', 'success'].forEach(function (c) {
      selTolerancia.classList.toggle('border-' + c, c === color);
    });
  }
  if (selTolerancia) {
    selTolerancia.addEventListener('change', pintarTolerancia);
  }
  if (modal) {
    var form = document.getElementById('formProductor');
    modal.addEventListener('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      var editar = !!btn && btn.getAttribute('data-modo') === 'editar';
      var attr = function (name, def) { return editar ? (btn.getAttribute('data-' + name) || def) : def; };

      form.action = editar ? btn.getAttribute('data-action') : form.getAttribute('data-action-nuevo');
      document.getElementById('tituloProductor').innerHTML = editar
        ? '<i class="bi bi-pencil"></i> Editar Productor de Leche'
        : '<i class="bi bi-droplet-fill"></i> Nuevo Productor de Leche';
      document.getElementById('prodNombre').value = attr('nombre', '');
      document.getElementById('prodDocumento').value = attr('documento', '');
      document.getElementById('prodTelefono').value = attr('telefono', '');
      if (window.AdaraContacto) {
        AdaraContacto.setDocumento('prodDocumento', attr('documento', ''));
        AdaraContacto.setTelefono('prodTelefono', attr('telefono', ''));
      }
      document.getElementById('prodCorreo').value = attr('correo', '');
      document.getElementById('prodDireccion').value = attr('direccion', '');
      document.getElementById('prodPrecio').value = attr('precio', '0.6000');
      document.getElementById('prodCredito').value = attr('credito', '7');
      var cierre = attr('cierre', '<?= CierreSemanal::DIA_CIERRE_DEFAULT ?>');
      document.getElementById('prodCierre').value = ['1', '2', '3', '4', '5', '6', '7'].indexOf(cierre) >= 0 ? cierre : '<?= CierreSemanal::DIA_CIERRE_DEFAULT ?>';
      var tolerancia = attr('tolerancia', '');
      selTolerancia.value = ['bajo', 'medio', 'alto'].indexOf(tolerancia) >= 0 ? tolerancia : '';
      pintarTolerancia();
      document.getElementById('prodEstado').value = attr('estado', 'activo');
      document.getElementById('prodMotivo').value = '';
      form.querySelectorAll('.solo-editar').forEach(function (el) {
        el.classList.toggle('d-none', !editar);
        el.querySelectorAll('input, select').forEach(function (f) { f.disabled = !editar; });
      });
    });
  }

  var borrar = document.getElementById('modalEliminar');
  if (borrar) {
    borrar.addEventListener('show.bs.modal', function (ev) {
      var btn = ev.relatedTarget;
      document.getElementById('eliminarId').value = btn ? (btn.getAttribute('data-id') || '') : '';
      document.getElementById('eliminarNombre').textContent = btn ? (btn.getAttribute('data-nombre') || 'este productor') : 'este productor';
    });
  }
})();
</script>
<?php
layout_end();
