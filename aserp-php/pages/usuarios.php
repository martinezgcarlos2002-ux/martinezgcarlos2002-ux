<?php
require_login();
// Vista solo lectura para empleados con permiso 'usuarios'; acciones de escritura solo para admin
if (!has_permiso('usuarios')) { flash('Sin permisos para ver usuarios.', 'error'); header('Location: ?p=dashboard'); exit; }
$puede_admin = is_admin(); // solo admins pueden crear/editar/eliminar/cambiar permisos
$db = get_db();
$action = $_GET['action'] ?? '';

$MODULOS = [
    'dashboard'   => 'Dashboard',
    'inventario'  => 'Inventario',
    'lotes'       => 'Lotes de Producción',
    'produccion_diaria' => 'Producción Diaria',
    'ventas'      => 'Ventas / Facturas',
    'empleados'   => 'Empleados',
    'usuarios'    => 'Usuarios del Sistema',
    'transportistas' => 'Transportistas',
    'proveedores' => 'Proveedores',
    'productores' => 'Productores',
    'facturas_masivas' => 'Facturas Masivas (Productores)',
    'control_diario' => 'Control Diario de Leche',
    'finanzas'    => 'Finanzas',
    'bancos'      => 'Cuentas Bancarias',
    'auditoria'   => 'Auditoría',
    'cierre_diario' => 'Cierre Diario (Respaldo SQL)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$puede_admin) { flash('Solo administradores pueden modificar usuarios.', 'error'); header('Location: ?p=usuarios'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = strtolower(trim($_POST['email'] ?? ''));
        $rol    = $_POST['rol'] ?? 'empleado';
        $estado_bool = ($_POST['estado'] ?? '') === 'activo';
        // MySQL usa TINYINT (1/0); PostgreSQL usa BOOLEAN ('true'/'false')
        $estado_db = is_mysql() ? ($estado_bool ? 1 : 0) : ($estado_bool ? 'true' : 'false');
        $pass   = $_POST['password'] ?? '';

        if ($nombre === '' || $email === '') {
            flash('Nombre y email son requeridos.', 'error');
            header('Location: ?p=usuarios'); exit;
        }
        if (mb_strlen($nombre) > 100 || mb_strlen($email) > 120 || mb_strlen($pass) > 64) {
            flash('Algún campo supera el máximo de caracteres permitido.', 'error');
            header('Location: ?p=usuarios'); exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('El email no es válido.', 'error');
            header('Location: ?p=usuarios'); exit;
        }

        // Verificar email duplicado (excluyendo el propio registro si es edición)
        $dup_st = $db->prepare('SELECT id FROM usuarios WHERE LOWER(email)=? AND id<>?');
        $dup_st->execute([$email, $id ?: 0]);
        if ($dup_st->fetch()) {
            flash('Ya existe un usuario con el email "' . h($email) . '". Usa otro correo.', 'error');
            header('Location: ?p=usuarios'); exit;
        }

        try {
            if ($id) {
                if ($pass) {
                    $hash = password_hash($pass, PASSWORD_BCRYPT);
                    $db->prepare('UPDATE usuarios SET nombre=?,email=?,rol=?,estado=?,password=? WHERE id=?')
                       ->execute([$nombre, $email, $rol, $estado_db, $hash, $id]);
                } else {
                    $db->prepare('UPDATE usuarios SET nombre=?,email=?,rol=?,estado=? WHERE id=?')
                       ->execute([$nombre, $email, $rol, $estado_db, $id]);
                }
                flash('Usuario actualizado.');
            } else {
                if (!$pass) { flash('La contraseña es requerida.', 'error'); header('Location: ?p=usuarios'); exit; }
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $db->prepare('INSERT INTO usuarios(nombre,email,password,rol,estado) VALUES(?,?,?,?,?)')
                   ->execute([$nombre, $email, $hash, $rol, $estado_db]);
                flash('Usuario creado.');
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'duplicate')) {
                flash('Ya existe un usuario con ese email. Usa otro correo.', 'error');
            } else {
                flash('Error al guardar usuario: ' . $e->getMessage(), 'error');
            }
            header('Location: ?p=usuarios'); exit;
        }

    } elseif ($act === 'delete') {
        $id = (int)$_POST['id'];
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('No puedes eliminar tu propio usuario.', 'error');
        } else {
            $db->prepare('DELETE FROM usuarios WHERE id=?')->execute([$id]);
            flash('Usuario eliminado.', 'warning');
        }

    } elseif ($act === 'permisos') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $permisos = [];
            foreach (array_keys($MODULOS) as $mod) {
                $permisos[$mod] = isset($_POST['permisos'][$mod]);
            }
            $db->prepare('UPDATE usuarios SET permisos_json=? WHERE id=?')
               ->execute([json_encode($permisos), $id]);
            flash('Permisos actualizados.');
        }
    }

    header('Location: ?p=usuarios'); exit;
}

$search = trim($_GET['q'] ?? '');
$where  = $search ? "WHERE nombre LIKE ? OR email LIKE ?" : '';
$params = $search ? ["%$search%", "%$search%"] : [];
$rows   = $db->prepare("SELECT id,nombre,email,rol,estado,creado_en,permisos_json FROM usuarios $where ORDER BY nombre");
$rows->execute($params);
$rows = $rows->fetchAll();

$edit = null;
if ($action === 'edit' && isset($_GET['id']) && $puede_admin) {
    $st = $db->prepare('SELECT id,nombre,email,rol,estado FROM usuarios WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

// Helper: permisos efectivos para mostrar en el modal (no conflicto con get_permisos() global)
function get_permisos_row(array $row, array $MODULOS): array {
    if ($row['rol'] === 'admin') {
        return array_fill_keys(array_keys($MODULOS), true);
    }
    if (!empty($row['permisos_json'])) {
        $p = json_decode($row['permisos_json'], true);
        if (is_array($p)) return $p;
    }
    // Sin permisos configurados → solo dashboard
    $default = array_fill_keys(array_keys($MODULOS), false);
    $default['dashboard'] = true;
    return $default;
}

// Helper: convierte boolean de PostgreSQL (true/false) a texto legible
function estado_label($val): string {
    return ($val === true || $val === 't' || $val === '1' || $val === 'true') ? 'activo' : 'inactivo';
}

layout_start('Usuarios del Sistema', 'usuarios');
?>

<style>
.perm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; }
.perm-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.7rem; border-radius: 8px; background: #f8f9fa; border: 1px solid #dee2e6; cursor: pointer; transition: .15s; }
.perm-item:hover { border-color: #90c5f0; }
.perm-item input[type=checkbox] { accent-color: #1e3a5f; width: 16px; height: 16px; flex-shrink: 0; }
.perm-item label { font-size: 0.83rem; margin: 0; cursor: pointer; user-select: none; }
.perm-item.active { background: #e8f4fd; border-color: #1e3a5f; }
.perm-item.active label { font-weight: 600; color: #1e3a5f; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <form class="d-flex gap-2" method="get">
    <input type="hidden" name="p" value="usuarios">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar usuario..." value="<?= h($search) ?>" style="width:220px">
    <button class="btn btn-sm btn-outline-secondary">Buscar</button>
    <?php if ($search): ?><a href="?p=usuarios" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
  </form>
  <?php if ($puede_admin): ?>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUser">+ Nuevo Usuario</button>
  <?php endif ?>
</div>

<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar Usuario</h5><a href="?p=usuarios" class="btn-close"></a></div>
    <div class="modal-body"><?= render_user_form($edit) ?></div>
  </div></div>
</div>
<?php endif ?>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Email</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>Módulos con acceso</th>
          <th>Creado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $es_activo = bool_estado($r['estado']);
        $etiqueta  = $es_activo ? 'Activo' : 'Inactivo';
        $perms_eff = get_permisos_row($r, $MODULOS);
        $mods_con_acceso = array_keys(array_filter($perms_eff));
        $mods_labels = array_map(fn($k) => $MODULOS[$k] ?? $k, $mods_con_acceso);
        $es_admin = $r['rol'] === 'admin';
      ?>
      <tr>
        <td class="fw-semibold"><?= h($r['nombre']) ?></td>
        <td class="text-muted small"><?= h($r['email']) ?></td>
        <td>
          <span class="badge <?= $es_admin ? 'bg-danger' : 'bg-primary' ?>">
            <?= $es_admin ? 'Admin' : 'Empleado' ?>
          </span>
        </td>
        <td>
          <span class="badge <?= $es_activo ? 'bg-success' : 'bg-secondary' ?>">
            <?= $etiqueta ?>
          </span>
        </td>
        <td style="max-width:260px;">
          <?php if ($es_admin): ?>
            <span class="text-success small fw-semibold">Acceso total</span>
          <?php elseif (empty($mods_labels)): ?>
            <span class="text-danger small">Sin módulos asignados</span>
          <?php else: ?>
            <span class="small text-muted"><?= h(implode(', ', $mods_labels)) ?></span>
          <?php endif ?>
        </td>
        <td class="small text-muted"><?= fmt_date($r['creado_en']) ?></td>
        <td>
          <div class="d-flex gap-1 flex-wrap">
            <?php if ($puede_admin): ?>
            <a href="?p=usuarios&action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">✏️ Editar</a>
            <button type="button"
              class="btn btn-sm <?= $es_admin ? 'btn-outline-secondary' : 'btn-outline-warning' ?>"
              data-bs-toggle="modal"
              data-bs-target="#modalPermisos"
              data-uid="<?= $r['id'] ?>"
              data-uname="<?= h($r['nombre']) ?>"
              data-urol="<?= h($r['rol']) ?>"
              data-permisos='<?= h(json_encode($perms_eff)) ?>'>
              🔑 Permisos
            </button>
            <?php if ($r['id'] != ($_SESSION['user_id'] ?? 0)): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar usuario <?= h($r['nombre']) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">🗑️ Eliminar</button>
            </form>
            <?php endif ?>
            <?php else: ?>
            <span class="text-muted small fst-italic">Solo lectura</span>
            <?php endif ?>
          </div>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Sin usuarios registrados</td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Nuevo Usuario -->
<div class="modal fade" id="modalUser" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:#1e3a5f;color:white;">
      <h5 class="modal-title">➕ Nuevo Usuario</h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body"><?= render_user_form() ?></div>
  </div></div>
</div>

<!-- Modal: Permisos de módulos -->
<div class="modal fade" id="modalPermisos" tabindex="-1">
  <div class="modal-dialog modal-md"><div class="modal-content">
    <div class="modal-header" style="background:#1e3a5f; color:white;">
      <h5 class="modal-title">🔑 Permisos — <span id="permNombre"></span></h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="post" id="formPermisos">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="permisos">
      <input type="hidden" name="id" id="permUserId">
      <div class="modal-body">
        <div id="permAdminNotice" class="alert alert-info d-none py-2 mb-3" style="font-size:0.83rem;">
          ℹ️ Los administradores tienen acceso total a todos los módulos automáticamente.
          No es necesario configurar permisos.
        </div>
        <p class="text-muted mb-2" style="font-size:0.82rem;">
          Marca los módulos a los que este usuario tendrá acceso. Los módulos no marcados
          quedarán completamente bloqueados.
        </p>
        <div class="perm-grid" id="permGrid">
          <?php foreach ($MODULOS as $key => $label): ?>
          <div class="perm-item" id="perm-item-<?= $key ?>" onclick="togglePerm('<?= $key ?>')">
            <input type="checkbox" name="permisos[<?= $key ?>]" id="perm-<?= $key ?>" value="1"
                   onclick="event.stopPropagation()">
            <label for="perm-<?= $key ?>"><?= h($label) ?></label>
          </div>
          <?php endforeach ?>
        </div>
      </div>
      <div class="modal-footer">
        <div class="me-auto d-flex gap-2">
          <button type="button" class="btn btn-sm btn-outline-success" onclick="setAllPerms(true)">✅ Marcar todo</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setAllPerms(false)">❌ Quitar todo</button>
        </div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary" id="permSaveBtn">💾 Guardar Permisos</button>
      </div>
    </form>
  </div></div>
</div>

<script>
const MODULOS_KEYS = <?= json_encode(array_keys($MODULOS)) ?>;

document.getElementById('modalPermisos').addEventListener('show.bs.modal', function(e) {
  const btn    = e.relatedTarget;
  const uid    = btn.dataset.uid;
  const uname  = btn.dataset.uname;
  const urol   = btn.dataset.urol;
  const permisos = JSON.parse(btn.dataset.permisos);

  document.getElementById('permUserId').value       = uid;
  document.getElementById('permNombre').textContent = uname;

  const isAdmin = urol === 'admin';
  document.getElementById('permAdminNotice').classList.toggle('d-none', !isAdmin);
  document.getElementById('permSaveBtn').disabled = isAdmin;

  MODULOS_KEYS.forEach(key => {
    const cb   = document.getElementById('perm-' + key);
    const item = document.getElementById('perm-item-' + key);
    if (!cb) return;
    cb.checked  = isAdmin ? true : (permisos[key] === true);
    cb.disabled = isAdmin;
    item.classList.toggle('active', cb.checked);
  });
});

function togglePerm(key) {
  const cb   = document.getElementById('perm-' + key);
  const item = document.getElementById('perm-item-' + key);
  if (cb && !cb.disabled) {
    cb.checked = !cb.checked;
    item.classList.toggle('active', cb.checked);
  }
}

function setAllPerms(val) {
  MODULOS_KEYS.forEach(key => {
    const cb   = document.getElementById('perm-' + key);
    const item = document.getElementById('perm-item-' + key);
    if (cb && !cb.disabled) {
      cb.checked = val;
      item.classList.toggle('active', val);
    }
  });
}
</script>

<?php layout_end();

function render_user_form(?array $r = null): string {
    $csrf = csrf_token();
    // estado viene como boolean de PostgreSQL o TINYINT de MySQL; usar helper
    $es_activo = !$r || bool_estado($r['estado']);
    ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">

      <div class="mb-2">
        <label class="form-label fw-semibold">Nombre completo</label>
        <input name="nombre" class="form-control" value="<?= h($r['nombre'] ?? '') ?>" required maxlength="100" placeholder="Ej: Juan Pérez">
      </div>
      <div class="mb-2">
        <label class="form-label fw-semibold">Correo electrónico</label>
        <input name="email" type="email" class="form-control" value="<?= h($r['email'] ?? '') ?>" required maxlength="120" placeholder="correo@empresa.com">
      </div>
      <div class="mb-2">
        <label class="form-label fw-semibold">
          Contraseña <?= $r ? '<span class="text-muted fw-normal">(dejar vacío para no cambiar)</span>' : '' ?>
        </label>
        <input name="password" type="password" class="form-control" maxlength="64" <?= !$r ? 'required' : '' ?> placeholder="<?= $r ? 'Nueva contraseña (opcional)' : 'Contraseña' ?>">
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label fw-semibold">Rol</label>
          <select name="rol" class="form-select">
            <option value="admin"    <?= ($r['rol']??'')==='admin'    ? 'selected' : '' ?>>👑 Admin</option>
            <option value="empleado" <?= ($r['rol']??'empleado')==='empleado' ? 'selected' : '' ?>>👤 Empleado</option>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label fw-semibold">Estado</label>
          <select name="estado" class="form-select">
            <option value="activo"   <?= $es_activo  ? 'selected' : '' ?>>✅ Activo</option>
            <option value="inactivo" <?= !$es_activo ? 'selected' : '' ?>>🚫 Inactivo</option>
          </select>
        </div>
      </div>
      <?php if (!$r): ?>
      <div class="alert alert-info py-2" style="font-size:0.82rem;">
        💡 Después de crear el usuario, usa el botón <strong>🔑 Permisos</strong> para asignarle
        los módulos a los que tendrá acceso.
      </div>
      <?php endif ?>
      <div class="d-flex gap-2">
        <button class="btn btn-primary">💾 Guardar</button>
        <a href="?p=usuarios" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
    <?php return ob_get_clean();
}
?>
