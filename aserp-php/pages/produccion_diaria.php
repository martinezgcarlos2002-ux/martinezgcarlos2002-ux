<?php
require_login();
if (!has_permiso('produccion_diaria')) {
    flash('Sin permisos para ver Producción Diaria.', 'error');
    header('Location: ?p=dashboard'); exit;
}
$db = get_db();
$MYSQL = is_mysql();

// ── Schema (idempotente, MySQL + Postgres) ───────────────────────────────────
(function() use ($db, $MYSQL) {
    static $done = false; if ($done) return; $done = true;
    try {
        if ($MYSQL) {
            $db->exec("CREATE TABLE IF NOT EXISTS productos_produccion (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(120) NOT NULL,
                precio_unit DECIMAL(12,4) NOT NULL DEFAULT 0,
                producto_id INT NULL,
                orden INT NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("CREATE TABLE IF NOT EXISTS produccion_diaria (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fecha DATE NOT NULL,
                producto_produccion_id INT NOT NULL,
                cantidad DECIMAL(12,3) NOT NULL DEFAULT 0,
                precio_unit DECIMAL(12,4) NOT NULL DEFAULT 0,
                total DECIMAL(14,2) NOT NULL DEFAULT 0,
                creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fecha_prod (fecha, producto_produccion_id),
                INDEX idx_fecha (fecha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS productos_produccion (
                id SERIAL PRIMARY KEY,
                nombre VARCHAR(120) NOT NULL,
                precio_unit NUMERIC(12,4) NOT NULL DEFAULT 0,
                producto_id INTEGER NULL,
                orden INTEGER NOT NULL DEFAULT 0,
                activo BOOLEAN NOT NULL DEFAULT TRUE,
                creado_en TIMESTAMP NOT NULL DEFAULT NOW()
            )");
            $db->exec("CREATE TABLE IF NOT EXISTS produccion_diaria (
                id SERIAL PRIMARY KEY,
                fecha DATE NOT NULL,
                producto_produccion_id INTEGER NOT NULL,
                cantidad NUMERIC(12,3) NOT NULL DEFAULT 0,
                precio_unit NUMERIC(12,4) NOT NULL DEFAULT 0,
                total NUMERIC(14,2) NOT NULL DEFAULT 0,
                creado_en TIMESTAMP NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_fecha_prod UNIQUE (fecha, producto_produccion_id)
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_pd_fecha ON produccion_diaria(fecha)");
        }
    } catch (Throwable $e) { error_log('[produccion schema] ' . $e->getMessage()); }

    // Seed catálogo con los productos típicos si está vacío
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM productos_produccion")->fetchColumn();
        if ($n === 0) {
            $seed = [
                ['GUAYANES', 4.10],['MESITAS', 2.80],['GUAYANES (4.20)', 4.20],
                ['BANDEJA GUAYANES', 3.90],['AREPERO', 2.90],['AREPERO GRANDE', 4.80],
                ['SUERO', 2.20],['NATILLA', 1.70],['SABORIZADA', 1.60],['CHEDDAR', 1.60],
                ['TELITA', 4.50],['TELITA (3.80)', 3.80],['MOZZARELLA', 8.50],
                ['MANTEQUILLA', 40.00],['CACHAPERO', 22.00],['RICOTA', 25.00],
                ['CLINEJAS', 7.60],['MESITAS PEQ', 1.40],['SUERO 1/2', 1.40],
                ['NATILLA SUAVE', 1.80],['AMARILLO', 9.00],['NATILLA CUÑETE', 45.00],
                ['GUAYA MANOS', 6.00],['SUERO MINI', 1.00],
            ];
            $st = $db->prepare("INSERT INTO productos_produccion (nombre, precio_unit, orden) VALUES (?,?,?)");
            foreach ($seed as $i => [$nom, $pr]) $st->execute([$nom, $pr, ($i+1)*10]);
        }
    } catch (Throwable $e) { error_log('[produccion seed] ' . $e->getMessage()); }
})();

ensure_almacenes_schema();
$almacenes = $db->query("SELECT * FROM almacenes WHERE " . ($MYSQL ? "activo=1" : "activo=TRUE") . " ORDER BY orden, id")->fetchAll();
$alm_default = $almacenes[0]['id'] ?? 0;

$action = $_GET['action'] ?? '';

// ── POST: guardar día ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'guardar_dia') {
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $items = $_POST['items'] ?? []; // [pp_id => ['precio'=>y, 'by_alm'=>[alm_id=>cant]]]

        $db->beginTransaction();
        try {
            // Cargar entradas anteriores de ese día, agrupadas por (pp_id, almacen_id)
            $st = $db->prepare("SELECT pd.producto_produccion_id, pd.cantidad, pd.almacen_id
                                FROM produccion_diaria pd
                                WHERE pd.fecha = ?");
            $st->execute([$fecha]);
            $prev = []; // [pp_id][alm_id] => cantidad
            foreach ($st->fetchAll() as $r) {
                $pp = (int)$r['producto_produccion_id'];
                $am = (int)($r['almacen_id'] ?? 0);
                if ($am <= 0) continue;
                $prev[$pp][$am] = (float)$r['cantidad'];
            }

            // Cargar links pp_id -> producto_id (inventario); auto-crear inventario para los no vinculados
            $st = $db->prepare("SELECT id, nombre, precio_unit, producto_id FROM productos_produccion");
            $st->execute();
            $link = [];
            $stFind = $db->prepare("SELECT id FROM productos WHERE LOWER(nombre)=LOWER(?) AND nombre NOT LIKE '[ARCHIVADO]%' LIMIT 1");
            $stIns  = $db->prepare("INSERT INTO productos (nombre,codigo,descripcion,precio,stock,umbral_alerta,moneda,unidad_medida,presentacion) VALUES (?,?,?,?,?,?,?,?,?)");
            $stUpdLink = $db->prepare("UPDATE productos_produccion SET producto_id=? WHERE id=?");
            foreach ($st->fetchAll() as $r) {
                $pp_id = (int)$r['id'];
                $pid = $r['producto_id'] ? (int)$r['producto_id'] : null;
                if (!$pid) {
                    $stFind->execute([$r['nombre']]);
                    $found = (int)($stFind->fetchColumn() ?: 0);
                    if ($found > 0) {
                        $pid = $found;
                    } else {
                        $codigo = 'PRD-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$r['nombre']),0,4)) . '-' . substr((string)(time()+$pp_id),-4);
                        $stIns->execute([$r['nombre'], $codigo, '', (float)$r['precio_unit'], 0, 5, 'USD', 'unidad', '']);
                        $pid = (int)$db->lastInsertId('productos_id_seq');
                    }
                    $stUpdLink->execute([$pid, $pp_id]);
                }
                $link[$pp_id] = $pid;
            }

            $stUp  = $db->prepare("INSERT INTO produccion_diaria (fecha, producto_produccion_id, cantidad, precio_unit, total, almacen_id)
                                   VALUES (?,?,?,?,?,?)" .
                                  ($MYSQL
                                    ? " ON DUPLICATE KEY UPDATE cantidad=VALUES(cantidad), precio_unit=VALUES(precio_unit), total=VALUES(total)"
                                    : " ON CONFLICT (fecha, producto_produccion_id, almacen_id) DO UPDATE SET cantidad=EXCLUDED.cantidad, precio_unit=EXCLUDED.precio_unit, total=EXCLUDED.total"));
            $stDelOne = $db->prepare("DELETE FROM produccion_diaria WHERE fecha=? AND producto_produccion_id=? AND almacen_id=?");
            $stStockTotal = $db->prepare("UPDATE productos SET stock = stock + ? WHERE id=?");

            // Set de (pp_id, alm_id) tocados en el POST
            $tocados = [];
            foreach ($items as $pp_id => $row) {
                $pp_id = (int)$pp_id;
                if (!isset($link[$pp_id])) continue;
                $prec = (float)str_replace(',', '.', (string)($row['precio'] ?? '0'));
                $byAlm = is_array($row['by_alm'] ?? null) ? $row['by_alm'] : [];
                $prod_inv = $link[$pp_id];

                foreach ($byAlm as $alm_id => $rawCant) {
                    $alm_id = (int)$alm_id;
                    if ($alm_id <= 0) continue;
                    $cant = (float)str_replace(',', '.', (string)$rawCant);
                    if ($cant < 0) $cant = 0;
                    $tocados[$pp_id][$alm_id] = true;
                    $prev_cant = $prev[$pp_id][$alm_id] ?? 0.0;

                    if ($cant > 0) {
                        $tot = round($cant * $prec, 2);
                        $stUp->execute([$fecha, $pp_id, $cant, $prec, $tot, $alm_id]);
                    } else {
                        if ($prev_cant > 0) $stDelOne->execute([$fecha, $pp_id, $alm_id]);
                    }

                    $delta = $cant - $prev_cant;
                    if ($prod_inv && $delta != 0.0) {
                        ajustar_stock_almacen($prod_inv, $alm_id, $delta);
                        $stStockTotal->execute([$delta, $prod_inv]);
                    }
                }
            }

            // Eliminar entradas previas (pp_id, alm_id) que NO vinieron en el POST → revertir stock
            foreach ($prev as $pp_id => $byAlm) {
                $prod_inv = $link[$pp_id] ?? null;
                foreach ($byAlm as $alm_id => $prev_cant) {
                    if (isset($tocados[$pp_id][$alm_id])) continue;
                    $stDelOne->execute([$fecha, $pp_id, $alm_id]);
                    if ($prod_inv && $prev_cant != 0) {
                        ajustar_stock_almacen($prod_inv, $alm_id, -$prev_cant);
                        $stStockTotal->execute([-$prev_cant, $prod_inv]);
                    }
                }
            }

            $db->commit();
            flash("Producción del $fecha guardada. Stock actualizado por almacén.");
        } catch (Throwable $e) {
            $db->rollBack();
            flash('Error guardando: ' . $e->getMessage(), 'error');
        }
        // Tras guardar, ir al día SIGUIENTE con la hoja vacía
        $next = (new DateTime($fecha))->modify('+1 day')->format('Y-m-d');
        header('Location: ?p=produccion_diaria&fecha=' . urlencode($next)); exit;
    }

    if ($act === 'cat_save') {
        $id     = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $precio = (float)str_replace(',', '.', (string)($_POST['precio_unit'] ?? '0'));
        $prod   = (int)($_POST['producto_id'] ?? 0) ?: null;
        $orden  = (int)($_POST['orden'] ?? 0);
        $activo = isset($_POST['activo']) ? 1 : 0;
        if ($nombre === '') { flash('El nombre es requerido.', 'error'); }
        else {
            // Si no se vinculó a inventario, crear/encontrar automáticamente uno con el mismo nombre.
            if (!$prod) {
                try {
                    $st = $db->prepare("SELECT id FROM productos WHERE LOWER(nombre)=LOWER(?) LIMIT 1");
                    $st->execute([$nombre]);
                    $found = (int)($st->fetchColumn() ?: 0);
                    if ($found > 0) {
                        $prod = $found;
                    } else {
                        // Generar código único
                        $codigo = 'PRD-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/','',$nombre),0,4)) . '-' . substr((string)time(),-4);
                        $db->prepare("INSERT INTO productos (nombre,codigo,descripcion,precio,stock,umbral_alerta,moneda,unidad_medida,presentacion) VALUES (?,?,?,?,?,?,?,?,?)")
                           ->execute([$nombre, $codigo, '', $precio, 0, 5, 'USD', 'unidad', '']);
                        $prod = (int)$db->lastInsertId('productos_id_seq');
                        flash("Producto creado en inventario automáticamente: $nombre.", 'success');
                    }
                } catch (Throwable $e) {
                    error_log('[auto-create producto] ' . $e->getMessage());
                }
            }
            if ($id) {
                $db->prepare("UPDATE productos_produccion SET nombre=?, precio_unit=?, producto_id=?, orden=?, activo=? WHERE id=?")
                   ->execute([$nombre, $precio, $prod, $orden, $MYSQL ? $activo : ($activo ? 'true' : 'false'), $id]);
                flash('Producto actualizado.');
            } else {
                $db->prepare("INSERT INTO productos_produccion (nombre, precio_unit, producto_id, orden, activo) VALUES (?,?,?,?,?)")
                   ->execute([$nombre, $precio, $prod, $orden, $MYSQL ? $activo : ($activo ? 'true' : 'false')]);
                flash('Producto agregado al catálogo y vinculado al inventario.');
            }
        }
        header('Location: ?p=produccion_diaria&action=catalogo'); exit;
    }

    if ($act === 'cat_delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM productos_produccion WHERE id=?")->execute([$id]);
            flash('Producto eliminado.', 'warning');
        } catch (Throwable $e) {
            flash('No se puede eliminar: tiene producción registrada. Desactívalo en su lugar.', 'error');
        }
        header('Location: ?p=produccion_diaria&action=catalogo'); exit;
    }
}

// ── Vista: Catálogo ──────────────────────────────────────────────────────────
if ($action === 'catalogo') {
    $cat = $db->query("SELECT pp.*, pr.nombre AS prod_nom
                       FROM productos_produccion pp
                       LEFT JOIN productos pr ON pr.id = pp.producto_id
                       ORDER BY pp.orden, pp.id")->fetchAll();
    $inv = $db->query("SELECT id, nombre FROM productos ORDER BY nombre")->fetchAll();
    $edit = null;
    if (isset($_GET['id'])) {
        $st = $db->prepare("SELECT * FROM productos_produccion WHERE id=?");
        $st->execute([(int)$_GET['id']]); $edit = $st->fetch() ?: null;
    }
    layout_start('Catálogo de Producción', 'produccion_diaria');
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h4 class="mb-0">Catálogo de productos de producción</h4>
      <div class="d-flex gap-2">
        <a href="?p=produccion_diaria" class="btn btn-sm btn-outline-secondary">← Volver al día</a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCat">+ Agregar producto</button>
      </div>
    </div>
    <div class="alert alert-info py-2 small">
      Define aquí los productos que se producen. El <b>precio unitario</b> es el valor por defecto que aparecerá en la planilla diaria. Si lo vinculas a un producto del inventario, al guardar la producción se sumará automáticamente al stock de ese producto.
    </div>
    <div class="card"><div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead><tr><th>Orden</th><th>Producto</th><th class="text-end">Precio Unit ($)</th><th>Vinculado a inventario</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($cat as $r): ?>
          <tr>
            <td><?= h($r['orden']) ?></td>
            <td><b><?= h($r['nombre']) ?></b></td>
            <td class="text-end">$ <?= number_format((float)$r['precio_unit'], 2) ?></td>
            <td><?= $r['prod_nom'] ? h($r['prod_nom']) : '<span class="text-muted">—</span>' ?></td>
            <td><?php $act = $MYSQL ? (int)$r['activo'] : ($r['activo'] === 't' || $r['activo'] === true || $r['activo']===1 || $r['activo']==='1'); ?>
              <?= $act ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?>
            </td>
            <td>
              <a href="?p=produccion_diaria&action=catalogo&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este producto del catálogo?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="cat_delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cat): ?><tr><td colspan="6" class="text-center text-muted py-4">Catálogo vacío</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>

    <div class="modal fade <?= $edit ? 'show d-block' : '' ?>" id="modalCat" tabindex="-1" style="<?= $edit ? 'background:rgba(0,0,0,.5)' : '' ?>">
      <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><?= $edit ? 'Editar producto' : 'Nuevo producto' ?></h5>
          <?php if ($edit): ?><a href="?p=produccion_diaria&action=catalogo" class="btn-close"></a>
          <?php else: ?><button class="btn-close" data-bs-dismiss="modal"></button><?php endif; ?>
        </div>
        <form method="post">
          <div class="modal-body">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="cat_save">
            <input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
            <div class="mb-3"><label class="form-label">Nombre</label>
              <input name="nombre" class="form-control" required value="<?= h($edit['nombre'] ?? '') ?>"></div>
            <div class="row g-2">
              <div class="col-6 mb-3"><label class="form-label">Precio Unit ($)</label>
                <input name="precio_unit" type="number" step="0.0001" min="0" class="form-control" required value="<?= h($edit['precio_unit'] ?? '0') ?>"></div>
              <div class="col-6 mb-3"><label class="form-label">Orden (menor = arriba)</label>
                <input name="orden" type="number" class="form-control" value="<?= h($edit['orden'] ?? '0') ?>"></div>
            </div>
            <div class="mb-3"><label class="form-label">Vincular a producto de inventario <small class="text-muted">(opcional — si lo eliges, al guardar producción se suma al stock)</small></label>
              <select name="producto_id" class="form-select">
                <option value="0">— Sin vínculo —</option>
                <?php foreach ($inv as $p): ?>
                  <option value="<?= $p['id'] ?>" <?= ($edit['producto_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-check"><input type="checkbox" name="activo" id="actChk" class="form-check-input" <?= (!$edit || (string)($edit['activo'] ?? '1') !== '0') ? 'checked' : '' ?>>
              <label for="actChk" class="form-check-label">Activo (aparece en la planilla diaria)</label></div>
          </div>
          <div class="modal-footer">
            <a href="?p=produccion_diaria&action=catalogo" class="btn btn-outline-secondary">Cancelar</a>
            <button class="btn btn-primary">Guardar</button>
          </div>
        </form>
      </div></div>
    </div>
    <?php layout_end(); return;
}

// ── Vista: Resumen mensual ───────────────────────────────────────────────────
if ($action === 'resumen') {
    $mes = $_GET['mes'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
    $ini = $mes . '-01';
    $dt  = new DateTime($ini);
    $fin = (clone $dt)->modify('last day of this month')->format('Y-m-d');

    // Por día
    $st = $db->prepare("SELECT fecha, SUM(total) AS total, SUM(cantidad) AS cantidad
                        FROM produccion_diaria WHERE fecha BETWEEN ? AND ?
                        GROUP BY fecha ORDER BY fecha");
    $st->execute([$ini, $fin]); $por_dia = $st->fetchAll();

    // Por producto
    $st = $db->prepare("SELECT pp.nombre, SUM(pd.cantidad) AS cantidad, SUM(pd.total) AS total
                        FROM produccion_diaria pd JOIN productos_produccion pp ON pp.id = pd.producto_produccion_id
                        WHERE pd.fecha BETWEEN ? AND ?
                        GROUP BY pp.id, pp.nombre ORDER BY total DESC");
    $st->execute([$ini, $fin]); $por_prod = $st->fetchAll();

    $total_mes = array_sum(array_column($por_dia, 'total'));
    $dias_con  = count($por_dia);
    $max_dia   = $por_dia ? max(array_column($por_dia, 'total')) : 0;
    $promedio  = $dias_con ? $total_mes / $dias_con : 0;
    $mejor_dia = null; $peor_dia = null;
    foreach ($por_dia as $d) {
        if ($mejor_dia === null || (float)$d['total'] > (float)$mejor_dia['total']) $mejor_dia = $d;
        if ($peor_dia === null  || (float)$d['total'] < (float)$peor_dia['total'])  $peor_dia  = $d;
    }

    layout_start('Resumen mensual — Producción', 'produccion_diaria');
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h4 class="mb-0">Resumen de producción — <?= h((new DateTime($ini))->format('F Y')) ?></h4>
      <form method="get" class="d-flex gap-2">
        <input type="hidden" name="p" value="produccion_diaria">
        <input type="hidden" name="action" value="resumen">
        <input type="month" name="mes" value="<?= h($mes) ?>" class="form-control form-control-sm">
        <button class="btn btn-sm btn-primary">Ver</button>
        <a href="?p=produccion_diaria" class="btn btn-sm btn-outline-secondary">← Día</a>
      </form>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-3"><div class="card"><div class="card-body">
        <small class="text-muted">Total del mes</small>
        <h3 class="mb-0">$ <?= number_format($total_mes, 2) ?></h3></div></div></div>
      <div class="col-md-3"><div class="card"><div class="card-body">
        <small class="text-muted">Días con producción</small>
        <h3 class="mb-0"><?= $dias_con ?></h3></div></div></div>
      <div class="col-md-3"><div class="card"><div class="card-body">
        <small class="text-muted">Promedio diario</small>
        <h3 class="mb-0">$ <?= number_format($promedio, 2) ?></h3></div></div></div>
      <div class="col-md-3"><div class="card"><div class="card-body">
        <small class="text-muted">Mejor día</small>
        <h6 class="mb-0"><?= $mejor_dia ? h($mejor_dia['fecha']) . ' — $' . number_format((float)$mejor_dia['total'], 2) : '—' ?></h6>
        <small class="text-muted">Día más bajo: <?= $peor_dia ? h($peor_dia['fecha']) . ' — $' . number_format((float)$peor_dia['total'], 2) : '—' ?></small>
      </div></div></div>
    </div>

    <div class="card mb-3"><div class="card-body">
      <h6 class="mb-3">Producción por día ($)</h6>
      <?php if (!$por_dia): ?><p class="text-muted mb-0">Sin producción en este mes.</p>
      <?php else: ?>
        <div style="display:flex;align-items:flex-end;gap:6px;height:180px;border-bottom:2px solid #ccc;padding-bottom:4px;overflow-x:auto">
          <?php foreach ($por_dia as $d): $h = $max_dia > 0 ? ((float)$d['total'] / $max_dia * 160) : 0; ?>
            <div style="flex:0 0 28px;text-align:center" title="<?= h($d['fecha']) ?>: $<?= number_format((float)$d['total'],2) ?>">
              <div style="background:#198754;width:100%;height:<?= $h ?>px;border-radius:3px 3px 0 0"></div>
              <small style="font-size:.65rem;color:#666;display:block;margin-top:2px"><?= (new DateTime($d['fecha']))->format('d') ?></small>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div></div>

    <div class="row g-3">
      <div class="col-md-6"><div class="card"><div class="card-body">
        <h6 class="mb-3">Días</h6>
        <table class="table table-sm mb-0">
          <thead><tr><th>Fecha</th><th class="text-end">Cantidad</th><th class="text-end">Total $</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($por_dia as $d): ?>
            <tr><td><?= h($d['fecha']) ?></td>
              <td class="text-end"><?= number_format((float)$d['cantidad'], 2) ?></td>
              <td class="text-end">$ <?= number_format((float)$d['total'], 2) ?></td>
              <td><a class="btn btn-sm btn-link p-0" href="?p=produccion_diaria&fecha=<?= h($d['fecha']) ?>">Ver</a></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div></div>
      <div class="col-md-6"><div class="card"><div class="card-body">
        <h6 class="mb-3">Productos (de mayor a menor)</h6>
        <table class="table table-sm mb-0">
          <thead><tr><th>Producto</th><th class="text-end">Cantidad</th><th class="text-end">Total $</th></tr></thead>
          <tbody>
          <?php foreach ($por_prod as $r): ?>
            <tr><td><?= h($r['nombre']) ?></td>
              <td class="text-end"><?= number_format((float)$r['cantidad'], 2) ?></td>
              <td class="text-end">$ <?= number_format((float)$r['total'], 2) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$por_prod): ?><tr><td colspan="3" class="text-muted text-center">—</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div></div></div>
    </div>
    <?php layout_end(); return;
}

// ── Vista por defecto: Producción del día ────────────────────────────────────
$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');

$prods = $db->query("SELECT pp.*, pr.nombre AS prod_nom
                     FROM productos_produccion pp
                     LEFT JOIN productos pr ON pr.id = pp.producto_id
                     WHERE " . ($MYSQL ? "pp.activo = 1" : "pp.activo = TRUE") . "
                     ORDER BY pp.orden, pp.id")->fetchAll();

$st = $db->prepare("SELECT producto_produccion_id, almacen_id, cantidad, precio_unit FROM produccion_diaria WHERE fecha=?");
$st->execute([$fecha]);
$exist = []; // [pp_id] => ['precio'=>x, 'by_alm'=>[alm_id=>cant]]
foreach ($st->fetchAll() as $r) {
    $pp = (int)$r['producto_produccion_id'];
    $am = (int)($r['almacen_id'] ?? 0);
    if (!isset($exist[$pp])) $exist[$pp] = ['precio' => (float)$r['precio_unit'], 'by_alm' => []];
    if ($am > 0) $exist[$pp]['by_alm'][$am] = (float)$r['cantidad'];
}
$total_dia_guardado = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM produccion_diaria WHERE fecha = " . $db->quote($fecha))->fetchColumn();

// Stock por almacén (para el modal)
$stock_alm_rows = $db->query("
    SELECT p.id, p.nombre, p.unidad_medida,
           ps.almacen_id, COALESCE(ps.stock,0) AS stock_alm
    FROM productos p
    LEFT JOIN productos_stock ps ON ps.producto_id = p.id
    WHERE p.nombre NOT LIKE '[ARCHIVADO]%'
    ORDER BY p.nombre
")->fetchAll();
$stock_alm = [];
foreach ($stock_alm_rows as $r) {
    $pid = (int)$r['id'];
    if (!isset($stock_alm[$pid])) {
        $stock_alm[$pid] = ['nombre'=>$r['nombre'],'unidad'=>$r['unidad_medida'],'total'=>0.0,'by_alm'=>[]];
    }
    if ($r['almacen_id'] !== null) {
        $v = (float)$r['stock_alm'];
        $stock_alm[$pid]['by_alm'][(int)$r['almacen_id']] = $v;
        $stock_alm[$pid]['total'] += $v;
    }
}
// Totales por almacén
$tot_por_alm = [];
foreach ($almacenes as $a) $tot_por_alm[(int)$a['id']] = 0.0;
foreach ($stock_alm as $info) {
    foreach ($info['by_alm'] as $aid => $v) {
        if (isset($tot_por_alm[$aid])) $tot_por_alm[$aid] += $v;
    }
}

layout_start('Producción Diaria', 'produccion_diaria');
?>
<style>
  .tabla-prod th{background:#1f2d5c;color:#fff;text-align:center;padding:.4rem .5rem;font-size:.82rem}
  .tabla-prod td{padding:.25rem .4rem;vertical-align:middle;font-size:.85rem}
  .tabla-prod input{width:100%;padding:.2rem .4rem;font-size:.85rem;border:1px solid #ccc;border-radius:.25rem;text-align:right}
  .tabla-prod tbody tr:nth-child(even){background:#fafafa}
  .tabla-prod tbody tr:hover{background:#fff8e1}
  .col-tot{background:#e8f4ff;font-weight:600;text-align:right;color:#0a4b6b}
  .resumen-bar{background:linear-gradient(90deg,#198754,#0d6e3f);color:#fff;padding:.7rem 1rem;border-radius:.4rem;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:1.1rem;box-shadow:0 2px 6px rgba(0,0,0,.1)}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">Producción Diaria</h4>
    <small class="text-muted">Inventario General de Producción Diaria</small>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <form method="get" class="d-flex gap-2">
      <input type="hidden" name="p" value="produccion_diaria">
      <input type="date" name="fecha" value="<?= h($fecha) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
    </form>
    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#modalStockAlm">🏬 Stock por almacén</button>
    <a href="?p=reporte_diario_pdf&fecha=<?= h($fecha) ?>" target="_blank" class="btn btn-sm btn-danger">📄 PDF del día</a>
    <a href="?p=produccion_diaria&action=resumen&mes=<?= h(substr($fecha,0,7)) ?>" class="btn btn-sm btn-outline-info">📊 Resumen mensual</a>
    <a href="?p=produccion_diaria&action=catalogo" class="btn btn-sm btn-outline-secondary">⚙ Catálogo</a>
  </div>
</div>

<div class="resumen-bar mb-3">
  <span>SEMANA / FECHA: <?= h((new DateTime($fecha))->format('d-m-Y')) ?> (<?= ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'][(int)(new DateTime($fecha))->format('w')] ?>)</span>
  <span>TOTAL DEL DÍA: $ <span id="totalDia">0.00</span></span>
</div>

<?php if (!$prods): ?>
  <div class="alert alert-warning">No hay productos en el catálogo. <a href="?p=produccion_diaria&action=catalogo">Agregar productos →</a></div>
<?php else: ?>
<form method="post" id="formDia">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="guardar_dia">
  <input type="hidden" name="fecha" value="<?= h($fecha) ?>">

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 sticky-top py-2" style="background:rgba(255,253,247,.95);backdrop-filter:blur(6px);z-index:5;top:0">
    <h6 class="fw-bold mb-0" style="color:#5a3a17">📋 Producción del día <?= h($fecha) ?></h6>
    <button class="btn btn-success btn-lg shadow-sm">💾 Guardar producción del día</button>
  </div>

  <div class="alert alert-info py-2 small mb-3" style="background:linear-gradient(135deg,#fff8e1,#fffbf0);border-color:#e6d9b8;color:#5a3a17">
    <b>🏬 Distribución por almacén:</b> escribe en cada columna la cantidad que va a ese almacén. Puedes repartir el mismo producto en varios (ej: 100 al <?= h($almacenes[0]['nombre']??'Alm 1') ?> y 50 al <?= h($almacenes[1]['nombre']??'Alm 2') ?>). Al guardar, irás automáticamente al siguiente día con la hoja vacía.
  </div>
  <div class="card"><div class="card-body p-0" style="overflow-x:auto">
    <table class="table tabla-prod mb-0">
      <thead><tr>
        <th style="width:35px">#</th>
        <th>PRODUCTO</th>
        <th style="width:100px">PRECIO UNIT</th>
        <?php foreach ($almacenes as $i => $a): ?>
          <th style="width:110px;background:<?= ['#1f6e3f','#1f4f87','#7a4a1a'][$i] ?? '#1f2d5c' ?>"><?= h(strtoupper($a['nombre'])) ?></th>
        <?php endforeach ?>
        <th style="width:90px;background:#5a3a17">TOTAL UDS</th>
        <th style="width:115px">TOTAL $</th>
        <th style="width:140px">→ Inventario</th>
      </tr></thead>
      <tbody>
      <?php $i=0; foreach ($prods as $p): $i++;
        $pid = (int)$p['id'];
        $ex = $exist[$pid] ?? null;
        $prec = $ex['precio'] ?? (float)$p['precio_unit'];
        $by_alm = $ex['by_alm'] ?? [];
        $sum_cant = array_sum($by_alm);
        $tot = $sum_cant * $prec;
      ?>
        <tr data-pid="<?= $pid ?>">
          <td class="text-center text-muted"><?= $i ?></td>
          <td><b><?= h($p['nombre']) ?></b></td>
          <td><input type="number" step="0.0001" min="0" name="items[<?= $pid ?>][precio]" value="<?= h(rtrim(rtrim(number_format((float)$prec,4,'.',''),'0'),'.')) ?>" class="precio" data-id="<?= $pid ?>"></td>
          <?php foreach ($almacenes as $a):
            $aid = (int)$a['id'];
            $c = $by_alm[$aid] ?? '';
          ?>
            <td><input type="number" step="0.001" min="0" name="items[<?= $pid ?>][by_alm][<?= $aid ?>]" value="<?= $c !== '' ? h(rtrim(rtrim(number_format((float)$c,3,'.',''),'0'),'.')) : '' ?>" class="cant-alm" data-id="<?= $pid ?>" data-alm="<?= $aid ?>" placeholder="0"></td>
          <?php endforeach ?>
          <td class="text-center" style="background:#f5e9c8;font-weight:700;color:#5a3a17" id="sum-<?= $pid ?>"><?= rtrim(rtrim(number_format($sum_cant,3,'.',''),'0'),'.') ?: '0' ?></td>
          <td class="col-tot" id="tot-<?= $pid ?>">$ <?= number_format($tot, 2) ?></td>
          <td><small class="<?= $p['prod_nom'] ? 'text-success' : 'text-muted' ?>"><?= $p['prod_nom'] ? '↑ '.h($p['prod_nom']) : '— sin vínculo —' ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <small class="text-muted">Cada celda de almacén es independiente. Al guardar, el stock de cada almacén se ajusta por su cuenta y se va al día siguiente.</small>
    <button class="btn btn-success btn-lg">💾 Guardar y pasar al siguiente día</button>
  </div>
</form>

<script>
(function(){
  function fmt(n){ return n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function fmt3(n){ var s=n.toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:3}); return s; }
  function recalcRow(pid){
    var prec = parseFloat(document.querySelector('.precio[data-id="'+pid+'"]').value) || 0;
    var sum = 0;
    document.querySelectorAll('.cant-alm[data-id="'+pid+'"]').forEach(function(el){
      sum += parseFloat(el.value) || 0;
    });
    document.getElementById('sum-'+pid).textContent = fmt3(sum);
    document.getElementById('tot-'+pid).textContent = '$ ' + fmt(sum*prec);
    recalcTotal();
  }
  function recalcTotal(){
    var tot = 0;
    document.querySelectorAll('tr[data-pid]').forEach(function(tr){
      var pid = tr.dataset.pid;
      var prec = parseFloat(document.querySelector('.precio[data-id="'+pid+'"]').value) || 0;
      var s = 0;
      document.querySelectorAll('.cant-alm[data-id="'+pid+'"]').forEach(function(el){ s += parseFloat(el.value)||0; });
      tot += s*prec;
    });
    document.getElementById('totalDia').textContent = fmt(tot);
  }
  document.querySelectorAll('.cant-alm, .precio').forEach(function(el){
    el.addEventListener('input', function(){ recalcRow(el.dataset.id); });
  });
  recalcTotal();
})();
</script>
<?php endif; ?>

<!-- Modal: Stock por almacén (pantalla completa) -->
<div class="modal fade" id="modalStockAlm" tabindex="-1">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header" style="background:#3a2410;color:#fff8e8">
        <h3 class="modal-title fw-bold">🏬 Stock total por almacén</h3>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" style="background:#fffdf7">
        <div class="p-4" style="background:linear-gradient(135deg,#fff8e1,#fffbf0);border-bottom:2px solid #e6d9b8">
          <div class="row g-3 mb-3">
            <?php foreach ($almacenes as $i => $a):
              $aid = (int)$a['id']; $tot = $tot_por_alm[$aid] ?? 0;
              $col = ['#1f6e3f','#1f4f87','#7a4a1a'][$i] ?? '#5a3a17';
            ?>
              <div class="col-md-4">
                <div style="background:#fff;border-left:8px solid <?= $col ?>;border-radius:12px;padding:18px 22px;box-shadow:0 2px 8px rgba(0,0,0,.08)">
                  <div style="font-size:.95rem;letter-spacing:.8px;color:#9a7a3a;text-transform:uppercase;font-weight:800"><?= h($a['nombre']) ?></div>
                  <div style="font-size:3.2rem;font-weight:900;color:<?= $col ?>;line-height:1"><?= rtrim(rtrim(number_format($tot,3,'.',''),'0'),'.') ?: '0' ?></div>
                  <div style="font-size:1rem;color:#5a3a17;font-weight:600">unidades totales</div>
                </div>
              </div>
            <?php endforeach ?>
          </div>
          <input type="text" id="stockAlmSearch" class="form-control form-control-lg" placeholder="🔍 Buscar producto por nombre..." autocomplete="off" style="font-size:1.2rem;padding:14px 20px;border:2px solid #d4a017;border-radius:12px;box-shadow:0 2px 6px rgba(0,0,0,.05)">
          <div id="stockAlmCount" class="mt-2" style="color:#5a3a17;font-weight:600"></div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="stockAlmTable" style="font-size:1.15rem">
            <thead>
              <tr style="background:#3a2410;color:#fff8e8">
                <th style="padding:16px 18px;font-size:1.05rem;letter-spacing:.6px">PRODUCTO</th>
                <?php foreach ($almacenes as $i => $a):
                  $col = ['#1f6e3f','#1f4f87','#7a4a1a'][$i] ?? '#5a3a17';
                ?>
                  <th style="padding:16px 18px;font-size:1.05rem;text-align:center;background:<?= $col ?>;letter-spacing:.6px"><?= h(strtoupper($a['nombre'])) ?></th>
                <?php endforeach ?>
                <th style="padding:16px 18px;font-size:1.05rem;text-align:center;background:#5a3a17;letter-spacing:.6px">TOTAL</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$stock_alm): ?>
                <tr><td colspan="<?= count($almacenes)+2 ?>" class="text-center text-muted py-5" style="font-size:1.2rem">Sin productos en inventario todavía.</td></tr>
              <?php endif ?>
              <?php foreach ($stock_alm as $info): ?>
                <tr data-nombre="<?= h(strtolower($info['nombre'])) ?>">
                  <td style="padding:14px 18px;font-weight:800;color:#3a2410;font-size:1.2rem"><?= h($info['nombre']) ?> <small style="color:#9a7a3a;font-size:.85rem;font-weight:500">· <?= h($info['unidad']) ?></small></td>
                  <?php foreach ($almacenes as $a):
                    $aid = (int)$a['id'];
                    $v = $info['by_alm'][$aid] ?? 0;
                    $vs = rtrim(rtrim(number_format($v,3,'.',''),'0'),'.') ?: '0';
                  ?>
                    <td style="padding:14px 18px;text-align:center;font-size:1.45rem;<?= $v > 0 ? 'font-weight:900;color:#2c1810' : 'color:#c9c0a8;font-weight:400' ?>"><?= $vs ?></td>
                  <?php endforeach ?>
                  <td style="padding:14px 18px;text-align:center;background:#fff8e8;font-weight:900;color:#5a3a17;font-size:1.55rem"><?= rtrim(rtrim(number_format((float)$info['total'],3,'.',''),'0'),'.') ?: '0' ?></td>
                </tr>
              <?php endforeach ?>
              <tr id="stockAlmEmpty" style="display:none"><td colspan="<?= count($almacenes)+2 ?>" class="text-center text-muted py-5" style="font-size:1.2rem">Ningún producto coincide con la búsqueda.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer" style="background:#fffdf7;border-top:2px solid #e6d9b8">
        <a href="?p=inventario" class="btn btn-outline-primary btn-lg">📦 Ir a Inventario</a>
        <button class="btn btn-secondary btn-lg" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var inp = document.getElementById('stockAlmSearch');
  var cnt = document.getElementById('stockAlmCount');
  var emp = document.getElementById('stockAlmEmpty');
  if (!inp) return;
  function filtrar(){
    var q = inp.value.trim().toLowerCase();
    var visibles = 0, total = 0;
    document.querySelectorAll('#stockAlmTable tbody tr[data-nombre]').forEach(function(tr){
      total++;
      var ok = !q || tr.dataset.nombre.indexOf(q) !== -1;
      tr.style.display = ok ? '' : 'none';
      if (ok) visibles++;
    });
    cnt.textContent = q ? ('Mostrando ' + visibles + ' de ' + total + ' productos') : (total + ' productos en inventario');
    emp.style.display = (q && visibles === 0) ? '' : 'none';
  }
  inp.addEventListener('input', filtrar);
  document.getElementById('modalStockAlm').addEventListener('shown.bs.modal', function(){ inp.focus(); filtrar(); });
})();
</script>

<?php layout_end();
