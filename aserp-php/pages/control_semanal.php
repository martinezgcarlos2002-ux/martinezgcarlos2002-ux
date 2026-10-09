<?php
require_login();
$db = get_db();

// ── Calcular sábado de la semana ──────────────────────────────────────────────
function snap_to_saturday(string $date): DateTime {
    $dt  = new DateTime($date);
    $dow = (int)$dt->format('N'); // 1=Lun … 6=Sáb, 7=Dom
    if ($dow !== 6) {
        $snap = ($dow === 7) ? 1 : ($dow + 1);
        $dt->modify("-$snap days");
    }
    return $dt;
}

$hoy        = new DateTime();
$dow_hoy    = (int)$hoy->format('N');
$off        = ($dow_hoy === 6) ? 0 : (($dow_hoy === 7) ? 1 : ($dow_hoy + 1));
$default_sab = (clone $hoy)->modify("-$off days")->format('Y-m-d');

$ws_raw = $_GET['ws'] ?? $default_sab;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ws_raw)) $ws_raw = $default_sab;

$ws_dt  = snap_to_saturday($ws_raw);
$we_dt  = (clone $ws_dt)->modify('+6 days');
$ws     = $ws_dt->format('Y-m-d');
$we     = $we_dt->format('Y-m-d');
$prev_ws = (clone $ws_dt)->modify('-7 days')->format('Y-m-d');
$next_ws = (clone $ws_dt)->modify('+7 days')->format('Y-m-d');

$dias        = [];
$d = clone $ws_dt;
for ($i = 0; $i < 7; $i++) { $dias[] = $d->format('Y-m-d'); $d->modify('+1 day'); }
$dias_labels = ['Sáb','Dom','Lun','Mar','Mié','Jue','Vie'];

// ── POST: guardar semana ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ws_p   = $_POST['ws'] ?? $ws;
    $ws_p   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ws_p) ? $ws_p : $ws;
    $ws_p_dt = snap_to_saturday($ws_p);
    $we_p   = (clone $ws_p_dt)->modify('+6 days')->format('Y-m-d');
    $ws_p   = $ws_p_dt->format('Y-m-d');

    // Precios de productores (para recalcular subtotal)
    $precios_map = [];
    foreach (($db->query("SELECT id, precio_litro_usd FROM productores WHERE estado=true")->fetchAll()) as $r)
        $precios_map[$r['id']] = (float)$r['precio_litro_usd'];

    // ── Actualizar precio por litro ──────────────────────────────────────────
    foreach ($_POST['precio'] ?? [] as $pid => $val) {
        $pid = (int)$pid; $val = (float)$val;
        if ($pid > 0 && $val > 0) {
            $db->prepare("UPDATE productores SET precio_litro_usd=? WHERE id=?")->execute([$val, $pid]);
            $precios_map[$pid] = $val;
        }
    }

    // ── Entregas (litros por día) ────────────────────────────────────────────
    $cs_sync = []; // ["pid|semana_inicio" => [pid, fecha]] para sincronizar cierres
    foreach ($_POST['lit'] ?? [] as $pid => $dias_arr) {
        $pid = (int)$pid;
        if (!$pid) continue;
        $precio = $precios_map[$pid] ?? 0;
        foreach ($dias_arr as $fecha => $litros) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) continue;
            $litros  = max(0, (float)$litros);
            $subtotal = round($litros * $precio, 4);
            $st = $db->prepare("SELECT id FROM productores_entregas WHERE productor_id=? AND fecha=?");
            $st->execute([$pid, $fecha]);
            $ex = $st->fetchColumn();
            if ($litros > 0) {
                if ($ex) {
                    $db->prepare("UPDATE productores_entregas SET litros=?, precio_litro_usd=?, subtotal_usd=? WHERE id=?")
                       ->execute([$litros, $precio, $subtotal, $ex]);
                } else {
                    $db->prepare("INSERT INTO productores_entregas(productor_id,fecha,litros,precio_litro_usd,subtotal_usd,gasto_traslado_usd,cuajada_kg) VALUES(?,?,?,?,?,0,0)")
                       ->execute([$pid, $fecha, $litros, $precio, $subtotal]);
                }
            } else {
                if ($ex) $db->prepare("DELETE FROM productores_entregas WHERE id=?")->execute([$ex]);
            }
            $cs_sync[$pid . '|' . snap_sat($fecha)->format('Y-m-d')] = [$pid, $fecha];
        }
    }
    // Si se editó una semana ya cerrada, refresca su snapshot (una vez por semana).
    foreach ($cs_sync as $t) sync_cierre_from_entregas($db, (int)$t[0], $t[1]);

    // ── Abonos editables: mercancia, pm_trans, efectivo ───────────────────────
    // Estrategia: respetar los abonos diarios cargados desde la ficha del productor
    // (los que tienen descripción). El Control Semanal solo gestiona el "ajuste"
    // (entrada sin descripción): si el monto deseado > suma de diarios, agrega el
    // diferencial como ajuste; si es igual o menor, borra el ajuste anterior.
    // Se considera la semana usando COALESCE(semana_aplicada, fecha).
    ensure_abono_semana_column();
    $avisos_menor = [];
    foreach (['mercancia' => 'merc', 'pm_trans' => 'pm', 'efectivo' => 'efec'] as $tipo => $key) {
        foreach ($_POST[$key] ?? [] as $pid => $monto) {
            $pid   = (int)$pid;
            $monto = max(0, (float)$monto);
            if (!$pid) continue;

            // Suma de abonos diarios (con descripción) que YA existen para esta semana/tipo
            $st = $db->prepare("SELECT COALESCE(SUM(monto_usd),0) FROM productores_abonos
                                WHERE productor_id=? AND COALESCE(semana_aplicada,fecha) BETWEEN ? AND ? AND tipo=?
                                  AND descripcion IS NOT NULL AND descripcion <> ''");
            $st->execute([$pid, $ws_p, $we_p, $tipo]);
            $sum_diarios = (float)$st->fetchColumn();

            // Borrar solo el ajuste previo del Control Semanal (sin descripción)
            $db->prepare("DELETE FROM productores_abonos
                          WHERE productor_id=? AND COALESCE(semana_aplicada,fecha) BETWEEN ? AND ? AND tipo=?
                            AND (descripcion IS NULL OR descripcion = '')")
               ->execute([$pid, $ws_p, $we_p, $tipo]);

            $delta = $monto - $sum_diarios;
            if ($delta > 0.005) {
                // Agregar el diferencial como ajuste del control semanal
                $db->prepare("INSERT INTO productores_abonos(productor_id,fecha,semana_aplicada,tipo,monto_usd) VALUES(?,?,?,?,?)")
                   ->execute([$pid, $ws_p, $ws_p, $tipo, $delta]);
            } elseif ($delta < -0.005) {
                // El usuario redujo por debajo de los pagos diarios; lo dejamos en el total individual
                // y avisamos para que pueda corregir manualmente desde la ficha del productor.
                $avisos_menor[] = $pid;
            }
        }
    }
    if ($avisos_menor) {
        $n = count(array_unique($avisos_menor));
        flash("Aviso: en $n productor(es) el monto que escribiste era menor que los pagos diarios ya registrados desde la ficha. Esos pagos se conservaron sin cambios; edítalos desde la ficha del productor si quieres reducirlos.", 'warning');
    }

    // ── Costos manuales del resumen diario ─────────────────────────────────
    // Nota: los costos (personal, transporte, operativo, otros) se gestionan ahora
    // en la calculadora de la página Control de Leche, no aquí.
    $conceptos_costo = ['ventas_prod','cortesias_n','vales_n','procesada_n'];
    foreach ($conceptos_costo as $conc) {
        foreach ($_POST['costo'][$conc] ?? [] as $fecha_c => $monto_c) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_c)) continue;
            $monto_c = max(0, (float)$monto_c);
            if ($monto_c > 0) {
                $upsert_sql = is_mysql()
                    ? "INSERT INTO resumen_costos_diarios(fecha,concepto,monto) VALUES(?,?,?) ON DUPLICATE KEY UPDATE monto=VALUES(monto)"
                    : "INSERT INTO resumen_costos_diarios(fecha,concepto,monto) VALUES(?,?,?) ON CONFLICT(fecha,concepto) DO UPDATE SET monto=EXCLUDED.monto";
                $db->prepare($upsert_sql)->execute([$fecha_c, $conc, $monto_c]);
            } else {
                $db->prepare("DELETE FROM resumen_costos_diarios WHERE fecha=? AND concepto=?")
                   ->execute([$fecha_c, $conc]);
            }
        }
    }

    flash('Semana guardada correctamente.');
    header("Location: ?p=control_semanal&ws=$ws_p"); exit;
}

// ── Productores activos ───────────────────────────────────────────────────────
$prods_st = $db->prepare("SELECT id, nombre, documento, precio_litro_usd FROM productores WHERE estado=true ORDER BY nombre ASC");
$prods_st->execute();
$productores = $prods_st->fetchAll();
$prod_ids    = array_column($productores, 'id');

// ── Entregas de la semana ─────────────────────────────────────────────────────
$ent_idx = [];
if ($prod_ids) {
    $st = $db->prepare("SELECT productor_id, fecha, litros, subtotal_usd FROM productores_entregas WHERE fecha BETWEEN ? AND ?");
    $st->execute([$ws, $we]);
    foreach ($st->fetchAll() as $r)
        $ent_idx[$r['productor_id']][$r['fecha']] = ['litros' => (float)$r['litros'], 'sub' => (float)$r['subtotal_usd']];
}

// ── Abonos de la semana ───────────────────────────────────────────────────────
$abo_idx = []; $abo_dia = [];
if ($prod_ids) {
    ensure_abono_semana_column();
    $st = $db->prepare("SELECT productor_id, fecha, semana_aplicada, tipo, monto_usd
                        FROM productores_abonos
                        WHERE COALESCE(semana_aplicada,fecha) BETWEEN ? AND ?");
    $st->execute([$ws, $we]);
    foreach ($st->fetchAll() as $r) {
        $pid = $r['productor_id'];
        $f_dia = $r['fecha']; // para el resumen diario usamos la fecha real del pago
        $abo_idx[$pid][$r['tipo']] = ($abo_idx[$pid][$r['tipo']] ?? 0) + (float)$r['monto_usd'];
        $abo_dia[$f_dia][$r['tipo']]['n']     = ($abo_dia[$f_dia][$r['tipo']]['n']     ?? 0) + 1;
        $abo_dia[$f_dia][$r['tipo']]['monto'] = ($abo_dia[$f_dia][$r['tipo']]['monto'] ?? 0) + (float)$r['monto_usd'];
    }
}

// ── Saldo pendiente ANTES de esta semana ─────────────────────────────────────
$saldos = [];
if ($prod_ids) {
    $ph = implode(',', array_fill(0, count($prod_ids), '?'));
    $st = $db->prepare("SELECT productor_id, COALESCE(SUM(subtotal_usd),0) AS tot FROM productores_entregas WHERE fecha<? AND productor_id IN ($ph) GROUP BY productor_id");
    $st->execute(array_merge([$ws], $prod_ids));
    $ep = [];
    foreach ($st->fetchAll() as $r) $ep[$r['productor_id']] = (float)$r['tot'];

    $st = $db->prepare("SELECT productor_id, COALESCE(SUM(monto_usd),0) AS tot FROM productores_abonos WHERE COALESCE(semana_aplicada,fecha)<? AND productor_id IN ($ph) GROUP BY productor_id");
    $st->execute(array_merge([$ws], $prod_ids));
    $ap = [];
    foreach ($st->fetchAll() as $r) $ap[$r['productor_id']] = (float)$r['tot'];

    foreach ($prod_ids as $pid) $saldos[$pid] = ($ep[$pid] ?? 0) - ($ap[$pid] ?? 0);
}

// ── Costos manuales cargados de la BD ────────────────────────────────────────
$costos_idx = []; // [concepto][fecha] = monto
$cs = $db->prepare("SELECT fecha, concepto, monto FROM resumen_costos_diarios WHERE fecha BETWEEN ? AND ?");
$cs->execute([$ws, $we]);
foreach ($cs->fetchAll() as $r) $costos_idx[$r['concepto']][$r['fecha']] = (float)$r['monto'];

// ── Totales diarios para tabla resumen ───────────────────────────────────────
$dia_totales = [];
foreach ($dias as $f) {
    $lit = 0; $n = 0; $costo = 0;
    foreach ($prod_ids as $pid) {
        if (isset($ent_idx[$pid][$f])) { $lit += $ent_idx[$pid][$f]['litros']; $costo += $ent_idx[$pid][$f]['sub']; $n++; }
    }
    $dia_totales[$f] = ['litros' => $lit, 'n_prods' => $n, 'costo' => $costo];
}

require_once __DIR__ . '/../layout.php';
layout_start('Control Semanal de Productores', 'productores');
?>

<style>
/* ── Tabla principal ── */
.cs-wrap { overflow-x: auto; }
.cs-table { border-collapse: collapse; font-size: .76rem; min-width: 1100px; width: 100%; }
.cs-table th, .cs-table td { border: 1px solid #c8d4e8; padding: 3px 5px; white-space: nowrap; }
.cs-table thead th { background: #1a2e5a; color: #fff; text-align: center; font-size: .7rem; text-transform: uppercase; }
.cs-table tbody tr:hover { background: #f0f6ff; }
.cs-table tfoot td { background: #1a2e5a; color: #fff; font-weight: 700; text-align: right; font-size: .78rem; padding: 4px 6px; }
.cs-table tfoot td.lbl { text-align: left; }

/* Colores de columnas */
.col-dia  { background: #eef4ff; }
.col-pagar{ background: #fff7e6; color: #92400e; font-weight: 700; }
.col-saldo{ background: #fef2f2; color: #b91c1c; font-weight: 600; }
.col-merc { background: #f0fdf4; color: #166534; }
.col-pm   { background: #eff6ff; color: #1e40af; }
.col-efec { background: #ecfdf5; color: #065f46; }
.col-tabo { background: #faf5ff; color: #6b21a8; font-weight: 700; }
.col-pend { background: #1a2e5a; color: #fff; font-weight: 700; }
.col-lit-tot { background: #dbeafe; color: #1e3a8a; font-weight: 700; }
.nombre-col { font-weight: 600; color: #1a2e5a; max-width: 150px; overflow: hidden; text-overflow: ellipsis; }

/* Inputs editables */
.cs-inp {
    background: transparent;
    border: 1px solid transparent;
    border-radius: 3px;
    text-align: right;
    font-size: .76rem;
    padding: 1px 3px;
    color: inherit;
    width: 62px;
    transition: border-color .15s, background .15s;
}
.cs-inp:hover  { border-color: #93c5fd; background: rgba(255,255,255,.8); }
.cs-inp:focus  { outline: none; border-color: #2563eb; background: #fff; box-shadow: 0 0 0 2px rgba(37,99,235,.2); color:#111; }
.cs-inp-precio { width: 52px; }
.cs-inp-abo    { width: 72px; }
.cs-inp[type=number]::-webkit-inner-spin-button,
.cs-inp[type=number]::-webkit-outer-spin-button { opacity: .4; }

/* Tabla resumen */
.cs-sum { border-collapse: collapse; font-size: .8rem; width: 100%; }
.cs-sum th, .cs-sum td { border: 1px solid #c8d4e8; padding: 4px 9px; white-space: nowrap; }
.cs-sum thead th { background: #1a2e5a; color: #fff; text-align: center; font-size: .7rem; }
.cs-sum tbody tr:nth-child(even) { background: #f8fafc; }
.cs-sum td.num { text-align: right; }
.row-proc { background: #dcfce7 !important; font-weight: 700; }

/* Botón guardar flotante */
.btn-save-fixed {
    position: sticky; bottom: 16px; float: right;
    background: #15803d; color: #fff; border: none;
    padding: 8px 24px; border-radius: 6px; font-size: .9rem; font-weight: 600;
    box-shadow: 0 4px 14px rgba(0,0,0,.25); cursor: pointer;
    z-index: 10;
}
.btn-save-fixed:hover { background: #166534; }
</style>

<!-- ── Cabecera ───────────────────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="?p=productores" class="btn btn-sm btn-outline-secondary">← Volver</a>
  <h5 class="mb-0">📋 Control Semanal de Productores</h5>
  <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
    <a href="?p=control_semanal&ws=<?= $prev_ws ?>" class="btn btn-sm btn-outline-secondary">‹ Ant.</a>
    <form method="get" class="d-flex align-items-center gap-1 m-0">
      <input type="hidden" name="p" value="control_semanal">
      <input type="date" name="ws" class="form-control form-control-sm" value="<?= $ws ?>" style="width:145px" onchange="this.form.submit()">
    </form>
    <a href="?p=control_semanal&ws=<?= $next_ws ?>" class="btn btn-sm btn-outline-secondary">Sig. ›</a>
    <a href="?p=control_semanal&ws=<?= $default_sab ?>" class="btn btn-sm btn-primary">Hoy</a>
  </div>
</div>

<?= get_flash() ?>

<!-- Rango -->
<div class="d-flex align-items-center gap-3 px-3 py-2 mb-2 rounded"
     style="background:#e8f0ff;border:1px solid #b8ccff;font-size:.85rem">
  <strong>📅 Semana:</strong>
  <span><?= $ws_dt->format('d/m/Y') ?> (Sábado) → <?= $we_dt->format('d/m/Y') ?> (Viernes)</span>
  <span class="ms-auto text-muted"><?= count($productores) ?> productores activos</span>
</div>

<!-- Barra de herramientas: búsqueda + exportar -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <div class="input-group input-group-sm" style="max-width:260px">
    <span class="input-group-text bg-white"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.415l-3.868-3.833zm-5.242 1.156a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg></span>
    <input type="text" id="buscar-productor" class="form-control form-control-sm"
           placeholder="Buscar productor..." oninput="filtrarProductores(this.value)">
    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="document.getElementById('buscar-productor').value='';filtrarProductores('')" title="Limpiar">✕</button>
  </div>
  <span id="buscar-resultado" class="text-muted small"></span>
  <div class="ms-auto d-flex gap-2">
    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportarExcel()" title="Exportar a Excel">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" class="me-1"><path d="M14 4.5V14a2 2 0 0 1-2 2h-1v-1h1a1 1 0 0 0 1-1V4.5h-2A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v9H2V2a2 2 0 0 1 2-2h5.5L14 4.5z"/><path d="M7.086 11.414 6 8.19l-1.086 3.224H3.8l1.77-4.29-1.66-4.01h1.1l1.01 3.075 1.01-3.075h1.1l-1.66 4.01 1.77 4.29H7.086z"/></svg>
      Excel
    </button>
    <button type="button" class="btn btn-sm btn-outline-danger" onclick="exportarPDF()" title="Exportar a PDF">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" class="me-1"><path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5L14 4.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5h-2z"/><path d="M5.5 8.5A.5.5 0 0 1 6 8h.5v.5H6a.5.5 0 0 1-.5-.5zm2.5-.5a.5.5 0 0 1 .5.5v.5H8V8a.5.5 0 0 1 .5-.5zm1.5.5a.5.5 0 0 0-1 0v.5h1V8.5z"/></svg>
      PDF
    </button>
  </div>
</div>

<!-- ══ FORMULARIO EDITABLE ═════════════════════════════════════════════════ -->
<form method="post" id="frmSemanal">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="ws" value="<?= $ws ?>">

<div class="d-flex justify-content-between align-items-center mb-2">
  <small class="text-muted">Haz clic en cualquier celda para editarla. Los totales se actualizan en tiempo real.</small>
  <button type="submit" class="btn btn-success btn-sm px-4">💾 Guardar Semana</button>
</div>

<!-- ── TABLA PRINCIPAL ───────────────────────────────────────────────────── -->
<div class="cs-wrap mb-4">
<table class="cs-table" id="tblMain">
<thead>
  <tr>
    <th rowspan="2">#</th>
    <th rowspan="2" style="min-width:130px">NOMBRE</th>
    <th rowspan="2">CÉDULA</th>
    <th rowspan="2">PRECIO $/L</th>
    <?php foreach ($dias_labels as $i => $dl): ?>
    <th class="col-dia"><?= $dl ?><br><span style="font-weight:400;font-size:.63rem"><?= date('d/m', strtotime($dias[$i])) ?></span></th>
    <?php endforeach ?>
    <th class="col-lit-tot">TOT.<br>LITROS</th>
    <th class="col-pagar">TOTAL A<br>PAGAR $</th>
    <th class="col-saldo">SALDO<br>PEND. $</th>
    <th class="col-merc">MERC. $</th>
    <th class="col-pm">PM/<br>TRANS $</th>
    <th class="col-efec">EFEC. $</th>
    <th class="col-tabo">TOT.<br>ABONOS $</th>
    <th class="col-pend">PENDIENTE<br>A PAGAR $</th>
  </tr>
</thead>
<tbody>
<?php
$num = 0;
$tot_dia_l   = array_fill(0, 7, 0);
$tot_litros_g= $tot_pagar_g= $tot_saldo_g= $tot_merc_g= $tot_pm_g= $tot_efec_g= $tot_abo_g= $tot_pend_g= 0;

foreach ($productores as $p):
    $num++;
    $pid    = $p['id'];
    $precio = (float)$p['precio_litro_usd'];
    $saldo  = $saldos[$pid] ?? 0;
    $merc   = $abo_idx[$pid]['mercancia'] ?? 0;
    $pm     = $abo_idx[$pid]['pm_trans']  ?? 0;
    $efec   = $abo_idx[$pid]['efectivo']  ?? 0;
    $vale   = $abo_idx[$pid]['vale']      ?? 0;
    $cort   = $abo_idx[$pid]['cortesia']  ?? 0;
    $tot_abo= $merc + $pm + $efec + $vale + $cort;

    $lit_dias = []; $tot_lit = 0;
    foreach ($dias as $i => $f) {
        $l = $ent_idx[$pid][$f]['litros'] ?? 0;
        $lit_dias[] = $l;
        $tot_lit   += $l;
        $tot_dia_l[$i] += $l;
    }
    $tot_sem_pagar = round($tot_lit * $precio, 2);
    $pendiente     = round($tot_sem_pagar + $saldo - $tot_abo, 2);

    $tot_litros_g += $tot_lit;
    $tot_pagar_g  += $tot_sem_pagar;
    $tot_saldo_g  += $saldo;
    $tot_merc_g   += $merc;
    $tot_pm_g     += $pm;
    $tot_efec_g   += $efec;
    $tot_abo_g    += $tot_abo;
    $tot_pend_g   += $pendiente;
?>
<tr data-pid="<?= $pid ?>" data-precio="<?= $precio ?>" data-saldo="<?= $saldo ?>">
  <td class="text-center text-muted" style="font-size:.7rem"><?= $num ?></td>
  <td class="nombre-col" title="<?= h($p['nombre']) ?>"><?= h($p['nombre']) ?></td>
  <td class="text-muted" style="font-size:.68rem"><?= h($p['documento'] ?? '') ?></td>
  <td class="col-dia" style="text-align:right">
    <input type="number" name="precio[<?= $pid ?>]" value="<?= number_format($precio,4,'.','') ?>"
           class="cs-inp cs-inp-precio inp-precio" min="0" step="0.0001">
  </td>
  <?php foreach ($dias as $i => $f): $l = $lit_dias[$i]; ?>
  <td class="col-dia" style="text-align:right">
    <input type="number" name="lit[<?= $pid ?>][<?= $f ?>]" value="<?= $l > 0 ? number_format($l,1,'.','') : '' ?>"
           class="cs-inp inp-lit" min="0" step="0.1" placeholder="0">
  </td>
  <?php endforeach ?>
  <td class="col-lit-tot num cell-tot-lit"><?= number_format($tot_lit,1) ?></td>
  <td class="col-pagar num cell-tot-pagar">$<?= number_format($tot_sem_pagar,2) ?></td>
  <td class="col-saldo num cell-saldo"><?= $saldo != 0 ? '$'.number_format($saldo,2) : '—' ?></td>
  <td class="col-merc" style="text-align:right">
    <input type="number" name="merc[<?= $pid ?>]" value="<?= $merc > 0 ? number_format($merc,2,'.','') : '' ?>"
           class="cs-inp cs-inp-abo inp-merc" min="0" step="0.01" placeholder="0">
  </td>
  <td class="col-pm" style="text-align:right">
    <input type="number" name="pm[<?= $pid ?>]" value="<?= $pm > 0 ? number_format($pm,2,'.','') : '' ?>"
           class="cs-inp cs-inp-abo inp-pm" min="0" step="0.01" placeholder="0">
  </td>
  <td class="col-efec" style="text-align:right">
    <input type="number" name="efec[<?= $pid ?>]" value="<?= $efec > 0 ? number_format($efec,2,'.','') : '' ?>"
           class="cs-inp cs-inp-abo inp-efec" min="0" step="0.01" placeholder="0">
  </td>
  <td class="col-tabo num cell-tot-abo">$<?= number_format($tot_abo,2) ?></td>
  <td class="col-pend num cell-pendiente">$<?= number_format($pendiente,2) ?></td>
</tr>
<?php endforeach ?>
</tbody>
<tfoot>
  <tr>
    <td colspan="4" class="lbl">TOTALES</td>
    <?php foreach ($tot_dia_l as $i => $tl): ?>
    <td id="ft-dia-<?= $i ?>"><?= number_format($tl,1) ?></td>
    <?php endforeach ?>
    <td id="ft-lit"><?= number_format($tot_litros_g,1) ?></td>
    <td id="ft-pagar">$<?= number_format($tot_pagar_g,2) ?></td>
    <td id="ft-saldo">$<?= number_format($tot_saldo_g,2) ?></td>
    <td id="ft-merc">$<?= number_format($tot_merc_g,2) ?></td>
    <td id="ft-pm">$<?= number_format($tot_pm_g,2) ?></td>
    <td id="ft-efec">$<?= number_format($tot_efec_g,2) ?></td>
    <td id="ft-tabo">$<?= number_format($tot_abo_g,2) ?></td>
    <td id="ft-pend">$<?= number_format($tot_pend_g,2) ?></td>
  </tr>
</tfoot>
</table>
</div>

<div class="d-flex justify-content-end mb-4">
  <button type="submit" class="btn btn-success px-5">💾 Guardar Semana</button>
</div>

<!-- ══ TABLA RESUMEN DIARIO ═════════════════════════════════════════════════ -->
<h6 class="fw-bold mb-2" style="color:#1a2e5a">📊 Resumen Diario de Leche</h6>
<div class="cs-wrap mb-4">
<table class="cs-sum">
<thead>
  <tr>
    <th style="text-align:left;min-width:170px">CONCEPTO</th>
    <?php foreach ($dias_labels as $i => $dl): ?>
    <th><?= $dl ?> <?= date('d/m', strtotime($dias[$i])) ?></th>
    <?php endforeach ?>
    <th style="background:#f97316">TOTAL SEMANAL</th>
  </tr>
</thead>
<tbody>
<?php
// Filas superiores editables: ventas/cortesías/vales en LITROS
$rows_editables = [
    ['VENTAS LECHE (productores)', 'ventas_prod'],
    ['CORTESÍAS LECHE',            'cortesias_n'],
    ['VALES LECHE',                'vales_n'],
];
foreach ($rows_editables as [$lbl, $conc]):
    $tot = 0;
    echo '<tr><td>'.$lbl.'</td>';
    foreach ($dias as $f) {
        $stored = $costos_idx[$conc][$f] ?? 0;
        $tot   += $stored;
        echo '<td class="num" style="padding:2px 4px">'
            .'<input type="number" name="costo['.$conc.']['.$f.']"'
            .' value="'.($stored > 0 ? number_format($stored,1,'.','') : '').'"'
            .' class="cs-inp inp-resumen inp-res-'.$conc.'"'
            .' min="0" step="0.1" placeholder="0.0" style="width:68px;text-align:right">'
            .'</td>';
    }
    echo '<td class="num cell-res-tot-'.$conc.'">'.($tot > 0 ? number_format($tot,1) : '—').'</td></tr>';
endforeach;

// Fila LECHE PROCESADA — editable (el usuario ingresa cuánto procesó realmente)
echo '<tr class="row-proc"><td><strong>LECHE PROCESADA (litros)</strong></td>';
$tot_proc = 0;
foreach ($dias as $f) {
    $stored = $costos_idx['procesada_n'][$f] ?? 0;
    $tot_proc += $stored;
    echo '<td class="num" style="padding:2px 4px">'
        .'<input type="number" name="costo[procesada_n]['.$f.']"'
        .' value="'.($stored > 0 ? number_format($stored,1,'.','') : '').'"'
        .' class="cs-inp inp-resumen inp-res-procesada_n"'
        .' min="0" step="0.1" placeholder="0.0" style="width:68px;text-align:right">'
        .'</td>';
}
echo '<td class="num cell-res-tot-procesada_n">'.($tot_proc > 0 ? number_format($tot_proc,1) : '—').'</td></tr>';

// Fila TOTAL LECHE RESTANTE = total recibido - ventas - cortesías - vales - procesada (calculada)
echo '<tr class="row-restante" style="background:#fff8e1"><td><strong style="color:#92400e">TOTAL LECHE RESTANTE</strong></td>';
$tot_rest = 0;
foreach ($dias as $f) {
    $total_dia = $dia_totales[$f]['litros'];
    $ventas    = $costos_idx['ventas_prod'][$f]  ?? 0;
    $cortesias = $costos_idx['cortesias_n'][$f]  ?? 0;
    $vales     = $costos_idx['vales_n'][$f]      ?? 0;
    $proc      = $costos_idx['procesada_n'][$f]  ?? 0;
    $rest      = max(0, $total_dia - $ventas - $cortesias - $vales - $proc);
    $tot_rest += $rest;
    $fid = str_replace('-','',$f);
    echo '<td class="num cell-restante-'.$fid.'" style="font-weight:700;color:#92400e">'
        .($rest > 0 ? number_format($rest,1) : ($total_dia > 0 ? '0.0' : '—')).'</td>';
}
echo '<td class="num" id="cell-restante-total" style="font-weight:700;color:#92400e">'.($tot_rest > 0 ? number_format($tot_rest,1) : '0.0').'</td></tr>';
?>
</tbody>
</table>
</div>
</form>

<!-- ══ JAVASCRIPT: recálculo en tiempo real ════════════════════════════════ -->
<script>
(function() {
  function fmt(n, dec=2) { return '$' + n.toFixed(dec); }

  function recalcRow(row) {
    const precio = parseFloat(row.dataset.precio) || 0;
    const saldo  = parseFloat(row.dataset.saldo)  || 0;
    // Actualizar precio si cambió
    const precioInp = row.querySelector('.inp-precio');
    const precioVal = precioInp ? (parseFloat(precioInp.value) || precio) : precio;
    row.dataset.precio = precioVal;

    let totLit = 0;
    row.querySelectorAll('.inp-lit').forEach(inp => { totLit += parseFloat(inp.value) || 0; });

    const merc = parseFloat(row.querySelector('.inp-merc')?.value) || 0;
    const pm   = parseFloat(row.querySelector('.inp-pm')?.value)   || 0;
    const efec = parseFloat(row.querySelector('.inp-efec')?.value) || 0;
    const totAbo   = merc + pm + efec;
    const totPagar = totLit * precioVal;
    const pendiente= totPagar + saldo - totAbo;

    row.querySelector('.cell-tot-lit').textContent  = totLit.toFixed(1);
    row.querySelector('.cell-tot-pagar').textContent = fmt(totPagar);
    row.querySelector('.cell-tot-abo').textContent   = fmt(totAbo);

    const cellPend = row.querySelector('.cell-pendiente');
    cellPend.textContent = fmt(pendiente);
    cellPend.style.color = pendiente < 0.005 ? '#4ade80' : '';
  }

  function recalcFooter() {
    let totLit=0, totPagar=0, totSaldo=0, totMerc=0, totPm=0, totEfec=0, totAbo=0, totPend=0;
    const dayTotals = new Array(7).fill(0);

    document.querySelectorAll('#tblMain tbody tr[data-pid]').forEach(row => {
      const precioVal = parseFloat(row.dataset.precio) || 0;
      const saldo     = parseFloat(row.dataset.saldo)  || 0;
      let rowLit = 0;
      row.querySelectorAll('.inp-lit').forEach((inp, i) => {
        const l = parseFloat(inp.value) || 0;
        rowLit += l; dayTotals[i] += l;
      });
      const merc  = parseFloat(row.querySelector('.inp-merc')?.value) || 0;
      const pm    = parseFloat(row.querySelector('.inp-pm')?.value)   || 0;
      const efec  = parseFloat(row.querySelector('.inp-efec')?.value) || 0;
      const rowAbo  = merc + pm + efec;
      const rowPagar= rowLit * precioVal;
      totLit   += rowLit;
      totPagar += rowPagar;
      totSaldo += saldo;
      totMerc  += merc;
      totPm    += pm;
      totEfec  += efec;
      totAbo   += rowAbo;
      totPend  += rowPagar + saldo - rowAbo;
    });

    dayTotals.forEach((v,i) => {
      const el = document.getElementById('ft-dia-'+i);
      if (el) el.textContent = v.toFixed(1);
    });
    const set = (id, val, isDollar=true) => {
      const el = document.getElementById(id);
      if (el) el.textContent = isDollar ? fmt(val) : val.toFixed(1);
    };
    set('ft-lit',   totLit,   false);
    set('ft-pagar', totPagar);
    set('ft-saldo', totSaldo);
    set('ft-merc',  totMerc);
    set('ft-pm',    totPm);
    set('ft-efec',  totEfec);
    set('ft-tabo',  totAbo);
    set('ft-pend',  totPend);
  }

  // Delegar eventos en la tabla
  document.getElementById('tblMain').addEventListener('input', function(e) {
    const row = e.target.closest('tr[data-pid]');
    if (!row) return;
    recalcRow(row);
    recalcFooter();
  });

  // Tab entre inputs
  document.getElementById('tblMain').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      const inputs = Array.from(document.querySelectorAll('#tblMain .cs-inp'));
      const idx = inputs.indexOf(e.target);
      if (idx >= 0 && idx < inputs.length - 1) inputs[idx + 1].focus();
    }
  });
})();

// ── Recálculo del resumen diario (ventas/cortesías/vales/procesada/restante) ──
(function() {
  // IDs de fechas en orden de columnas
  const fechas = <?= json_encode($dias) ?>;

  // Total de litros entregados por día (desde BD, valor fijo)
  const litrosDia = <?= json_encode(array_values(array_map(fn($f) => $dia_totales[$f]['litros'], $dias))) ?>;

  // Recálculo: actualiza totales de filas editables + LECHE RESTANTE (calculada)
  function recalcResumen() {
    const editConc = ['ventas_prod','cortesias_n','vales_n','procesada_n'];

    // Actualizar totales semanales de cada fila editable
    editConc.forEach(function(c) {
      let tot = 0;
      fechas.forEach(function(f) {
        const inp = document.querySelector('input[name="costo['+c+']['+f+']"]');
        tot += inp ? (parseFloat(inp.value) || 0) : 0;
      });
      const cell = document.querySelector('.cell-res-tot-' + c);
      if (cell) cell.textContent = tot > 0 ? tot.toFixed(1) : '—';
    });

    // Recalcular TOTAL LECHE RESTANTE por día y total semanal
    let totRest = 0;
    fechas.forEach(function(f, i) {
      const litros = litrosDia[i] || 0;
      let usado = 0;
      editConc.forEach(function(c) {
        const inp = document.querySelector('input[name="costo['+c+']['+f+']"]');
        usado += inp ? (parseFloat(inp.value) || 0) : 0;
      });
      const rest = Math.max(0, litros - usado);
      totRest += rest;
      const fid = f.replace(/-/g,'');
      const cell = document.querySelector('.cell-restante-' + fid);
      if (cell) cell.textContent = rest > 0 ? rest.toFixed(1) : (litros > 0 ? '0.0' : '—');
    });
    const totCell = document.getElementById('cell-restante-total');
    if (totCell) totCell.textContent = totRest.toFixed(1);
  }

  document.querySelectorAll('.inp-resumen').forEach(function(inp) {
    inp.addEventListener('input', recalcResumen);
  });
})();

// ── Navegación con Enter: bajar al mismo campo de la siguiente fila ──────────
(function() {
  function siguienteInput(inp, dir) {
    const td = inp.closest('td');
    const tr = inp.closest('tr');
    if (!td || !tr) return null;
    const tds = Array.from(tr.children);
    const colIdx = tds.indexOf(td);
    if (colIdx < 0) return null;
    let row = tr;
    while (true) {
      row = dir === 'down' ? row.nextElementSibling : row.previousElementSibling;
      if (!row) return null;
      if (row.style.display === 'none') continue;
      if (!row.hasAttribute('data-pid')) continue;
      const cell = row.children[colIdx];
      if (!cell) continue;
      const next = cell.querySelector('input[type="number"]');
      if (next) return next;
    }
  }
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    const t = e.target;
    if (!t || t.tagName !== 'INPUT') return;
    if (t.type !== 'number') return;
    if (!t.closest('#tblMain')) return;
    const dir = e.key === 'ArrowUp' ? 'up' : 'down';
    const next = siguienteInput(t, dir);
    if (next) {
      e.preventDefault();
      next.focus();
      try { next.select(); } catch (_) {}
    } else if (e.key === 'Enter') {
      e.preventDefault(); // evitar submit accidental
    }
  });
})();

// ── Búsqueda de productor ─────────────────────────────────────────────────────
function filtrarProductores(q) {
  q = q.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  const rows = document.querySelectorAll('#tblMain tbody tr[data-pid]');
  let visible = 0;
  rows.forEach(function(row) {
    const nombre = (row.querySelector('.nombre-col')?.textContent || '')
      .toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    const show = !q || nombre.includes(q);
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  const info = document.getElementById('buscar-resultado');
  if (info) info.textContent = q ? visible + ' resultado' + (visible !== 1 ? 's' : '') : '';
}

// ── Exportar Excel ────────────────────────────────────────────────────────────
function exportarExcel() {
  if (typeof XLSX === 'undefined') { alert('Cargando librería, intenta de nuevo...'); return; }
  const wb = XLSX.utils.book_new();

  // Hoja 1: Tabla de productores
  const tblMain = document.getElementById('tblMain');
  const ws1 = tableToSheetClean(tblMain);
  XLSX.utils.book_append_sheet(wb, ws1, 'Productores');

  // Hoja 2: Resumen Diario
  const tblResumen = document.querySelector('.cs-sum');
  const ws2 = tableToSheetClean(tblResumen);
  XLSX.utils.book_append_sheet(wb, ws2, 'Resumen Diario');

  const semana = document.querySelector('.d-flex strong')?.nextElementSibling?.textContent?.trim() || 'semana';
  XLSX.writeFile(wb, 'Control_Semanal_' + '<?= $ws ?>' + '.xlsx');
}

function tableToSheetClean(table) {
  // Extraer datos mostrando valores de inputs cuando existan
  const rows = [];
  table.querySelectorAll('tr').forEach(function(tr) {
    if (tr.style.display === 'none') return;
    const rowData = [];
    tr.querySelectorAll('th, td').forEach(function(cell) {
      const inp = cell.querySelector('input[type="number"]');
      if (inp) {
        rowData.push(parseFloat(inp.value) || 0);
      } else {
        rowData.push(cell.textContent.trim().replace(/\s+/g,' '));
      }
    });
    rows.push(rowData);
  });
  return XLSX.utils.aoa_to_sheet(rows);
}

// ── Exportar PDF ──────────────────────────────────────────────────────────────
function exportarPDF() {
  if (typeof window.jspdf === 'undefined') { alert('Cargando librería, intenta de nuevo...'); return; }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a3' });

  const semana = '<?= $ws_dt->format('d/m/Y') ?> — <?= $we_dt->format('d/m/Y') ?>';
  doc.setFontSize(13);
  doc.setTextColor(26, 46, 90);
  doc.text('Control Semanal de Productores', 14, 14);
  doc.setFontSize(9);
  doc.setTextColor(100);
  doc.text('Semana: ' + semana, 14, 20);

  // Tabla de productores
  const headMain = [], bodyMain = [];
  const tblMain = document.getElementById('tblMain');
  tblMain.querySelectorAll('thead tr').forEach(function(tr) {
    const row = [];
    tr.querySelectorAll('th').forEach(function(th) { row.push(th.textContent.trim()); });
    headMain.push(row);
  });
  tblMain.querySelectorAll('tbody tr[data-pid]').forEach(function(tr) {
    if (tr.style.display === 'none') return;
    const row = [];
    tr.querySelectorAll('td').forEach(function(td) {
      const inp = td.querySelector('input[type="number"]');
      row.push(inp ? (inp.value || '0') : td.textContent.trim());
    });
    bodyMain.push(row);
  });

  doc.autoTable({
    head: headMain, body: bodyMain,
    startY: 25, styles: { fontSize: 6, cellPadding: 1.5 },
    headStyles: { fillColor: [26,46,90], textColor: 255, fontSize: 6 },
    alternateRowStyles: { fillColor: [240,246,255] },
    margin: { left: 14, right: 14 },
  });

  // Resumen Diario
  const y2 = doc.lastAutoTable.finalY + 8;
  doc.setFontSize(10); doc.setTextColor(26,46,90);
  doc.text('Resumen Diario de Leche', 14, y2);

  const headRes = [], bodyRes = [];
  const tblRes = document.querySelector('.cs-sum');
  tblRes.querySelectorAll('thead tr').forEach(function(tr) {
    const row = [];
    tr.querySelectorAll('th').forEach(function(th) { row.push(th.textContent.trim()); });
    headRes.push(row);
  });
  tblRes.querySelectorAll('tbody tr, tfoot tr').forEach(function(tr) {
    const row = [];
    tr.querySelectorAll('th, td').forEach(function(td) {
      const inp = td.querySelector('input[type="number"]');
      row.push(inp ? (inp.value || '0') : td.textContent.trim());
    });
    bodyRes.push(row);
  });

  doc.autoTable({
    head: headRes, body: bodyRes,
    startY: y2 + 4, styles: { fontSize: 7, cellPadding: 1.5 },
    headStyles: { fillColor: [26,46,90], textColor: 255 },
    margin: { left: 14, right: 14 },
  });

  doc.save('Control_Semanal_<?= $ws ?>.pdf');
}
</script>

<!-- SheetJS (Excel) + jsPDF (PDF) vía CDN -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>

<?php layout_end(); ?>
