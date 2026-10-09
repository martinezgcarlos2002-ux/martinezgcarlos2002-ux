<?php
require_login();
$db = get_db();

// ── Helpers ───────────────────────────────────────────────────────────────────
function snap_sun(string $d): string {
    $dt = new DateTime($d);
    $w = (int)$dt->format('w'); // 0=Sun
    if ($w !== 0) $dt->modify('-' . $w . ' days');
    return $dt->format('Y-m-d');
}
function fmt_money2($n): string { return number_format((float)$n, 2, '.', ','); }
function fmt_money4($n): string { return number_format((float)$n, 4, '.', ','); }

// ── Semana activa (rango libre) ───────────────────────────────────────────────
// Fecha de inicio: la que el usuario eligió (sin forzar domingo), o el domingo de esta semana por defecto
$semana = isset($_GET['semana']) && $_GET['semana']
    ? $_GET['semana']
    : snap_sun(date('Y-m-d'));
// Fecha fin: la que el usuario eligió, o +6 días por defecto.
// Si la fecha fin es menor que la de inicio, se ajusta a inicio + 6 días.
if (isset($_GET['semana_fin']) && $_GET['semana_fin']) {
    $semana_fin = $_GET['semana_fin'];
    if (strtotime($semana_fin) < strtotime($semana)) {
        $semana_fin = (new DateTime($semana))->modify('+6 days')->format('Y-m-d');
    }
} else {
    $semana_fin = (new DateTime($semana))->modify('+6 days')->format('Y-m-d');
}
$tasa = get_tasa();

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save_emp') {
        $id      = (int)($_POST['id'] ?? 0);
        $nombre  = trim($_POST['nombre'] ?? '');
        $apell   = trim($_POST['apellidos'] ?? '');
        $ced     = trim($_POST['cedula'] ?? '');
        $cargo   = trim($_POST['cargo'] ?? '');
        $tel     = trim($_POST['telefono'] ?? '');
        $dir     = trim($_POST['direccion'] ?? '');
        $sal     = (float)($_POST['salario_semanal'] ?? 0);
        $fi      = $_POST['fecha_ingreso'] ?: null;
        $fs      = $_POST['fecha_salida'] ?: null;
        $fn      = $_POST['fecha_nacimiento'] ?: null;
        $cp      = isset($_POST['cuenta_propia']) ? 'true' : 'false';
        $ep      = isset($_POST['expediente_penal']) ? 'true' : 'false';
        $est     = isset($_POST['estado']) ? 'true' : 'false';
        if ($id) {
            $db->prepare("UPDATE empleados SET nombre=?, apellidos=?, cedula=?, cargo=?, telefono=?,
                          direccion=?, salario_semanal=?, fecha_ingreso=?, fecha_salida=?,
                          fecha_nacimiento=?, cuenta_propia=$cp, expediente_penal=$ep, estado=$est WHERE id=?")
               ->execute([$nombre,$apell,$ced,$cargo,$tel,$dir,$sal,$fi,$fs,$fn,$id]);
            flash('Empleado actualizado.');
        } else {
            $db->prepare("INSERT INTO empleados (nombre, apellidos, cedula, cargo, telefono, direccion,
                          salario_semanal, fecha_ingreso, fecha_salida, fecha_nacimiento,
                          cuenta_propia, expediente_penal, estado)
                          VALUES (?,?,?,?,?,?,?,?,?,?,$cp,$ep,$est)")
               ->execute([$nombre,$apell,$ced,$cargo,$tel,$dir,$sal,$fi,$fs,$fn]);
            flash('Empleado creado.');
        }
    }
    elseif ($act === 'delete_emp') {
        $db->prepare('DELETE FROM empleados WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Empleado eliminado.', 'warning');
    }
    elseif ($act === 'save_nomina') {
        $sw = snap_sun($_POST['semana'] ?? $semana);
        if (is_mysql()) {
            $ups = $db->prepare("INSERT INTO empleados_nomina
                                  (empleado_id, semana_inicio, dias_no_trabajados, pagos_extras,
                                   vales_mercancia, bodega_bs, pendiente)
                                  VALUES (?,?,?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE
                                    dias_no_trabajados=VALUES(dias_no_trabajados),
                                    pagos_extras=VALUES(pagos_extras),
                                    vales_mercancia=VALUES(vales_mercancia),
                                    bodega_bs=VALUES(bodega_bs),
                                    pendiente=VALUES(pendiente)");
        } else {
            $ups = $db->prepare("INSERT INTO empleados_nomina
                                  (empleado_id, semana_inicio, dias_no_trabajados, pagos_extras,
                                   vales_mercancia, bodega_bs, pendiente)
                                  VALUES (?,?,?,?,?,?,?)
                                  ON CONFLICT (empleado_id, semana_inicio) DO UPDATE SET
                                    dias_no_trabajados=EXCLUDED.dias_no_trabajados,
                                    pagos_extras=EXCLUDED.pagos_extras,
                                    vales_mercancia=EXCLUDED.vales_mercancia,
                                    bodega_bs=EXCLUDED.bodega_bs,
                                    pendiente=EXCLUDED.pendiente");
        }
        $rows = $_POST['nom'] ?? [];
        foreach ($rows as $eid => $vals) {
            $eid = (int)$eid; if (!$eid) continue;
            $ups->execute([
                $eid, $sw,
                (float)($vals['dias'] ?? 0),
                (float)($vals['extras'] ?? 0),
                (float)($vals['vales'] ?? 0),
                (float)($vals['bodega'] ?? 0),
                (float)($vals['pendiente'] ?? 0),
            ]);
        }
        flash('Nómina de la semana guardada.');
        $q = trim((string)($_POST['q'] ?? ''));
        $loc = '?p=empleados&semana=' . urlencode($sw);
        if ($q !== '') $loc .= '&q=' . urlencode($q);
        header('Location: ' . $loc); exit;
    }

    $q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
    $loc = '?p=empleados&semana=' . urlencode($semana);
    if ($q !== '') $loc .= '&q=' . urlencode($q);
    header('Location: ' . $loc); exit;
}

// ── Edit modal data ───────────────────────────────────────────────────────────
$edit = null;
if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM empleados WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

// ── Cargar empleados + nómina de la semana ────────────────────────────────────
$st = $db->prepare("SELECT e.*, n.dias_no_trabajados, n.pagos_extras, n.vales_mercancia, n.bodega_bs, n.pendiente
                    FROM empleados e
                    LEFT JOIN empleados_nomina n
                           ON n.empleado_id = e.id AND n.semana_inicio = ?
                    WHERE e.estado = true
                    ORDER BY e.id");
$st->execute([$semana]);
$emps = $st->fetchAll();

// Empresa header
$EMPRESA = 'El Castillo del Queso Artesanal F.P';
$RIF     = 'V-28601335-6';

// ── Datos calculados (también usados por exportaciones y PDF) ─────────────────
function calc_fila_simple(array $e, float $tasa): array {
    $sueldo  = (float)$e['salario_semanal'];
    $vales   = (float)($e['vales_mercancia'] ?? 0);      // M/V en $
    $bodega  = (float)($e['bodega_bs'] ?? 0);
    $bc      = $tasa > 0 ? $bodega / $tasa : 0;          // B-C en $
    $extras  = (float)($e['pagos_extras'] ?? 0);
    $sal_dia = $sueldo / 7;
    $dias    = (float)($e['dias_no_trabajados'] ?? 0);
    $desc_d  = $sal_dia * $dias;
    // Total Semana = mismo Neto a Pagar $ que muestra la tabla en pantalla
    $neto      = $sueldo + $extras - $vales - $desc_d;
    $pendiente = (float)($e['pendiente'] ?? 0);          // manual
    return [
        'sueldo'    => $sueldo,
        'bc'        => $bc,
        'mv'        => $vales,
        'pendiente' => $pendiente,
        'dias_nt'   => $dias,
        'desc_dias' => $desc_d,
        'total_sem' => $neto,
        'resto'     => $neto - $bc,   // Resto a pagar después de la bodega ($)
    ];
}

// Exportación a Excel "resumen" (formato planilla original) ───────────────────
if (($_GET['export'] ?? '') === 'xls_simple') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="nomina_resumen_' . $semana . '.xls"');
    $sem_lbl = (new DateTime($semana))->format('d-m') . ' al ' . (new DateTime($semana_fin))->format('d-m');
    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1' cellpadding='4' cellspacing='0' style='border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt'>";
    echo "<tr><td colspan='10' style='background:#9bd49b;font-weight:bold;text-align:center;font-size:14pt'>SEMANA del $sem_lbl</td></tr>";
    echo "<tr style='background:#c8e6c9;font-weight:bold;text-align:center'>"
       . "<th>Nombres</th><th>Apellidos</th><th>Sueldo</th><th>B-C</th><th>M/V</th><th>Pendien</th><th>Días N/T</th><th>$ Días N/T</th><th>Total Semana</th><th>RESTO $</th></tr>";
    $T = ['sueldo'=>0,'bc'=>0,'mv'=>0,'pendiente'=>0,'dias_nt'=>0,'desc_dias'=>0,'total_sem'=>0,'resto'=>0];
    foreach ($emps as $e) {
        $c = calc_fila_simple($e, (float)$tasa);
        foreach ($T as $k=>$v) $T[$k] += $c[$k];
        echo "<tr>"
           . "<td>" . h($e['nombre']) . "</td>"
           . "<td>" . h($e['apellidos']) . "</td>"
           . "<td style='text-align:right'>$ " . fmt_money2($c['sueldo']) . "</td>"
           . "<td style='text-align:right'>$ " . fmt_money2($c['bc'])     . "</td>"
           . "<td style='text-align:right'>$ " . fmt_money2($c['mv'])     . "</td>"
           . "<td style='text-align:right'>$ " . fmt_money2($c['pendiente']) . "</td>"
           . "<td style='text-align:center'>" . ($c['dias_nt'] > 0 ? rtrim(rtrim(number_format($c['dias_nt'],2,'.',''),'0'),'.') : '0') . "</td>"
           . "<td style='text-align:right'>$ " . fmt_money2($c['desc_dias']) . "</td>"
           . "<td style='text-align:right'>$ " . fmt_money2($c['total_sem']) . "</td>"
           . "<td style='text-align:right'>$ " . fmt_money2($c['resto']) . "</td>"
           . "</tr>";
    }
    $n = count($emps);
    echo "<tr style='background:#9bd49b;font-weight:bold'>"
       . "<td>$n</td><td>$n</td>"
       . "<td style='text-align:right'>$ " . fmt_money2($T['sueldo'])    . "</td>"
       . "<td style='text-align:right'>$ " . fmt_money2($T['bc'])        . "</td>"
       . "<td style='text-align:right'>$ " . fmt_money2($T['mv'])        . "</td>"
       . "<td style='text-align:right'>$ " . fmt_money2($T['pendiente']) . "</td>"
       . "<td style='text-align:center'>" . rtrim(rtrim(number_format($T['dias_nt'],2,'.',''),'0'),'.') . "</td>"
       . "<td style='text-align:right'>$ " . fmt_money2($T['desc_dias']) . "</td>"
       . "<td style='text-align:right'>$ " . fmt_money2($T['total_sem']) . "</td>"
       . "<td style='text-align:right'>$ " . fmt_money2($T['resto']) . "</td></tr>";
    echo "<tr><td colspan='10' style='height:60px'></td></tr>";
    echo "<tr style='text-align:center;font-weight:bold'><td colspan='5' style='border-top:1px solid #000'>SELLO</td><td colspan='5' style='border-top:1px solid #000'>FIRMA</td></tr>";
    echo "</table></body></html>";
    exit;
}

// Vista "Notaría": hoja con Nombres + días Domingo a Viernes + Observaciones ─
if (($_GET['notaria'] ?? '') === '1') {
    $st_n = $db->query("SELECT nombre, apellidos FROM empleados
                        WHERE estado = true
                        ORDER BY UPPER(nombre) ASC, UPPER(apellidos) ASC");
    $emp_notaria = $st_n->fetchAll();
    // Construir los 6 días: Domingo a Viernes a partir de $semana (si $semana no es domingo, se ajusta al domingo de esa semana)
    $base = new DateTime($semana);
    // En PHP la semana ISO arranca lunes (1)..domingo (7). Queremos arrancar en domingo:
    $dow = (int)$base->format('w'); // 0=dom..6=sab
    if ($dow !== 0) {
        $base->modify('-' . $dow . ' days'); // retrocede al domingo de esa semana
    }
    $dias_lbl = ['DOMINGO','LUNES','MARTES','MI&Eacute;RCOLES','JUEVES','VIERNES','S&Aacute;BADO'];
    $dias_fechas = [];
    for ($d = 0; $d < 7; $d++) {
        $f = (clone $base)->modify('+' . $d . ' days');
        $dias_fechas[] = $f->format('d/m');
    }
    $sem_lbl_n = $base->format('d/m/y') . ' al ' . (clone $base)->modify('+6 days')->format('d/m/y');
    $fecha_hoy = date('d/m/Y');
    echo "<!doctype html><html lang='es'><head><meta charset='UTF-8'>";
    echo "<title>Notar&iacute;a " . h($fecha_hoy) . "</title>";
    echo "<style>
      @page { size: Letter portrait; margin: 8mm; }
      html,body{margin:0;padding:0}
      body{font-family:Calibri,Arial,sans-serif;font-size:10pt;color:#000;padding:8mm}
      .titulo{background:#4f81bd;color:#fff;font-weight:bold;text-align:center;font-size:15pt;padding:6px;border:1px solid #2a4d7a;}
      .subt{background:#fff;color:#000;font-weight:bold;text-align:center;font-size:11pt;padding:3px;border:1px solid #2a4d7a;border-top:0;margin-bottom:6px;}
      table{border-collapse:collapse;width:100%;table-layout:fixed;margin-top:4px;}
      th,td{border:1px solid #2a4d7a;padding:5px 6px;font-size:10pt;vertical-align:middle;}
      th{background:#dce6f1;text-align:center;color:#1f3864;font-size:9.5pt;}
      col.c-n{width:4%;} col.c-nom{width:22%;} col.c-d{width:7.5%;} col.c-obs{width:21.5%;}
      td.n{text-align:center;font-weight:600;}
      td.nom{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
      td.empty{height:22px;}
      .toolbar{margin-bottom:8px}
      .toolbar button{padding:6px 14px;font-size:11pt;cursor:pointer;margin-right:8px}
      @media print { .toolbar{display:none} body{padding:0} }
    </style></head><body>";
    echo "<div class='toolbar'>"
       . "<button onclick='window.print()'>Imprimir / Guardar PDF</button>"
       . "<button onclick=\"window.location='?p=empleados&semana=" . h($semana) . "&semana_fin=" . h($semana_fin) . "'\">Volver</button>"
       . "</div>";
    echo "<div class='titulo'>NOTAR&Iacute;A</div>";
    echo "<div class='subt'>Semana del " . h($sem_lbl_n) . " &nbsp;&nbsp;&nbsp; Impreso: " . h($fecha_hoy) . "</div>";
    echo "<table>";
    echo "<colgroup><col class='c-n'><col class='c-nom'>";
    for ($d = 0; $d < 7; $d++) { echo "<col class='c-d'>"; }
    echo "<col class='c-obs'></colgroup>";
    echo "<thead><tr>"
       . "<th>N</th>"
       . "<th>NOMBRES Y APELLIDOS</th>";
    for ($d = 0; $d < 7; $d++) {
        echo "<th>" . $dias_lbl[$d] . "<br><span style='font-weight:normal;font-size:8.5pt'>" . h($dias_fechas[$d]) . "</span></th>";
    }
    echo "<th>OBSERVACIONES</th>"
       . "</tr></thead><tbody>";
    $i = 0;
    foreach ($emp_notaria as $e) {
        $i++;
        $nom_completo = trim(((string)$e['nombre']) . ' ' . ((string)$e['apellidos']));
        echo "<tr>"
           . "<td class='n'>$i</td>"
           . "<td class='nom'>" . h($nom_completo) . "</td>"
           . str_repeat("<td class='empty'></td>", 7)
           . "<td class='empty'></td>"
           . "</tr>";
    }
    // Filas vacías de relleno para anotar a mano
    $objetivo = max($i + 3, 30);
    for ($k = $i + 1; $k <= $objetivo; $k++) {
        echo "<tr>"
           . "<td class='n'>$k</td>"
           . "<td class='empty'></td>"
           . str_repeat("<td class='empty'></td>", 7)
           . "<td class='empty'></td>"
           . "</tr>";
    }
    echo "</tbody></table>";
    echo "</body></html>";
    exit;
}

// Vista PDF "Control de Asistencia" (matriz semanal con días lun-dom) ─────────
if (($_GET['asistencia'] ?? '') === '1') {
    $dt0 = new DateTime($semana);
    $sem_lbl = $dt0->format('d/m/y') . ' al ' . (new DateTime($semana_fin))->format('d/m/y');
    echo "<!doctype html><html lang='es'><head><meta charset='UTF-8'>";
    echo "<title>Control de Asistencia " . h($sem_lbl) . "</title>";
    echo "<style>
      @page { size: Letter portrait; margin: 8mm 8mm 10mm 8mm; }
      html,body{margin:0;padding:0}
      body{font-family:Calibri,Arial,sans-serif;font-size:9pt;color:#000;padding:8mm}
      .titulo{background:#4f81bd;color:#fff;font-weight:bold;text-align:center;font-size:15pt;padding:6px;border:1px solid #2a4d7a;}
      .subt  {background:#fff;color:#000;font-weight:bold;text-align:center;font-size:10.5pt;padding:3px;border:1px solid #2a4d7a;border-top:0;margin-bottom:4px;}
      table{border-collapse:collapse;width:100%;table-layout:fixed;}
      th,td{border:1px solid #2a4d7a;padding:1px 3px;font-size:8.5pt;}
      th{background:#dce6f1;text-align:center;color:#1f3864;font-size:8pt;}
      col.c-item{width:7%;} col.c-nom{width:25%;} col.c-day{width:9.7%;}
      td.item{text-align:center;}
      td.nom {text-align:center;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
      td.day {height:14px;}
      .toolbar{margin-bottom:8px}
      .toolbar button{padding:6px 14px;font-size:11pt;cursor:pointer;margin-right:8px}
      @media print { .toolbar{display:none} body{padding:0} }
    </style></head><body>";
    echo "<div class='toolbar'>"
       . "<button onclick='window.print()'>Imprimir / Guardar PDF</button>"
       . "<button onclick=\"window.location='?p=empleados&semana=" . h($semana) . "&semana_fin=" . h($semana_fin) . "'\">Volver</button>"
       . "</div>";
    echo "<div class='titulo'>Control de Asistencia</div>";
    echo "<div class='subt'>Semana del $sem_lbl &nbsp;&nbsp;&nbsp; Matriz: 02#</div>";
    echo "<table>";
    echo "<colgroup><col class='c-item'><col class='c-nom'>"
       . str_repeat("<col class='c-day'>", 7) . "</colgroup>";
    echo "<thead><tr>"
       . "<th>ITEM</th><th>NOMBRE Y APELLIDO</th>"
       . "<th>DOMINGO</th><th>LUNES</th><th>MARTES</th><th>MIERCOLES</th>"
       . "<th>JUEVES</th><th>VIERNES</th><th>SABADO</th>"
       . "</tr></thead><tbody>";
    $i = 0;
    foreach ($emps as $e) {
        $i++;
        $pn = strtok(trim((string)$e['nombre']),    " \t");
        $pa = strtok(trim((string)$e['apellidos']), " \t");
        $nombre_corto = trim(($pn ?: '') . ' ' . ($pa ?: ''));
        echo "<tr><td class='item'>$i</td><td class='nom'>" . h($nombre_corto) . "</td>"
           . str_repeat("<td class='day'></td>", 7) . "</tr>";
    }
    // Rellenar hasta 50 filas (cabe en una carta vertical)
    $objetivo = max($i + 2, 50);
    for ($k = $i + 1; $k <= $objetivo; $k++) {
        echo "<tr><td class='item'>$k</td><td class='nom'></td>"
           . str_repeat("<td class='day'></td>", 7) . "</tr>";
    }
    echo "</tbody></table>";
    echo "<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script>";
    echo "</body></html>";
    exit;
}

// Vista PDF imprimible (formato planilla original) ────────────────────────────
if (($_GET['pdf'] ?? '') === '1') {
    $sem_lbl = (new DateTime($semana))->format('d-m') . ' al ' . (new DateTime($semana_fin))->format('d-m');
    echo "<!doctype html><html lang='es'><head><meta charset='UTF-8'>";
    echo "<title>Nómina " . h($sem_lbl) . "</title>";
    echo "<style>
      @page { size: Letter portrait; margin: 8mm 8mm 10mm 8mm; }
      html,body{margin:0;padding:0}
      body{font-family:Calibri,Arial,sans-serif;font-size:9pt;color:#000;padding:8mm}
      table{border-collapse:collapse;width:100%;table-layout:fixed;}
      th,td{border:1px solid #2e7d32;padding:2px 4px;font-size:8.5pt;word-wrap:break-word;}
      th{background:#c8e6c9;text-align:center;font-size:8.5pt;}
      .titulo{background:#9bd49b;font-weight:bold;text-align:center;font-size:13pt;padding:5px;border:1px solid #2e7d32;}
      .num{text-align:right;}
      .totales{background:#9bd49b;font-weight:bold;}
      col.c-nom{width:15%;} col.c-ape{width:16%;} col.c-d{width:9.4%;} col.c-dnt{width:6.5%;}
      .firmas{margin-top:40px;display:flex;justify-content:space-around;text-align:center;}
      .firmas div{border-top:1px solid #000;padding-top:4px;width:30%;font-weight:bold;font-size:10pt;}
      .toolbar{margin-bottom:8px}
      .toolbar button{padding:6px 14px;font-size:11pt;cursor:pointer;margin-right:8px}
      @media print { .toolbar{display:none} body{padding:0} }
    </style></head><body>";
    echo "<div class='toolbar'>"
       . "<button onclick='window.print()'>Imprimir / Guardar PDF</button>"
       . "<button onclick=\"window.location='?p=empleados&semana=" . h($semana) . "&semana_fin=" . h($semana_fin) . "'\">Volver</button>"
       . "</div>";
    echo "<div class='titulo'>SEMANA del $sem_lbl</div>";
    echo "<table>";
    echo "<colgroup><col class='c-nom'><col class='c-ape'>"
       . str_repeat("<col class='c-d'>", 4)
       . "<col class='c-dnt'><col class='c-d'><col class='c-d'><col class='c-d'>"
       . "</colgroup>";
    echo "<thead><tr><th>Nombres</th><th>Apellidos</th><th>Sueldo</th><th>B-C</th><th>M/V</th><th>Pendien</th><th>Días N/T</th><th>$ Días N/T</th><th>Total Semana</th><th>RESTO $</th></tr></thead><tbody>";
    $T = ['sueldo'=>0,'bc'=>0,'mv'=>0,'pendiente'=>0,'dias_nt'=>0,'desc_dias'=>0,'total_sem'=>0,'resto'=>0];
    foreach ($emps as $e) {
        $c = calc_fila_simple($e, (float)$tasa);
        foreach ($T as $k=>$v) $T[$k] += $c[$k];
        echo "<tr><td>" . h($e['nombre']) . "</td><td>" . h($e['apellidos']) . "</td>"
           . "<td class='num'>$ " . fmt_money2($c['sueldo']) . "</td>"
           . "<td class='num'>$ " . fmt_money2($c['bc'])     . "</td>"
           . "<td class='num'>$ " . fmt_money2($c['mv'])     . "</td>"
           . "<td class='num'>$ " . fmt_money2($c['pendiente']) . "</td>"
           . "<td style='text-align:center'>" . ($c['dias_nt'] > 0 ? rtrim(rtrim(number_format($c['dias_nt'],2,'.',''),'0'),'.') : '0') . "</td>"
           . "<td class='num'>$ " . fmt_money2($c['desc_dias']) . "</td>"
           . "<td class='num'>$ " . fmt_money2($c['total_sem']) . "</td>"
           . "<td class='num'>$ " . fmt_money2($c['resto']) . "</td></tr>";
    }
    $n = count($emps);
    echo "<tr class='totales'><td>$n</td><td>$n</td>"
       . "<td class='num'>$ " . fmt_money2($T['sueldo'])    . "</td>"
       . "<td class='num'>$ " . fmt_money2($T['bc'])        . "</td>"
       . "<td class='num'>$ " . fmt_money2($T['mv'])        . "</td>"
       . "<td class='num'>$ " . fmt_money2($T['pendiente']) . "</td>"
       . "<td style='text-align:center'>" . rtrim(rtrim(number_format($T['dias_nt'],2,'.',''),'0'),'.') . "</td>"
       . "<td class='num'>$ " . fmt_money2($T['desc_dias']) . "</td>"
       . "<td class='num'>$ " . fmt_money2($T['total_sem']) . "</td>"
       . "<td class='num'>$ " . fmt_money2($T['resto']) . "</td></tr>";
    echo "</tbody></table>";
    echo "<div class='firmas'><div>SELLO</div><div>FIRMA</div></div>";
    echo "<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script>";
    echo "</body></html>";
    exit;
}

// Exportación a Excel completa (HTML table con MIME xls)
if (($_GET['export'] ?? '') === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="nomina_' . $semana . '.xls"');
    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<h3>$EMPRESA — Nómina semana $semana al $semana_fin (Tasa BCV $tasa)</h3>";
}

layout_start('Empleados — Nómina semanal', 'empleados');
?>
<style>
  .nomina-header { background:#fff8e1; border:1px solid #e0c97a; border-radius:.5rem;
                   padding:.6rem .9rem; font-size:.85rem; margin-bottom:.75rem; }
  .nomina-header .lbl { font-weight:600; color:#5a3e00; }
  .nomina-header .tasa { background:#198754; color:#fff; padding:.2rem .6rem;
                         border-radius:.3rem; font-weight:700; }
  .tabla-nomina { font-size:.72rem; white-space:nowrap; }
  .tabla-nomina th { background:#1f2d5c; color:#fff; text-align:center;
                     padding:.3rem .4rem; font-weight:600; vertical-align:middle; }
  .tabla-nomina td { padding:.2rem .35rem; vertical-align:middle; }
  .tabla-nomina tbody tr:nth-child(even) { background:#fafafa; }
  .tabla-nomina input.cell { width:70px; padding:.1rem .25rem; font-size:.7rem;
                              border:1px solid #ccc; border-radius:.2rem; text-align:right; }
  .tabla-nomina .calc { background:#e8f4ff; color:#0a4b6b; font-weight:600; text-align:right; }
  .tabla-nomina .ro   { background:#f4f4f4; text-align:right; }
  .tabla-nomina .ctr  { text-align:center; }
  .tabla-nomina .num  { text-align:right; }
  .scroll-x { overflow-x:auto; }
  .scroll-top { overflow-x:auto; overflow-y:hidden; height:18px; }
  .scroll-top > div { height:1px; }
</style>

<div class="nomina-header d-flex flex-wrap gap-3 align-items-center justify-content-between">
  <div>
    <div><span class="lbl">EMPRESA:</span> <?= h($EMPRESA) ?></div>
    <div><span class="lbl">RIF:</span> <?= h($RIF) ?></div>
    <div><span class="lbl">TIPO DE NÓMINA:</span> Semanal</div>
    <div><span class="lbl">PERÍODO:</span> SEMANA del
      <?= (new DateTime($semana))->format('d-m') ?> al
      <?= (new DateTime($semana_fin))->format('d-m-Y') ?>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <form class="d-flex gap-1 align-items-center" method="get">
      <input type="hidden" name="p" value="empleados">
      <label class="lbl small mb-0">Del:</label>
      <input type="date" name="semana" value="<?= h($semana) ?>" class="form-control form-control-sm" style="width:140px" title="Fecha de inicio del período">
      <label class="lbl small mb-0">al:</label>
      <input type="date" name="semana_fin" value="<?= h($semana_fin) ?>" class="form-control form-control-sm" style="width:140px" title="Fecha de fin del período">
      <button class="btn btn-sm btn-outline-primary">Ir</button>
    </form>
    <span class="tasa">TASA BCV: <?= fmt_money2($tasa) ?></span>
    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalEmp">+ Empleado</button>
    <button type="submit" form="formNomina" class="btn btn-sm btn-primary fw-bold" title="Guarda la nómina de esta semana. Cada semana queda archivada y se puede consultar después con el selector de semana.">💾 Guardar semana</button>
    <a class="btn btn-sm btn-outline-secondary" href="?p=empleados&semana=<?= h($semana) ?>&semana_fin=<?= h($semana_fin) ?>&export=xls">📥 Excel completo</a>
    <a class="btn btn-sm btn-success" href="?p=empleados&semana=<?= h($semana) ?>&semana_fin=<?= h($semana_fin) ?>&export=xls_simple" title="Excel formato planilla (Nombres, Sueldo, B-C, M/V, Pendien, Total Semana)">📊 Excel resumen</a>
    <a class="btn btn-sm btn-danger" href="?p=empleados&semana=<?= h($semana) ?>&semana_fin=<?= h($semana_fin) ?>&pdf=1" target="_blank" title="Vista PDF de la nómina resumen">📄 PDF nómina</a>
    <a class="btn btn-sm btn-info text-white" href="?p=empleados&semana=<?= h($semana) ?>&semana_fin=<?= h($semana_fin) ?>&asistencia=1" target="_blank" title="PDF Control de Asistencia (lista con primer nombre + primer apellido)">📋 Asistencia</a>
    <a class="btn btn-sm btn-dark" href="?p=empleados&semana=<?= h($semana) ?>&semana_fin=<?= h($semana_fin) ?>&notaria=1" target="_blank" title="Hoja de Notar&iacute;a A-Z con los datos de la semana">📜 Notaría</a>
  </div>
</div>

<div class="d-flex gap-2 align-items-center mb-2 flex-wrap">
  <div class="input-group input-group-sm" style="max-width:340px">
    <span class="input-group-text">🔍</span>
    <input id="buscarEmp" type="search" class="form-control"
           placeholder="Buscar por nombre, cédula, cargo, teléfono…" autocomplete="off">
    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('buscarEmp').value='';filtrarEmp()">✕</button>
  </div>
  <span id="contadorEmp" class="text-muted small"></span>
</div>
<script>
function filtrarEmp() {
  const q = (document.getElementById('buscarEmp').value || '').toLowerCase().trim();
  let visibles = 0, total = 0;
  document.querySelectorAll('tbody tr[data-emp]').forEach(tr => {
    total++;
    const txt = tr.getAttribute('data-emp');
    const ok = !q || txt.indexOf(q) !== -1;
    tr.style.display = ok ? '' : 'none';
    if (ok) visibles++;
  });
  const c = document.getElementById('contadorEmp');
  if (c) c.textContent = q ? (visibles + ' de ' + total + ' empleados') : (total + ' empleados');
  const tot = document.getElementById('filaTotales');
  if (tot) tot.style.display = q ? 'none' : '';
}
document.addEventListener('DOMContentLoaded', () => {
  const b = document.getElementById('buscarEmp');
  const qHidden = document.getElementById('qHidden');
  if (b) {
    // 1) Restaurar desde la URL (?q=...) — sobrevive Enter, Guardar y recarga
    const url = new URL(window.location.href);
    const fromUrl = url.searchParams.get('q') || '';
    if (fromUrl) b.value = fromUrl;
    if (qHidden) qHidden.value = b.value;

    const updateUrl = () => {
      const u = new URL(window.location.href);
      if (b.value) u.searchParams.set('q', b.value);
      else         u.searchParams.delete('q');
      history.replaceState(null, '', u.toString());
      if (qHidden) qHidden.value = b.value;
    };

    b.addEventListener('input', () => { updateUrl(); filtrarEmp(); });
    // La "x" del input type=search dispara 'search' con value vacío
    b.addEventListener('search', () => { updateUrl(); filtrarEmp(); });
    // Si pulsa Enter dentro del buscador, evitar submit del form principal
    b.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); b.blur(); }
    });
    filtrarEmp();
  }

  // Sincroniza el scroll horizontal superior con el de la tabla
  const top = document.getElementById('scrollTop');
  const body = document.getElementById('scrollBody');
  const inner = document.getElementById('scrollTopInner');
  if (top && body && inner) {
    const sync = () => { inner.style.width = body.scrollWidth + 'px'; };
    sync(); window.addEventListener('resize', sync);
    let lock = false;
    top.addEventListener('scroll', () => { if(lock) return; lock=true; body.scrollLeft = top.scrollLeft; lock=false; });
    body.addEventListener('scroll', () => { if(lock) return; lock=true; top.scrollLeft = body.scrollLeft; lock=false; });
  }
});
</script>

<form method="post" id="formNomina">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="action" value="save_nomina">
<input type="hidden" name="semana" value="<?= h($semana) ?>">
<input type="hidden" name="q" id="qHidden" value="<?= h($_GET['q'] ?? '') ?>">

<div class="card">
  <!-- Barra de scroll horizontal espejo, en la parte SUPERIOR -->
  <div id="scrollTop" class="scroll-top border-bottom">
    <div id="scrollTopInner"></div>
  </div>
  <div id="scrollBody" class="card-body p-0 scroll-x">
    <table class="table table-bordered tabla-nomina mb-0">
      <thead>
        <tr>
          <th>N°</th>
          <th>FECHA<br>ENTRADA</th>
          <th>FECHA<br>SALIDA</th>
          <th>Cédula</th>
          <th>Nombres</th>
          <th>Apellidos</th>
          <th>Cargo /<br>Puesto</th>
          <th>Salario<br>semanal</th>
          <th>Salario<br>diario</th>
          <th>Días no<br>trabajados</th>
          <th>Pagos<br>extras</th>
          <th>Salario<br>+ extras</th>
          <th>Vales /<br>mercancía</th>
          <th>Desc.<br>días</th>
          <th>Neto<br>pagar $</th>
          <th>Monto<br>en Bs</th>
          <th>Desc. merc.<br>u otros $</th>
          <th>BODEGA<br>Bs</th>
          <th>Resto a<br>pagar Bs</th>
          <th>RESTO<br>$</th>
          <th>PENDIENTE<br>$</th>
          <th>PENDIENTE<br>Bs</th>
          <th>Cuenta<br>propia</th>
          <th>Dirección</th>
          <th>Exp.<br>penal</th>
          <th>Fecha<br>nacim.</th>
          <th>Teléfono</th>
          <th>Acc.</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $i = 0;
      $tot_salsem = 0; $tot_saldia = 0; $tot_dias = 0; $tot_extras = 0;
      $tot_salext = 0; $tot_vales = 0; $tot_descd = 0;
      $tot_neto = 0; $tot_bs = 0; $tot_descm = 0; $tot_bodega = 0;
      $tot_resto_bs = 0; $tot_resto_usd = 0;
      $tot_pend_usd = 0; $tot_pend_bs = 0;
      foreach ($emps as $e):
        $i++;
        $sal_sem = (float)$e['salario_semanal'];
        $sal_dia = $sal_sem / 7;
        $dias    = (float)($e['dias_no_trabajados'] ?? 0);
        $extras  = (float)($e['pagos_extras'] ?? 0);
        $vales   = (float)($e['vales_mercancia'] ?? 0);
        $bodega  = (float)($e['bodega_bs'] ?? 0);
        $pend    = (float)($e['pendiente'] ?? 0);
        $sal_ext = $sal_sem + $extras;
        $desc_d  = $sal_dia * $dias;
        $neto    = $sal_ext - $vales - $desc_d;
        $monto_b = $neto * $tasa;
        $desc_m  = $tasa > 0 ? $bodega / $tasa : 0;
        $resto_b = $monto_b - $bodega;
        $resto_u = $tasa > 0 ? $resto_b / $tasa : 0;
        $pend_bs = $pend * $tasa;
        $tot_salsem += $sal_sem; $tot_saldia += $sal_dia; $tot_dias += $dias;
        $tot_extras += $extras; $tot_salext += $sal_ext; $tot_vales += $vales;
        $tot_descd += $desc_d; $tot_descm += $desc_m;
        $tot_neto += $neto; $tot_bs += $monto_b; $tot_bodega += $bodega;
        $tot_resto_bs += $resto_b; $tot_resto_usd += $resto_u;
        $tot_pend_usd += $pend; $tot_pend_bs += $pend_bs;
        $buscar = strtolower(trim(($e['nombre'] ?? '').' '.($e['apellidos'] ?? '').' '.($e['cedula'] ?? '').' '.($e['cargo'] ?? '').' '.($e['telefono'] ?? '').' '.($e['direccion'] ?? '')));
      ?>
        <tr data-emp="<?= h($buscar) ?>">
          <td class="ctr"><?= $i ?></td>
          <td class="ctr"><?= h($e['fecha_ingreso'] ? (new DateTime($e['fecha_ingreso']))->format('d/m/Y') : '') ?></td>
          <td class="ctr"><?= h($e['fecha_salida'] ? (new DateTime($e['fecha_salida']))->format('d/m/Y') : '') ?></td>
          <td><?= h($e['cedula']) ?></td>
          <td><?= h($e['nombre']) ?></td>
          <td><?= h($e['apellidos']) ?></td>
          <td><?= h($e['cargo']) ?></td>
          <td class="ro">$<?= fmt_money2($sal_sem) ?></td>
          <td class="ro">$<?= fmt_money4($sal_dia) ?></td>
          <td><input class="cell" type="number" step="0.5" min="0" max="7"
                     name="nom[<?= $e['id'] ?>][dias]"   value="<?= $dias ?: '' ?>"></td>
          <td><input class="cell" type="number" step="0.01" min="0"
                     name="nom[<?= $e['id'] ?>][extras]" value="<?= $extras ?: '' ?>"></td>
          <td class="calc">$<?= fmt_money2($sal_ext) ?></td>
          <td><input class="cell" type="number" step="0.01" min="0"
                     name="nom[<?= $e['id'] ?>][vales]"  value="<?= $vales ?: '' ?>"></td>
          <td class="ro">$<?= fmt_money2($desc_d) ?></td>
          <td class="calc">$<?= fmt_money2($neto) ?></td>
          <td class="calc">Bs <?= fmt_money2($monto_b) ?></td>
          <td class="ro">$<?= fmt_money4($desc_m) ?></td>
          <td><input class="cell" type="number" step="0.01" min="0"
                     name="nom[<?= $e['id'] ?>][bodega]" value="<?= $bodega ?: '' ?>"></td>
          <td class="calc">Bs <?= fmt_money2($resto_b) ?></td>
          <td class="calc">$<?= fmt_money4($resto_u) ?></td>
          <td><input class="cell" type="number" step="0.01" min="0"
                     name="nom[<?= $e['id'] ?>][pendiente]" value="<?= $pend ?: '' ?>"
                     title="Monto pendiente (manual)"></td>
          <td class="calc">Bs <?= fmt_money2($pend_bs) ?></td>
          <td class="ctr"><?= $e['cuenta_propia'] ? 'SI' : 'NO' ?></td>
          <td><?= h($e['direccion']) ?></td>
          <td class="ctr"><?= $e['expediente_penal'] ? 'SI' : 'NO' ?></td>
          <td class="ctr"><?= h($e['fecha_nacimiento'] ? (new DateTime($e['fecha_nacimiento']))->format('d/m/Y') : '') ?></td>
          <td><?= h($e['telefono']) ?></td>
          <td class="ctr">
            <a href="?p=empleados&action=edit&id=<?= $e['id'] ?>&semana=<?= h($semana) ?>"
               class="btn btn-sm btn-warning py-0 px-2" title="Modificar datos del empleado"
               style="font-size:.7rem; font-weight:600;">✎ Editar</a>
          </td>
        </tr>
      <?php endforeach ?>
      <?php if (!$emps): ?>
        <tr><td colspan="28" class="text-center text-muted py-4">Sin empleados activos. Agrega uno con "+ Empleado".</td></tr>
      <?php else: ?>
        <tr id="filaTotales" style="background:#fff3cd; font-weight:700;">
          <td colspan="7" class="text-end">TOTALES:</td>
          <td class="calc">$<?= fmt_money2($tot_salsem) ?></td>
          <td class="calc">$<?= fmt_money4($tot_saldia) ?></td>
          <td class="calc"><?= fmt_money2($tot_dias) ?></td>
          <td class="calc">$<?= fmt_money2($tot_extras) ?></td>
          <td class="calc">$<?= fmt_money2($tot_salext) ?></td>
          <td class="calc">$<?= fmt_money2($tot_vales) ?></td>
          <td class="calc">$<?= fmt_money2($tot_descd) ?></td>
          <td class="calc">$<?= fmt_money2($tot_neto) ?></td>
          <td class="calc">Bs <?= fmt_money2($tot_bs) ?></td>
          <td class="calc">$<?= fmt_money4($tot_descm) ?></td>
          <td class="calc">Bs <?= fmt_money2($tot_bodega) ?></td>
          <td class="calc">Bs <?= fmt_money2($tot_resto_bs) ?></td>
          <td class="calc">$<?= fmt_money2($tot_resto_usd) ?></td>
          <td class="calc">$<?= fmt_money2($tot_pend_usd) ?></td>
          <td class="calc">Bs <?= fmt_money2($tot_pend_bs) ?></td>
          <td colspan="6"></td>
        </tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
  <?php if ($emps): ?>
  <div class="card-footer">
    <small class="text-muted">Edita los campos en amarillo (días, extras, vales, bodega). Al pulsar <b>💾 Guardar semana</b> arriba, los datos quedan archivados para esa semana — puedes cambiar el selector de semana para consultar semanas anteriores.</small>
  </div>
  <?php endif ?>
</div>
</form>

<!-- Modal Nuevo / Editar empleado -->
<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar empleado</h5>
      <a href="?p=empleados&semana=<?= h($semana) ?>" class="btn-close"></a></div>
    <div class="modal-body"><?= render_emp_form($edit, $semana) ?></div>
  </div></div>
</div>
<?php endif ?>

<div class="modal fade" id="modalEmp" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Nuevo empleado</h5>
      <button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><?= render_emp_form(null, $semana) ?></div>
  </div></div>
</div>

<?php layout_end();

function render_emp_form(?array $r, string $semana): string {
    $csrf = csrf_token();
    $cp = !empty($r['cuenta_propia']); $ep = !empty($r['expediente_penal']);
    $act = !$r || !empty($r['estado']);
    ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_emp">
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">
      <div class="row g-2">
        <div class="col-4"><label class="form-label">Cédula</label>
          <input name="cedula" class="form-control form-control-sm" value="<?= h($r['cedula'] ?? '') ?>" required></div>
        <div class="col-4"><label class="form-label">Nombres</label>
          <input name="nombre" class="form-control form-control-sm" value="<?= h($r['nombre'] ?? '') ?>" required></div>
        <div class="col-4"><label class="form-label">Apellidos</label>
          <input name="apellidos" class="form-control form-control-sm" value="<?= h($r['apellidos'] ?? '') ?>"></div>
        <div class="col-4"><label class="form-label">Cargo / Puesto</label>
          <input name="cargo" class="form-control form-control-sm" value="<?= h($r['cargo'] ?? '') ?>"></div>
        <div class="col-4"><label class="form-label">Teléfono</label>
          <input name="telefono" class="form-control form-control-sm" value="<?= h($r['telefono'] ?? '') ?>"></div>
        <div class="col-4"><label class="form-label">Salario semanal (USD)</label>
          <input name="salario_semanal" type="number" step="0.01" class="form-control form-control-sm" value="<?= h($r['salario_semanal'] ?? 0) ?>"></div>
        <div class="col-12"><label class="form-label">Dirección / vivienda</label>
          <input name="direccion" class="form-control form-control-sm" value="<?= h($r['direccion'] ?? '') ?>"></div>
        <div class="col-4"><label class="form-label">Fecha ingreso</label>
          <input name="fecha_ingreso" type="date" class="form-control form-control-sm"
                 value="<?= h(isset($r['fecha_ingreso']) ? substr($r['fecha_ingreso'],0,10) : date('Y-m-d')) ?>"></div>
        <div class="col-4"><label class="form-label">Fecha salida</label>
          <input name="fecha_salida" type="date" class="form-control form-control-sm"
                 value="<?= h(isset($r['fecha_salida']) ? substr($r['fecha_salida'],0,10) : '') ?>"></div>
        <div class="col-4"><label class="form-label">Fecha nacimiento</label>
          <input name="fecha_nacimiento" type="date" class="form-control form-control-sm"
                 value="<?= h(isset($r['fecha_nacimiento']) ? substr($r['fecha_nacimiento'],0,10) : '') ?>"></div>
        <div class="col-4 d-flex align-items-end">
          <div class="form-check"><input type="checkbox" name="cuenta_propia" class="form-check-input" id="cp" <?= $cp?'checked':'' ?>>
            <label for="cp" class="form-check-label">Cuenta propia</label></div></div>
        <div class="col-4 d-flex align-items-end">
          <div class="form-check"><input type="checkbox" name="expediente_penal" class="form-check-input" id="ep" <?= $ep?'checked':'' ?>>
            <label for="ep" class="form-check-label">Expediente penal</label></div></div>
        <div class="col-4 d-flex align-items-end">
          <div class="form-check"><input type="checkbox" name="estado" class="form-check-input" id="es" <?= $act?'checked':'' ?>>
            <label for="es" class="form-check-label">Activo</label></div></div>
      </div>
      <div class="mt-3 d-flex gap-2 justify-content-between">
        <?php if (!empty($r['id'])): ?>
          <button type="button" class="btn btn-outline-danger btn-sm"
                  onclick="if(confirm('¿Eliminar empleado?')){document.getElementById('frmDel<?= $r['id'] ?>').submit()}">Eliminar</button>
        <?php else: ?><span></span><?php endif ?>
        <div class="d-flex gap-2">
          <a href="?p=empleados&semana=<?= h($semana) ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
          <button class="btn btn-primary btn-sm">Guardar</button>
        </div>
      </div>
    </form>
    <?php if (!empty($r['id'])): ?>
    <form id="frmDel<?= $r['id'] ?>" method="post" class="d-none">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="delete_emp">
      <input type="hidden" name="id" value="<?= $r['id'] ?>">
    </form>
    <?php endif ?>
    <?php return ob_get_clean();
}
