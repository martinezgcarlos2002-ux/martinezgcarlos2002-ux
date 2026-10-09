<?php
require_login();
$db = get_db();

if (!function_exists('save_config_produccion')) {
    function ensure_config_produccion_table(): void {
        static $done = false; if ($done) return;
        try {
            $db = get_db();
            if (is_mysql()) {
                $db->exec("CREATE TABLE IF NOT EXISTS config_produccion (clave VARCHAR(50) PRIMARY KEY, valor MEDIUMTEXT NOT NULL, actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS config_produccion (clave VARCHAR(50) PRIMARY KEY, valor TEXT NOT NULL, actualizado_en TIMESTAMP NOT NULL DEFAULT NOW())");
            }
            $done = true;
        } catch (Throwable $e) { error_log('[ensure_config_produccion_table] ' . $e->getMessage()); }
    }
    function get_config_produccion(string $clave = 'panel'): ?array {
        ensure_config_produccion_table();
        try {
            $db = get_db();
            $st = $db->prepare("SELECT valor FROM config_produccion WHERE clave=?");
            $st->execute([$clave]);
            $v = $st->fetchColumn();
            if ($v) { $d = json_decode($v, true); if (is_array($d)) return $d; }
        } catch (Throwable $e) { error_log('[get_config_produccion] ' . $e->getMessage()); }
        return null;
    }
    function save_config_produccion(array $data, string $clave = 'panel'): bool {
        ensure_config_produccion_table();
        try {
            $db = get_db();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (is_mysql()) {
                $db->prepare("INSERT INTO config_produccion (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), actualizado_en=CURRENT_TIMESTAMP")->execute([$clave, $json]);
            } else {
                $db->prepare("INSERT INTO config_produccion (clave, valor) VALUES (?,?) ON CONFLICT (clave) DO UPDATE SET valor=EXCLUDED.valor, actualizado_en=NOW()")->execute([$clave, $json]);
            }
            return true;
        } catch (Throwable $e) { error_log('[save_config_produccion] ' . $e->getMessage()); return false; }
    }
}

if (($_POST['accion'] ?? '') === 'guardar_produccion') {
    header('Content-Type: application/json');
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $data = json_decode($_POST['config'] ?? '', true);
    echo json_encode(['ok' => is_array($data) ? save_config_produccion($data, 'panel') : false]);
    exit;
}
$cc_cfg = get_config_produccion('panel');

$rows = $db->query("SELECT id, nombre, estado FROM productores ORDER BY nombre ASC")->fetchAll();
$prods = array_values(array_filter($rows, fn($r) => !isset($r['estado']) || bool_estado($r['estado'])));
$total = count($prods);

// ── Leche del día (automático desde las entregas registradas) ────────────────
$fecha_sel = $_GET['fecha'] ?? date('Y-m-d');
$fecha_dt  = DateTime::createFromFormat('Y-m-d', (string)$fecha_sel);
if (!$fecha_dt || $fecha_dt->format('Y-m-d') !== $fecha_sel) $fecha_sel = date('Y-m-d');
$leche_litros = 0.0; $leche_costo = 0.0; $leche_traslado = 0.0;
try {
    $ent = $db->prepare("SELECT COALESCE(SUM(e.litros),0) AS litros, COALESCE(SUM(e.subtotal_usd),0) AS costo, COALESCE(SUM(e.gasto_traslado_usd),0) AS traslado FROM productores_entregas e JOIN productores p ON p.id = e.productor_id WHERE e.fecha = ? AND p.estado = true");
    $ent->execute([$fecha_sel]);
    if ($er = $ent->fetch()) {
        $leche_litros   = (float)($er['litros']   ?? 0);
        $leche_costo    = (float)($er['costo']    ?? 0);
        $leche_traslado = (float)($er['traslado'] ?? 0);
    }
} catch (Throwable $e) { /* tabla aún sin datos */ }

// ── Leche procesada (la que realmente se convierte en queso) ─────────────────
// Se registra en Control de Leche → Resumen Diario (concepto procesada_n).
$leche_recibida = $leche_litros;
$leche_procesada = 0.0;
try {
    $pq = $db->prepare("SELECT COALESCE(SUM(monto),0) AS m FROM resumen_costos_diarios WHERE concepto='procesada_n' AND fecha = ?");
    $pq->execute([$fecha_sel]);
    if ($pr = $pq->fetch()) $leche_procesada = (float)($pr['m'] ?? 0);
} catch (Throwable $e) { /* sin datos de leche procesada */ }
// Los litros son los realmente procesados; el costo de la leche y el traslado
// quedan en el TOTAL gastado ese día (datos de Control de Leche), sin recortar.
$leche_litros = $leche_procesada;
// ── Leche restante = recibida − ventas − cortesías − vales − procesada ────────
// (mismo cálculo que Control de Leche → Resumen Diario: TOTAL LECHE RESTANTE)
$dia_ventas = 0.0; $dia_cortesias = 0.0; $dia_vales = 0.0;
try {
    $rq = $db->prepare("SELECT concepto, COALESCE(SUM(monto),0) AS m FROM resumen_costos_diarios WHERE fecha = ? AND concepto IN ('ventas_prod','cortesias_n','vales_n') GROUP BY concepto");
    $rq->execute([$fecha_sel]);
    foreach ($rq->fetchAll() as $rr) {
        if ($rr['concepto']==='ventas_prod') $dia_ventas=(float)$rr['m'];
        elseif ($rr['concepto']==='cortesias_n') $dia_cortesias=(float)$rr['m'];
        elseif ($rr['concepto']==='vales_n') $dia_vales=(float)$rr['m'];
    }
} catch (Throwable $e) { /* sin datos */ }
$leche_restante = max(0, $leche_recibida - $dia_ventas - $dia_cortesias - $dia_vales - $leche_procesada);

// ── Nómina (pago de empleados) — automático desde la sección de empleados ────
$nomina_semanal = 0.0;
try {
    $emps = $db->query("SELECT salario_semanal, estado FROM empleados")->fetchAll();
    foreach ($emps as $emp) {
        if (!isset($emp['estado']) || bool_estado($emp['estado'])) {
            $nomina_semanal += (float)($emp['salario_semanal'] ?? 0);
        }
    }
} catch (Throwable $e) { /* tabla aún sin datos */ }
$nomina_diaria = $nomina_semanal / 7;

// Etiquetas del bloque derecho, distribuidas proporcionalmente.
$labels_72 = [
    1   => 'VENTAS',
    5   => 'CORTESIA',
    10  => 'VALES',
    15  => 'TOTAL FALTANTE',
    21  => 'TOTAL',
    35  => 'SELLO',
    44  => 'FIRMA',
];
$labels = [];
if ($total > 0) {
    foreach ($labels_72 as $row => $lbl) {
        $r = max(1, min($total, (int)round($row * $total / 72)));
        while (isset($labels[$r]) && $r < $total) $r++;
        $labels[$r] = $lbl;
    }
}
// Construye secciones (start, span, label) para usar rowspan en la columna derecha.
$section_at = []; // row => [span, label] solo para fila inicio de seccion
if ($total > 0) {
    $starts = array_keys($labels);
    sort($starts);
    if (empty($starts) || $starts[0] !== 1) array_unshift($starts, 1); // garantiza inicio en 1
    for ($k = 0; $k < count($starts); $k++) {
        $s = $starts[$k];
        $next = $starts[$k + 1] ?? ($total + 1);
        $span = $next - $s;
        $lbl = $labels[$s] ?? '';
        $section_at[$s] = [$span, $lbl];
    }
}

layout_start('Control Diario de Leche', 'control_diario');
?>
<style>
.cd-wrap{max-width:1400px;margin:0 auto}
.cd-actions{display:flex;gap:.5rem;justify-content:flex-end;margin-bottom:1rem}

.cd-sheet{
  background:#fff;
  border:1px solid #000;
  font-family:Arial,Helvetica,sans-serif;
  color:#000;
  margin:0 auto;
  width:100%;
}
.cd-sheet table{width:100%;border-collapse:collapse;table-layout:auto}
.cd-sheet th, .cd-sheet td{
  border:1px solid #000;
  padding:7px 6px;
  vertical-align:middle;
  font-size:11px;
  line-height:1.2;
}
.cd-head .cd-logo{height:54px;width:140px;text-align:center;padding:3px}
.cd-head .cd-logo img{max-width:100%;max-height:48px}
.cd-head .cd-title{text-align:center;font-weight:700;font-size:15px;letter-spacing:.5px}
.cd-head .cd-date{text-align:center;font-weight:700;font-size:13px}
.cd-head th{text-align:center;font-weight:700;font-size:11px;padding:4px 6px}
.cd-col-name-head{background:#22c55e;color:#fff}
.cd-body td.cd-num-n{text-align:center;font-weight:700;width:30px}
.cd-body td.cd-name{font-weight:700;text-transform:uppercase;white-space:nowrap;font-size:11px;line-height:1.2;padding:7px 10px}
.cd-body td.cd-blank{background:#fff}
.cd-body td.cd-right-section{text-align:center;vertical-align:top;background:#fff;font-weight:700;font-size:11px;letter-spacing:.5px;padding-top:4px}
.cd-body td.cd-right-section .cd-right-lbl-text{display:block}

/* Anchos de columnas (table-layout:auto, son sugerencias) */
.col-n{width:28px}
.col-name{width:300px}
.col-num{width:90px}
.col-right{width:110px}

.cd-preview{background:#e9ecef;padding:18px;border-radius:8px;overflow:auto}
.cd-preview .cd-sheet{box-shadow:0 4px 12px rgba(0,0,0,.18)}

/* ─── Calculadora de Costos y Rentabilidad ─── */
.costos-card{background:#fff;border:1px solid #e0d6c8;border-radius:10px;padding:20px;margin-top:24px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.cc-title{margin:0 0 4px;font-size:18px;font-weight:700;color:hsl(24,38%,20%)}
.cc-sub{margin:0 0 16px;font-size:13px;color:#6b6056}
.cc-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.cc-grid{grid-template-columns:1fr}}
.cc-box{border:1px solid #ece3d6;border-radius:8px;overflow:hidden}
.cc-box-head{background:hsl(24,38%,13%);color:#fff;font-weight:700;font-size:13px;padding:8px 12px}
.cc-table{width:100%;border-collapse:collapse}
.cc-table td{padding:7px 12px;font-size:13px;border-bottom:1px solid #f0eae0}
.cc-table tr:nth-child(odd) td{background:#faf6ef}
.cc-table td:last-child{text-align:right;white-space:nowrap}
.cc-table input{width:90px;text-align:right;border:1px solid #cdbfa8;border-radius:4px;padding:3px 6px;font-size:13px}
.cc-table input[readonly]{background:#efe6d5;color:#5b4a36;font-weight:700;border-color:#d8c8ad;cursor:not-allowed}
.cc-fecha{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f3ead9;border:1px solid #e0d3ba;border-radius:8px;padding:10px 14px;margin:0 0 16px}
.cc-fecha input[type=date]{border:1px solid #cdbfa8;border-radius:4px;padding:4px 8px;font-size:13px}
.cc-filtrar{background:hsl(24,38%,25%);color:#fff;border:none;border-radius:6px;padding:5px 18px;font-size:13px;cursor:pointer;font-weight:600}
.cc-filtrar:hover{background:hsl(24,38%,20%)}
.cc-box-total{background:#fbf7ef}
.cc-total-day{margin:10px 12px;padding:16px;border-radius:8px;background:hsl(140,40%,22%);color:#fff;display:flex;flex-direction:column;gap:4px;align-items:center;text-align:center}
.cc-total-day span{font-size:12px;letter-spacing:.5px;font-weight:600;opacity:.9}
.cc-total-day strong{font-size:2rem}
.cc-price{width:100%;border-collapse:collapse;font-size:13px}
.cc-price th{background:hsl(24,38%,25%);color:#fff;padding:7px 10px;text-align:right;white-space:nowrap;font-weight:600}
.cc-price th:first-child{text-align:left}
.cc-price td{padding:6px 10px;border-bottom:1px solid #f0eae0;text-align:right;white-space:nowrap}
.cc-price td:first-child{text-align:left}
.cc-price input{border:1px solid #cdbfa8;border-radius:4px;padding:3px 6px;font-size:13px}
.cc-price input.txt{width:160px;text-align:left}
.cc-price input.num{width:72px;text-align:right}
.cc-price .out-costo{font-weight:600;color:#7a5b2e}
.cc-price .out-precio{font-weight:700;color:hsl(140,45%,30%);font-size:14px}
.cc-price .out-kg{font-weight:600;color:#5b4a36}
.cc-price tr:nth-child(even) td{background:#faf6ef}
.cc-fact{width:100%;max-width:440px;border-collapse:collapse;font-size:14px;margin-top:4px}
.cc-fact td{padding:8px 12px;border-bottom:1px solid #f0eae0}
.cc-fact td:last-child{text-align:right;font-weight:700;color:hsl(140,45%,30%)}
.cc-fact tr:first-child td{border-top:2px solid hsl(24,38%,25%)}
.cc-fact .cc-fact-empty{color:#8a7d6c;font-weight:400;text-align:left}
.cc-delrow{border:none;background:#f3d9d4;color:#b3402e;border-radius:4px;cursor:pointer;padding:3px 9px;font-weight:700}
.cc-addbtn{margin:12px;background:hsl(24,38%,25%);color:#fff;border:none;border-radius:6px;padding:8px 14px;font-size:13px;cursor:pointer;font-weight:600}
.cc-addbtn:hover{background:hsl(24,38%,20%)}
.cc-table tr.cc-auto td{font-weight:700;color:hsl(24,38%,25%);background:#f3ead9!important}
.cc-note{margin:6px 12px 10px;font-size:11px;color:#8a7d6c}
.cc-results{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:16px}
.cc-res{background:#faf6ef;border:1px solid #ece3d6;border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:4px;justify-content:center}
.cc-res span{font-size:12px;color:#6b6056}
.cc-res strong{font-size:20px;color:hsl(24,38%,18%)}
.cc-res-big{background:hsl(24,38%,13%);border-color:hsl(24,38%,13%)}
.cc-res-big span{color:#e8d9c4}
.cc-res-big strong{color:#fff;font-size:24px}
.cc-res-green{background:#1b5e20;border-color:#1b5e20}
.cc-res-green span{color:#c8e6c9}
.cc-res-green strong{color:#fff;font-size:24px}

/* ─── IMPRESIÓN: tamaño oficio (legal) sin escalas ni JS ─── */
@page {
  size: legal portrait;
  margin: 5mm;
}
@media print{
  html, body{background:#fff!important;margin:0!important;padding:0!important;font-size:0}
  body *{visibility:hidden!important}
  #cdSheet, #cdSheet *{visibility:visible!important}
  #cdSheet{
    position:absolute!important;left:0;top:0;
    width:205mm!important; /* oficio 215.9 - 2*5mm margen */
    max-width:205mm!important;
    height:auto!important;
    border:0.4mm solid #000!important;
    box-shadow:none!important;
    page-break-inside:avoid;
    break-inside:avoid;
    overflow:visible!important;
  }
  #cdSheet table{width:100%!important;height:auto!important;table-layout:fixed!important;page-break-inside:avoid;break-inside:avoid}
  #cdSheet thead{display:table-header-group}
  #cdSheet tr, #cdSheet td, #cdSheet th{page-break-inside:avoid;break-inside:avoid}
  #cdSheet th, #cdSheet td{
    font-size:<?= $total > 70 ? '7pt' : ($total > 50 ? '8pt' : '9pt') ?>!important;
    padding:0 2px!important;
    border:0.2mm solid #000!important;
    line-height:1!important;
    vertical-align:middle!important;
    height:<?= $total > 0 ? number_format(328 / $total, 3, '.', '') : 6 ?>mm!important;
  }
  #cdSheet .cd-body td.cd-name{white-space:normal!important;word-break:break-word!important;overflow-wrap:anywhere!important;padding:0 3px!important;font-size:<?= $total > 70 ? '7pt' : ($total > 50 ? '8pt' : '9pt') ?>!important;text-align:left!important}
  #cdSheet tbody td.cd-name{overflow:visible!important}
  #cdSheet .cd-head td, #cdSheet .cd-head th{height:auto!important}
  #cdSheet .cd-head .cd-title{font-size:11pt!important;height:10mm!important}
  #cdSheet .cd-head .cd-date{font-size:9pt!important}
  #cdSheet .cd-head .cd-logo{height:10mm!important;padding:0!important}
  #cdSheet .cd-head .cd-logo img{max-height:9mm!important;max-width:100%}
  #cdSheet .cd-head th{padding:1mm 2px!important;font-size:8pt!important;height:6mm!important}
  #cdSheet tbody td{overflow:hidden!important}
  /* col widths para imprimir, en mm */
  #cdSheet col.col-n{width:7mm}
  #cdSheet col.col-name{width:auto}
  #cdSheet col.col-num{width:12mm}
  #cdSheet col.col-right{width:25mm}
  #cdSheet .cd-right-section{font-size:8pt!important;font-weight:700;vertical-align:top!important;padding-top:1mm!important}
  .no-print, .sidebar, .topbar, header, nav, footer{display:none!important}
}
</style>

<div class="cd-wrap">
  <div class="cd-actions no-print">
    <button class="btn btn-outline-secondary btn-sm" onclick="window.history.back()">← Volver</button>
    <a class="btn btn-success btn-sm" href="/aserp-php/?p=control_diario_excel">📊 Exportar a Excel</a>
    <a class="btn btn-danger btn-sm" href="/aserp-php/?p=control_diario_pdf" target="_blank">📄 Exportar a PDF</a>
  </div>
  <div class="no-print" style="background:#d1ecf1;border:1px solid #17a2b8;padding:8px 12px;border-radius:6px;margin-bottom:1rem;font-size:13px">
    <strong>Cómo exportar:</strong> <em>Excel</em> descarga un archivo <strong>.xls</strong> listo para imprimir en una sola hoja. <em>PDF</em> abre la vista de impresión &mdash; elige "Guardar como PDF" en el destino de impresión.
  </div>

  <div class="cd-preview">
    <div id="cdSheet" class="cd-sheet">
      <table>
        <colgroup>
          <col class="col-n">
          <col class="col-name">
          <col class="col-num">
          <col class="col-num">
          <col class="col-num">
          <col class="col-right">
        </colgroup>
        <thead class="cd-head">
          <tr>
            <td class="cd-logo" colspan="2">
              <img src="/aserp-php/public/logo.jpg" alt="logo" onerror="this.style.display='none'">
            </td>
            <td colspan="3" class="cd-title">CONTROL DIARIO DE LECHE</td>
            <td class="cd-date"><?= h(date('d/m/Y', strtotime($fecha_sel))) ?></td>
          </tr>
          <tr>
            <th>N</th>
            <th class="cd-col-name-head">NOMBRE Y APELLIDO</th>
            <th>LITROS</th>
            <th>DENSIDAD</th>
            <th>FALTANTE</th>
            <th></th>
          </tr>
        </thead>
        <tbody class="cd-body">
          <?php foreach ($prods as $i => $p):
            $n = $i + 1;
            $sec = $section_at[$n] ?? null;
          ?>
          <tr>
            <td class="cd-num-n"><?= $n ?></td>
            <td class="cd-name"><?= h(strtoupper($p['nombre'])) ?></td>
            <td class="cd-blank"></td>
            <td class="cd-blank"></td>
            <td class="cd-blank"></td>
            <?php if ($sec !== null): ?>
              <td class="cd-right-section" rowspan="<?= (int)$sec[0] ?>"><span class="cd-right-lbl-text"><?= h($sec[1]) ?></span></td>
            <?php endif ?>
          </tr>
          <?php endforeach ?>
          <?php if (!$total): ?>
          <tr><td colspan="6" style="text-align:center;padding:20px">Sin productores activos.</td></tr>
          <?php endif ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="costos-card no-print" id="costosCalc">
    <h3 class="cc-title">Producci&oacute;n &mdash; Costos del d&iacute;a y precio de venta</h3>
    <p class="cc-sub">Los <strong>kg del d&iacute;a</strong> se calculan con los litros de <strong>leche recibida</strong> (la que hay ese d&iacute;a); el <strong>costo total de la leche</strong> es lo realmente gastado ese d&iacute;a (de Control de Leche). Se le suma la n&oacute;mina de los empleados y los gastos generales para saber <strong>cu&aacute;nto se gasta diario en producci&oacute;n</strong> y <strong>a c&oacute;mo vender cada tipo de queso</strong> sin perder. Todo es autom&aacute;tico pero editable.</p>

    <form method="get" class="cc-fecha">
      <input type="hidden" name="p" value="control_diario">
      <label style="font-size:13px;font-weight:600;color:#5b4a36">D&iacute;a de producci&oacute;n:</label>
      <input type="date" name="fecha" value="<?= h($fecha_sel) ?>">
      <button type="submit" class="cc-filtrar">Filtrar</button>
      <span style="font-size:13px;color:#5b4a36">Leche procesada: <strong><?= number_format($leche_litros,1) ?> L</strong> &middot; Costo total de la leche: <strong>$<?= number_format($leche_costo,2) ?></strong> &middot; Recibida: <strong><?= number_format($leche_recibida,1) ?> L</strong></span>
    </form>

    <div class="cc-grid">
      <div class="cc-box">
        <div class="cc-box-head">Gastos del d&iacute;a</div>
        <input type="hidden" id="cc_litros" value="<?= number_format($leche_recibida,1,'.','') ?>">
        <table class="cc-table">
          <tr class="cc-auto"><td><strong>Total leche restante</strong> <span style="font-weight:400;color:#8a7d6c;font-size:11px">(recibida &minus; ventas, cortes&iacute;a, vales y procesada)</span></td><td><input type="number" id="cc_restante" value="<?= number_format($leche_restante,1,'.','') ?>" readonly> L</td></tr>
          <tr class="cc-auto"><td>Costo total de la leche <span style="font-weight:400;color:#8a7d6c;font-size:11px">(de Control de Leche)</span></td><td>$ <input type="number" id="cc_costo_leche" value="<?= number_format($leche_costo,2,'.','') ?>" readonly></td></tr>
          <tr class="cc-auto"><td><strong>Costo real por litro de leche</strong> <span style="font-weight:400;color:#8a7d6c;font-size:11px">(costo total &divide; litros recibidos)</span></td><td><strong id="cc_costo_litro" style="color:#7a4d12">$0,00</strong> / L</td></tr>
          <tr><td>N&oacute;mina (pago de empleados) $ <span style="font-weight:400;color:#8a7d6c;font-size:11px">(autom.)</span></td><td>$ <input type="number" id="cc_nomina" min="0" step="any" placeholder="0" value="<?= $nomina_diaria > 0 ? number_format($nomina_diaria,2,'.','') : '' ?>"></td></tr>
          <tr><td>Costo personal extra $</td><td>$ <input type="number" id="cc_personal" min="0" step="any" placeholder="0" value=""></td></tr>
          <tr><td>Costo transporte $ <span style="font-weight:400;color:#8a7d6c;font-size:11px">(traslado autom.)</span></td><td>$ <input type="number" id="cc_transporte" min="0" step="any" placeholder="0" value="<?= $leche_traslado > 0 ? number_format($leche_traslado,2,'.','') : '' ?>"></td></tr>
          <tr><td>Costo operativo $</td><td>$ <input type="number" id="cc_operativo" min="0" step="any" placeholder="0" value=""></td></tr>
          <tr><td>Otros gastos $</td><td>$ <input type="number" id="cc_otros" min="0" step="any" placeholder="0" value=""></td></tr>
        </table>
        <p class="cc-note">La n&oacute;mina diaria se calcula con los sueldos semanales de los empleados activos &divide; 7. Si trabajas otra cantidad de d&iacute;as, edita el monto.</p>
      </div>

      <div class="cc-box cc-box-total">
        <div class="cc-box-head">Resumen del d&iacute;a</div>
        <table class="cc-table">
          <tr><td>Margen de ganancia deseado</td><td><input type="number" id="cc_margen" min="0" max="99" step="any" value="40"> %</td></tr>
        </table>
        <div class="cc-total-day">
          <span>GASTO TOTAL DIARIO EN PRODUCCI&Oacute;N</span>
          <strong id="cc_gasto_total">$0,00</strong>
        </div>
        <p class="cc-note">Suma de leche + n&oacute;mina + todos los gastos del d&iacute;a. Es lo que se gasta para producir.</p>
      </div>
    </div>

    <div class="cc-box" style="margin-top:16px">
      <div class="cc-box-head">Lista de precios por tipo de queso (margen <span id="cc_margen_lbl">40</span>%)</div>
      <p class="cc-note">Indica cu&aacute;ntos kg de queso salen por cada cierta cantidad de litros (ej.: por cada 10 L salen 8 kg de Guayan&eacute;s). Adem&aacute;s puedes registrar gastos propios de cada queso (<strong>sal</strong>, <strong>empaque</strong>, <strong>otros</strong>) como monto total del d&iacute;a; se suman al costo y se reparten entre los kilos de ese queso. El sistema calcula cu&aacute;nto cuesta producir 1 kg y a c&oacute;mo venderlo para ganar tu margen.</p>
      <div style="overflow-x:auto">
        <table class="cc-price">
          <thead>
            <tr>
              <th>Tipo de queso</th>
              <th>Por cada (L)</th>
              <th>Salen (kg)</th>
              <th>Sal ($)</th>
              <th>Empaque ($)</th>
              <th>Otros ($)</th>
              <th>Kg del d&iacute;a</th>
              <th>Costo por kg</th>
              <th>Precio de venta x kg</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="cc_price_body"></tbody>
        </table>
      </div>
      <button type="button" id="cc_add_row" class="cc-addbtn">+ Agregar tipo de queso</button>
    </div>

    <div class="cc-box" style="margin-top:16px">
      <div class="cc-box-head">Factura &mdash; precios de venta x kg</div>
      <div style="text-align:center;background:#fbf3e6;border:2px solid #d9b46a;border-radius:10px;padding:14px;margin-bottom:14px">
        <div style="font-size:13px;color:#8a7d6c;text-transform:uppercase;letter-spacing:1px">Margen de ganancia</div>
        <div style="font-size:44px;font-weight:800;color:#7a4d12;line-height:1.1"><span id="cc_margen_big">40</span>%</div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <button type="button" id="cc_guardar" class="cc-addbtn" style="background:#7a4d12;color:#fff;border-color:#7a4d12">Guardar todos los cambios</button>
        <button type="button" id="cc_imprimir" class="cc-addbtn">Imprimir factura</button>
        <span id="cc_guardar_msg" style="font-size:13px;font-weight:600"></span>
      </div>
      <p class="cc-note">Lista lista para imprimir o copiar: nombre del producto y su precio de venta por kg. Usa <strong>Guardar todos los cambios</strong> para que la lista y los precios se mantengan hasta que los actualices.</p>
      <table class="cc-fact"><tbody id="cc_factura"></tbody></table>
    </div>
  </div>
</div>

<script>
(function(){
  function v(id){var e=document.getElementById(id);var n=e?parseFloat(e.value):0;return isFinite(n)?n:0;}
  function money(n){if(!isFinite(n))n=0;var s=Math.abs(n).toFixed(2).split('.');s[0]=s[0].replace(/\B(?=(\d{3})+(?!\d))/g,'.');return (n<0?'-':'')+'$'+s[0]+','+s[1];}

  var defaults=[
    {n:'Queso Guayan\u00e9s', l:10, k:8},
    {n:'Queso Blanco',       l:10, k:7},
    {n:'Queso de Mano',      l:10, k:6.5},
    {n:'Queso Telita',       l:10, k:7}
  ];
  var body=document.getElementById('cc_price_body');

  function rowHtml(d){
    return '<td><input class="txt" type="text" value="'+(d.n||'')+'"></td>'+
           '<td><input class="num l" type="number" min="0" step="any" value="'+d.l+'"> L</td>'+
           '<td><input class="num k" type="number" min="0" step="any" value="'+d.k+'"> kg</td>'+
           '<td><input class="num c1" type="number" min="0" step="any" placeholder="0" value="'+(d.s||'')+'"></td>'+
           '<td><input class="num c2" type="number" min="0" step="any" placeholder="0" value="'+(d.e||'')+'"></td>'+
           '<td><input class="num c3" type="number" min="0" step="any" placeholder="0" value="'+(d.o||'')+'"></td>'+
           '<td class="out-kg">0 kg</td>'+
           '<td class="out-costo">$0,00</td>'+
           '<td class="out-precio">$0,00</td>'+
           '<td><button type="button" class="cc-delrow" title="Quitar">\u2715</button></td>';
  }
  function addRow(d){
    var tr=document.createElement('tr');
    tr.innerHTML=rowHtml(d||{n:'',l:10,k:8});
    body.appendChild(tr);
    tr.querySelectorAll('input').forEach(function(i){i.addEventListener('input',calc);});
    tr.querySelector('.cc-delrow').addEventListener('click',function(){tr.remove();calc();});
  }

  function calc(){
    var litros=v('cc_litros');
    var precioLitro=litros>0?v('cc_costo_leche')/litros:0;
    var pl=document.getElementById('cc_costo_litro'); if(pl) pl.textContent=money(precioLitro);
    var gastoTotal=v('cc_costo_leche')+v('cc_nomina')+v('cc_personal')+v('cc_transporte')+v('cc_operativo')+v('cc_otros');
    var margen=v('cc_margen');
    document.getElementById('cc_gasto_total').textContent=money(gastoTotal);
    var ml=document.getElementById('cc_margen_lbl'); if(ml) ml.textContent=(isFinite(margen)?margen:0);
    var mb=document.getElementById('cc_margen_big'); if(mb) mb.textContent=(isFinite(margen)?margen:0);
    var fact='';
    body.querySelectorAll('tr').forEach(function(tr){
      var lref=parseFloat(tr.querySelector('.l').value)||0;
      var kref=parseFloat(tr.querySelector('.k').value)||0;
      var ratio=lref>0?kref/lref:0;
      var kgDia=litros*ratio;
      var extra=(parseFloat(tr.querySelector('.c1').value)||0)+(parseFloat(tr.querySelector('.c2').value)||0)+(parseFloat(tr.querySelector('.c3').value)||0);
      var costoKg=kgDia>0?(gastoTotal+extra)/kgDia:0;
      var precioKg=margen<100?costoKg/(1-margen/100):0;
      tr.querySelector('.out-kg').textContent=kgDia.toFixed(1)+' kg';
      tr.querySelector('.out-costo').textContent=money(costoKg);
      tr.querySelector('.out-precio').textContent=money(precioKg);
      var nm=(tr.querySelector('.txt').value||'').trim();
      if(nm && precioKg>0){ fact+='<tr><td>'+nm.replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</td><td>'+money(precioKg)+'</td></tr>'; }
    });
    var fb=document.getElementById('cc_factura');
    if(fb) fb.innerHTML = fact || '<tr><td class="cc-fact-empty" colspan="2">Sin productos con precio</td></tr>';
  }

  ['cc_nomina','cc_personal','cc_transporte','cc_operativo','cc_otros','cc_margen'].forEach(function(id){
    var e=document.getElementById(id);
    if(e){e.addEventListener('input',calc);e.addEventListener('change',calc);}
  });
  document.getElementById('cc_add_row').addEventListener('click',function(){addRow();});
  var CC_SAVED = <?= json_encode($cc_cfg ?: null) ?>;
  var CC_CSRF = <?= json_encode(csrf_token()) ?>;
  if (CC_SAVED) {
    if (CC_SAVED.lista && CC_SAVED.lista.length) defaults = CC_SAVED.lista;
    if (CC_SAVED.margen != null) { var _em=document.getElementById('cc_margen'); if(_em) _em.value=CC_SAVED.margen; }
    if (CC_SAVED.gastos) {
      var _sv=function(id,val){ var e=document.getElementById(id); if(e && val!=null && +val!==0) e.value=val; };
      _sv('cc_personal',CC_SAVED.gastos.personal); _sv('cc_operativo',CC_SAVED.gastos.operativo); _sv('cc_otros',CC_SAVED.gastos.otros);
    }
  }
  defaults.forEach(addRow);
  function recolectar(){
    var lista=[];
    body.querySelectorAll('tr').forEach(function(tr){
      lista.push({
        n:(tr.querySelector('.txt').value||'').trim(),
        l:parseFloat(tr.querySelector('.l').value)||0,
        k:parseFloat(tr.querySelector('.k').value)||0,
        s:parseFloat(tr.querySelector('.c1').value)||0,
        e:parseFloat(tr.querySelector('.c2').value)||0,
        o:parseFloat(tr.querySelector('.c3').value)||0
      });
    });
    return { lista:lista, margen:v('cc_margen'), gastos:{ personal:v('cc_personal'), operativo:v('cc_operativo'), otros:v('cc_otros') } };
  }
  function guardar(){
    var btn=document.getElementById('cc_guardar');
    var msg=document.getElementById('cc_guardar_msg');
    if(btn){ btn.disabled=true; btn.textContent='Guardando...'; }
    var fd=new FormData();
    fd.append('accion','guardar_produccion');
    fd.append('_csrf', CC_CSRF);
    fd.append('config', JSON.stringify(recolectar()));
    fetch(window.location.href, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(btn){ btn.disabled=false; btn.textContent='Guardar todos los cambios'; }
        if(msg){ if(j&&j.ok){ msg.textContent='Cambios guardados'; msg.style.color='#1a7a3a'; } else { msg.textContent='No se pudo guardar'; msg.style.color='#b00020'; } }
      })
      .catch(function(){
        if(btn){ btn.disabled=false; btn.textContent='Guardar todos los cambios'; }
        if(msg){ msg.textContent='Error de conexion'; msg.style.color='#b00020'; }
      });
  }
  function imprimir(){
    var fb=document.getElementById('cc_factura');
    var rows=fb?fb.innerHTML:'';
    var margen=v('cc_margen');
    var fecha=new Date().toLocaleDateString('es-VE');
    var w=window.open('','_blank','width=440,height=660');
    if(!w){ alert('Permite las ventanas emergentes para imprimir.'); return; }
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Factura - precios de venta</title>'+
      '<style>body{font-family:Arial,Helvetica,sans-serif;color:#3a2f23;padding:22px}'+
      'h1{font-size:17px;margin:0 0 4px}.sub{color:#8a7d6c;font-size:12px;margin:0 0 16px}'+
      'table{width:100%;border-collapse:collapse}td{padding:9px 10px;border-bottom:1px solid #e7ddcf;font-size:14px}'+
      'td:last-child{text-align:right;font-weight:bold}</style></head><body>'+
      '<h1>El Castillo del Queso - Precios de venta x kg</h1>'+
      '<p class="sub">Margen de ganancia: '+margen+'%  -  '+fecha+'</p>'+
      '<table>'+rows+'</table></body></html>');
    w.document.close(); w.focus();
    setTimeout(function(){ try{ w.print(); }catch(e){} }, 300);
  }
  var _gb=document.getElementById('cc_guardar'); if(_gb) _gb.addEventListener('click', guardar);
  var _ib=document.getElementById('cc_imprimir'); if(_ib) _ib.addEventListener('click', imprimir);
  calc();
})();
</script>
<?php layout_end(); ?>
