<?php
require_login();
$db = get_db();

// ── Determinar semana activa (Sábado → Viernes, igual que el Excel) ──────────
$semana_raw = $_GET['semana'] ?? '';
if ($semana_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $semana_raw)) {
    $week_start = new DateTime($semana_raw);
    // Ajustar al sábado más cercano anterior o igual
    $dow = (int)$week_start->format('N');
    $off = [6=>0,7=>1,1=>2,2=>3,3=>4,4=>5,5=>6][$dow];
    $week_start->modify("-$off days");
} else {
    $week_start = new DateTime();
    $dow = (int)$week_start->format('N');
    $off = [6=>0,7=>1,1=>2,2=>3,3=>4,4=>5,5=>6][$dow];
    $week_start->modify("-$off days");
}

$week_end = clone $week_start;
$week_end->modify('+6 days');

$ws       = $week_start->format('Y-m-d');
$we       = $week_end->format('Y-m-d');
$prev_ws  = (clone $week_start)->modify('-7 days')->format('Y-m-d');
$next_ws  = (clone $week_start)->modify('+7 days')->format('Y-m-d');

// Días de la semana Sáb→Vie
$dias_labels = ['Sáb','Dom','Lun','Mar','Mié','Jue','Vie'];
$week_dates  = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $week_start; $d->modify("+$i days");
    $week_dates[] = $d->format('Y-m-d');
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save_fila') {
        $pid    = (int)$_POST['proveedor_id'];
        $precio = (float)($_POST['precio'] ?? 0);
        for ($i = 0; $i < 7; $i++) {
            $fecha = $week_dates[$i];
            $cant  = (float)($_POST["d$i"] ?? 0);
            if ($cant > 0) {
                $sub = round($cant * $precio, 4);
                $ex  = $db->prepare('SELECT id FROM proveedores_entregas WHERE proveedor_id=? AND fecha=?');
                $ex->execute([$pid, $fecha]);
                $eid = $ex->fetchColumn();
                if ($eid) {
                    $db->prepare('UPDATE proveedores_entregas SET cantidad=?,precio_usd=?,subtotal_usd=? WHERE id=?')
                       ->execute([$cant, $precio, $sub, $eid]);
                } else {
                    $db->prepare('INSERT INTO proveedores_entregas(proveedor_id,fecha,cantidad,precio_usd,subtotal_usd) VALUES(?,?,?,?,?)')
                       ->execute([$pid, $fecha, $cant, $precio, $sub]);
                }
            } else {
                // Si viene vacío, eliminar si existe
                $db->prepare('DELETE FROM proveedores_entregas WHERE proveedor_id=? AND fecha=?')
                   ->execute([$pid, $fecha]);
            }
        }
        flash("Fila guardada.");
        header("Location: ?p=proveedores_control&semana=$ws"); exit;
    }

    if ($act === 'save_pago') {
        $pid   = (int)$_POST['proveedor_id'];
        $tipo  = $_POST['tipo']  ?? 'efectivo';
        $monto = (float)($_POST['monto'] ?? 0);
        $nota  = trim($_POST['nota'] ?? '');
        $fecha = $_POST['fecha'] ?? $ws;
        if ($monto > 0) {
            $db->prepare('INSERT INTO proveedores_pagos(proveedor_id,fecha,tipo,monto_usd,descripcion) VALUES(?,?,?,?,?)')
               ->execute([$pid, $fecha, $tipo, $monto, $nota]);
            flash("Pago registrado.");
        }
        header("Location: ?p=proveedores_control&semana=$ws"); exit;
    }

    if ($act === 'del_pago') {
        $pid = (int)$_POST['proveedor_id'];
        $db->prepare('DELETE FROM proveedores_pagos WHERE id=? AND proveedor_id=?')
           ->execute([(int)$_POST['pago_id'], $pid]);
        flash("Pago eliminado.", "warning");
        header("Location: ?p=proveedores_control&semana=$ws"); exit;
    }

    header("Location: ?p=proveedores_control&semana=$ws"); exit;
}

// ── Cargar datos ──────────────────────────────────────────────────────────────
$proveedores = $db->query('SELECT id, nombre, contacto, telefono FROM proveedores ORDER BY nombre')->fetchAll();

// Entregas de la semana → [proveedor_id][fecha] = row
$ent_rows = $db->prepare('SELECT * FROM proveedores_entregas WHERE fecha>=? AND fecha<=?');
$ent_rows->execute([$ws, $we]);
$ent_map = [];
foreach ($ent_rows->fetchAll() as $e) {
    $ent_map[$e['proveedor_id']][$e['fecha']] = $e;
}

// Pagos de la semana → [proveedor_id][] = row
$pag_rows = $db->prepare('SELECT * FROM proveedores_pagos WHERE fecha>=? AND fecha<=? ORDER BY proveedor_id,fecha');
$pag_rows->execute([$ws, $we]);
$pag_map = [];
foreach ($pag_rows->fetchAll() as $p) {
    $pag_map[$p['proveedor_id']][] = $p;
}

// Saldo pendiente acumulado hasta antes de esta semana (por proveedor)
$sd = $db->prepare('SELECT proveedor_id, COALESCE(SUM(subtotal_usd),0) as tot FROM proveedores_entregas WHERE fecha<? GROUP BY proveedor_id');
$sd->execute([$ws]);
$deuda_ant = [];
foreach ($sd->fetchAll() as $r) { $deuda_ant[$r['proveedor_id']] = (float)$r['tot']; }

$sp = $db->prepare('SELECT proveedor_id, COALESCE(SUM(monto_usd),0) as tot FROM proveedores_pagos WHERE fecha<? GROUP BY proveedor_id');
$sp->execute([$ws]);
$pagos_ant = [];
foreach ($sp->fetchAll() as $r) { $pagos_ant[$r['proveedor_id']] = (float)$r['tot']; }

// ── Calcular totales por proveedor ────────────────────────────────────────────
$filas = [];
foreach ($proveedores as $pv) {
    $pid = $pv['id'];
    $ents   = $ent_map[$pid] ?? [];
    $pags   = $pag_map[$pid] ?? [];

    $cantidades = [];
    $total_cant = 0; $total_pagar = 0;
    $precio_ref = 0;
    for ($i = 0; $i < 7; $i++) {
        $fecha = $week_dates[$i];
        $ent   = $ents[$fecha] ?? null;
        $c = $ent ? (float)$ent['cantidad'] : 0;
        $p = $ent ? (float)$ent['precio_usd'] : 0;
        if ($p > 0) $precio_ref = $p;
        $cantidades[$i] = $c;
        $total_cant  += $c;
        $total_pagar += $ent ? (float)$ent['subtotal_usd'] : 0;
    }

    $mercancia  = 0; $pm_trans = 0; $efectivo = 0;
    foreach ($pags as $pg) {
        if ($pg['tipo'] === 'mercancia') $mercancia  += (float)$pg['monto_usd'];
        if ($pg['tipo'] === 'pm_trans')  $pm_trans   += (float)$pg['monto_usd'];
        if ($pg['tipo'] === 'efectivo')  $efectivo   += (float)$pg['monto_usd'];
    }
    $saldo_pend    = ($deuda_ant[$pid] ?? 0) - ($pagos_ant[$pid] ?? 0);
    $total_abonado = $mercancia + $pm_trans + $efectivo;
    $pendiente     = $total_pagar + $saldo_pend - $total_abonado;

    $filas[$pid] = [
        'prov'          => $pv,
        'cantidades'    => $cantidades,
        'precio_ref'    => $precio_ref,
        'total_cant'    => $total_cant,
        'total_pagar'   => $total_pagar,
        'saldo_pend'    => $saldo_pend,
        'mercancia'     => $mercancia,
        'pm_trans'      => $pm_trans,
        'efectivo'      => $efectivo,
        'total_abonado' => $total_abonado,
        'pendiente'     => $pendiente,
        'pagos'         => $pags,
    ];
}

$tasa = get_tasa();

// ── Exportar CSV (Excel) ───────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $fname = 'control-proveedores-' . $ws . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['CONTROL SEMANAL DE PROVEEDORES — El Castillo del Queso'], ';');
    fputcsv($out, ['RIF:', 'V-286013356', 'Semana:', $week_start->format('d/m/Y') . ' al ' . $week_end->format('d/m/Y')], ';');
    fputcsv($out, ['Tasa Bs/USD:', number_format($tasa, 2, '.', '')], ';');
    fputcsv($out, [], ';');
    $header = ['#', 'Proveedor', 'Precio USD/L'];
    foreach ($dias_labels as $i => $dl) {
        $header[] = $dl . ' ' . (new DateTime($week_dates[$i]))->format('d/m');
    }
    $header = array_merge($header, ['Total USD', 'Total Bs', 'Saldo Ant.', 'Mercancía', 'PM/Trans', 'Efectivo', 'Total Abonado', 'Pendiente USD', 'Pendiente Bs']);
    fputcsv($out, $header, ';');
    $no = 0;
    foreach ($filas as $pid => $f) {
        $no++;
        $row = [$no, $f['prov']['nombre'], $f['precio_ref'] > 0 ? number_format($f['precio_ref'], 4, '.', '') : ''];
        for ($i = 0; $i < 7; $i++) {
            $row[] = $f['cantidades'][$i] > 0 ? number_format($f['cantidades'][$i], 3, '.', '') : '';
        }
        $row[] = number_format($f['total_pagar'], 2, '.', '');
        $row[] = number_format($f['total_pagar'] * $tasa, 2, '.', '');
        $row[] = number_format($f['saldo_pend'], 2, '.', '');
        $row[] = $f['mercancia']  > 0 ? number_format($f['mercancia'], 2, '.', '') : '';
        $row[] = $f['pm_trans']   > 0 ? number_format($f['pm_trans'],  2, '.', '') : '';
        $row[] = $f['efectivo']   > 0 ? number_format($f['efectivo'],  2, '.', '') : '';
        $row[] = number_format($f['total_abonado'], 2, '.', '');
        $row[] = number_format($f['pendiente'], 2, '.', '');
        $row[] = number_format($f['pendiente'] * $tasa, 2, '.', '');
        fputcsv($out, $row, ';');
    }
    fputcsv($out, [], ';');
    // Totales
    $tot_cols2 = array_fill(0, 7, 0);
    $tot_tp = $tot_sp = $tot_m = $tot_p = $tot_e = $tot_ab = $tot_pe = 0;
    foreach ($filas as $f) {
        for ($i = 0; $i < 7; $i++) $tot_cols2[$i] += $f['cantidades'][$i];
        $tot_tp += $f['total_pagar']; $tot_sp += $f['saldo_pend'];
        $tot_m  += $f['mercancia'];   $tot_p  += $f['pm_trans'];
        $tot_e  += $f['efectivo'];    $tot_ab += $f['total_abonado'];
        $tot_pe += $f['pendiente'];
    }
    $trow = ['', 'TOTALES', ''];
    for ($i = 0; $i < 7; $i++) $trow[] = $tot_cols2[$i] > 0 ? number_format($tot_cols2[$i], 1, '.', '') : '';
    $trow = array_merge($trow, [
        number_format($tot_tp, 2, '.', ''), number_format($tot_tp * $tasa, 2, '.', ''),
        number_format($tot_sp, 2, '.', ''),
        number_format($tot_m,  2, '.', ''), number_format($tot_p,  2, '.', ''),
        number_format($tot_e,  2, '.', ''), number_format($tot_ab, 2, '.', ''),
        number_format($tot_pe, 2, '.', ''), number_format($tot_pe * $tasa, 2, '.', ''),
    ]);
    fputcsv($out, $trow, ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Generado:', date('d/m/Y H:i')], ';');
    fclose($out);
    exit;
}

// ── Modo impresión / PDF ───────────────────────────────────────────────────
if (($_GET['print'] ?? '') === '1'):
    $tasa = get_tasa();
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Control Semanal Proveedores – <?= $week_start->format('d/m/Y') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  @page { size: Letter landscape; margin: 8mm; }
  body { background:#f5f5f5; font-family:'Segoe UI',sans-serif; font-size:.78rem; }
  .rep-wrap { max-width:1100px; margin:16px auto; background:#fff; border-radius:10px;
              box-shadow:0 2px 16px rgba(0,0,0,.1); overflow:hidden; }
  .rep-header { background:linear-gradient(135deg,#1a2e5a,#2a4a8a); color:#fff;
                padding:18px 28px; display:flex; justify-content:space-between; align-items:center; }
  .logo-img { height:70px; width:70px; object-fit:contain; background:#fff; border-radius:6px; padding:3px; }
  .rep-body { padding:20px 24px; }
  table { border-collapse:collapse; width:100%; font-size:.72rem; }
  thead th { background:#1a2e5a; color:#fff; padding:5px 6px; text-align:center;
             font-weight:600; font-size:.66rem; letter-spacing:.03em; white-space:nowrap; }
  thead th.left { text-align:left; }
  tbody td { border:1px solid #dee2e6; padding:4px 6px; vertical-align:middle; white-space:nowrap; }
  tbody td.nom { font-weight:600; color:#1a2e5a; max-width:160px; overflow:hidden; text-overflow:ellipsis; }
  tbody td.r { text-align:right; font-family:monospace; }
  tbody td.c { text-align:center; }
  tfoot td { background:#1a2e5a; color:#fff; font-weight:700; text-align:right; padding:5px 6px; white-space:nowrap; }
  tfoot td.left { text-align:left; }
  .pos { color:#dc2626; } .neg { color:#16a34a; }
  .pie { text-align:center; font-size:.7rem; color:#aaa; padding:10px 24px 16px; border-top:1px solid #eee; }
  .no-print { max-width:1100px; margin:12px auto; display:flex; gap:10px; }
  @media print {
    body { background:#fff; font-size:.68rem; margin:0; }
    .no-print { display:none !important; }
    .rep-wrap { box-shadow:none; border-radius:0; margin:0; max-width:100%; }
    table { font-size:.65rem; }
    thead th, tfoot td, .rep-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()" class="btn btn-primary btn-sm">🖨 Imprimir</button>
  <button onclick="exportarPDF()" class="btn btn-outline-danger btn-sm">📄 Exportar PDF</button>
  <a href="?p=proveedores_control&semana=<?= $ws ?>&export=csv" class="btn btn-outline-success btn-sm">📊 Excel / CSV</a>
  <a href="?p=proveedores_control&semana=<?= $ws ?>" class="btn btn-outline-secondary btn-sm">← Volver</a>
</div>

<div class="rep-wrap" id="repDoc">
  <div class="rep-header">
    <div class="d-flex align-items-center gap-3">
      <img src="public/logo.jpg" class="logo-img" alt="Logo">
      <div>
        <div style="font-size:1.2rem;font-weight:700">El Castillo del Queso</div>
        <div style="font-size:.75rem;opacity:.8">Productora Artesanal · RIF: V-286013356</div>
      </div>
    </div>
    <div class="text-end">
      <div style="font-size:.85rem;opacity:.8">Control Semanal de Proveedores</div>
      <div style="font-size:1.2rem;font-weight:700"><?= $week_start->format('d/m/Y') ?> — <?= $week_end->format('d/m/Y') ?></div>
      <div style="font-size:.75rem;opacity:.7">Tasa: Bs. <?= number_format($tasa,2,'.',',') ?>/USD · <?= date('d/m/Y H:i') ?></div>
    </div>
  </div>

  <div class="rep-body">
    <table>
      <thead>
        <tr>
          <th class="left">#</th>
          <th class="left" style="min-width:130px">Proveedor</th>
          <th>Precio/L</th>
          <?php foreach ($dias_labels as $i => $dl): ?>
          <th><?= $dl ?><br><span style="font-size:.6rem;opacity:.8"><?= (new DateTime($week_dates[$i]))->format('d/m') ?></span></th>
          <?php endforeach ?>
          <th>Total L</th>
          <th>Total $</th>
          <th>Total Bs</th>
          <th>Saldo Ant.</th>
          <th>Mercancía</th>
          <th>PM/Trans</th>
          <th>Efectivo</th>
          <th>Abonado</th>
          <th>Pendiente $</th>
          <th>Pendiente Bs</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $no = 0;
      $gt_cols = array_fill(0, 7, 0);
      $gt_litros = $gt_tp = $gt_sp = $gt_m = $gt_pt = $gt_e = $gt_ab = $gt_pe = 0;
      foreach ($filas as $pid => $f):
          $no++;
          $gt_litros += $f['total_cant'];
          for ($i = 0; $i < 7; $i++) $gt_cols[$i] += $f['cantidades'][$i];
          $gt_tp += $f['total_pagar']; $gt_sp += $f['saldo_pend'];
          $gt_m  += $f['mercancia'];   $gt_pt += $f['pm_trans'];
          $gt_e  += $f['efectivo'];    $gt_ab += $f['total_abonado'];
          $gt_pe += $f['pendiente'];
      ?>
      <tr>
        <td class="c"><?= $no ?></td>
        <td class="nom"><?= h(mb_strimwidth($f['prov']['nombre'], 0, 30, '…')) ?>
          <?php if ($f['prov']['telefono']): ?><br><span style="color:#94a3b8;font-size:.6rem"><?= h($f['prov']['telefono']) ?></span><?php endif ?>
        </td>
        <td class="c"><?= $f['precio_ref'] > 0 ? '$'.number_format($f['precio_ref'],4,'.',',') : '—' ?></td>
        <?php for ($i = 0; $i < 7; $i++): ?>
        <td class="c"><?= $f['cantidades'][$i] > 0 ? number_format($f['cantidades'][$i],1,'.',',') : '—' ?></td>
        <?php endfor ?>
        <td class="r"><?= $f['total_cant'] > 0 ? number_format($f['total_cant'],1,'.',',') : '—' ?></td>
        <td class="r">$<?= number_format($f['total_pagar'],2,'.',',') ?></td>
        <td class="r">Bs.<?= number_format($f['total_pagar']*$tasa,0,'.',',') ?></td>
        <td class="r <?= $f['saldo_pend']>0?'pos':'' ?>"><?= $f['saldo_pend']!=0 ? '$'.number_format(abs($f['saldo_pend']),2,'.',',') : '—' ?></td>
        <td class="r"><?= $f['mercancia']>0  ? '$'.number_format($f['mercancia'],2,'.',',') : '—' ?></td>
        <td class="r"><?= $f['pm_trans']>0   ? '$'.number_format($f['pm_trans'],2,'.',',')  : '—' ?></td>
        <td class="r"><?= $f['efectivo']>0   ? '$'.number_format($f['efectivo'],2,'.',',')  : '—' ?></td>
        <td class="r">$<?= number_format($f['total_abonado'],2,'.',',') ?></td>
        <td class="r fw-bold <?= $f['pendiente']>0?'pos':'neg' ?>">$<?= number_format($f['pendiente'],2,'.',',') ?></td>
        <td class="r <?= $f['pendiente']>0?'pos':'neg' ?>">Bs.<?= number_format($f['pendiente']*$tasa,0,'.',',') ?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" class="left">TOTALES</td>
          <td></td>
          <?php for ($i=0;$i<7;$i++): ?>
          <td><?= $gt_cols[$i]>0 ? number_format($gt_cols[$i],1,'.',',') : '—' ?></td>
          <?php endfor ?>
          <td><?= number_format($gt_litros,1,'.',',') ?></td>
          <td>$<?= number_format($gt_tp,2,'.',',') ?></td>
          <td>Bs.<?= number_format($gt_tp*$tasa,0,'.',',') ?></td>
          <td>$<?= number_format($gt_sp,2,'.',',') ?></td>
          <td>$<?= number_format($gt_m,2,'.',',') ?></td>
          <td>$<?= number_format($gt_pt,2,'.',',') ?></td>
          <td>$<?= number_format($gt_e,2,'.',',') ?></td>
          <td>$<?= number_format($gt_ab,2,'.',',') ?></td>
          <td>$<?= number_format($gt_pe,2,'.',',') ?></td>
          <td>Bs.<?= number_format($gt_pe*$tasa,0,'.',',') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="pie">
    El Castillo del Queso · RIF V-286013356 · Quesos Artesanales Venezolanos<br>
    Reporte generado por ASERP · <?= date('d/m/Y H:i') ?>
  </div>
</div>

<script>
function exportarPDF() {
  html2pdf().set({
    margin: 0.3,
    filename: 'control-proveedores-<?= $ws ?>.pdf',
    image:    { type:'jpeg', quality:0.97 },
    html2canvas: { scale:2, useCORS:true },
    jsPDF:    { unit:'in', format:'letter', orientation:'landscape' }
  }).from(document.getElementById('repDoc')).save();
}
</script>
</body>
</html>
<?php exit; endif;

layout_start('Control Semanal – Proveedores', 'proveedores');
?>
<style>
.ctrl-table { border-collapse: collapse; font-size: .8rem; min-width: 1400px; }
.ctrl-table th { background:#1a2e5a; color:#fff; text-align:center; white-space:nowrap; padding:6px 8px; position:sticky; top:0; z-index:10; font-weight:600; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase; }
.ctrl-table th.col-fixed { position:sticky; left:0; z-index:20; background:#1a2e5a; }
.ctrl-table th.col-no  { left:0; min-width:36px; }
.ctrl-table th.col-nom { left:36px; min-width:180px; text-align:left; }
.ctrl-table td { border:1px solid #dee2e6; padding:4px 6px; vertical-align:middle; white-space:nowrap; }
.ctrl-table td.col-fixed { position:sticky; background:#fff; z-index:5; }
.ctrl-table td.col-no  { left:0; text-align:center; color:#64748b; font-weight:600; }
.ctrl-table td.col-nom { left:36px; font-weight:600; color:#1a2e5a; min-width:180px; max-width:220px; overflow:hidden; text-overflow:ellipsis; }
.ctrl-table tr:hover td { background:#f8faff; }
.ctrl-table tr:hover td.col-fixed { background:#f0f4ff; }
.day-inp { width:68px; text-align:right; border:1px solid #cbd5e1; border-radius:4px; padding:2px 4px; font-size:.8rem; }
.day-inp:focus { outline:2px solid #f97316; border-color:#f97316; }
.precio-inp { width:62px; text-align:right; border:1px solid #cbd5e1; border-radius:4px; padding:2px 4px; font-size:.8rem; background:#fffbf0; }
.col-money { text-align:right; font-family:monospace; }
.col-saldo { color:#b45309; font-weight:600; }
.col-pend  { font-weight:700; }
.col-pend.neg { color:#16a34a; }
.col-pend.pos { color:#dc2626; }
.col-total { background:#f0f4ff; font-weight:700; color:#1a2e5a; }
.tfoot-row td { background:#1a2e5a; color:#fff; font-weight:700; text-align:right; }
.tfoot-row td.col-fixed { background:#1a2e5a !important; }
.btn-pago { font-size:.72rem; padding:1px 7px; border-radius:20px; }
.semana-nav { display:flex; align-items:center; gap:.5rem; }
</style>

<!-- Cabecera -->
<div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
  <div class="d-flex align-items-center gap-2">
    <a href="?p=proveedores" class="btn btn-sm btn-outline-secondary">← Proveedores</a>
    <h5 class="mb-0 fw-bold" style="color:#1a2e5a">📋 Control Semanal de Proveedores</h5>
  </div>
  <div class="d-flex gap-2">
    <a href="?p=proveedores_control&semana=<?= $ws ?>&print=1" target="_blank"
       class="btn btn-sm btn-primary">🖨 PDF</a>
    <a href="?p=proveedores_control&semana=<?= $ws ?>&export=csv"
       class="btn btn-sm btn-success">📊 Excel</a>
  </div>
</div>

<!-- Navegación de semana -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <strong style="color:#1a2e5a">📅 Semana:</strong>
      <div class="semana-nav">
        <a href="?p=proveedores_control&semana=<?= $prev_ws ?>" class="btn btn-sm btn-outline-secondary">‹ Ant.</a>
        <form method="get" class="d-flex align-items-center gap-1">
          <input type="hidden" name="p" value="proveedores_control">
          <input type="date" name="semana" class="form-control form-control-sm" value="<?= $ws ?>"
                 onchange="this.form.submit()" style="width:150px">
        </form>
        <a href="?p=proveedores_control&semana=<?= $next_ws ?>" class="btn btn-sm btn-outline-secondary">Sig. ›</a>
      </div>
      <span class="badge" style="background:#1a2e5a;font-size:.85rem">
        <?= $week_start->format('d/m/Y') ?> — <?= $week_end->format('d/m/Y') ?>
      </span>
      <span class="text-muted small">Sábado → Viernes</span>
    </div>
  </div>
</div>

<!-- Leyenda pagos -->
<div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
  <small class="text-muted fw-semibold">Abonos:</small>
  <span class="badge bg-warning text-dark">📦 Mercancía</span>
  <span class="badge bg-info text-dark">🚚 PM/Trans</span>
  <span class="badge bg-success">💵 Efectivo</span>
  <small class="text-muted ms-2">* Edita las cantidades y presiona 💾 para guardar cada fila</small>
</div>

<!-- Tabla maestra -->
<div style="overflow-x:auto; border-radius:8px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,.06)">
<table class="ctrl-table w-100">
<thead>
  <tr>
    <th class="col-fixed col-no">#</th>
    <th class="col-fixed col-nom">Proveedor</th>
    <th>Precio&nbsp;$</th>
    <?php foreach ($dias_labels as $i => $dl): ?>
    <th>
      <?= $dl ?><br>
      <span style="font-size:.65rem;opacity:.8"><?= (new DateTime($week_dates[$i]))->format('d/m') ?></span>
    </th>
    <?php endforeach ?>
    <th>Total&nbsp;$</th>
    <th>Saldo<br>Pend.</th>
    <th>Mercancía</th>
    <th>PM/Trans</th>
    <th>Efectivo</th>
    <th>Total<br>Abonado</th>
    <th>Pendiente<br>a&nbsp;Pagar</th>
    <th>Acciones</th>
  </tr>
</thead>
<tbody>
<?php
$tot_cols = array_fill(0, 7, 0);
$tot_total_pagar = $tot_saldo = $tot_merc = $tot_pm = $tot_efec = $tot_abonado = $tot_pend = 0;
$no = 0;
foreach ($filas as $pid => $f):
    $no++;
    $pv = $f['prov'];
    $today = date('Y-m-d');
    for ($i=0;$i<7;$i++) $tot_cols[$i] += $f['cantidades'][$i];
    $tot_total_pagar += $f['total_pagar'];
    $tot_saldo       += $f['saldo_pend'];
    $tot_merc        += $f['mercancia'];
    $tot_pm          += $f['pm_trans'];
    $tot_efec        += $f['efectivo'];
    $tot_abonado     += $f['total_abonado'];
    $tot_pend        += $f['pendiente'];
?>
<tr id="fila-<?= $pid ?>">
  <td class="col-fixed col-no"><?= $no ?></td>
  <td class="col-fixed col-nom" title="<?= h($pv['nombre']) ?>">
    <?= h(mb_strimwidth($pv['nombre'], 0, 28, '…')) ?>
    <?php if ($pv['telefono']): ?>
      <br><small class="text-muted"><?= h($pv['telefono']) ?></small>
    <?php endif ?>
  </td>
  <!-- Formulario de fila -->
  <form method="post" id="form-<?= $pid ?>">
    <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
    <input type="hidden" name="action"       value="save_fila">
    <input type="hidden" name="proveedor_id" value="<?= $pid ?>">
    <td>
      <input type="number" name="precio" id="prec-<?= $pid ?>"
             class="precio-inp" step="0.0001" min="0"
             value="<?= $f['precio_ref'] > 0 ? number_format($f['precio_ref'],4,'.','') : '' ?>"
             placeholder="0.00"
             oninput="recalcFila(<?= $pid ?>)">
    </td>
    <?php for ($i = 0; $i < 7; $i++):
      $c = $f['cantidades'][$i];
      $is_today = ($week_dates[$i] === $today);
    ?>
    <td style="<?= $is_today ? 'background:rgba(249,115,22,.07)' : '' ?>">
      <input type="number" name="d<?= $i ?>" id="d<?= $pid ?>_<?= $i ?>"
             class="day-inp" step="0.001" min="0"
             value="<?= $c > 0 ? number_format($c,3,'.','') : '' ?>"
             placeholder="—"
             oninput="recalcFila(<?= $pid ?>)">
    </td>
    <?php endfor ?>
  </form>
  <td class="col-money col-total" id="tot-<?= $pid ?>">
    $<?= number_format($f['total_pagar'],2) ?>
  </td>
  <td class="col-money col-saldo">
    <?= $f['saldo_pend'] != 0 ? '$'.number_format(abs($f['saldo_pend']),2).($f['saldo_pend']<0?' ✓':'') : '—' ?>
  </td>
  <td class="col-money text-warning">
    <?= $f['mercancia'] > 0 ? '$'.number_format($f['mercancia'],2) : '—' ?>
  </td>
  <td class="col-money text-info">
    <?= $f['pm_trans'] > 0 ? '$'.number_format($f['pm_trans'],2) : '—' ?>
  </td>
  <td class="col-money text-success">
    <?= $f['efectivo'] > 0 ? '$'.number_format($f['efectivo'],2) : '—' ?>
  </td>
  <td class="col-money">
    <?= $f['total_abonado'] > 0 ? '$'.number_format($f['total_abonado'],2) : '—' ?>
  </td>
  <td class="col-money col-pend <?= $f['pendiente'] <= 0 ? 'neg' : 'pos' ?>">
    $<?= number_format($f['pendiente'],2) ?>
  </td>
  <td style="white-space:nowrap">
    <button type="submit" form="form-<?= $pid ?>"
            class="btn btn-sm btn-success btn-pago" title="Guardar fila">💾</button>
    <button class="btn btn-sm btn-outline-primary btn-pago ms-1"
            title="Registrar abono"
            onclick="openPago(<?= $pid ?>, '<?= h($pv['nombre']) ?>')">+$</button>
    <?php if ($f['pagos']): ?>
    <button class="btn btn-sm btn-outline-secondary btn-pago ms-1"
            title="Ver pagos"
            onclick="verPagos(<?= $pid ?>, '<?= h($pv['nombre']) ?>')">
      📄<?= count($f['pagos']) ?>
    </button>
    <?php endif ?>
  </td>
</tr>
<?php endforeach ?>
</tbody>
<tfoot>
  <tr class="tfoot-row">
    <td class="col-fixed col-no" style="text-align:center;background:#1a2e5a!important">—</td>
    <td class="col-fixed col-nom" style="text-align:left!important;background:#1a2e5a!important">TOTALES</td>
    <td></td>
    <?php for ($i=0;$i<7;$i++): ?>
    <td><?= $tot_cols[$i] > 0 ? number_format($tot_cols[$i],1) : '—' ?></td>
    <?php endfor ?>
    <td>$<?= number_format($tot_total_pagar,2) ?></td>
    <td>$<?= number_format($tot_saldo,2) ?></td>
    <td>$<?= number_format($tot_merc,2) ?></td>
    <td>$<?= number_format($tot_pm,2) ?></td>
    <td>$<?= number_format($tot_efec,2) ?></td>
    <td>$<?= number_format($tot_abonado,2) ?></td>
    <td>$<?= number_format($tot_pend,2) ?></td>
    <td></td>
  </tr>
</tfoot>
</table>
</div>

<!-- Modal: Registrar Pago -->
<div class="modal fade" id="modalPago" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#1a2e5a;color:#fff">
        <h6 class="modal-title mb-0">💰 Registrar Abono</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
        <input type="hidden" name="action"       value="save_pago">
        <input type="hidden" name="proveedor_id" id="pago_pid" value="">
        <div class="modal-body">
          <p class="fw-bold small mb-2" id="pago_nombre"></p>
          <div class="mb-2">
            <label class="form-label form-label-sm">Tipo</label>
            <select name="tipo" class="form-select form-select-sm">
              <option value="mercancia">📦 Mercancía</option>
              <option value="pm_trans">🚚 Abono PM/Trans</option>
              <option value="efectivo">💵 Efectivo</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Monto USD</label>
            <input type="number" name="monto" step="0.01" min="0.01"
                   class="form-control form-control-sm" required placeholder="0.00">
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Fecha</label>
            <input type="date" name="fecha" class="form-control form-control-sm"
                   value="<?= date('Y-m-d') >= $ws && date('Y-m-d') <= $we ? date('Y-m-d') : $ws ?>">
          </div>
          <div class="mb-1">
            <label class="form-label form-label-sm">Nota (opcional)</label>
            <input type="text" name="nota" class="form-control form-control-sm" placeholder="Referencia…">
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="submit" class="btn btn-primary btn-sm w-100">Guardar Abono</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Ver pagos -->
<div class="modal fade" id="modalVerPagos" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#1a2e5a;color:#fff">
        <h6 class="modal-title mb-0">📄 Abonos de la semana</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <p class="fw-bold small px-3 pt-2 mb-1" id="ver_nombre"></p>
        <table class="table table-sm mb-0">
          <thead><tr><th>Tipo</th><th>Monto</th><th>Fecha</th><th>Nota</th><th></th></tr></thead>
          <tbody id="ver_pagos_body"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
// Datos de pagos para el modal ver pagos
const PAGOS_DATA = {
<?php foreach ($filas as $pid => $f): ?>
  <?= $pid ?>: {
    nombre: <?= json_encode($f['prov']['nombre']) ?>,
    pagos: <?= json_encode(array_map(fn($p) => [
        'id'   => $p['id'],
        'tipo' => $p['tipo'],
        'monto'=> number_format((float)$p['monto_usd'],2),
        'fecha'=> date('d/m', strtotime($p['fecha'])),
        'nota' => $p['descripcion'] ?? '',
    ], $f['pagos'])) ?>
  },
<?php endforeach ?>
};

// Recalcular total de fila en tiempo real
function recalcFila(pid) {
  var precio = parseFloat(document.getElementById('prec-'+pid).value) || 0;
  var tot = 0;
  for (var i = 0; i < 7; i++) {
    var el = document.getElementById('d'+pid+'_'+i);
    tot += (parseFloat(el.value) || 0) * precio;
  }
  document.getElementById('tot-'+pid).textContent = '$' + tot.toFixed(2);
}

// Abrir modal de pago
function openPago(pid, nombre) {
  document.getElementById('pago_pid').value = pid;
  document.getElementById('pago_nombre').textContent = nombre;
  new bootstrap.Modal(document.getElementById('modalPago')).show();
}

// Abrir modal ver pagos
function verPagos(pid, nombre) {
  var d = PAGOS_DATA[pid];
  document.getElementById('ver_nombre').textContent = nombre;
  var tbody = document.getElementById('ver_pagos_body');
  tbody.innerHTML = '';
  var tipoLabel = {mercancia:'📦 Mercancía', pm_trans:'🚚 PM/Trans', efectivo:'💵 Efectivo'};
  var tipoBadge = {mercancia:'warning', pm_trans:'info', efectivo:'success'};
  d.pagos.forEach(function(p) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><span class="badge bg-'+tipoBadge[p.tipo]+' text-dark">'+tipoLabel[p.tipo]+'</span></td>' +
      '<td class="fw-bold">$'+p.monto+'</td>' +
      '<td class="text-muted">'+p.fecha+'</td>' +
      '<td class="text-muted small">'+p.nota+'</td>' +
      '<td><form method="post" onsubmit="return confirm(\'¿Eliminar?\')">'+
        '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">'+
        '<input type="hidden" name="action" value="del_pago">'+
        '<input type="hidden" name="proveedor_id" value="'+pid+'">'+
        '<input type="hidden" name="pago_id" value="'+p.id+'">'+
        '<button class="btn btn-xs btn-sm btn-outline-danger py-0 px-1">✕</button>'+
      '</form></td>';
    tbody.appendChild(tr);
  });
  new bootstrap.Modal(document.getElementById('modalVerPagos')).show();
}
</script>

<?php layout_end(); ?>
