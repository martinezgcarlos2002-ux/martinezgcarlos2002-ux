<?php
declare(strict_types=1);

/**
 * Usuarios del Sistema.
 * @var list<array> $usuarios
 * @var string $q
 * @var array<string, array{label:string}> $asignables   módulos que se asignan por usuario
 * @var list<string> $acciones                          ver|crear|editar|anular
 * @var int $yo
 * @var array<string, string> $old
 * @var array<string, string> $errors
 */
$etiquetasAccion = ['ver' => 'Ver', 'crear' => 'Crear', 'editar' => 'Editar', 'anular' => 'Anular'];
$modoForm = isset($old['id']) && $old['id'] !== '' ? 'editar' : (isset($old['email']) ? 'nuevo' : null);

layout_start('Usuarios del Sistema', 'usuarios');
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <form class="d-flex gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
    <input type="hidden" name="p" value="usuarios">
    <input type="search" name="q" class="form-control form-control-sm w-filtro" placeholder="Buscar usuario..." maxlength="80" value="<?= e($q) ?>" aria-label="Buscar usuario">
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i> Buscar</button>
  </form>
  <button type="button" class="btn btn-primary ms-lg-auto" data-bs-toggle="modal" data-bs-target="#modalUsuario" data-modo="nuevo">
    <i class="bi bi-plus-lg"></i> Nuevo Usuario
  </button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr class="small text-uppercase text-muted">
          <th>Nombre</th>
          <th>Email</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>Acceso Móvil</th>
          <th>Módulos con acceso</th>
          <th>Creado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($usuarios === []): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Sin usuarios que coincidan.</td></tr>
      <?php endif; ?>
      <?php foreach ($usuarios as $u):
          $admin = $u['rol'] === 'admin';
          $soyYo = $u['id'] === $yo;
          $mods = array_map(static fn (string $m): string => modulos()[$m]['label'] ?? $m, array_keys($u['permisos']));
      ?>
        <tr>
          <td class="fw-semibold"><?= e($u['nombre']) ?><?php if ($soyYo): ?> <span class="badge text-bg-light border">Tú</span><?php endif; ?></td>
          <td class="small"><?= e($u['email']) ?></td>
          <td><span class="badge <?= $admin ? 'text-bg-danger' : 'text-bg-primary' ?>"><?= $admin ? 'Admin' : 'Empleado' ?></span></td>
          <td><span class="badge <?= $u['activo'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
          <td>
            <span class="badge <?= $u['acceso_movil'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $u['acceso_movil'] ? 'Permitido' : 'Bloqueado' ?></span>
          </td>
          <td class="small <?= $admin ? 'text-success fw-semibold' : '' ?>">
            <?= e($admin ? 'Acceso total' : ($mods ? implode(', ', $mods) : 'Solo Dashboard')) ?>
          </td>
          <td class="small"><?= e((new DateTimeImmutable($u['creado_en']))->format('d/m/Y')) ?></td>
          <td class="text-nowrap">
            <button type="button" class="btn btn-sm btn-outline-warning"
                    data-bs-toggle="modal" data-bs-target="#modalUsuario" data-modo="editar"
                    data-id="<?= $u['id'] ?>"
                    data-nombre="<?= e($u['nombre']) ?>"
                    data-email="<?= e($u['email']) ?>"
                    data-rol="<?= e($u['rol']) ?>"
                    data-estado="<?= $u['activo'] ? 'activo' : 'inactivo' ?>"
                    data-movil="<?= $u['acceso_movil'] ? '1' : '0' ?>"
                    data-yo="<?= $soyYo ? '1' : '0' ?>">
              <i class="bi bi-pencil"></i> Editar
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal" data-bs-target="#modalPermisos"
                    data-id="<?= $u['id'] ?>"
                    data-nombre="<?= e($u['nombre']) ?>"
                    data-permisos="<?= e(json_encode((object) $u['permisos'], JSON_THROW_ON_ERROR)) ?>"
                    <?= $admin ? 'disabled title="El administrador tiene acceso total"' : '' ?>>
              <i class="bi bi-key"></i> Permisos
            </button>
            <form method="post" action="<?= e(url('usuarios_movil')) ?>" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $u['acceso_movil'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                      title="<?= $u['acceso_movil'] ? 'Bloquear acceso móvil' : 'Permitir acceso móvil' ?>">
                <i class="bi bi-phone"></i> Móvil
              </button>
            </form>
            <?php if (!$soyYo): ?>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    data-bs-toggle="modal" data-bs-target="#modalEliminar"
                    data-id="<?= $u['id'] ?>"
                    data-nombre="<?= e($u['nombre']) ?>">
              <i class="bi bi-trash"></i> Eliminar
            </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============================================================
     MODAL: Nuevo / Editar Usuario
     ============================================================ -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-labelledby="tituloUsuario" aria-hidden="true"
     data-abrir="<?= $modoForm !== null && $errors ? '1' : '0' ?>"
     data-action-nuevo="<?= e(url('usuarios_nuevo')) ?>"
     data-action-editar="<?= e(url('usuarios_editar')) ?>">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloUsuario"><?= $modoForm === 'editar' ? 'Editar Usuario' : 'Nuevo Usuario' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form id="formUsuario" method="post" action="<?= e(url($modoForm === 'editar' ? 'usuarios_editar' : 'usuarios_nuevo')) ?>" autocomplete="off" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="usrId" value="<?= e($old['id'] ?? '') ?>">
        <div class="modal-body">
          <?php if (isset($errors['id'])): ?><div class="alert alert-danger py-2 small"><?= e($errors['id']) ?></div><?php endif; ?>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="usrNombre">Nombre completo</label>
            <input type="text" class="form-control <?= field_class($errors, 'nombre') ?>" id="usrNombre" name="nombre" minlength="2" maxlength="80" required value="<?= e($old['nombre'] ?? '') ?>">
            <?= field_error($errors, 'nombre') ?>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="usrEmail">Correo electrónico</label>
            <input type="email" class="form-control <?= field_class($errors, 'email') ?>" id="usrEmail" name="email" maxlength="100" required value="<?= e($old['email'] ?? '') ?>">
            <?= field_error($errors, 'email') ?>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="usrClave">Contraseña <span class="text-muted fw-normal" id="usrClaveAyuda">(dejar vacío para no cambiar)</span></label>
            <input type="password" class="form-control <?= field_class($errors, 'password') ?>" id="usrClave" name="password" minlength="<?= PASSWORD_MIN_LEN ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>" autocomplete="new-password" placeholder="Mínimo <?= PASSWORD_MIN_LEN ?> caracteres">
            <?= field_error($errors, 'password') ?>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold" for="usrRol">Rol</label>
              <select class="form-select <?= field_class($errors, 'rol') ?>" id="usrRol" name="rol">
                <option value="empleado" <?= ($old['rol'] ?? 'empleado') === 'empleado' ? 'selected' : '' ?>>Empleado</option>
                <option value="admin" <?= ($old['rol'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
              </select>
              <?= field_error($errors, 'rol') ?>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold" for="usrEstado">Estado</label>
              <select class="form-select <?= field_class($errors, 'estado') ?>" id="usrEstado" name="estado">
                <option value="activo" <?= ($old['estado'] ?? 'activo') === 'activo' ? 'selected' : '' ?>>Activo</option>
                <option value="inactivo" <?= ($old['estado'] ?? '') === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
              </select>
              <?= field_error($errors, 'estado') ?>
            </div>
          </div>
          <div class="form-check form-switch border rounded p-3 ps-5">
            <input class="form-check-input" type="checkbox" role="switch" id="usrMovil" name="acceso_movil" value="1" <?= isset($old['acceso_movil']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="usrMovil">
              <i class="bi bi-phone"></i> Permitir acceso móvil
              <span class="d-block text-muted small">Los módulos y acciones se asignan desde el botón Permisos.</span>
            </label>
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

<!-- ============================================================
     MODAL: Permisos por módulo y acción
     ============================================================ -->
<div class="modal fade" id="modalPermisos" tabindex="-1" aria-labelledby="tituloPermisos" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content modal-navy">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloPermisos"><i class="bi bi-key"></i> Permisos — <span id="permNombre"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form id="formPermisos" method="post" action="<?= e(url('usuarios_permisos')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="permId" value="">
      </form>
      <div class="modal-body">
        <p class="text-muted small">Marca los módulos a los que este usuario tendrá acceso y qué puede hacer en cada uno. Los módulos no marcados quedarán completamente bloqueados. Dashboard siempre es visible; Usuarios, Auditoría y Respaldo son exclusivos de administradores.</p>
        <div class="row g-2" id="permGrid">
          <?php foreach ($asignables as $clave => $modulo): ?>
          <div class="col-md-6">
            <div class="border rounded px-3 py-2 h-100" data-modulo="<?= e($clave) ?>">
              <div class="form-check mb-1">
                <input class="form-check-input js-modulo" type="checkbox" id="perm-<?= e($clave) ?>" aria-label="Acceso a <?= e($modulo['label']) ?>">
                <label class="form-check-label fw-semibold" for="perm-<?= e($clave) ?>"><?= e($modulo['label']) ?></label>
              </div>
              <div class="d-flex flex-wrap gap-3 ps-4">
                <?php foreach ($acciones as $accion): ?>
                <div class="form-check form-check-inline m-0">
                  <input class="form-check-input js-accion" type="checkbox" form="formPermisos"
                         name="permisos[<?= e($clave) ?>][]" value="<?= e($accion) ?>" id="perm-<?= e($clave) ?>-<?= e($accion) ?>">
                  <label class="form-check-label small" for="perm-<?= e($clave) ?>-<?= e($accion) ?>"><?= e($etiquetasAccion[$accion] ?? $accion) ?></label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-sm btn-outline-success" id="permTodos"><i class="bi bi-check2-all"></i> Marcar todo</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="permSoloVer"><i class="bi bi-eye"></i> Solo ver</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="permNinguno"><i class="bi bi-x-lg"></i> Quitar todo</button>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button>
          <button type="submit" form="formPermisos" class="btn btn-primary"><i class="bi bi-save"></i> Guardar Permisos</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: Eliminar usuario
     ============================================================ -->
<div class="modal fade" id="modalEliminar" tabindex="-1" aria-labelledby="tituloEliminar" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="<?= e(url('usuarios_eliminar')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="eliminarId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloEliminar"><i class="bi bi-trash"></i> Eliminar usuario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          ¿Eliminar a <strong id="eliminarNombre">este usuario</strong>? Perderá el acceso de inmediato.
          <div class="text-muted small mt-2">Su historial de auditoría se conserva y su correo no podrá reutilizarse.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var modalUsuario = document.getElementById('modalUsuario');
  var formUsuario = document.getElementById('formUsuario');
  modalUsuario.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    if (!btn) return;
    var editar = btn.getAttribute('data-modo') === 'editar';
    var attr = function (n, d) { return editar ? (btn.getAttribute('data-' + n) || d) : d; };
    formUsuario.action = modalUsuario.getAttribute(editar ? 'data-action-editar' : 'data-action-nuevo');
    formUsuario.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
    document.getElementById('tituloUsuario').textContent = editar ? 'Editar Usuario' : 'Nuevo Usuario';
    document.getElementById('usrClaveAyuda').textContent = editar ? '(dejar vacío para no cambiar)' : '(obligatoria)';
    document.getElementById('usrClave').required = !editar;
    document.getElementById('usrId').value = attr('id', '');
    document.getElementById('usrNombre').value = attr('nombre', '');
    document.getElementById('usrEmail').value = attr('email', '');
    document.getElementById('usrClave').value = '';
    document.getElementById('usrRol').value = attr('rol', 'empleado');
    document.getElementById('usrEstado').value = attr('estado', 'activo');
    document.getElementById('usrMovil').checked = attr('movil', '0') === '1';
    var yo = attr('yo', '0') === '1';
    document.getElementById('usrRol').disabled = yo;
    document.getElementById('usrEstado').disabled = yo;
  });
  formUsuario.addEventListener('submit', function () {
    document.getElementById('usrRol').disabled = false;
    document.getElementById('usrEstado').disabled = false;
  });

  var grid = document.getElementById('permGrid');
  var bloques = Array.prototype.slice.call(grid.querySelectorAll('[data-modulo]'));
  function sincronizar(bloque) {
    var acciones = bloque.querySelectorAll('.js-accion');
    var marcadas = bloque.querySelectorAll('.js-accion:checked').length;
    var modulo = bloque.querySelector('.js-modulo');
    modulo.checked = marcadas > 0;
    modulo.indeterminate = marcadas > 0 && marcadas < acciones.length;
    bloque.classList.toggle('border-primary', marcadas > 0);
  }
  function fijar(soloVer, valor) {
    bloques.forEach(function (b) {
      b.querySelectorAll('.js-accion').forEach(function (c) { c.checked = valor && (!soloVer || c.value === 'ver'); });
      sincronizar(b);
    });
  }
  grid.addEventListener('change', function (ev) {
    var bloque = ev.target.closest('[data-modulo]');
    if (!bloque) return;
    if (ev.target.classList.contains('js-modulo')) {
      bloque.querySelectorAll('.js-accion').forEach(function (c) { c.checked = ev.target.checked; });
    } else if (ev.target.checked && ev.target.value !== 'ver') {
      bloque.querySelector('.js-accion[value="ver"]').checked = true;
    } else if (!ev.target.checked && ev.target.value === 'ver') {
      bloque.querySelectorAll('.js-accion').forEach(function (c) { c.checked = false; });
    }
    sincronizar(bloque);
  });
  document.getElementById('modalPermisos').addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    if (!btn) return;
    document.getElementById('permId').value = btn.getAttribute('data-id') || '';
    document.getElementById('permNombre').textContent = btn.getAttribute('data-nombre') || '';
    var permisos = {};
    try { permisos = JSON.parse(btn.getAttribute('data-permisos') || '{}'); } catch (e) { permisos = {}; }
    bloques.forEach(function (b) {
      var asignadas = permisos[b.getAttribute('data-modulo')] || [];
      b.querySelectorAll('.js-accion').forEach(function (c) { c.checked = asignadas.indexOf(c.value) !== -1; });
      sincronizar(b);
    });
  });
  document.getElementById('permTodos').addEventListener('click', function () { fijar(false, true); });
  document.getElementById('permSoloVer').addEventListener('click', function () { fijar(true, true); });
  document.getElementById('permNinguno').addEventListener('click', function () { fijar(false, false); });

  document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    document.getElementById('eliminarId').value = btn ? (btn.getAttribute('data-id') || '') : '';
    document.getElementById('eliminarNombre').textContent = btn ? (btn.getAttribute('data-nombre') || 'este usuario') : 'este usuario';
  });

  if (modalUsuario.getAttribute('data-abrir') === '1') {
    document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(modalUsuario).show(); });
  }
})();
</script>
<?php
layout_end();
