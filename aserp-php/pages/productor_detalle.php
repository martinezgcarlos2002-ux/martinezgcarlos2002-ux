<?php
require_login();
$db = get_db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ?p=productores'); exit; }

$prod = $db->prepare('SELECT * FROM productores WHERE id=?');
$prod->execute([$id]);
$prod = $prod->fetch();
if (!$prod) { flash('Productor no encontrado.', 'error'); header('Location: ?p=productores'); exit; }

// Cierra automáticamente cualquier semana ya terminada con entregas pendientes
auto_close_past_weeks($id);
recompute_cierres($db, $id);

// ── Determinar semana activa ──────────────────────────────────────────────────
// El input type="week" devuelve "YYYY-Www". Si viene un día normal (type=date),
// también lo manejamos calculando el lunes de esa semana.
$semana_raw = $_GET['semana'] ?? '';
if ($semana_raw && preg_match('/^\d{4}-W(\d{2})$/', $semana_raw, $m)) {
    // Formato YYYY-Www
    $week_num  = (int)$m[1];
    $year      = (int)substr($semana_raw, 0, 4);
    $week_start = new DateTime();
    $week_start->setISODate($year, $week_num, 1); // lunes
} else {
    // Semana actual: lunes
    $week_start = new DateTime();
    $dow = (int)$week_start->format('N'); // 1=lun … 7=dom
    $week_start->modify('-' . ($dow - 1) . ' days');
}
$week_end = clone $week_start;
$week_end->modify('+6 days');

$ws  = $week_start->format('Y-m-d');
$we  = $week_end->format('Y-m-d');
$iso = $week_start->format('o-\WW'); // para el input[type=week]

// Navegación prev/next semana
$prev_week = clone $week_start; $prev_week->modify('-7 days');
$next_week = clone $week_start; $next_week->modify('+7 days');
$prev_iso  = $prev_week->format('o-\WW');
$next_iso  = $next_week->format('o-\WW');

// ── POST: guardar entrega de un día ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save_entrega') {
        $fecha  = $_POST['fecha']  ?? '';
        $litros = (float)($_POST['litros'] ?? 0);
        $precio = (float)($_POST['precio'] ?? $prod['precio_litro_usd']);
        $sub    = round($litros * $precio, 4);
        // Upsert: si ya existe entrega para ese día+productor → actualizar
        $exist = $db->prepare('SELECT id FROM productores_entregas WHERE productor_id=? AND fecha=?');
        $exist->execute([$id, $fecha]);
        $eid = $exist->fetchColumn();
        if ($eid) {
            $db->prepare('UPDATE productores_entregas SET litros=?,precio_litro_usd=?,subtotal_usd=? WHERE id=?')
               ->execute([$litros, $precio, $sub, $eid]);
        } else {
            $db->prepare('INSERT INTO productores_entregas(productor_id,fecha,litros,precio_litro_usd,subtotal_usd) VALUES(?,?,?,?,?)')
               ->execute([$id, $fecha, $litros, $precio, $sub]);
        }
        // Si la fecha cae en una semana ya cerrada, refresca su snapshot.
        sync_cierre_from_entregas($db, $id, $fecha);
        flash('Entrega guardada.');
        header("Location: ?p=productor_detalle&id=$id&semana=$iso&tab=control"); exit;
    }

    if ($act === 'del_entrega') {
        $eid = (int)($_POST['eid'] ?? 0);
        $fst = $db->prepare('SELECT fecha FROM productores_entregas WHERE id=? AND productor_id=?');
        $fst->execute([$eid, $id]);
        $del_fecha = $fst->fetchColumn();
        $db->prepare('DELETE FROM productores_entregas WHERE id=? AND productor_id=?')->execute([$eid, $id]);
        if ($del_fecha) sync_cierre_from_entregas($db, $id, $del_fecha);
        flash('Entrega eliminada.', 'warning');
        header("Location: ?p=productor_detalle&id=$id&semana=$iso&tab=control"); exit;
    }

    if ($act === 'save_abono') {
        ensure_abono_semana_column();
        $tipo  = $_POST['tipo']  ?? 'efectivo';
        $monto = (float)($_POST['monto'] ?? 0);
        $desc  = trim($_POST['descripcion'] ?? '');
        $fecha = $_POST['fecha'] ?? $ws;
        // Semana a la que se aplica el pago (snap a sábado). Si no llega, usar la fecha del pago.
        $sem_raw = $_POST['semana_aplicada'] ?? $fecha;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sem_raw)) $sem_raw = $fecha;
        $sem_apl = snap_sat($sem_raw)->format('Y-m-d');
        $db->prepare('INSERT INTO productores_abonos(productor_id,fecha,semana_aplicada,tipo,monto_usd,descripcion) VALUES(?,?,?,?,?,?)')
           ->execute([$id, $fecha, $sem_apl, $tipo, $monto, $desc]);
        flash('Pago registrado.');
        header("Location: ?p=productor_detalle&id=$id&semana=$iso&tab=control"); exit;
    }

    if ($act === 'del_abono') {
        $aid = (int)($_POST['aid'] ?? 0);
        $db->prepare('DELETE FROM productores_abonos WHERE id=? AND productor_id=?')->execute([$aid, $id]);
        flash('Pago eliminado.', 'warning');
        $back_tab = $_POST['tab'] ?? 'control';
        header("Location: ?p=productor_detalle&id=$id&semana=$iso&tab=$back_tab"); exit;
    }

    // ── Cierres: pagar deuda de una semana específica ───────────────────────
    if ($act === 'pagar_cierre') {
        ensure_cierres_table();
        $cid   = (int)($_POST['cid'] ?? 0);
        $monto = (float)($_POST['monto'] ?? 0);
        $tipo  = $_POST['tipo'] ?? 'efectivo';
        $nota  = trim($_POST['nota'] ?? '');
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');
        if (!in_array($tipo, ['efectivo','mercancia','pm_trans'], true)) $tipo = 'efectivo';

        $st = $db->prepare("SELECT * FROM productores_cierres WHERE id=? AND productor_id=?");
        $st->execute([$cid, $id]);
        $c = $st->fetch();
        if ($c && $monto > 0) {
            $pend = (float)$c['total_factura'] + (float)$c['arrastre_anterior'] - (float)$c['abonos_semana'] - (float)$c['monto_pagado'];
            if ($monto > $pend + 0.01) {
                flash("El monto supera el pendiente ($".number_format($pend,2).").", 'error');
            } else {
                ensure_abono_semana_column();
                $desc = trim("Pago cierre semana " . date('d/m/Y', strtotime($c['semana_inicio'])) . " — " . $nota, ' —');
                $db->prepare("INSERT INTO productores_abonos(productor_id,fecha,semana_aplicada,tipo,monto_usd,descripcion) VALUES(?,?,?,?,?,?)")
                   ->execute([$id, $fecha, $c['semana_inicio'], $tipo, $monto, $desc]);
                $np = (float)$c['monto_pagado'] + $monto;
                $pen2 = (float)$c['total_factura'] + (float)$c['arrastre_anterior'] - (float)$c['abonos_semana'] - $np;
                $est  = $pen2 <= 0.01 ? 'pagada' : 'parcial';
                $pts  = $est === 'pagada' ? date('Y-m-d H:i:s') : null;
                if ($pts) $db->prepare("UPDATE productores_cierres SET monto_pagado=?, estado=?, pagado_en=? WHERE id=?")
                              ->execute([$np, $est, $pts, $cid]);
                else      $db->prepare("UPDATE productores_cierres SET monto_pagado=?, estado=? WHERE id=?")
                              ->execute([$np, $est, $cid]);
                audit_log('productores', 'pago_cierre', ['cierre_id'=>$cid, 'monto'=>$monto, 'tipo'=>$tipo]);
                flash("Pago registrado: $".number_format($monto,2), 'success');
            }
        }
        header("Location: ?p=productor_detalle&id=$id&tab=cierres"); exit;
    }

    // ── Cierres: editar arrastre/notas de una semana cerrada ────────────────
    if ($act === 'editar_arrastre') {
        ensure_cierres_table();
        $cid      = (int)($_POST['cid'] ?? 0);
        $arrastre = (float)($_POST['arrastre'] ?? 0);
        $notas    = trim($_POST['notas'] ?? '');
        $st = $db->prepare("SELECT * FROM productores_cierres WHERE id=? AND productor_id=?");
        $st->execute([$cid, $id]);
        $c = $st->fetch();
        if ($c) {
            $pend = (float)$c['total_factura'] + $arrastre - (float)$c['abonos_semana'] - (float)$c['monto_pagado'];
            $est  = $pend <= 0.01 ? 'pagada' : ((float)$c['monto_pagado']>0 ? 'parcial' : 'cerrada');
            $db->prepare("UPDATE productores_cierres SET arrastre_anterior=?, notas=?, estado=? WHERE id=?")
               ->execute([$arrastre, $notas ?: null, $est, $cid]);
            audit_log('productores', 'editar_arrastre', ['cierre_id'=>$cid, 'arrastre'=>$arrastre]);
            flash('Arrastre actualizado.', 'success');
        }
        header("Location: ?p=productor_detalle&id=$id&tab=cierres"); exit;
    }

    // ── Cierres: editar datos de un cierre ya cerrado ───────────────────────
    // Permite corregir litros, precio, factura y notas de cualquier semana ya
    // cerrada (incluso de semanas anteriores a la actual). El arrastre NO se
    // edita aquí: es un valor derivado que recompute_cierres() recalcula solo a
    // partir del saldo de la semana anterior (editarlo a mano no persistiría).
    if ($act === 'editar_cierre') {
        ensure_cierres_table();
        $cid    = (int)($_POST['cid'] ?? 0);
        $litros = max(0, (float)($_POST['litros'] ?? 0));
        $precio = max(0, (float)($_POST['precio'] ?? 0));
        $total  = (float)($_POST['total_factura'] ?? 0);
        $notas  = trim($_POST['notas'] ?? '');
        if (mb_strlen($notas) > 500) $notas = mb_substr($notas, 0, 500);
        // Si dan litros y precio pero no factura, la calculamos.
        if ($total <= 0 && $litros > 0 && $precio > 0) $total = round($litros * $precio, 2);
        $total = max(0, $total);
        $st = $db->prepare("SELECT * FROM productores_cierres WHERE id=? AND productor_id=?");
        $st->execute([$cid, $id]);
        $c = $st->fetch();
        if ($c) {
            $ws = $c['semana_inicio'];
            $we = $c['semana_fin'];
            $precioOld = (float)$c['precio_litro'];
            // ¿Hay entregas de leche registradas en la semana de este cierre?
            $ent = $db->prepare("SELECT id, litros FROM productores_entregas
                                 WHERE productor_id=? AND fecha BETWEEN ? AND ?");
            $ent->execute([$id, $ws, $we]);
            $entregas = $ent->fetchAll();

            $precioCambio = ($precio > 0 && abs($precio - $precioOld) > 0.00001);

            if ($precioCambio && $entregas) {
                // El cierre y las entregas están conectados: al cambiar el precio en
                // el cierre, se aplica el nuevo precio $/L a TODAS las entregas de esa
                // semana (solo esa, según la fecha). El subtotal de cada día se recalcula.
                // Todo en una transacción: o se actualiza la semana completa, o nada.
                $db->beginTransaction();
                try {
                    $upd = $db->prepare("UPDATE productores_entregas
                                         SET precio_litro_usd=?, subtotal_usd=? WHERE id=?");
                    foreach ($entregas as $e) {
                        $sub = round((float)$e['litros'] * $precio, 4);
                        $upd->execute([$precio, $sub, (int)$e['id']]);
                    }
                    // Refresca el snapshot del cierre desde las entregas ya actualizadas
                    // (litros, precio y total quedan coherentes) y recalcula arrastre/estado.
                    sync_cierre_from_entregas($db, $id, $ws);
                    // sync no toca las notas: persistirlas aparte.
                    $db->prepare("UPDATE productores_cierres SET notas=? WHERE id=?")
                       ->execute([$notas ?: null, $cid]);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
            } else {
                // Edición manual del snapshot (semana sin entregas por día, o sin
                // cambio de precio): se respeta lo que escribió el usuario.
                $db->prepare("UPDATE productores_cierres
                              SET litros=?, precio_litro=?, total_factura=?, notas=?
                              WHERE id=?")
                   ->execute([$litros, $precio, $total, $notas ?: null, $cid]);
                // recompute_cierres recalcula arrastre/abonos/pagado/estado coherentemente.
                recompute_cierres($db, $id);
            }
            audit_log('productores', 'editar_cierre',
                      ['cierre_id'=>$cid, 'litros'=>$litros, 'precio'=>$precio, 'total'=>$total,
                       'precio_aplicado_a_entregas'=>($precioCambio && $entregas) ? count($entregas) : 0]);
            flash(($precioCambio && $entregas)
                  ? 'Cierre actualizado. El nuevo precio se aplicó a las entregas de esa semana.'
                  : 'Cierre actualizado.', 'success');
        } else {
            flash('Cierre no encontrado.', 'error');
        }
        header("Location: ?p=productor_detalle&id=$id&tab=cierres"); exit;
    }

    // ── Cierres: cerrar manualmente la semana en curso ──────────────────────
    if ($act === 'cerrar_semana_actual') {
        ensure_cierres_table();
        $ws_in = $_POST['ws'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ws_in)) {
            flash('Fecha inválida.', 'error');
            header("Location: ?p=productor_detalle&id=$id&tab=cierres"); exit;
        }
        $ws_dt = snap_sat($ws_in);
        $sw = $ws_dt->format('Y-m-d');
        $sf = (clone $ws_dt)->modify('+6 days')->format('Y-m-d');

        $st = $db->prepare("SELECT COALESCE(SUM(litros),0) lit, COALESCE(SUM(subtotal_usd),0) tot
                            FROM productores_entregas WHERE productor_id=? AND fecha BETWEEN ? AND ?");
        $st->execute([$id, $sw, $sf]);
        $r = $st->fetch();
        $lit = (float)$r['lit']; $tot = (float)$r['tot'];

        $st = $db->prepare("SELECT COALESCE(SUM(monto_usd),0) FROM productores_abonos
                            WHERE productor_id=? AND COALESCE(semana_aplicada,fecha) BETWEEN ? AND ?
                              AND (descripcion IS NULL OR descripcion NOT LIKE 'Pago cierre%')");
        $st->execute([$id, $sw, $sf]);
        $abo = (float)$st->fetchColumn();

        $st = $db->prepare("SELECT COALESCE(SUM(total_factura + arrastre_anterior - abonos_semana - monto_pagado),0)
                            FROM productores_cierres
                            WHERE productor_id=? AND semana_inicio<? AND estado<>'pagada'");
        $st->execute([$id, $sw]);
        $arr = max(0, (float)$st->fetchColumn());

        $precio = (float)$prod['precio_litro_usd'];

        $st = $db->prepare("SELECT id, monto_pagado FROM productores_cierres WHERE productor_id=? AND semana_inicio=?");
        $st->execute([$id, $sw]);
        $ex = $st->fetch();
        if ($ex) {
            $pend = $tot + $arr - $abo - (float)$ex['monto_pagado'];
            $est = $pend <= 0.01 ? 'pagada' : ((float)$ex['monto_pagado']>0 ? 'parcial' : 'cerrada');
            $db->prepare("UPDATE productores_cierres SET litros=?, precio_litro=?, total_factura=?,
                          abonos_semana=?, arrastre_anterior=?, estado=?, cerrado_en=NOW() WHERE id=?")
               ->execute([$lit, $precio, $tot, $abo, $arr, $est, $ex['id']]);
            flash('Cierre recalculado.', 'success');
        } else {
            $est = ($tot + $arr - $abo) <= 0.01 ? 'pagada' : 'cerrada';
            $db->prepare("INSERT INTO productores_cierres
                          (productor_id, semana_inicio, semana_fin, litros, precio_litro, total_factura,
                           abonos_semana, arrastre_anterior, estado, cerrado_por)
                          VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$id, $sw, $sf, $lit, $precio, $tot, $abo, $arr, $est, $_SESSION['user_id'] ?? null]);
            flash('Semana cerrada.', 'success');
        }
        audit_log('productores', 'cerrar_semana_manual', ['productor_id'=>$id, 'semana_inicio'=>$sw]);
        header("Location: ?p=productor_detalle&id=$id&tab=cierres"); exit;
    }

    if ($act === 'upd_abono') {
        ensure_abono_semana_column();
        $aid   = (int)($_POST['aid'] ?? 0);
        $tipo  = $_POST['tipo']  ?? 'efectivo';
        $monto = (float)($_POST['monto'] ?? 0);
        $desc  = trim($_POST['descripcion'] ?? '');
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $sem_raw = $_POST['semana_aplicada'] ?? $fecha;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sem_raw)) $sem_raw = $fecha;
        $sem_apl = snap_sat($sem_raw)->format('Y-m-d');
        if ($aid) {
            $db->prepare('UPDATE productores_abonos SET tipo=?,monto_usd=?,descripcion=?,fecha=?,semana_aplicada=? WHERE id=? AND productor_id=?')
               ->execute([$tipo, $monto, $desc, $fecha, $sem_apl, $aid, $id]);
            flash('Pago actualizado.');
        }
        $back_tab = $_POST['tab'] ?? 'control';
        header("Location: ?p=productor_detalle&id=$id&semana=$iso&tab=$back_tab"); exit;
    }

    $back_tab = $_POST['tab'] ?? 'control';
    header("Location: ?p=productor_detalle&id=$id&semana=$iso&tab=$back_tab"); exit;
}

// ── Datos de la semana ────────────────────────────────────────────────────────
$ent_week = $db->prepare('SELECT * FROM productores_entregas WHERE productor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha');
$ent_week->execute([$id, $ws, $we]);
$ent_week = $ent_week->fetchAll();
// Indexar por fecha para lookup rápido
$ent_by_date = [];
foreach ($ent_week as $e) { $ent_by_date[$e['fecha']] = $e; }

ensure_abono_semana_column();
$abo_week = $db->prepare("SELECT * FROM productores_abonos WHERE productor_id=? AND COALESCE(semana_aplicada,fecha)>=? AND COALESCE(semana_aplicada,fecha)<=? ORDER BY fecha,tipo");
$abo_week->execute([$id, $ws, $we]);
$abo_week = $abo_week->fetchAll();

// Lista de semanas para el selector (semana actual + 12 anteriores).
// Etiqueta: "Semana del 09/05 al 15/05/2026".
$semanas_select = [];
$cur_sat = snap_sat(date('Y-m-d'));
for ($i = 0; $i < 13; $i++) {
    $sat = (clone $cur_sat)->modify("-$i week");
    $fri = (clone $sat)->modify('+6 days');
    $key = $sat->format('Y-m-d');
    $lbl = 'Semana del ' . $sat->format('d/m') . ' al ' . $fri->format('d/m/Y');
    if ($i === 0) $lbl .= ' (actual)';
    $semanas_select[$key] = $lbl;
}

// Totales semana
$total_litros   = array_sum(array_column($ent_week, 'litros'));
$total_semanal  = array_sum(array_column($ent_week, 'subtotal_usd'));
$mercancia      = array_sum(array_map(fn($a) => $a['tipo']==='mercancia' ? (float)$a['monto_usd'] : 0, $abo_week));
$abono_pm_trans = array_sum(array_map(fn($a) => $a['tipo']==='pm_trans'  ? (float)$a['monto_usd'] : 0, $abo_week));
$efectivo       = array_sum(array_map(fn($a) => $a['tipo']==='efectivo'  ? (float)$a['monto_usd'] : 0, $abo_week));
$cortesia       = array_sum(array_map(fn($a) => $a['tipo']==='cortesia'  ? (float)$a['monto_usd'] : 0, $abo_week));
$vales          = array_sum(array_map(fn($a) => $a['tipo']==='vale'      ? (float)$a['monto_usd'] : 0, $abo_week));

// Saldo pendiente = todo lo adeudado antes de esta semana
$saldo_st = $db->prepare('SELECT COALESCE(SUM(subtotal_usd),0) FROM productores_entregas WHERE productor_id=? AND fecha<?');
$saldo_st->execute([$id, $ws]);
$deuda_ant = (float)$saldo_st->fetchColumn();

$abo_ant_st = $db->prepare('SELECT COALESCE(SUM(monto_usd),0) FROM productores_abonos WHERE productor_id=? AND COALESCE(semana_aplicada,fecha)<?');
$abo_ant_st->execute([$id, $ws]);
$abonos_ant = (float)$abo_ant_st->fetchColumn();

$saldo_pendiente = $deuda_ant - $abonos_ant;
$restante = $total_semanal + $saldo_pendiente - $mercancia - $abono_pm_trans - $efectivo - $cortesia - $vales;

// ── Totales globales (para cabecera) ─────────────────────────────────────────
// Misma fuente que la pestaña Cierres (snapshot en semanas cerradas + entregas
// en vivo en la semana en curso) para que cabecera y cierres SIEMPRE coincidan,
// sin importar desde dónde se haya editado.
$glob_uni    = producer_unified_facturado($db, $id);
$glob_litros = $glob_uni['litros'];
$glob_deuda  = $glob_uni['facturado'];

$glob_abo = $db->prepare('SELECT COALESCE(SUM(monto_usd),0) FROM productores_abonos WHERE productor_id=?');
$glob_abo->execute([$id]);
$glob_abonos = (float)$glob_abo->fetchColumn();
$glob_saldo  = (float)$glob_deuda - $glob_abonos;

$tab = $_GET['tab'] ?? 'control';

layout_start('Control Productor: ' . $prod['nombre'], 'productores');
?>
<style>
.week-control-table th { background: #1a2e5a; color:#fff; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
.week-control-table td { vertical-align: middle; font-size:.875rem; }
.week-control-table tr.today-row { background: rgba(255,140,0,.07); }
.week-control-table tr.no-data   { background: transparent; }
.summary-box { background:#f8f9fc; border:1px solid #dee2e6; border-radius:8px; padding:1rem; }
.summary-row { display:flex; justify-content:space-between; align-items:center; padding:.35rem 0; border-bottom:1px solid #e9ecef; font-size:.9rem; }
.summary-row:last-child { border-bottom:none; }
.summary-row.total-row { font-weight:700; font-size:1rem; color:#1a2e5a; }
.summary-row.restante-row { font-weight:700; font-size:1.1rem; background:#1a2e5a; color:#fff; border-radius:6px; padding:.5rem .75rem; margin-top:.5rem; }
.abono-badge { font-size:.72rem; }
.week-nav { display:flex; align-items:center; gap:.5rem; }
</style>

<div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
  <a href="?p=productores" class="btn btn-sm btn-outline-secondary">← Productores</a>
  <h5 class="mb-0 fw-bold" style="color:#1a2e5a">🐄 <?= h($prod['nombre']) ?></h5>
  <?php $prod_activo = bool_estado($prod['estado']); ?>
  <span class="badge <?= $prod_activo ? 'bg-success' : 'bg-secondary' ?>"><?= $prod_activo ? 'Activo' : 'Inactivo' ?></span>
</div>

<!-- Stats globales -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="stat-card text-center"><div class="stat-val"><?= number_format((float)$glob_litros,1) ?></div><div class="stat-label">Litros totales</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card text-center"><div class="stat-val text-primary">$<?= number_format((float)$glob_deuda,2) ?></div><div class="stat-label">Total facturado</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card text-center"><div class="stat-val text-success">$<?= number_format($glob_abonos,2) ?></div><div class="stat-label">Total pagado</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card text-center"><div class="stat-val <?= $glob_saldo>0?'text-danger':'text-success' ?>">$<?= number_format($glob_saldo,2) ?></div><div class="stat-label">Saldo global</div></div></div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3 flex-wrap">
  <li class="nav-item"><a class="nav-link <?= $tab==='control'?'active':'' ?>" href="?p=productor_detalle&id=<?=$id?>&semana=<?=$iso?>&tab=control">📋 Ventas Diarias</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='cortesias'?'active':'' ?>" href="?p=productor_detalle&id=<?=$id?>&tab=cortesias">🎁 Cortesías</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='vales'?'active':'' ?>" href="?p=productor_detalle&id=<?=$id?>&tab=vales">📜 Vales</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='proyeccion'?'active':'' ?>" href="?p=productor_detalle&id=<?=$id?>&tab=proyeccion">📊 Proyección</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='perfil'?'active':'' ?>" href="?p=productor_detalle&id=<?=$id?>&tab=perfil">👤 Perfil</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='cierres'?'active':'' ?>" href="?p=productor_detalle&id=<?=$id?>&tab=cierres">🔒 Cierres Semanales</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='historial'?'active':'' ?>" href="?p=productor_detalle&id=<?=$id?>&tab=historial">📜 Historial</a></li>
</ul>

<?php if ($tab === 'control'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- CONTROL SEMANAL                                                            -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->

<!-- Selector de semana -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <strong style="color:#1a2e5a">📅 Semana:</strong>
      <div class="week-nav">
        <a href="?p=productor_detalle&id=<?=$id?>&semana=<?=$prev_iso?>&tab=control" class="btn btn-sm btn-outline-secondary">‹</a>
        <form method="get" class="d-flex align-items-center gap-1">
          <input type="hidden" name="p" value="productor_detalle">
          <input type="hidden" name="id" value="<?=$id?>">
          <input type="hidden" name="tab" value="control">
          <input type="week" name="semana" class="form-control form-control-sm" value="<?=$iso?>" onchange="this.form.submit()" style="width:160px">
        </form>
        <a href="?p=productor_detalle&id=<?=$id?>&semana=<?=$next_iso?>&tab=control" class="btn btn-sm btn-outline-secondary">›</a>
      </div>
      <span class="text-muted small">
        <?= $week_start->format('d/m/Y') ?> — <?= $week_end->format('d/m/Y') ?>
      </span>
      <span class="ms-auto badge bg-secondary">Precio base: $<?= number_format((float)$prod['precio_litro_usd'],4) ?>/L</span>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Tabla de 7 días -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header py-2" style="background:#1a2e5a;color:#fff">
        <strong>🥛 Entregas de Leche — Semana <?= $week_start->format('d/m') ?> al <?= $week_end->format('d/m/Y') ?></strong>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0 week-control-table">
          <thead>
            <tr>
              <th style="width:110px">Día / Fecha</th>
              <th style="width:100px">Litros</th>
              <th style="width:110px">Precio $/L</th>
              <th style="width:110px">Total $</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php
          $dias_es = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
          $total_l = 0; $total_s = 0;
          $today = date('Y-m-d');
          for ($d = 0; $d < 7; $d++):
            $fecha_obj = clone $week_start;
            $fecha_obj->modify("+$d days");
            $fecha = $fecha_obj->format('Y-m-d');
            $dia   = $dias_es[$d];
            $ent   = $ent_by_date[$fecha] ?? null;
            $litros_val = $ent ? (float)$ent['litros'] : 0;
            $precio_val = $ent ? (float)$ent['precio_litro_usd'] : (float)$prod['precio_litro_usd'];
            $sub_val    = round($litros_val * $precio_val, 2);
            $total_l   += $litros_val;
            $total_s   += $sub_val;
            $is_today   = ($fecha === $today);
          ?>
          <tr class="<?= $is_today?'today-row':'' ?>">
            <td>
              <span class="fw-semibold"><?= $dia ?></span>
              <br><small class="text-muted"><?= $fecha_obj->format('d/m') ?></small>
              <?php if ($is_today): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">Hoy</span><?php endif ?>
            </td>
            <td>
              <form method="post" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save_entrega">
                <input type="hidden" name="fecha" value="<?= $fecha ?>">
                <input type="hidden" name="precio" id="precio_<?=$d?>" value="<?= h($precio_val) ?>">
                <input type="number" name="litros" step="0.1" min="0"
                       class="form-control form-control-sm" style="width:80px"
                       value="<?= $litros_val > 0 ? number_format($litros_val,1,'.','') : '' ?>"
                       placeholder="0.0"
                       oninput="calcRow(<?=$d?>, this.value)">
                <button class="btn btn-sm btn-success py-0 px-1" title="Guardar" style="font-size:.75rem">💾</button>
              </form>
            </td>
            <td>
              <input type="number" id="pp_<?=$d?>" step="0.0001" min="0"
                     class="form-control form-control-sm" style="width:90px"
                     value="<?= number_format($precio_val,4,'.','') ?>"
                     oninput="document.getElementById('precio_<?=$d?>').value=this.value; calcRow(<?=$d?>, document.querySelector('[name=litros]', this.closest('tr')).value)">
            </td>
            <td id="sub_<?=$d?>" class="fw-semibold <?= $sub_val>0?'text-primary':'' ?>">
              $<?= number_format($sub_val,2) ?>
            </td>
            <td>
              <?php if ($ent): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar entrega?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="del_entrega">
                <input type="hidden" name="eid" value="<?= $ent['id'] ?>">
                <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1" title="Eliminar">✕</button>
              </form>
              <?php endif ?>
            </td>
          </tr>
          <?php endfor ?>
          </tbody>
          <tfoot>
            <tr style="background:#f0f4ff;font-weight:700;">
              <td colspan="1" class="text-end pe-2" style="color:#1a2e5a">TOTALES</td>
              <td style="color:#1a2e5a"><?= number_format($total_litros,1) ?> L</td>
              <td></td>
              <td style="color:#1a2e5a">$<?= number_format($total_semanal,2) ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- Resumen y pagos -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header py-2" style="background:#1a2e5a;color:#fff">
        <strong>💰 Resumen de Semana</strong>
      </div>
      <div class="card-body">
        <div class="summary-box">
          <div class="summary-row">
            <span>Total de Litros</span>
            <strong><?= number_format($total_litros,1) ?> L</strong>
          </div>
          <div class="summary-row total-row">
            <span>Total Semanal $</span>
            <strong>$<?= number_format($total_semanal,2) ?></strong>
          </div>
          <div class="summary-row" style="color:#856404">
            <span>Saldo Pendiente</span>
            <strong>$<?= number_format($saldo_pendiente,2) ?></strong>
          </div>
          <div class="summary-row text-danger">
            <span>(-) Mercancía</span>
            <strong>-$<?= number_format($mercancia,2) ?></strong>
          </div>
          <div class="summary-row text-danger">
            <span>(-) Abono PM/Trans</span>
            <strong>-$<?= number_format($abono_pm_trans,2) ?></strong>
          </div>
          <div class="summary-row text-danger">
            <span>(-) Efectivo</span>
            <strong>-$<?= number_format($efectivo,2) ?></strong>
          </div>
          <div class="summary-row text-danger">
            <span>(-) Cortesías</span>
            <strong>-$<?= number_format($cortesia,2) ?></strong>
          </div>
          <div class="summary-row text-danger">
            <span>(-) Vales</span>
            <strong>-$<?= number_format($vales,2) ?></strong>
          </div>
          <div class="summary-row restante-row">
            <span>RESTANTE</span>
            <strong>$<?= number_format($restante,2) ?></strong>
          </div>
        </div>
      </div>
    </div>

    <!-- Registrar pago -->
    <div class="card">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <strong>+ Registrar Pago</strong>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_abono">
          <div class="mb-2">
            <label class="form-label form-label-sm">Tipo</label>
            <select name="tipo" class="form-select form-select-sm">
              <option value="efectivo">💵 Efectivo</option>
              <option value="mercancia">📦 Mercancía</option>
              <option value="pm_trans">🚚 Abono PM/Trans</option>
              <option value="cortesia">🎁 Cortesía</option>
              <option value="vale">📜 Vale</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Monto USD</label>
            <input type="number" name="monto" step="0.01" min="0" class="form-control form-control-sm" required placeholder="0.00">
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Fecha del pago <small class="text-muted">(día en que se cobró)</small></label>
            <input type="date" name="fecha" class="form-control form-control-sm" value="<?= min($today, $we) > $ws ? (($today >= $ws && $today <= $we) ? $today : $ws) : $ws ?>">
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Semana del abono <small class="text-muted">(a cuál semana aplica)</small></label>
            <select name="semana_aplicada" class="form-select form-select-sm">
              <?php foreach ($semanas_select as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $k === $ws ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach ?>
            </select>
            <small class="text-muted">Cambia esta semana si el pago corresponde a una semana anterior.</small>
          </div>
          <div class="mb-3">
            <label class="form-label form-label-sm">Descripción</label>
            <input type="text" name="descripcion" class="form-control form-control-sm" placeholder="Opcional">
          </div>
          <button class="btn btn-primary btn-sm w-100">Guardar Pago</button>
        </form>
      </div>
    </div>

    <!-- Lista de pagos de esta semana -->
    <?php if ($abo_week): ?>
    <div class="card mt-3">
      <div class="card-header py-2"><strong>Pagos registrados esta semana</strong></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Tipo</th><th>Monto</th><th>Pago</th><th>Semana</th><th>Descripción</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($abo_week as $a):
            $tipo_label = ['efectivo'=>'Efectivo','mercancia'=>'Mercancía','pm_trans'=>'PM/Trans','cortesia'=>'Cortesía','vale'=>'Vale'][$a['tipo']] ?? $a['tipo'];
            $tipo_color = ['efectivo'=>'success','mercancia'=>'warning','pm_trans'=>'info','cortesia'=>'primary','vale'=>'dark'][$a['tipo']] ?? 'secondary';
            $sem_disp = $a['semana_aplicada'] ?? null;
          ?>
          <tr>
            <td><span class="badge bg-<?=$tipo_color?> abono-badge"><?= h($tipo_label) ?></span></td>
            <td class="fw-semibold">$<?= number_format((float)$a['monto_usd'],2) ?></td>
            <td class="text-muted small"><?= date('d/m',strtotime($a['fecha'])) ?></td>
            <td class="text-muted small"><?= $sem_disp ? 'Sem. '.date('d/m',strtotime($sem_disp)) : '—' ?></td>
            <td class="text-muted small"><?= h($a['descripcion'] ?? '') ?: '—' ?></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-xs btn-sm btn-outline-warning py-0 px-1"
                data-bs-toggle="modal" data-bs-target="#modalEditAbono"
                data-aid="<?= $a['id'] ?>"
                data-tipo="<?= h($a['tipo']) ?>"
                data-monto="<?= number_format((float)$a['monto_usd'],2,'.','') ?>"
                data-fecha="<?= h($a['fecha']) ?>"
                data-semana="<?= h($sem_disp ?: $a['fecha']) ?>"
                data-desc="<?= h($a['descripcion'] ?? '') ?>"
                data-tab="control">✏️</button>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="del_abono">
                <input type="hidden" name="tab" value="control">
                <input type="hidden" name="aid" value="<?= $a['id'] ?>">
                <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif ?>
  </div>
</div>

<script>
function calcRow(d, litros) {
  var precio = parseFloat(document.getElementById('pp_' + d).value) || 0;
  var l = parseFloat(litros) || 0;
  var sub = (l * precio).toFixed(2);
  var el = document.getElementById('sub_' + d);
  el.textContent = '$' + sub;
  el.className = 'fw-semibold ' + (l > 0 ? 'text-primary' : '');
  // Sync hidden precio field
  document.getElementById('precio_' + d).value = precio;
}
// Sync price inputs: when precio changes, re-calc subtotal
document.querySelectorAll('[id^="pp_"]').forEach(function(inp) {
  inp.addEventListener('input', function() {
    var d = this.id.replace('pp_','');
    var litrosInput = this.closest('tr').querySelector('input[name="litros"]');
    if (litrosInput) calcRow(d, litrosInput.value);
    document.getElementById('precio_' + d).value = this.value;
  });
});
</script>

<?php elseif ($tab === 'cortesias'):
/* ══════ TAB CORTESÍAS ══════════════════════════════════════════════════════ */
$cort_all = $db->prepare("SELECT * FROM productores_abonos WHERE productor_id=? AND tipo='cortesia' ORDER BY fecha DESC LIMIT 200");
$cort_all->execute([$id]); $cort_all = $cort_all->fetchAll();
$total_cort = array_sum(array_column($cort_all, 'monto_usd'));
?>
<div class="row g-3">
  <div class="col-md-8">
    <div class="card">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <strong>🎁 Cortesías entregadas</strong>
        <span class="badge bg-primary">Total: $<?= number_format($total_cort,2) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Fecha</th><th>Descripción</th><th class="text-end">Monto $</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($cort_all as $a): ?>
          <tr>
            <td><?= date('d/m/Y',strtotime($a['fecha'])) ?></td>
            <td><?= h($a['descripcion']) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end fw-semibold text-primary">$<?= number_format((float)$a['monto_usd'],2) ?></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-xs btn-sm btn-outline-warning py-0 px-1"
                data-bs-toggle="modal" data-bs-target="#modalEditAbono"
                data-aid="<?= $a['id'] ?>"
                data-tipo="cortesia"
                data-monto="<?= number_format((float)$a['monto_usd'],2,'.','') ?>"
                data-fecha="<?= h($a['fecha']) ?>"
                data-desc="<?= h($a['descripcion'] ?? '') ?>"
                data-tab="cortesias">✏️</button>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="del_abono">
                <input type="hidden" name="tab" value="cortesias">
                <input type="hidden" name="aid" value="<?= $a['id'] ?>">
                <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach ?>
          <?php if (!$cort_all): ?><tr><td colspan="4" class="text-center text-muted py-4">Sin cortesías registradas</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card">
      <div class="card-header py-2"><strong>+ Nueva Cortesía</strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_abono">
          <input type="hidden" name="tipo" value="cortesia">
          <div class="mb-2">
            <label class="form-label form-label-sm">Monto USD</label>
            <input type="number" name="monto" step="0.01" min="0" class="form-control form-control-sm" required placeholder="0.00">
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Fecha</label>
            <input type="date" name="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label form-label-sm">Descripción</label>
            <input type="text" name="descripcion" class="form-control form-control-sm" placeholder="Ej: Queso duro 2kg, yogurt...">
          </div>
          <button class="btn btn-primary btn-sm w-100">💾 Guardar Cortesía</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'vales'):
/* ══════ TAB VALES ══════════════════════════════════════════════════════════ */
$vales_all = $db->prepare("SELECT * FROM productores_abonos WHERE productor_id=? AND tipo='vale' ORDER BY fecha DESC LIMIT 200");
$vales_all->execute([$id]); $vales_all = $vales_all->fetchAll();
$total_vales = array_sum(array_column($vales_all, 'monto_usd'));
?>
<div class="row g-3">
  <div class="col-md-8">
    <div class="card">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <strong>📜 Vales emitidos</strong>
        <span class="badge bg-dark">Total: $<?= number_format($total_vales,2) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Fecha</th><th>Descripción</th><th class="text-end">Monto $</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($vales_all as $a): ?>
          <tr>
            <td><?= date('d/m/Y',strtotime($a['fecha'])) ?></td>
            <td><?= h($a['descripcion']) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end fw-semibold">$<?= number_format((float)$a['monto_usd'],2) ?></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-xs btn-sm btn-outline-warning py-0 px-1"
                data-bs-toggle="modal" data-bs-target="#modalEditAbono"
                data-aid="<?= $a['id'] ?>"
                data-tipo="vale"
                data-monto="<?= number_format((float)$a['monto_usd'],2,'.','') ?>"
                data-fecha="<?= h($a['fecha']) ?>"
                data-desc="<?= h($a['descripcion'] ?? '') ?>"
                data-tab="vales">✏️</button>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="del_abono">
                <input type="hidden" name="tab" value="vales">
                <input type="hidden" name="aid" value="<?= $a['id'] ?>">
                <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach ?>
          <?php if (!$vales_all): ?><tr><td colspan="4" class="text-center text-muted py-4">Sin vales registrados</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card">
      <div class="card-header py-2"><strong>+ Nuevo Vale</strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_abono">
          <input type="hidden" name="tipo" value="vale">
          <div class="mb-2">
            <label class="form-label form-label-sm">Monto USD</label>
            <input type="number" name="monto" step="0.01" min="0" class="form-control form-control-sm" required placeholder="0.00">
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Fecha</label>
            <input type="date" name="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label form-label-sm">Descripción / Referencia</label>
            <input type="text" name="descripcion" class="form-control form-control-sm" placeholder="Ej: Vale #001 semana 20">
          </div>
          <button class="btn btn-dark btn-sm w-100">💾 Guardar Vale</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'proyeccion'):
/* ══════ TAB PROYECCIÓN ══════════════════════════════════════════════════════ */
// Últimas 8 semanas de entregas para calcular promedios
$proy_data = $db->prepare("
    SELECT fecha, litros, subtotal_usd
    FROM productores_entregas
    WHERE productor_id=? AND fecha >= ?
    ORDER BY fecha ASC
");
$date_56_ago = date('Y-m-d', strtotime('-56 days'));
$proy_data->execute([$id, $date_56_ago]); $proy_data = $proy_data->fetchAll();

// Agrupar por semana (lunes)
$semanas_data = [];
foreach ($proy_data as $e) {
    $d = new DateTime($e['fecha']);
    $dow = (int)$d->format('N');
    $lunes = (clone $d)->modify('-'.($dow-1).' days')->format('Y-m-d');
    if (!isset($semanas_data[$lunes])) $semanas_data[$lunes] = ['litros'=>0,'monto'=>0,'dias'=>0];
    $semanas_data[$lunes]['litros'] += (float)$e['litros'];
    $semanas_data[$lunes]['monto']  += (float)$e['subtotal_usd'];
    $semanas_data[$lunes]['dias']++;
}
$n_semanas   = count($semanas_data) ?: 1;
$prom_litros = array_sum(array_column($semanas_data,'litros')) / $n_semanas;
$prom_monto  = array_sum(array_column($semanas_data,'monto'))  / $n_semanas;
$precio_base = (float)$prod['precio_litro_usd'];
$proy_mes    = $prom_litros * 4;
$proy_ingreso_mes = $proy_mes * $precio_base;
?>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header py-2" style="background:#1a2e5a;color:#fff">
        <strong>📊 Proyección de Producción</strong>
        <span class="ms-2 small opacity-75">(basada en las últimas <?= $n_semanas ?> semanas)</span>
      </div>
      <div class="card-body">
        <div class="summary-box">
          <div class="summary-row">
            <span>Precio base acordado</span>
            <strong>$<?= number_format($precio_base,4) ?>/L</strong>
          </div>
          <div class="summary-row">
            <span>Promedio litros/semana</span>
            <strong><?= number_format($prom_litros,1) ?> L</strong>
          </div>
          <div class="summary-row">
            <span>Promedio ingreso/semana</span>
            <strong class="text-primary">$<?= number_format($prom_monto,2) ?></strong>
          </div>
          <div class="summary-row total-row">
            <span>Proyección mensual (litros)</span>
            <strong><?= number_format($proy_mes,1) ?> L</strong>
          </div>
          <div class="summary-row restante-row">
            <span>INGRESO PROYECTADO/MES</span>
            <strong>$<?= number_format($proy_ingreso_mes,2) ?></strong>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header py-2"><strong>📅 Historial de últimas semanas</strong></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Semana (lunes)</th><th class="text-end">Litros</th><th class="text-end">Monto $</th><th class="text-end">Días</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($semanas_data, true) as $lunes => $sd): ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($lunes)) ?></td>
            <td class="text-end"><?= number_format($sd['litros'],1) ?></td>
            <td class="text-end text-primary fw-semibold">$<?= number_format($sd['monto'],2) ?></td>
            <td class="text-end text-muted"><?= $sd['dias'] ?></td>
          </tr>
          <?php endforeach ?>
          <?php if (!$semanas_data): ?><tr><td colspan="4" class="text-center text-muted py-4">Sin datos suficientes</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'cierres'):
/* ══════ TAB CIERRES SEMANALES ═══════════════════════════════════════════════ */
ensure_cierres_table();

// Construir lista unificada: cierres existentes + semanas abiertas con entregas
$semanas_prod = [];

$st = $db->prepare("SELECT * FROM productores_cierres WHERE productor_id=? ORDER BY semana_inicio DESC");
$st->execute([$id]);
foreach ($st->fetchAll() as $c) {
    $semanas_prod[$c['semana_inicio']] = [
        'cid'           => $c['id'],
        'semana_inicio' => $c['semana_inicio'],
        'semana_fin'    => $c['semana_fin'],
        'litros'        => (float)$c['litros'],
        'precio_litro'  => (float)$c['precio_litro'],
        'total_factura' => (float)$c['total_factura'],
        'abonos_semana' => (float)$c['abonos_semana'],
        'arrastre'      => (float)$c['arrastre_anterior'],
        'monto_pagado'  => (float)$c['monto_pagado'],
        'estado'        => $c['estado'],
        'notas'         => $c['notas'],
        'cerrada'       => true,
    ];
}

// Semanas con entregas pero sin cierre (típicamente la semana en curso)
$st = $db->prepare("SELECT fecha, SUM(litros) lit, SUM(subtotal_usd) tot
                    FROM productores_entregas WHERE productor_id=?
                    GROUP BY fecha ORDER BY fecha DESC");
$st->execute([$id]);
$por_semana = [];
foreach ($st->fetchAll() as $r) {
    $sw = snap_sat($r['fecha'])->format('Y-m-d');
    if (!isset($por_semana[$sw])) $por_semana[$sw] = ['lit'=>0,'tot'=>0];
    $por_semana[$sw]['lit'] += (float)$r['lit'];
    $por_semana[$sw]['tot'] += (float)$r['tot'];
}

$st = $db->prepare("SELECT fecha, semana_aplicada, monto_usd FROM productores_abonos
                    WHERE productor_id=?
                      AND (descripcion IS NULL OR descripcion NOT LIKE 'Pago cierre%')");
$st->execute([$id]);
$abo_por_semana = [];
foreach ($st->fetchAll() as $r) {
    $base = $r['semana_aplicada'] ?: $r['fecha'];
    $sw = snap_sat($base)->format('Y-m-d');
    $abo_por_semana[$sw] = ($abo_por_semana[$sw] ?? 0) + (float)$r['monto_usd'];
}

$precio_actual = (float)$prod['precio_litro_usd'];
foreach ($por_semana as $sw => $info) {
    if (isset($semanas_prod[$sw])) continue;
    $we_v = (clone snap_sat($sw))->modify('+6 days')->format('Y-m-d');
    // Arrastre = saldo pendiente SOLO de la semana cerrada inmediatamente anterior
    // (esa semana ya absorbió las anteriores, no se acumulan otra vez).
    $st = $db->prepare("SELECT (total_factura + arrastre_anterior - abonos_semana - monto_pagado) AS pend
                        FROM productores_cierres
                        WHERE productor_id=? AND semana_inicio<?
                        ORDER BY semana_inicio DESC LIMIT 1");
    $st->execute([$id, $sw]);
    $arrastre_pendiente = max(0, (float)($st->fetchColumn() ?: 0));
    $semanas_prod[$sw] = [
        'cid'           => null,
        'semana_inicio' => $sw,
        'semana_fin'    => $we_v,
        'litros'        => (float)$info['lit'],
        'precio_litro'  => $info['lit'] > 0 ? round($info['tot'] / $info['lit'], 4) : $precio_actual,
        'total_factura' => round((float)$info['tot'], 2),
        'abonos_semana' => $abo_por_semana[$sw] ?? 0,
        'arrastre'      => $arrastre_pendiente,
        'monto_pagado'  => 0,
        'estado'        => 'abierta',
        'notas'         => null,
        'cerrada'       => false,
    ];
}
krsort($semanas_prod);

// ── Filtro por rango de fechas ─────────────────────────────────────────────
$f_desde = $_GET['desde'] ?? '';
$f_hasta = $_GET['hasta'] ?? '';
if ($f_desde || $f_hasta) {
    $semanas_prod = array_filter($semanas_prod, function($r) use ($f_desde, $f_hasta) {
        if ($f_desde && $r['semana_fin']    < $f_desde) return false;
        if ($f_hasta && $r['semana_inicio'] > $f_hasta) return false;
        return true;
    });
}

// ── Pagos detallados por semana (para el botón "Pagos" de cada cierre) ──────
// Agrupa TODOS los movimientos de productores_abonos (pagos de cierre, abonos,
// efectivo, mercancía, etc.) por la semana a la que se aplicaron.
$pagos_por_semana = [];
$pp_st = $db->prepare("SELECT fecha, semana_aplicada, tipo, monto_usd, descripcion
                       FROM productores_abonos WHERE productor_id=?
                       ORDER BY fecha, id");
$pp_st->execute([$id]);
foreach ($pp_st->fetchAll() as $r) {
    $base = $r['semana_aplicada'] ?: $r['fecha'];
    $sw = snap_sat($base)->format('Y-m-d');
    $pagos_por_semana[$sw][] = [
        'fecha' => date('d/m/Y', strtotime($r['fecha'])),
        'tipo'  => $r['tipo'],
        'monto' => (float)$r['monto_usd'],
        'desc'  => $r['descripcion'] ?? '',
    ];
}

// ── Export XLS ────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'xls') {
    $fn = 'cierres_'.preg_replace('/[^a-z0-9]+/i','_', $prod['nombre']).'_'.date('Ymd_His').'.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"$fn\"");
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<h3>Cierres Semanales - ".h($prod['nombre'])."</h3>";
    if ($f_desde || $f_hasta) echo "<p>Rango: ".h($f_desde?:'inicio')." a ".h($f_hasta?:'hoy')."</p>";
    echo "<table border='1'><tr><th>Semana Inicio</th><th>Semana Fin</th><th>Litros</th><th>$/L</th><th>Factura</th><th>Arrastre</th><th>Abonos</th><th>Pagado</th><th>Pendiente</th><th>Estado</th><th>Notas</th></tr>";
    foreach ($semanas_prod as $r) {
        $pw = $r['total_factura'] + $r['arrastre'] - $r['abonos_semana'] - $r['monto_pagado'];
        $est = $r['cerrada'] ? $r['estado'] : 'abierta';
        echo "<tr>";
        echo "<td>".date('d/m/Y', strtotime($r['semana_inicio']))."</td>";
        echo "<td>".date('d/m/Y', strtotime($r['semana_fin']))."</td>";
        echo "<td>".number_format($r['litros'],1)."</td>";
        echo "<td>".number_format($r['precio_litro'],4)."</td>";
        echo "<td>".number_format($r['total_factura'],2)."</td>";
        echo "<td>".number_format($r['arrastre'],2)."</td>";
        echo "<td>".number_format($r['abonos_semana'],2)."</td>";
        echo "<td>".number_format($r['monto_pagado'],2)."</td>";
        echo "<td>".number_format($pw,2)."</td>";
        echo "<td>".h($est)."</td>";
        echo "<td>".h($r['notas'] ?? '')."</td>";
        echo "</tr>";
    }
    echo "</table></body></html>";
    exit;
}

// ── Export PDF (vista para imprimir / guardar como PDF) ────────────────────
if (($_GET['export'] ?? '') === 'pdf') {
    $tot_l=0; $tot_f=0; $tot_p=0;
    foreach ($semanas_prod as $r) {
        $tot_l += $r['litros']; $tot_f += $r['total_factura'];
        $tot_p += $r['abonos_semana'] + $r['monto_pagado'];
    }
    // Pendiente total = Facturado - Pagado (igual que la cabecera y la pestaña).
    $tot_pd = $tot_f - $tot_p;
    ?><!doctype html><html><head><meta charset="UTF-8"><title>Cierres - <?=h($prod['nombre'])?></title>
    <style>
      @page { size: Letter portrait; margin: 10mm; }
      body{font-family:Arial,sans-serif;color:#1a2e5a;padding:20px;font-size:12px}
      h2{margin:0 0 4px 0}
      .meta{color:#666;font-size:11px;margin-bottom:14px}
      table{border-collapse:collapse;width:100%;font-size:11px}
      th{background:#1a2e5a;color:#fff;padding:6px;text-align:left}
      td{padding:5px;border-bottom:1px solid #ddd}
      .end{text-align:right}
      .deuda{color:#b91c1c;font-weight:bold}
      .ok{color:#15803d}
      tfoot td{font-weight:bold;border-top:2px solid #1a2e5a;background:#f8f9fa}
      .noprint{margin-bottom:12px}
      @media print { .noprint{display:none} body{padding:0;margin:0}
        th, tfoot td { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
    </style></head><body>
    <div class="noprint">
      <button onclick="window.print()" style="padding:8px 16px;background:#1a2e5a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px">🖨️ Imprimir / Guardar como PDF</button>
      <button onclick="window.close()" style="padding:8px 16px;background:#6c757d;color:#fff;border:none;border-radius:4px;cursor:pointer;margin-left:6px">Cerrar</button>
    </div>
    <h2>Cierres Semanales</h2>
    <div class="meta">
      Productor: <strong><?=h($prod['nombre'])?></strong>
      <?php if ($prod['documento']): ?> · Doc: <?=h($prod['documento'])?><?php endif ?><br>
      Emitido: <?=date('d/m/Y H:i')?>
      <?php if ($f_desde || $f_hasta): ?> · Rango: <?=h($f_desde?:'inicio')?> a <?=h($f_hasta?:'hoy')?><?php endif ?>
    </div>
    <table>
      <thead><tr>
        <th>Semana</th><th class="end">Litros</th><th class="end">$/L</th>
        <th class="end">Factura</th><th class="end">Arrastre</th><th class="end">Abonos</th>
        <th class="end">Pagado</th><th class="end">Pendiente</th><th>Estado</th>
      </tr></thead>
      <tbody>
      <?php foreach ($semanas_prod as $r):
        $pw = $r['total_factura'] + $r['arrastre'] - $r['abonos_semana'] - $r['monto_pagado'];
        $est = $r['cerrada'] ? ucfirst($r['estado']) : 'Abierta';
      ?>
        <tr>
          <td><?=date('d/m/Y', strtotime($r['semana_inicio']))?> – <?=date('d/m/Y', strtotime($r['semana_fin']))?></td>
          <td class="end"><?=number_format($r['litros'],1)?></td>
          <td class="end">$<?=number_format($r['precio_litro'],4)?></td>
          <td class="end">$<?=number_format($r['total_factura'],2)?></td>
          <td class="end">$<?=number_format($r['arrastre'],2)?></td>
          <td class="end ok">$<?=number_format($r['abonos_semana'],2)?></td>
          <td class="end ok">$<?=number_format($r['monto_pagado'],2)?></td>
          <td class="end <?=$pw>0.01?'deuda':'ok'?>"><?=$pw>0.01?'$'.number_format($pw,2):'✓'?></td>
          <td><?=h($est)?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot><tr>
        <td>TOTALES (<?=count($semanas_prod)?> semanas)</td>
        <td class="end"><?=number_format($tot_l,1)?></td>
        <td></td>
        <td class="end">$<?=number_format($tot_f,2)?></td>
        <td></td><td></td>
        <td class="end">$<?=number_format($tot_p,2)?></td>
        <td class="end <?=$tot_pd>0.01?'deuda':'ok'?>">$<?=number_format($tot_pd,2)?></td>
        <td></td>
      </tr></tfoot>
    </table>
    <script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300))</script>
    </body></html><?php
    exit;
}

// Identificar la semana más reciente: sólo ahí se acumula y se puede pagar.
// Las semanas anteriores con deuda ya fueron "arrastradas" al arrastre_anterior
// de la semana siguiente (auto_close_past_weeks lo hace), así que no se deben
// pagar por separado para no romper la lógica.
$latest_ws = $semanas_prod ? array_key_first($semanas_prod) : null; // krsort → la 1ª es la más reciente

// Totales. El "Pendiente" se calcula como Facturado - Pagado, EXACTAMENTE igual
// que el "Saldo global" de la cabecera, para que ambos bloques siempre coincidan
// (incluido el saldo a favor / sobrepago). No se suman los pendientes semana por
// semana porque el arrastre haría doble conteo.
$tot_lit=0; $tot_fact=0; $tot_pagado=0; $cuantas_pend=0;
$detalle = [];
foreach ($semanas_prod as $sw => $r) {
    $tot_lit    += $r['litros'];
    $tot_fact   += $r['total_factura'];
    $tot_pagado += $r['abonos_semana'] + $r['monto_pagado'];
}
$tot_pend = $tot_fact - $tot_pagado;
if ($tot_pend > 0.01 && $latest_ws !== null) {
    $cuantas_pend = 1;
    $rl = $semanas_prod[$latest_ws];
    $detalle[] = 'Semana del <strong>'.date('d/m/Y', strtotime($rl['semana_inicio'])).'</strong>: <strong>$'.number_format($tot_pend,2).'</strong> (incluye arrastre acumulado)';
}
?>

<div class="alert <?= $tot_pend > 0.01 ? 'alert-danger':'alert-success' ?> py-3 mb-3" style="font-size:1.05rem">
  <?php if ($tot_pend > 0.01): ?>
    <strong>💸 Se le debe en total:</strong>
    <span class="fs-3 fw-bold">$<?= number_format($tot_pend,2) ?></span>
    <span class="text-muted">(<?= $cuantas_pend ?> semana<?= $cuantas_pend==1?'':'s' ?> con deuda)</span>
    <div class="mt-2 small"><strong>Detalle:</strong> <?= implode(' · ', $detalle) ?></div>
  <?php else: ?>
    <strong>✅ No se le debe nada.</strong> Todas las semanas están saldadas.
  <?php endif ?>
</div>

<div class="alert alert-info py-2 mb-3 small">
  ⚡ El sistema revisa y cierra <strong>automáticamente cada día</strong> las semanas ya terminadas (sábado–viernes).
  Las semanas que aparecen como <span class="badge bg-info text-dark">Abierta</span> son las que todavía están en curso.
  Puedes corregir cualquier cierre con el botón <span class="badge bg-primary">✏️ Editar</span>.
  <?php if ($f_desde || $f_hasta): ?>
    <div class="mt-1 text-muted"><strong>Nota:</strong> los totales de abajo corresponden solo al <strong>rango de fechas filtrado</strong>, por eso pueden diferir de los totales generales de la cabecera.</div>
  <?php endif ?>
</div>

<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-2"><div class="fs-5 fw-bold"><?= number_format($tot_lit,1) ?></div><div class="text-muted small">Litros totales</div></div></div></div>
  <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-2"><div class="fs-5 fw-bold text-primary">$<?= number_format($tot_fact,2) ?></div><div class="text-muted small">Facturado</div></div></div></div>
  <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-2"><div class="fs-5 fw-bold text-success">$<?= number_format($tot_pagado,2) ?></div><div class="text-muted small">Pagado</div></div></div></div>
  <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-2"><div class="fs-5 fw-bold <?= $tot_pend>0.01?'text-danger':'text-success' ?>">$<?= number_format($tot_pend,2) ?></div><div class="text-muted small">Pendiente</div></div></div></div>
</div>

<!-- Barra de búsqueda por fechas + exportadores -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="p" value="productor_detalle">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="tab" value="cierres">
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1 fw-semibold">📅 Desde</label>
        <input type="date" name="desde" value="<?= h($f_desde) ?>" class="form-control form-control-sm">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1 fw-semibold">📅 Hasta</label>
        <input type="date" name="hasta" value="<?= h($f_hasta) ?>" class="form-control form-control-sm">
      </div>
      <div class="col-12 col-md-6 d-flex gap-2 flex-wrap">
        <button class="btn btn-sm btn-primary">🔍 Buscar</button>
        <a href="?p=productor_detalle&id=<?=$id?>&tab=cierres" class="btn btn-sm btn-outline-secondary">Limpiar</a>
        <div class="vr d-none d-md-block"></div>
        <a href="?p=productor_detalle&id=<?=$id?>&tab=cierres&export=xls<?= $f_desde?'&desde='.h($f_desde):'' ?><?= $f_hasta?'&hasta='.h($f_hasta):'' ?>"
           class="btn btn-sm btn-success">📊 Excel</a>
        <a href="?p=productor_detalle&id=<?=$id?>&tab=cierres&export=pdf<?= $f_desde?'&desde='.h($f_desde):'' ?><?= $f_hasta?'&hasta='.h($f_hasta):'' ?>"
           target="_blank" class="btn btn-sm btn-danger">📄 PDF</a>
      </div>
    </form>
    <?php if ($f_desde || $f_hasta): ?>
      <div class="mt-2 small text-muted">
        Mostrando <strong><?= count($semanas_prod) ?></strong> semana(s) entre
        <strong><?= h($f_desde ?: '—') ?></strong> y <strong><?= h($f_hasta ?: '—') ?></strong>.
      </div>
    <?php endif ?>
  </div>
</div>

<?php if (!$semanas_prod): ?>
  <div class="alert alert-warning">No hay semanas que coincidan con el filtro.</div>
<?php else: ?>
<div class="card">
  <div class="card-header py-2" style="background:#1a2e5a;color:#fff">
    <strong>📋 Historial semana por semana</strong>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead style="background:#f8f9fa">
          <tr>
            <th>Semana</th>
            <th class="text-end">Litros</th>
            <th class="text-end">$/L</th>
            <th class="text-end">Factura</th>
            <th class="text-end">Arrastre</th>
            <th class="text-end">Abonos</th>
            <th class="text-end">Pagado</th>
            <th class="text-end">¿Se le debe?</th>
            <th>Estado</th>
            <th>Notas</th>
            <th style="width:230px">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($semanas_prod as $sw => $c):
          $pw = $c['total_factura'] + $c['arrastre'] - $c['abonos_semana'] - $c['monto_pagado'];
          $est = $c['estado'];
          $abierta = !$c['cerrada'];
          $es_latest = ($sw === $latest_ws);
          // Semana arrastrada: tiene deuda pero NO es la última → su saldo ya rodó al arrastre de la siguiente
          $arrastrada = (!$es_latest && $pw > 0.01);
        ?>
        <tr<?= ($pw>0.01 && $es_latest) ? ' style="background:#fef2f2"' : ($arrastrada ? ' style="background:#fff8e1"' : '') ?>>
          <td>
            <span class="fw-semibold" style="color:#1a2e5a"><?= date('d/m/Y', strtotime($c['semana_inicio'])) ?></span>
            <div class="text-muted" style="font-size:.7rem">al <?= date('d/m/Y', strtotime($c['semana_fin'])) ?></div>
          </td>
          <td class="text-end"><?= number_format($c['litros'],1) ?></td>
          <td class="text-end small">$<?= number_format($c['precio_litro'],4) ?></td>
          <td class="text-end">$<?= number_format($c['total_factura'],2) ?></td>
          <td class="text-end <?= $c['arrastre']>0?'text-warning fw-semibold':'text-muted' ?>">
            $<?= number_format($c['arrastre'],2) ?>
            <?php if ($c['arrastre']>0): ?>
              <div class="small text-warning" style="font-size:.65rem">↑ viene de semanas previas</div>
            <?php endif ?>
          </td>
          <td class="text-end text-success">$<?= number_format($c['abonos_semana'],2) ?></td>
          <td class="text-end text-success">$<?= number_format($c['monto_pagado'],2) ?></td>
          <td class="text-end">
            <?php if ($arrastrada): ?>
              <span class="text-warning fw-semibold" title="Esta deuda ya está incluida en el arrastre de la semana más reciente">
                ↪ $<?= number_format($pw,2) ?>
              </span>
            <?php elseif ($pw > 0.01): ?>
              <span class="text-danger fw-bold">$<?= number_format($pw,2) ?></span>
            <?php else: ?>
              <span class="text-success fw-bold">✓</span>
            <?php endif ?>
          </td>
          <td>
            <?php if ($abierta): ?>
              <span class="badge bg-info text-dark">Abierta</span>
            <?php elseif ($arrastrada): ?>
              <span class="badge bg-warning text-dark" title="Saldo arrastrado a la semana más reciente">Arrastrada</span>
            <?php else: ?>
              <?php $bg = ['cerrada'=>'bg-warning text-dark','parcial'=>'bg-primary','pagada'=>'bg-success'][$est] ?? 'bg-secondary'; ?>
              <span class="badge <?= $bg ?>"><?= ['cerrada'=>'Cerrada','parcial'=>'Parcial','pagada'=>'Pagada'][$est] ?? $est ?></span>
            <?php endif ?>
          </td>
          <td class="small text-muted" style="max-width:160px;white-space:normal">
            <?= h($c['notas'] ?? '') ?>
            <?php if ($arrastrada): ?><div class="small text-warning">Pago consolidado en semana más reciente</div><?php endif ?>
          </td>
          <td class="text-nowrap">
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1 mb-1 btn-ver-pagos"
                    data-semana="<?= $c['semana_inicio'] ?>"
                    data-semlabel="<?= date('d/m/Y', strtotime($c['semana_inicio'])) ?> al <?= date('d/m/Y', strtotime($c['semana_fin'])) ?>"
                    title="Ver los pagos hechos en esta semana">🧾 Pagos</button>
            <?php if ($abierta && $pw > 0.01): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('¿Cerrar manualmente esta semana en curso?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="cerrar_semana_actual">
                <input type="hidden" name="ws" value="<?= $c['semana_inicio'] ?>">
                <button class="btn btn-sm btn-warning py-0 px-2" title="Cerrar ahora (no esperar al sábado)">🔒</button>
              </form>
            <?php elseif (!$abierta): ?>
              <button class="btn btn-sm btn-outline-primary py-0 px-2 me-1" title="Editar precios o datos de este cierre"
                      data-bs-toggle="modal" data-bs-target="#modalEditarCierre"
                      data-cid="<?= $c['cid'] ?>"
                      data-semana="<?= date('d/m/Y', strtotime($c['semana_inicio'])) ?>"
                      data-litros="<?= number_format($c['litros'],3,'.','') ?>"
                      data-precio="<?= number_format($c['precio_litro'],4,'.','') ?>"
                      data-factura="<?= number_format($c['total_factura'],2,'.','') ?>"
                      data-arrastre="<?= number_format($c['arrastre'],2,'.','') ?>"
                      data-notas="<?= h($c['notas'] ?? '') ?>">✏️ Editar</button>
              <?php if ($pw > 0.01 && $es_latest): ?>
                <button class="btn btn-sm btn-success py-0 px-2" data-bs-toggle="modal" data-bs-target="#modalPagarCierre"
                        data-cid="<?= $c['cid'] ?>"
                        data-semana="<?= date('d/m/Y', strtotime($c['semana_inicio'])) ?>"
                        data-factura="<?= number_format($c['total_factura'],2,'.','') ?>"
                        data-arrastre="<?= number_format($c['arrastre'],2,'.','') ?>"
                        data-abonos="<?= number_format($c['abonos_semana'],2,'.','') ?>"
                        data-pagprev="<?= number_format($c['monto_pagado'],2,'.','') ?>"
                        data-pendiente="<?= number_format($pw,2,'.','') ?>">💵 Pagar todo</button>
              <?php elseif ($arrastrada): ?>
                <span class="badge bg-secondary" title="Esta deuda ya fue arrastrada a la semana más reciente">🔒 Cerrada</span>
              <?php elseif ($pw <= 0.01): ?>
                <span class="badge bg-success">✓ Saldada</span>
              <?php endif ?>
            <?php endif ?>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal Pagos de la Semana -->
<div class="modal fade" id="modalPagosSemana" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:#1a2e5a;color:#fff">
        <h6 class="modal-title">🧾 Pagos de la semana <span id="ps-semana"></span></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2">Esto es lo que se le pagó en esta semana específica.</p>
        <div id="ps-vacio" class="alert alert-secondary mb-0" style="display:none">
          No se registró ningún pago en esta semana.
        </div>
        <div class="table-responsive" id="ps-tabla-wrap">
          <table class="table table-sm align-middle mb-0">
            <thead style="background:#f8f9fa">
              <tr><th>Fecha</th><th>Tipo</th><th class="text-end">Monto</th><th>Detalle</th></tr>
            </thead>
            <tbody id="ps-body"></tbody>
            <tfoot>
              <tr class="fw-bold border-top">
                <td colspan="2" class="text-end">Total pagado esta semana:</td>
                <td class="text-end text-success">$<span id="ps-total">0.00</span></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
window.PAGOS_SEMANA = <?= json_encode($pagos_por_semana, JSON_UNESCAPED_UNICODE) ?>;
(function(){
  var tipoLabel = {efectivo:'Efectivo', pm_trans:'Pago Móvil / Transf.', transferencia:'Pago Móvil / Transf.', mercancia:'Mercancía', cortesia:'Cortesía', vale:'Vale'};
  function escapeHtml(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  document.querySelectorAll('.btn-ver-pagos').forEach(function(b){
    b.addEventListener('click', function(){
      var sem = b.dataset.semana;
      var lista = (window.PAGOS_SEMANA && window.PAGOS_SEMANA[sem]) || [];
      document.getElementById('ps-semana').textContent = b.dataset.semlabel || '';
      var body = document.getElementById('ps-body'); body.innerHTML = '';
      var total = 0;
      lista.forEach(function(p){
        total += parseFloat(p.monto) || 0;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="text-nowrap">' + p.fecha + '</td>' +
          '<td>' + escapeHtml(tipoLabel[p.tipo] || p.tipo) + '</td>' +
          '<td class="text-end text-success">$' + (parseFloat(p.monto) || 0).toFixed(2) + '</td>' +
          '<td class="small text-muted" style="white-space:normal">' + (p.desc ? escapeHtml(p.desc) : '') + '</td>';
        body.appendChild(tr);
      });
      document.getElementById('ps-total').textContent = total.toFixed(2);
      document.getElementById('ps-vacio').style.display = lista.length ? 'none' : 'block';
      document.getElementById('ps-tabla-wrap').style.display = lista.length ? '' : 'none';
      new bootstrap.Modal(document.getElementById('modalPagosSemana')).show();
    });
  });
})();
</script>
<?php endif ?>

<!-- Modal Pagar Cierre -->
<div class="modal fade" id="modalPagarCierre" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="pagar_cierre">
      <input type="hidden" name="cid" id="pc-cid" value="">
      <div class="modal-header" style="background:#1a2e5a;color:#fff">
        <h6 class="modal-title">💵 Registrar pago — Semana <span id="pc-semana"></span></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3 border-warning">
          <div class="card-header py-1 bg-warning bg-opacity-25"><strong>🧾 Detalle del saldo a pagar</strong></div>
          <div class="card-body p-2 small">
            <div class="d-flex justify-content-between"><span>Factura de la semana:</span><strong>$<span id="pc-factura">0.00</span></strong></div>
            <div class="d-flex justify-content-between text-warning"><span>(+) Arrastre acumulado de semanas anteriores:</span><strong>$<span id="pc-arrastre">0.00</span></strong></div>
            <div class="d-flex justify-content-between text-success"><span>(−) Abonos de la semana:</span><strong>$<span id="pc-abonos">0.00</span></strong></div>
            <div class="d-flex justify-content-between text-success"><span>(−) Pagos previos a este cierre:</span><strong>$<span id="pc-pagprev">0.00</span></strong></div>
            <hr class="my-1">
            <div class="d-flex justify-content-between fs-6"><strong>Total pendiente:</strong><strong class="text-danger">$<span id="pc-pend">0.00</span></strong></div>
            <div class="small text-muted mt-1" id="pc-arrastre-info" style="display:none">
              ⓘ Al pagar esta semana se salda automáticamente todo el arrastre de las semanas anteriores.
            </div>
          </div>
        </div>
        <div class="mb-2"><label class="form-label form-label-sm">Monto a pagar</label>
          <input type="number" step="0.01" min="0.01" name="monto" id="pc-monto" class="form-control" required></div>
        <div class="mb-2"><label class="form-label form-label-sm">Tipo</label>
          <select name="tipo" class="form-select form-select-sm">
            <option value="efectivo">Efectivo</option>
            <option value="pm_trans">Pago Móvil / Transferencia</option>
            <option value="mercancia">Mercancía</option>
          </select></div>
        <div class="mb-2"><label class="form-label form-label-sm">Fecha</label>
          <input type="date" name="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
        <div class="mb-2"><label class="form-label form-label-sm">Nota (opcional)</label>
          <input type="text" name="nota" class="form-control form-control-sm" placeholder="Ref. recibo, etc."></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm btn-success">💾 Registrar pago</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Editar Cierre -->
<div class="modal fade" id="modalEditarCierre" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="editar_cierre">
      <input type="hidden" name="cid" id="ec-cid" value="">
      <div class="modal-header" style="background:#1a2e5a;color:#fff">
        <h6 class="modal-title">✏️ Editar cierre — Semana <span id="ec-semana"></span></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2 small mb-3">
          Corrige los datos de esta semana ya cerrada. Si cambias litros o precio,
          la factura se recalcula sola (o puedes escribirla a mano).
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label form-label-sm">Litros</label>
            <input type="number" step="0.001" min="0" max="9999999" name="litros" id="ec-litros"
                   class="form-control form-control-sm" oninput="ecCalc()">
          </div>
          <div class="col-6">
            <label class="form-label form-label-sm">Precio $/L</label>
            <input type="number" step="0.0001" min="0" max="9999" name="precio" id="ec-precio"
                   class="form-control form-control-sm" oninput="ecCalc()">
          </div>
        </div>
        <div class="mb-2 mt-2">
          <label class="form-label form-label-sm">Factura total $ <span class="text-muted">(litros × precio)</span></label>
          <input type="number" step="0.01" min="0" max="99999999" name="total_factura" id="ec-total"
                 class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label form-label-sm">Notas <span class="text-muted">(máx. 500)</span></label>
          <textarea name="notas" id="ec-notas" maxlength="500" rows="2" class="form-control form-control-sm"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm btn-primary">💾 Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Arrastre Cierre (ya no en uso, dejado para compat) -->
<div class="modal fade" id="modalArrastreCierre" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="editar_arrastre">
      <input type="hidden" name="cid" id="ar-cid" value="">
      <div class="modal-header" style="background:#1a2e5a;color:#fff">
        <h6 class="modal-title">✏️ Editar — Semana <span id="ar-semana"></span></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label form-label-sm">Arrastre (deuda anterior aplicada)</label>
          <input type="number" step="0.01" min="0" name="arrastre" id="ar-arrastre" class="form-control form-control-sm"></div>
        <div class="mb-2"><label class="form-label form-label-sm">Notas</label>
          <textarea name="notas" id="ar-notas" class="form-control form-control-sm" rows="3"></textarea></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm btn-warning">💾 Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('modalPagarCierre')?.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('pc-cid').value = b.dataset.cid;
  document.getElementById('pc-semana').textContent = b.dataset.semana;
  document.getElementById('pc-pend').textContent = b.dataset.pendiente;
  document.getElementById('pc-factura').textContent  = b.dataset.factura  || '0.00';
  document.getElementById('pc-arrastre').textContent = b.dataset.arrastre || '0.00';
  document.getElementById('pc-abonos').textContent   = b.dataset.abonos   || '0.00';
  document.getElementById('pc-pagprev').textContent  = b.dataset.pagprev  || '0.00';
  document.getElementById('pc-arrastre-info').style.display =
    (parseFloat(b.dataset.arrastre||0) > 0.01) ? '' : 'none';
  document.getElementById('pc-monto').value = b.dataset.pendiente;
  document.getElementById('pc-monto').max = b.dataset.pendiente;
});
function ecCalc(){
  const l = parseFloat(document.getElementById('ec-litros').value) || 0;
  const p = parseFloat(document.getElementById('ec-precio').value) || 0;
  if (l > 0 && p > 0) document.getElementById('ec-total').value = (l * p).toFixed(2);
}
document.getElementById('modalEditarCierre')?.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('ec-cid').value      = b.dataset.cid;
  document.getElementById('ec-semana').textContent = b.dataset.semana;
  document.getElementById('ec-litros').value   = b.dataset.litros;
  document.getElementById('ec-precio').value   = b.dataset.precio;
  document.getElementById('ec-total').value    = b.dataset.factura;
  document.getElementById('ec-notas').value    = b.dataset.notas || '';
});
document.getElementById('modalArrastreCierre')?.addEventListener('show.bs.modal', e => {
  const b = e.relatedTarget;
  document.getElementById('ar-cid').value = b.dataset.cid;
  document.getElementById('ar-semana').textContent = b.dataset.semana;
  document.getElementById('ar-arrastre').value = b.dataset.arrastre;
  document.getElementById('ar-notas').value = b.dataset.notas;
});
</script>

<?php elseif ($tab === 'perfil'):
/* ══════ TAB PERFIL ══════════════════════════════════════════════════════════ */
?>
<div class="row g-3">
  <div class="col-md-5">
    <div class="card">
      <div class="card-header py-2" style="background:#1a2e5a;color:#fff"><strong>👤 Datos del Productor</strong></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tbody>
            <tr><th class="text-muted" style="width:40%">Nombre</th><td class="fw-semibold"><?= h($prod['nombre']) ?></td></tr>
            <tr><th class="text-muted">Documento</th><td><?= h($prod['documento']) ?: '—' ?></td></tr>
            <tr><th class="text-muted">Teléfono</th><td><?= h($prod['telefono']) ?: '—' ?></td></tr>
            <tr><th class="text-muted">Correo</th><td><?= h($prod['correo']) ?: '—' ?></td></tr>
            <tr><th class="text-muted">Dirección</th><td><?= h($prod['direccion']) ?: '—' ?></td></tr>
            <tr><th class="text-muted">Precio/L</th><td class="fw-semibold text-primary">$<?= number_format((float)$prod['precio_litro_usd'],4) ?>/L</td></tr>
            <tr><th class="text-muted">Crédito</th><td><?= (int)$prod['credito_dias'] ?> días</td></tr>
            <tr><th class="text-muted">Estado</th><td><span class="badge <?= $prod_activo?'bg-success':'bg-secondary' ?>"><?= $prod_activo?'Activo':'Inactivo' ?></span></td></tr>
          </tbody>
        </table>
        <div class="mt-3">
          <a href="?p=productores&action=edit&id=<?= $id ?>&ent_tipo=productor" class="btn btn-sm btn-outline-primary">✏️ Editar datos</a>
          <a href="?p=productor_reporte&id=<?= $id ?>" class="btn btn-sm btn-outline-info ms-2">📄 Reporte semanal</a>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="row g-2">
      <div class="col-6">
        <div class="stat-card text-center">
          <div class="stat-val"><?= number_format((float)$glob_litros,1) ?></div>
          <div class="stat-label">Litros totales entregados</div>
        </div>
      </div>
      <div class="col-6">
        <div class="stat-card text-center">
          <div class="stat-val text-primary">$<?= number_format((float)$glob_deuda,2) ?></div>
          <div class="stat-label">Total facturado</div>
        </div>
      </div>
      <div class="col-6">
        <div class="stat-card text-center">
          <div class="stat-val text-success">$<?= number_format($glob_abonos,2) ?></div>
          <div class="stat-label">Total pagado</div>
        </div>
      </div>
      <div class="col-6">
        <div class="stat-card text-center">
          <div class="stat-val <?= $glob_saldo>0?'text-danger':'text-success' ?>">$<?= number_format($glob_saldo,2) ?></div>
          <div class="stat-label">Saldo global pendiente</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- HISTORIAL COMPLETO                                                         -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<?php
$h_desde = $_GET['h_desde'] ?? date('Y-m-01');          // primer día del mes actual
$h_hasta = $_GET['h_hasta'] ?? date('Y-m-d');            // hoy

$ent_all_st = $db->prepare('SELECT * FROM productores_entregas WHERE productor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha DESC');
$ent_all_st->execute([$id, $h_desde, $h_hasta]);
$ent_all = $ent_all_st->fetchAll();

$abo_all_st = $db->prepare('SELECT * FROM productores_abonos WHERE productor_id=? AND fecha>=? AND fecha<=? ORDER BY fecha DESC');
$abo_all_st->execute([$id, $h_desde, $h_hasta]);
$abo_all = $abo_all_st->fetchAll();

$tot_litros  = array_sum(array_column($ent_all, 'litros'));
$tot_factura = array_sum(array_column($ent_all, 'subtotal_usd'));
$tot_pagado  = array_sum(array_column($abo_all,  'monto_usd'));
$saldo_per   = $tot_factura - $tot_pagado;
?>

<!-- Filtro de fechas + botón factura -->
<div class="card mb-3">
  <div class="card-body py-3">
    <form method="get" class="d-flex align-items-end gap-3 flex-wrap">
      <input type="hidden" name="p"   value="productor_detalle">
      <input type="hidden" name="id"  value="<?= $id ?>">
      <input type="hidden" name="tab" value="historial">
      <div>
        <label class="form-label small fw-semibold mb-1">Desde</label>
        <input type="date" name="h_desde" class="form-control form-control-sm" value="<?= h($h_desde) ?>">
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1">Hasta</label>
        <input type="date" name="h_hasta" class="form-control form-control-sm" value="<?= h($h_hasta) ?>">
      </div>
      <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
      <a href="?p=productor_detalle&id=<?=$id?>&tab=historial&h_desde=<?= date('Y-m-01') ?>&h_hasta=<?= date('Y-m-d') ?>"
         class="btn btn-sm btn-outline-secondary">Este mes</a>
      <a href="?p=productor_factura&id=<?=$id?>&desde=<?= urlencode($h_desde) ?>&hasta=<?= urlencode($h_hasta) ?>"
         target="_blank"
         class="btn btn-sm btn-primary ms-auto"
         style="background:#f97316;border-color:#f97316">
        🧾 Generar Factura
      </a>
    </form>
  </div>
</div>

<!-- Resumen del período -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="stat-card text-center py-2"><div class="stat-val fs-5"><?= number_format($tot_litros,1) ?></div><div class="stat-label">Litros período</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card text-center py-2"><div class="stat-val fs-5 text-primary">$<?= number_format($tot_factura,2) ?></div><div class="stat-label">Facturado</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card text-center py-2"><div class="stat-val fs-5 text-success">$<?= number_format($tot_pagado,2) ?></div><div class="stat-label">Pagado</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card text-center py-2"><div class="stat-val fs-5 <?= $saldo_per>0?'text-danger':'text-success' ?>">$<?= number_format($saldo_per,2) ?></div><div class="stat-label">Saldo período</div></div></div>
</div>

<div class="row g-3">
  <div class="col-md-7">
    <div class="card">
      <div class="card-header py-2">📦 Entregas del período</div>
      <div class="card-body p-0" style="max-height:460px;overflow-y:auto">
        <table class="table table-sm mb-0">
          <thead style="position:sticky;top:0;z-index:1"><tr><th>Fecha</th><th>Litros</th><th>Precio</th><th>Total</th></tr></thead>
          <tbody>
          <?php foreach ($ent_all as $e): ?>
          <tr>
            <td><?= date('d/m/Y',strtotime($e['fecha'])) ?></td>
            <td><?= number_format((float)$e['litros'],1) ?></td>
            <td>$<?= number_format((float)$e['precio_litro_usd'],4) ?></td>
            <td class="fw-semibold">$<?= number_format((float)$e['subtotal_usd'],2) ?></td>
          </tr>
          <?php endforeach ?>
          <?php if (!$ent_all): ?><tr><td colspan="4" class="text-center text-muted py-3">Sin entregas en este período</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card">
      <div class="card-header py-2">💵 Pagos del período</div>
      <div class="card-body p-0" style="max-height:460px;overflow-y:auto">
        <table class="table table-sm mb-0">
          <thead style="position:sticky;top:0;z-index:1"><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Monto</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($abo_all as $a):
            $tl = ['efectivo'=>'Efectivo','mercancia'=>'Mercancía','pm_trans'=>'PM/Trans','cortesia'=>'Cortesía','vale'=>'Vale'][$a['tipo']] ?? $a['tipo'];
            $tc = ['efectivo'=>'success','mercancia'=>'warning','pm_trans'=>'info','cortesia'=>'primary','vale'=>'dark'][$a['tipo']] ?? 'secondary';
          ?>
          <tr>
            <td><?= date('d/m/Y',strtotime($a['fecha'])) ?></td>
            <td><span class="badge bg-<?=$tc?> abono-badge"><?= h($tl) ?></span></td>
            <td class="text-muted small"><?= h($a['descripcion'] ?? '') ?: '—' ?></td>
            <td class="fw-semibold">$<?= number_format((float)$a['monto_usd'],2) ?></td>
            <td>
              <button type="button" class="btn btn-xs btn-sm btn-outline-warning py-0 px-1"
                data-bs-toggle="modal" data-bs-target="#modalEditAbono"
                data-aid="<?= $a['id'] ?>"
                data-tipo="<?= h($a['tipo']) ?>"
                data-monto="<?= number_format((float)$a['monto_usd'],2,'.','') ?>"
                data-fecha="<?= h($a['fecha']) ?>"
                data-desc="<?= h($a['descripcion'] ?? '') ?>"
                data-tab="historial">✏️</button>
            </td>
          </tr>
          <?php endforeach ?>
          <?php if (!$abo_all): ?><tr><td colspan="5" class="text-center text-muted py-3">Sin pagos en este período</td></tr><?php endif ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif ?>

<!-- Modal: Editar Pago / Abono -->
<div class="modal fade" id="modalEditAbono" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:#1a2e5a;color:white">
      <h5 class="modal-title">✏️ Modificar Pago</h5>
      <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="upd_abono">
        <input type="hidden" name="aid"   id="eaId">
        <input type="hidden" name="tab"   id="eaTab">
        <div class="mb-2">
          <label class="form-label form-label-sm fw-semibold">Tipo</label>
          <select name="tipo" id="eaTipo" class="form-select form-select-sm">
            <option value="efectivo">💵 Efectivo</option>
            <option value="mercancia">📦 Mercancía</option>
            <option value="pm_trans">🚚 Abono PM/Trans</option>
            <option value="cortesia">🎁 Cortesía</option>
            <option value="vale">📜 Vale</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label form-label-sm fw-semibold">Monto USD</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text">$</span>
            <input type="number" name="monto" id="eaMonto" step="0.01" min="0" class="form-control" required>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label form-label-sm fw-semibold">Fecha del pago</label>
          <input type="date" name="fecha" id="eaFecha" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label form-label-sm fw-semibold">Semana del abono</label>
          <select name="semana_aplicada" id="eaSemana" class="form-select form-select-sm">
            <?php foreach ($semanas_select as $k => $lbl): ?>
              <option value="<?= $k ?>"><?= h($lbl) ?></option>
            <?php endforeach ?>
          </select>
          <small class="text-muted">Semana a la que aplica este pago.</small>
        </div>
        <div class="mb-2">
          <label class="form-label form-label-sm fw-semibold">Descripción</label>
          <input type="text" name="descripcion" id="eaDesc" class="form-control form-control-sm" placeholder="Opcional">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">💾 Guardar cambios</button>
      </div>
    </form>
  </div></div>
</div>
<script>
document.getElementById('modalEditAbono').addEventListener('show.bs.modal', function(e) {
  var b = e.relatedTarget;
  document.getElementById('eaId').value    = b.dataset.aid;
  document.getElementById('eaTab').value   = b.dataset.tab  || 'control';
  document.getElementById('eaMonto').value = b.dataset.monto;
  document.getElementById('eaFecha').value = b.dataset.fecha;
  // Para el select de semana: snap a sábado (la opción del select usa esa fecha)
  var semVal = b.dataset.semana || b.dataset.fecha;
  if (semVal) {
    var d = new Date(semVal + 'T00:00:00');
    var dow = d.getDay(); // 0=Dom,6=Sab
    var off = (dow === 6) ? 0 : ((dow === 0) ? 1 : (dow + 1));
    d.setDate(d.getDate() - off);
    var sat = d.toISOString().slice(0, 10);
    var sel = document.getElementById('eaSemana');
    var found = false;
    for (var j = 0; j < sel.options.length; j++) {
      if (sel.options[j].value === sat) { sel.selectedIndex = j; found = true; break; }
    }
    if (!found && sel.options.length) sel.selectedIndex = 0;
  }
  document.getElementById('eaDesc').value  = b.dataset.desc  || '';
  var sel = document.getElementById('eaTipo');
  for (var i=0; i<sel.options.length; i++) {
    sel.options[i].selected = (sel.options[i].value === b.dataset.tipo);
  }
});
</script>

<?php layout_end(); ?>
