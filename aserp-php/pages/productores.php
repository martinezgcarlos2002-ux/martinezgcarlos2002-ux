<?php
require_login();
$db       = get_db();
$action   = $_GET['action']   ?? '';
$edit_tipo = $_GET['ent_tipo'] ?? 'productor';

/* ── POST ─────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act      = $_POST['action']   ?? '';
    $ent_tipo = $_POST['ent_tipo'] ?? 'productor';
    $id       = (int)($_POST['id'] ?? 0);

    if ($act === 'save') {
        $nombre = trim($_POST['nombre']           ?? '');
        $tel    = trim($_POST['telefono']         ?? '');
        $email  = trim($_POST['email']            ?? '');
        $dir    = trim($_POST['direccion']        ?? '');
        $precio = (float)($_POST['precio_litro_usd'] ?? 0);

        if ($ent_tipo === 'productor') {
            $doc     = trim($_POST['documento']      ?? '');
            $credito = (int)($_POST['credito_dias']  ?? 0);
            $estado  = form_bool($_POST['estado']    ?? 'activo');
            // Verificar nombre duplicado
            $dup = $db->prepare('SELECT id FROM productores WHERE LOWER(nombre)=LOWER(?) AND id<>?');
            $dup->execute([$nombre, $id ?: 0]);
            if ($dup->fetch()) {
                flash("Ya existe un productor con el nombre «{$nombre}». Elige un nombre diferente.", 'error');
                header('Location: ?p=productores'); exit;
            }
            if ($id) {
                $db->prepare('UPDATE productores SET nombre=?,documento=?,telefono=?,correo=?,direccion=?,precio_litro_usd=?,credito_dias=?,estado=? WHERE id=?')
                   ->execute([$nombre,$doc,$tel,$email,$dir,$precio,$credito,$estado,$id]);
                flash('Productor actualizado.');
            } else {
                $db->prepare('INSERT INTO productores(nombre,documento,telefono,correo,direccion,precio_litro_usd,credito_dias,estado) VALUES(?,?,?,?,?,?,?,?)')
                   ->execute([$nombre,$doc,$tel,$email,$dir,$precio,$credito,$estado]);
                flash('Productor creado.');
            }
        } else {
            $contacto = trim($_POST['contacto'] ?? '');
            // Verificar nombre duplicado en proveedores
            $dup = $db->prepare('SELECT id FROM proveedores WHERE LOWER(nombre)=LOWER(?) AND id<>?');
            $dup->execute([$nombre, $id ?: 0]);
            if ($dup->fetch()) {
                flash("Ya existe un proveedor con el nombre «{$nombre}». Elige un nombre diferente.", 'error');
                header('Location: ?p=productores'); exit;
            }
            if ($id) {
                $db->prepare('UPDATE proveedores SET nombre=?,contacto=?,telefono=?,email=?,direccion=?,precio_litro_usd=? WHERE id=?')
                   ->execute([$nombre,$contacto,$tel,$email,$dir,$precio,$id]);
                flash('Proveedor actualizado.');
            } else {
                $db->prepare('INSERT INTO proveedores(nombre,contacto,telefono,email,direccion,precio_litro_usd) VALUES(?,?,?,?,?,?)')
                   ->execute([$nombre,$contacto,$tel,$email,$dir,$precio]);
                flash('Proveedor creado.');
            }
        }
    } elseif ($act === 'delete') {
        if ($ent_tipo === 'productor') {
            $db->prepare('DELETE FROM productores WHERE id=?')->execute([$id]);
            flash('Productor eliminado.', 'warning');
        } else {
            $db->prepare('DELETE FROM proveedores WHERE id=?')->execute([$id]);
            flash('Proveedor eliminado.', 'warning');
        }
    }
    header('Location: ?p=productores'); exit;
}

/* ── Filtros ───────────────────────────────────────────────────────────── */
$search = trim($_GET['q']      ?? '');
$tipo_f = $_GET['tipo']        ?? '';   // 'productor'|'proveedor'|''
$est_f  = $_GET['estado']      ?? '';

/* ── Vista "Notaría" productores: Nombre + Domingo a Sábado + Observaciones ── */
if (($_GET['notaria'] ?? '') === '1') {
    $semana_n = $_GET['semana'] ?? date('Y-m-d');
    try { $base = new DateTime($semana_n); } catch (Exception $e) { $base = new DateTime('today'); }
    $dow = (int)$base->format('w'); // 0=dom..6=sab
    if ($dow !== 0) { $base->modify('-' . $dow . ' days'); }
    $dias_lbl = ['DOMINGO','LUNES','MARTES','MI&Eacute;RCOLES','JUEVES','VIERNES','S&Aacute;BADO'];
    $dias_fechas = [];
    for ($d = 0; $d < 7; $d++) {
        $dias_fechas[] = (clone $base)->modify('+' . $d . ' days')->format('d/m');
    }
    $sem_lbl_n = $base->format('d/m/y') . ' al ' . (clone $base)->modify('+6 days')->format('d/m/y');
    $fecha_hoy = date('d/m/Y');

    // Solo productores activos (filtrado en PHP para soportar PG bool y MySQL int/string).
    $st_n = $db->query("SELECT nombre, estado FROM productores ORDER BY UPPER(nombre) ASC");
    $prods = [];
    foreach (($st_n ? $st_n->fetchAll() : []) as $row) {
        if (function_exists('bool_estado') ? bool_estado($row['estado']) : (bool)$row['estado']) {
            $prods[] = $row;
        }
    }

    echo "<!doctype html><html lang='es'><head><meta charset='UTF-8'>";
    echo "<title>Notar&iacute;a Productores " . h($fecha_hoy) . "</title>";
    echo "<style>
      @page { size: Letter portrait; margin: 8mm; }
      body{font-family:Arial,Helvetica,sans-serif;color:#000;margin:0;padding:0;}
      .titulo{background:#1f3864;color:#fff;font-weight:bold;text-align:center;font-size:14pt;padding:5px;border:1px solid #2a4d7a;}
      .subt{background:#fff;color:#000;font-weight:bold;text-align:center;font-size:11pt;padding:3px;border:1px solid #2a4d7a;border-top:0;margin-bottom:6px;}
      table{border-collapse:collapse;width:100%;table-layout:fixed;margin-top:4px;}
      th,td{border:1px solid #2a4d7a;padding:5px 6px;font-size:10pt;vertical-align:middle;}
      th{background:#dce6f1;text-align:center;color:#1f3864;font-size:9.5pt;}
      col.c-n{width:4%;} col.c-nom{width:22%;} col.c-d{width:7.5%;} col.c-obs{width:21.5%;}
      td.n{text-align:center;font-weight:600;}
      td.nom{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
      td.empty{height:22px;}
      .toolbar{margin-bottom:8px}
      .toolbar button{margin-right:6px;padding:4px 12px;cursor:pointer;}
      @media print { .toolbar { display:none; } }
    </style></head><body style='padding:6mm'>";
    echo "<div class='toolbar'>"
       . "<button onclick='window.print()'>Imprimir / Guardar PDF</button>"
       . "<button onclick=\"window.location='?p=productores'\">Volver</button>"
       . "</div>";
    echo "<div class='titulo'>NOTAR&Iacute;A &mdash; PRODUCTORES</div>";
    echo "<div class='subt'>Semana del " . h($sem_lbl_n) . " &nbsp;&nbsp;&nbsp; Impreso: " . h($fecha_hoy) . "</div>";
    echo "<table>";
    echo "<colgroup><col class='c-n'><col class='c-nom'>";
    for ($d = 0; $d < 7; $d++) { echo "<col class='c-d'>"; }
    echo "<col class='c-obs'></colgroup>";
    echo "<thead><tr><th>N</th><th>NOMBRE DEL PRODUCTOR</th>";
    for ($d = 0; $d < 7; $d++) {
        echo "<th>" . $dias_lbl[$d] . "<br><span style='font-weight:normal;font-size:8.5pt'>" . h($dias_fechas[$d]) . "</span></th>";
    }
    echo "<th>OBSERVACIONES</th></tr></thead><tbody>";
    $i = 0;
    foreach ($prods as $p) {
        $i++;
        echo "<tr>"
           . "<td class='n'>$i</td>"
           . "<td class='nom'>" . h((string)$p['nombre']) . "</td>"
           . str_repeat("<td class='empty'></td>", 7)
           . "<td class='empty'></td>"
           . "</tr>";
    }
    $objetivo = max($i + 3, 30);
    for ($k = $i + 1; $k <= $objetivo; $k++) {
        echo "<tr><td class='n'>$k</td><td class='empty'></td>"
           . str_repeat("<td class='empty'></td>", 7)
           . "<td class='empty'></td></tr>";
    }
    echo "</tbody></table></body></html>";
    exit;
}

/* ── Consultas ─────────────────────────────────────────────────────────── */
$rows = [];

if ($tipo_f === '' || $tipo_f === 'productor') {
    $conds = []; $params = [];
    if ($search) {
        $conds[] = '(nombre LIKE ? OR documento LIKE ? OR correo LIKE ?)';
        $params  = ["%$search%", "%$search%", "%$search%"];
    }
    if ($est_f !== '') {
        $conds[] = 'estado = ?';
        // MySQL TINYINT: bind 1/0; PostgreSQL BOOLEAN: bind 'true'/'false'
        $params[] = is_mysql() ? form_bool($est_f) : ($est_f === 'activo' ? 'true' : 'false');
    }
    $where = $conds ? 'WHERE '.implode(' AND ',$conds) : '';
    $st = $db->prepare("
        SELECT p.id, p.nombre, p.telefono, p.correo AS email,
               p.precio_litro_usd, p.estado, 'productor' AS tipo,
               COALESCE((SELECT SUM(subtotal_usd) FROM productores_entregas WHERE productor_id=p.id),0) AS total_suministros,
               COALESCE((SELECT SUM(monto_usd)    FROM productores_abonos    WHERE productor_id=p.id),0) AS total_pagos
        FROM productores p $where ORDER BY p.nombre");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) $rows[] = $r;
}

if ($tipo_f === '' || $tipo_f === 'proveedor') {
    $conds = []; $params = [];
    if ($search) {
        $conds[] = '(nombre LIKE ? OR contacto LIKE ? OR email LIKE ?)';
        $params  = ["%$search%", "%$search%", "%$search%"];
    }
    $where = $conds ? 'WHERE '.implode(' AND ',$conds) : '';
    $st = $db->prepare("
        SELECT p.id, p.nombre, p.telefono, p.email,
               p.precio_litro_usd, NULL AS estado, 'proveedor' AS tipo,
               COALESCE((SELECT SUM(subtotal_usd) FROM proveedores_entregas WHERE proveedor_id=p.id),0) AS total_suministros,
               COALESCE((SELECT SUM(monto_usd)    FROM proveedores_pagos     WHERE proveedor_id=p.id),0) AS total_pagos
        FROM proveedores p $where ORDER BY p.nombre");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) $rows[] = $r;
}

usort($rows, fn($a,$b) => strcmp(mb_strtolower($a['nombre']), mb_strtolower($b['nombre'])));

/* ── Totales rápidos ───────────────────────────────────────────────────── */
$tot_sum  = array_sum(array_column($rows, 'total_suministros'));
$tot_pago = array_sum(array_column($rows, 'total_pagos'));
$tot_sal  = $tot_sum - $tot_pago;
$tot_prod = count(array_filter($rows, fn($r) => $r['tipo']==='productor'));
$tot_prov = count(array_filter($rows, fn($r) => $r['tipo']==='proveedor'));

/* ── Editar ────────────────────────────────────────────────────────────── */
$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $eid = (int)$_GET['id'];
    if ($edit_tipo === 'productor') {
        $st = $db->prepare("SELECT *, 'productor' AS tipo FROM productores WHERE id=?");
    } else {
        $st = $db->prepare("SELECT *, 'proveedor' AS tipo FROM proveedores WHERE id=?");
    }
    $st->execute([$eid]);
    $edit = $st->fetch();
}

layout_start('Productores y Proveedores', 'productores');
?>

<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header" style="background:<?= $edit['tipo']==='productor'?'#1a2e5a':'#1565c0' ?>;color:white">
      <h5 class="modal-title">Editar <?= $edit['tipo']==='productor' ? '🐄 Productor de Leche' : '🏭 Proveedor de Insumos' ?></h5>
      <a href="?p=productores" class="btn-close btn-close-white"></a>
    </div>
    <div class="modal-body"><?= render_sum_form($edit, $edit['tipo']) ?></div>
  </div></div>
</div>
<?php endif ?>

<!-- Stats rápidos -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val"><?= $tot_prod + $tot_prov ?></div>
      <div class="stat-label">Total Suministradores</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val text-primary">$<?= number_format($tot_sum, 2) ?></div>
      <div class="stat-label">Total Suministrado</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val text-success">$<?= number_format($tot_pago, 2) ?></div>
      <div class="stat-label">Total Pagado</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card text-center">
      <div class="stat-val <?= $tot_sal>0?'text-danger':'text-success' ?>">$<?= number_format($tot_sal, 2) ?></div>
      <div class="stat-label">Saldo Pendiente</div>
    </div>
  </div>
</div>

<!-- Toolbar -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2 flex-wrap" method="get">
    <input type="hidden" name="p" value="productores">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar..." value="<?= h($search) ?>" style="width:180px">
    <select name="tipo" class="form-select form-select-sm" style="width:140px">
      <option value="">Todos (<?= $tot_prod+$tot_prov ?>)</option>
      <option value="productor" <?= $tipo_f==='productor'?'selected':'' ?>>🐄 Leche (<?= $tot_prod ?>)</option>
      <option value="proveedor" <?= $tipo_f==='proveedor'?'selected':'' ?>>🏭 Insumos (<?= $tot_prov ?>)</option>
    </select>
    <select name="estado" class="form-select form-select-sm" style="width:110px">
      <option value="">Estado</option>
      <option value="activo"   <?= $est_f==='activo'?'selected':'' ?>>Activos</option>
      <option value="inactivo" <?= $est_f==='inactivo'?'selected':'' ?>>Inactivos</option>
    </select>
    <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
    <?php if ($search||$tipo_f||$est_f): ?>
      <a href="?p=productores" class="btn btn-sm btn-outline-danger">✕ Limpiar</a>
    <?php endif ?>
  </form>
  <div class="d-flex gap-2">
    <a href="?p=control_semanal" class="btn btn-sm" style="background:#15803d;color:white">📊 Control Semanal</a>
    <a href="?p=facturas_masivas" class="btn btn-sm" style="background:#b91c1c;color:white">📑 Facturas Masivas</a>
    <a href="?p=productores&notaria=1&semana=<?= h(date('Y-m-d')) ?>" target="_blank" class="btn btn-sm btn-dark" title="Hoja de Notaría de productores (Nombre + Domingo a Sábado + Observaciones)">📜 Notaría</a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalProd">🐄 Nuevo Productor</button>
    <button class="btn btn-sm" style="background:#1565c0;color:white" data-bs-toggle="modal" data-bs-target="#modalProv">🏭 Nuevo Proveedor</button>
  </div>
</div>

<!-- Tabla unificada -->
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Tipo</th>
          <th>Teléfono</th>
          <th class="text-end">Precio/L USD</th>
          <th class="text-end">Suministrado</th>
          <th class="text-end">Pagado</th>
          <th class="text-end">Saldo</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $saldo   = (float)$r['total_suministros'] - (float)$r['total_pagos'];
        $es_prod = ($r['tipo'] === 'productor');
        $activo  = $es_prod ? bool_estado($r['estado']) : true;
        $det     = $es_prod ? 'productor_detalle' : 'proveedor_detalle';
      ?>
      <tr>
        <td>
          <a href="?p=<?= $det ?>&id=<?= $r['id'] ?>" class="fw-semibold text-decoration-none" style="color:#1a2e5a">
            <?= h($r['nombre']) ?>
          </a>
          <?php if ($r['email']): ?>
            <br><small class="text-muted"><?= h($r['email']) ?></small>
          <?php endif ?>
        </td>
        <td>
          <?php if ($es_prod): ?>
            <span class="badge" style="background:#e8f5e9;color:#2e7d32;font-size:.75rem">🐄 Leche</span>
          <?php else: ?>
            <span class="badge" style="background:#e3f2fd;color:#1565c0;font-size:.75rem">🏭 Insumos</span>
          <?php endif ?>
        </td>
        <td class="text-muted small"><?= h($r['telefono']) ?></td>
        <td class="text-end">$<?= number_format((float)$r['precio_litro_usd'],4) ?></td>
        <td class="text-end fw-semibold">$<?= number_format((float)$r['total_suministros'],2) ?></td>
        <td class="text-end text-success">$<?= number_format((float)$r['total_pagos'],2) ?></td>
        <td class="text-end fw-bold <?= $saldo>0?'text-danger':'text-success' ?>">
          <?php if ($es_prod): ?>
            <a href="?p=productor_detalle&id=<?= $r['id'] ?>&tab=cierres" class="text-decoration-none <?= $saldo>0?'text-danger':'text-success' ?>"
               title="Ver desglose por semana">
              $<?= number_format($saldo,2) ?>
              <?php if ($saldo > 0.01): ?><span style="font-size:.7rem">🔍</span><?php endif ?>
            </a>
          <?php else: ?>
            $<?= number_format($saldo,2) ?>
          <?php endif ?>
        </td>
        <td>
          <?php if ($es_prod): ?>
            <span class="badge <?= $activo?'bg-success':'bg-secondary' ?>"><?= $activo?'Activo':'Inactivo' ?></span>
          <?php else: ?>
            <span class="badge bg-light text-dark border">Proveedor</span>
          <?php endif ?>
        </td>
        <td>
          <div class="d-flex gap-1 flex-wrap">
            <a href="?p=<?= $det ?>&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">👁 Ver</a>
            <a href="?p=productores&action=edit&id=<?= $r['id'] ?>&ent_tipo=<?= $r['tipo'] ?>" class="btn btn-sm btn-outline-secondary">Editar</a>
            <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar <?= h(addslashes($r['nombre'])) ?>?')">
              <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
              <input type="hidden" name="action"   value="delete">
              <input type="hidden" name="id"       value="<?= $r['id'] ?>">
              <input type="hidden" name="ent_tipo" value="<?= $r['tipo'] ?>">
              <button class="btn btn-sm btn-outline-danger">🗑</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Sin registros</td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Nuevo Productor de Leche -->
<div class="modal fade" id="modalProd" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header" style="background:#1a2e5a;color:white">
      <h5 class="modal-title">🐄 Nuevo Productor de Leche</h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body"><?= render_sum_form(null, 'productor') ?></div>
  </div></div>
</div>

<!-- Modal Nuevo Proveedor de Insumos -->
<div class="modal fade" id="modalProv" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header" style="background:#1565c0;color:white">
      <h5 class="modal-title">🏭 Nuevo Proveedor de Insumos</h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body"><?= render_sum_form(null, 'proveedor') ?></div>
  </div></div>
</div>

<?php layout_end();

function render_sum_form(?array $r, string $tipo): string {
    $csrf    = csrf_token();
    $es_prod = ($tipo === 'productor');
    ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf"    value="<?= h($csrf) ?>">
      <input type="hidden" name="action"   value="save">
      <input type="hidden" name="ent_tipo" value="<?= h($tipo) ?>">
      <input type="hidden" name="id"       value="<?= h($r['id'] ?? '') ?>">
      <div class="row g-2">
        <div class="col-8">
          <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
          <input name="nombre" class="form-control" value="<?= h($r['nombre'] ?? '') ?>" required>
        </div>
        <?php if ($es_prod): ?>
        <div class="col-4">
          <label class="form-label fw-semibold">Documento</label>
          <input name="documento" class="form-control" value="<?= h($r['documento'] ?? '') ?>">
        </div>
        <?php else: ?>
        <div class="col-4">
          <label class="form-label fw-semibold">Contacto</label>
          <input name="contacto" class="form-control" value="<?= h($r['contacto'] ?? '') ?>">
        </div>
        <?php endif ?>
        <div class="col-6">
          <label class="form-label fw-semibold">Teléfono</label>
          <input name="telefono" class="form-control" value="<?= h($r['telefono'] ?? '') ?>">
        </div>
        <div class="col-6">
          <label class="form-label fw-semibold">Email</label>
          <input name="email" type="email" class="form-control" value="<?= h($r['email'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Dirección</label>
          <textarea name="direccion" class="form-control" rows="2"><?= h($r['direccion'] ?? '') ?></textarea>
        </div>
        <div class="col-4">
          <label class="form-label fw-semibold">Precio/L (USD)</label>
          <div class="input-group">
            <span class="input-group-text">$</span>
            <input name="precio_litro_usd" type="number" step="0.0001" min="0" class="form-control"
                   value="<?= h(number_format((float)($r['precio_litro_usd']??0), 4, '.', '')) ?>">
          </div>
        </div>
        <?php if ($es_prod): ?>
        <div class="col-4">
          <label class="form-label fw-semibold">Crédito (días)</label>
          <input name="credito_dias" type="number" class="form-control" value="<?= h($r['credito_dias'] ?? '0') ?>">
        </div>
        <div class="col-4">
          <label class="form-label fw-semibold">Estado</label>
          <?php $es_activo = !isset($r['estado']) || bool_estado($r['estado']); ?>
          <select name="estado" class="form-select">
            <option value="activo"   <?= $es_activo  ? 'selected':'' ?>>Activo</option>
            <option value="inactivo" <?= !$es_activo ? 'selected':'' ?>>Inactivo</option>
          </select>
        </div>
        <?php endif ?>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary">💾 Guardar</button>
        <a href="?p=productores" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
    <?php return ob_get_clean();
}
?>
