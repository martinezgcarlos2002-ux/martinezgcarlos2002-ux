<?php
require_login();
$db = get_db();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $nombre   = trim($_POST['nombre'] ?? '');
        $contacto = trim($_POST['contacto'] ?? '');
        $tel      = trim($_POST['telefono'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $dir      = trim($_POST['direccion'] ?? '');
        $precio_l = (float)($_POST['precio_litro_usd'] ?? 0);
        if ($id) {
            $db->prepare('UPDATE proveedores SET nombre=?,contacto=?,telefono=?,email=?,direccion=?,precio_litro_usd=? WHERE id=?')
               ->execute([$nombre,$contacto,$tel,$email,$dir,$precio_l,$id]);
            flash('Proveedor actualizado.');
        } else {
            $db->prepare('INSERT INTO proveedores(nombre,contacto,telefono,email,direccion,precio_litro_usd) VALUES(?,?,?,?,?,?)')
               ->execute([$nombre,$contacto,$tel,$email,$dir,$precio_l]);
            flash('Proveedor creado.');
        }

    } elseif ($act === 'delete') {
        $db->prepare('DELETE FROM proveedores WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Proveedor eliminado.', 'warning');

    } elseif ($act === 'save_semana') {
        // Guardar registro semanal de litros por día
        $prov_id    = (int)($_POST['proveedor_id'] ?? 0);
        $precio     = (float)($_POST['precio_litro'] ?? 0);
        $dias       = $_POST['dias'] ?? [];   // ['fecha' => cantidad, ...]
        $descripcion = trim($_POST['descripcion'] ?? '');
        $guardados  = 0;

        if ($prov_id && is_array($dias)) {
            $insert_sql = is_mysql()
                ? 'INSERT IGNORE INTO proveedores_entregas(proveedor_id,fecha,descripcion,cantidad,precio_usd,subtotal_usd) VALUES(?,?,?,?,?,?)'
                : 'INSERT INTO proveedores_entregas(proveedor_id,fecha,descripcion,cantidad,precio_usd,subtotal_usd) VALUES(?,?,?,?,?,?) ON CONFLICT DO NOTHING';
            $stmt = $db->prepare($insert_sql);
            foreach ($dias as $fecha => $qty) {
                $qty = (float)$qty;
                if ($qty <= 0) continue;
                $subtotal = $qty * $precio;
                try {
                    $stmt->execute([$prov_id, $fecha, $descripcion ?: 'Entrega semanal', $qty, $precio, $subtotal]);
                    $guardados++;
                } catch (Exception $e) {
                    // Ignorar conflictos
                }
            }
        }
        flash($guardados > 0 ? "Registradas $guardados entregas." : 'No se registraron entregas (cantidades en 0).', $guardados > 0 ? 'success' : 'warning');
    }
    header('Location: ?p=proveedores'); exit;
}

$search = trim($_GET['q'] ?? '');
$where  = $search ? "WHERE p.nombre LIKE ? OR p.contacto LIKE ? OR p.email LIKE ?" : '';
$params = $search ? ["%$search%","%$search%","%$search%"] : [];
$rows   = $db->prepare("SELECT p.*,
    COALESCE((SELECT SUM(subtotal_usd) FROM proveedores_entregas WHERE proveedor_id=p.id),0) AS total_suministros,
    COALESCE((SELECT SUM(monto_usd)   FROM proveedores_pagos     WHERE proveedor_id=p.id),0) AS total_pagos
    FROM proveedores p $where ORDER BY p.nombre");
$rows->execute($params);
$rows = $rows->fetchAll();

// Lista de proveedores con precio para el selector del modal semanal
$todos_prov = $db->query('SELECT id, nombre, precio_litro_usd FROM proveedores ORDER BY nombre')->fetchAll();
$prov_precios_json = json_encode(
    array_column($todos_prov, 'precio_litro_usd', 'id'),
    JSON_UNESCAPED_UNICODE
);

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM proveedores WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

// Calcular fechas de la semana actual (Lun → Dom)
$hoy      = new DateTime();
$dow      = (int)$hoy->format('N'); // 1=Lun … 7=Dom
$lunes    = (clone $hoy)->modify('-' . ($dow - 1) . ' days');
$semana   = [];
$dias_es  = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
for ($i = 0; $i < 7; $i++) {
    $d = (clone $lunes)->modify("+$i days");
    $semana[] = ['label' => $dias_es[$i], 'fecha' => $d->format('Y-m-d'), 'corto' => $d->format('d/m')];
}

layout_start('Proveedores', 'proveedores');
?>

<!-- Modal editar proveedor -->
<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Editar Proveedor</h5>
      <a href="?p=proveedores" class="btn-close"></a>
    </div>
    <div class="modal-body"><?= render_prov_form($edit) ?></div>
  </div></div>
</div>
<?php endif ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2" method="get">
    <input type="hidden" name="p" value="proveedores">
    <input type="search" name="q" class="form-control form-control-sm"
           placeholder="Buscar proveedor..." value="<?= h($search) ?>" style="width:220px">
    <button class="btn btn-sm btn-outline-secondary">Buscar</button>
    <?php if ($search): ?>
      <a href="?p=proveedores" class="btn btn-sm btn-outline-danger">✕</a>
    <?php endif ?>
  </form>
  <div class="d-flex gap-2">
    <a href="?p=proveedores_control" class="btn btn-sm btn-outline-primary">📋 Control Semanal</a>
    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalSemana">
      🥛 Registro de Litros
    </button>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalProv">
      + Nuevo Proveedor
    </button>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Proveedor</th>
          <th>Contacto</th>
          <th>Teléfono</th>
          <th>Email</th>
          <th class="text-end">Suministros</th>
          <th class="text-end">Pagado</th>
          <th class="text-end">Saldo</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $saldo = (float)$r['total_suministros'] - (float)$r['total_pagos'];
      ?>
      <tr>
        <td>
          <a href="?p=proveedor_detalle&id=<?= $r['id'] ?>" class="fw-semibold text-decoration-none" style="color:#1a2e5a">
            🏭 <?= h($r['nombre']) ?>
          </a>
        </td>
        <td class="text-muted small"><?= h($r['contacto']) ?></td>
        <td class="text-muted small"><?= h($r['telefono']) ?></td>
        <td class="text-muted small"><?= h($r['email']) ?></td>
        <td class="text-end fw-semibold">$<?= number_format((float)$r['total_suministros'],2) ?></td>
        <td class="text-end text-success">$<?= number_format((float)$r['total_pagos'],2) ?></td>
        <td class="text-end fw-bold <?= $saldo>0?'text-danger':'text-success' ?>">
          $<?= number_format($saldo,2) ?>
        </td>
        <td>
          <div class="d-flex gap-1 flex-wrap">
            <a href="?p=proveedor_detalle&id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-primary">📋 Control</a>
            <a href="?p=proveedor_reporte&id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-info" title="Reporte semanal">📄 Reporte</a>
            <a href="?p=proveedores&action=edit&id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-secondary">Editar</a>
            <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar proveedor <?= h($r['nombre']) ?>?')">
              <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?= $r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">🗑️</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Sin proveedores registrados</td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ════════════════════════════════════════════════
     Modal: Registro Semanal de Litros
     ════════════════════════════════════════════════ -->
<div class="modal fade" id="modalSemana" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header" style="background:#1a2e5a;color:white;">
      <h5 class="modal-title">🥛 Registro Semanal de Litros</h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="post" id="formSemana">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="action"  value="save_semana">
      <div class="modal-body">

        <!-- Proveedor y precio -->
        <div class="row g-3 mb-3">
          <div class="col-md-7">
            <label class="form-label fw-semibold">Proveedor <span class="text-danger">*</span></label>
            <select name="proveedor_id" id="selProveedor" class="form-select" required
                    onchange="autoFillPrecio(this.value)">
              <option value="">— Selecciona un proveedor —</option>
              <?php foreach ($todos_prov as $pv): ?>
              <option value="<?= $pv['id'] ?>"><?= h($pv['nombre']) ?></option>
              <?php endforeach ?>
            </select>
            <div class="form-text">Al seleccionar, se cargará el precio acordado automáticamente.</div>
          </div>
          <div class="col-md-5">
            <label class="form-label fw-semibold">Precio por litro (USD)</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" name="precio_litro" id="precioLitro" class="form-control"
                     min="0" step="0.0001" value="0" placeholder="0.0000">
            </div>
            <div class="form-text">Deja en 0 si solo quieres registrar litros sin precio.</div>
          </div>
        </div>

        <!-- Semana de referencia -->
        <div class="mb-3">
          <label class="form-label fw-semibold">Semana del</label>
          <input type="date" id="semanaInicio" class="form-control" style="max-width:200px"
                 value="<?= $semana[0]['fecha'] ?>"
                 onchange="recalcFechas(this.value)">
          <div class="form-text">Al cambiar la fecha se actualizan los días automáticamente.</div>
        </div>

        <!-- Tabla de días -->
        <div class="table-responsive">
          <table class="table table-bordered align-middle" id="tablaDias">
            <thead style="background:#1a2e5a;color:#fff;">
              <tr>
                <th style="width:110px">Día</th>
                <th style="width:120px">Fecha</th>
                <th>Litros entregados</th>
                <th style="width:110px" class="text-end">Subtotal $</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($semana as $i => $dia): ?>
            <tr id="fila-<?= $i ?>">
              <td class="fw-semibold" style="color:#1a2e5a"><?= $dia['label'] ?></td>
              <td class="text-muted small" id="fecha-label-<?= $i ?>"><?= $dia['corto'] ?></td>
              <td>
                <input type="number"
                       name="dias[<?= $dia['fecha'] ?>]"
                       id="litros-<?= $i ?>"
                       data-row="<?= $i ?>"
                       class="form-control litros-input"
                       min="0" step="0.001" value=""
                       placeholder="0.000">
              </td>
              <td class="text-end fw-semibold" id="sub-<?= $i ?>">—</td>
            </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot>
              <tr style="background:#f0f4fa;">
                <td colspan="2" class="fw-bold text-end" style="color:#1a2e5a">TOTAL SEMANAL</td>
                <td class="fw-bold" id="totalLitros" style="color:#1a2e5a;font-size:1.05rem">0.000 L</td>
                <td class="text-end fw-bold" id="totalUsd" style="color:#1a2e5a;font-size:1.05rem">$0.00</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Descripción opcional -->
        <div class="mb-2">
          <label class="form-label">Descripción / Nota (opcional)</label>
          <input type="text" name="descripcion" class="form-control"
                 placeholder="Ej: Entrega regular semana 18">
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-success">💾 Guardar Registro Semanal</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal nuevo proveedor -->
<div class="modal fade" id="modalProv" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:#1a2e5a;color:white;">
      <h5 class="modal-title">Nuevo Proveedor</h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body"><?= render_prov_form() ?></div>
  </div></div>
</div>

<script>
// Precios por proveedor
const PROV_PRECIOS = <?= $prov_precios_json ?>;

function autoFillPrecio(provId) {
    const precio = PROV_PRECIOS[provId];
    if (precio !== undefined && parseFloat(precio) > 0) {
        document.getElementById('precioLitro').value = parseFloat(precio).toFixed(4);
        recalcTotales();
    }
}

// Fechas originales para cada fila
const FECHAS_ORIG = <?= json_encode(array_column($semana, 'fecha')) ?>;
const LABELS_ORIG = <?= json_encode(array_column($semana, 'corto')) ?>;

function recalcFechas(lunesStr) {
    if (!lunesStr) return;
    const lunes = new Date(lunesStr + 'T00:00:00');
    // Ajustar al lunes de esa semana
    const dow = lunes.getDay(); // 0=Dom
    const diff = (dow === 0) ? -6 : 1 - dow;
    lunes.setDate(lunes.getDate() + diff);

    for (let i = 0; i < 7; i++) {
        const d = new Date(lunes);
        d.setDate(lunes.getDate() + i);
        const fechaStr = d.toISOString().slice(0, 10);
        const corto = String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0');

        // Actualizar label visible
        document.getElementById('fecha-label-' + i).textContent = corto;

        // Actualizar name del input para que envíe la fecha correcta
        const inp = document.getElementById('litros-' + i);
        inp.name = 'dias[' + fechaStr + ']';
    }
    recalcTotales();
}

function recalcTotales() {
    const precio = parseFloat(document.getElementById('precioLitro').value) || 0;
    let totalL = 0, totalU = 0;
    document.querySelectorAll('.litros-input').forEach((inp, i) => {
        const qty = parseFloat(inp.value) || 0;
        const sub = qty * precio;
        totalL += qty;
        totalU += sub;
        const subEl = document.getElementById('sub-' + i);
        subEl.textContent = (precio > 0 && qty > 0) ? '$' + sub.toFixed(2) : '—';
    });
    document.getElementById('totalLitros').textContent = totalL.toFixed(3) + ' L';
    document.getElementById('totalUsd').textContent    = precio > 0 ? '$' + totalU.toFixed(2) : '—';
}

document.getElementById('precioLitro').addEventListener('input', recalcTotales);
document.querySelectorAll('.litros-input').forEach(inp => {
    inp.addEventListener('input', recalcTotales);
});

// Limpiar modal al cerrar
document.getElementById('modalSemana').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formSemana').reset();
    document.querySelectorAll('.litros-input').forEach((inp, i) => {
        document.getElementById('sub-' + i).textContent = '—';
    });
    document.getElementById('totalLitros').textContent = '0.000 L';
    document.getElementById('totalUsd').textContent = '$0.00';
    // Restaurar fechas originales
    FECHAS_ORIG.forEach((f, i) => {
        document.getElementById('litros-' + i).name = 'dias[' + f + ']';
        document.getElementById('fecha-label-' + i).textContent = LABELS_ORIG[i];
    });
    document.getElementById('semanaInicio').value = FECHAS_ORIG[0];
});
</script>

<?php layout_end();

function render_prov_form(?array $r = null): string {
    $csrf = csrf_token(); ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= h($csrf) ?>">
      <input type="hidden" name="action"  value="save">
      <input type="hidden" name="id"      value="<?= h($r['id'] ?? '') ?>">
      <div class="mb-2">
        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
        <input name="nombre" class="form-control" value="<?= h($r['nombre'] ?? '') ?>" required
               placeholder="Nombre del proveedor">
      </div>
      <div class="mb-2">
        <label class="form-label fw-semibold">Persona de contacto</label>
        <input name="contacto" class="form-control" value="<?= h($r['contacto'] ?? '') ?>"
               placeholder="Nombre del representante">
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6">
          <label class="form-label fw-semibold">Teléfono</label>
          <input name="telefono" class="form-control" value="<?= h($r['telefono'] ?? '') ?>"
                 placeholder="04XX-XXXXXXX">
        </div>
        <div class="col-6">
          <label class="form-label fw-semibold">Email</label>
          <input name="email" type="email" class="form-control" value="<?= h($r['email'] ?? '') ?>">
        </div>
      </div>
      <div class="mb-2">
        <label class="form-label fw-semibold">Precio por litro de leche (USD)</label>
        <div class="input-group">
          <span class="input-group-text">$</span>
          <input name="precio_litro_usd" type="number" step="0.0001" min="0"
                 class="form-control" value="<?= h(number_format((float)($r['precio_litro_usd'] ?? 0), 4, '.', '')) ?>"
                 placeholder="0.0000">
        </div>
        <div class="form-text">Se usará como precio por defecto al registrar entregas de leche.</div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Dirección</label>
        <textarea name="direccion" class="form-control" rows="2"
                  placeholder="Dirección del proveedor"><?= h($r['direccion'] ?? '') ?></textarea>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary">💾 Guardar</button>
        <a href="?p=proveedores" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
    <?php return ob_get_clean();
}
?>
