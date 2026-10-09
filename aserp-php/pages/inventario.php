<?php
require_login();
$db = get_db();
ensure_almacenes_schema();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'alm_rename') {
        $nombres = $_POST['nombre'] ?? [];
        foreach ($nombres as $id => $nom) {
            $nom = trim((string)$nom);
            if ($nom !== '') {
                $db->prepare("UPDATE almacenes SET nombre=? WHERE id=?")->execute([$nom, (int)$id]);
            }
        }
        flash('Almacenes actualizados.');
        header('Location: ?p=inventario'); exit;
    }
    if ($act === 'alm_add') {
        $nom = trim($_POST['nombre_nuevo'] ?? '');
        $nom = mb_substr($nom, 0, 80);
        if ($nom !== '') {
            $maxOrden = (int)$db->query("SELECT COALESCE(MAX(orden),0) FROM almacenes")->fetchColumn();
            $db->prepare("INSERT INTO almacenes (nombre, orden) VALUES (?,?)")->execute([$nom, $maxOrden + 10]);
            flash('Almacén "' . $nom . '" creado.');
        } else {
            flash('Escribe un nombre para el nuevo almacén.', 'warning');
        }
        header('Location: ?p=inventario'); exit;
    }
    if ($act === 'alm_delete') {
        $id    = (int)($_POST['id'] ?? 0);
        $total = (int)$db->query("SELECT COUNT(*) FROM almacenes")->fetchColumn();
        $q = $db->prepare("SELECT nombre FROM almacenes WHERE id=?");
        $q->execute([$id]);
        $nom = $q->fetchColumn();
        if ($id <= 0 || $nom === false) {
            flash('Ese almacén ya no existe.', 'error');
        } elseif ($total <= 1) {
            flash('No puedes eliminar el último almacén. Debe quedar al menos uno.', 'error');
        } else {
            // Almacén destino: el primero que queda. Su stock e historial se mueven aquí
            // para no perder inventario ni dejar referencias rotas en ventas/producción.
            $dq = $db->prepare("SELECT id, nombre FROM almacenes WHERE id<>? ORDER BY orden, id LIMIT 1");
            $dq->execute([$id]);
            $dest    = $dq->fetch();
            $destId  = (int)($dest['id'] ?? 0);
            $destNom = (string)($dest['nombre'] ?? '');
            try {
                $db->beginTransaction();
                // 1) Mover stock al destino (sumando a lo que ya tenga).
                $sel = $db->prepare("SELECT producto_id, stock FROM productos_stock WHERE almacen_id=?");
                $sel->execute([$id]);
                $chk = $db->prepare("SELECT 1 FROM productos_stock WHERE producto_id=? AND almacen_id=?");
                $upd = $db->prepare("UPDATE productos_stock SET stock = stock + ? WHERE producto_id=? AND almacen_id=?");
                $ins = $db->prepare("INSERT INTO productos_stock (producto_id, almacen_id, stock) VALUES (?,?,?)");
                foreach ($sel->fetchAll() as $r) {
                    $pid = (int)$r['producto_id']; $stk = (float)$r['stock'];
                    $chk->execute([$pid, $destId]);
                    if ($chk->fetchColumn()) $upd->execute([$stk, $pid, $destId]);
                    else                     $ins->execute([$pid, $destId, $stk]);
                }
                $db->prepare("DELETE FROM productos_stock WHERE almacen_id=?")->execute([$id]);
                // 2) Producción diaria: reapuntar al destino, fusionando si choca con el índice único.
                $pd      = $db->prepare("SELECT id, fecha, producto_produccion_id, cantidad, total FROM produccion_diaria WHERE almacen_id=?");
                $pd->execute([$id]);
                $pdFind  = $db->prepare("SELECT id FROM produccion_diaria WHERE fecha=? AND producto_produccion_id=? AND almacen_id=?");
                $pdMerge = $db->prepare("UPDATE produccion_diaria SET cantidad = cantidad + ?, total = total + ? WHERE id=?");
                $pdDel   = $db->prepare("DELETE FROM produccion_diaria WHERE id=?");
                $pdRemap = $db->prepare("UPDATE produccion_diaria SET almacen_id=? WHERE id=?");
                foreach ($pd->fetchAll() as $r) {
                    $pdFind->execute([$r['fecha'], $r['producto_produccion_id'], $destId]);
                    $existe = $pdFind->fetchColumn();
                    if ($existe) {
                        $pdMerge->execute([$r['cantidad'], $r['total'], (int)$existe]);
                        $pdDel->execute([(int)$r['id']]);
                    } else {
                        $pdRemap->execute([$destId, (int)$r['id']]);
                    }
                }
                // 3) Facturas y su detalle: reapuntar al destino (sin índice único que choque).
                $db->prepare("UPDATE facturas SET almacen_id=? WHERE almacen_id=?")->execute([$destId, $id]);
                $db->prepare("UPDATE detalle_factura SET almacen_id=? WHERE almacen_id=?")->execute([$destId, $id]);
                // 4) Eliminar el almacén.
                $db->prepare("DELETE FROM almacenes WHERE id=?")->execute([$id]);
                $db->commit();
                $etq = ($nom !== '' ? $nom : ('#' . $id));
                flash('Almacén "' . $etq . '" eliminado. Su stock e historial se movieron a "' . ($destNom !== '' ? $destNom : ('#' . $destId)) . '".');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[alm_delete] ' . $e->getMessage());
                flash('No se pudo eliminar el almacén.', 'error');
            }
        }
        header('Location: ?p=inventario'); exit;
    }
    if ($act === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $codigo = trim($_POST['codigo'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');
        $precio = (float)($_POST['precio'] ?? 0);
        $stock  = (int)($_POST['stock'] ?? 0);
        $umbral = (int)($_POST['umbral_alerta'] ?? 0);
        $moneda = $_POST['moneda'] ?? 'USD';
        $unidad = trim($_POST['unidad_medida'] ?? '');
        $pres   = trim($_POST['presentacion'] ?? '');
        if ($id) {
            $db->prepare('UPDATE productos SET nombre=?,codigo=?,descripcion=?,precio=?,stock=?,umbral_alerta=?,moneda=?,unidad_medida=?,presentacion=? WHERE id=?')
               ->execute([$nombre,$codigo,$desc,$precio,$stock,$umbral,$moneda,$unidad,$pres,$id]);
            flash('Producto actualizado.');
        } else {
            $db->prepare('INSERT INTO productos(nombre,codigo,descripcion,precio,stock,umbral_alerta,moneda,unidad_medida,presentacion) VALUES(?,?,?,?,?,?,?,?,?)')
               ->execute([$nombre,$codigo,$desc,$precio,$stock,$umbral,$moneda,$unidad,$pres]);
            flash('Producto creado.');
        }
    } elseif ($act === 'delete') {
        $pid = (int)$_POST['id'];
        // Si ya está archivado, hacer borrado en cascada (elimina detalle_factura + producción vinculada)
        $stN = $db->prepare('SELECT nombre FROM productos WHERE id=?');
        $stN->execute([$pid]);
        $nmActual = (string)($stN->fetchColumn() ?: '');
        $isArchived = stripos($nmActual, '[ARCHIVADO]') !== false;
        try {
            if ($isArchived) {
                // Cascada total: borrar líneas de factura + producción diaria vinculada
                try { $db->prepare('DELETE FROM detalle_factura WHERE producto_id=?')->execute([$pid]); } catch (Throwable $e) {}
                try {
                    $ppIds = $db->prepare('SELECT id FROM productos_produccion WHERE producto_id=?');
                    $ppIds->execute([$pid]);
                    foreach ($ppIds->fetchAll(\PDO::FETCH_COLUMN) as $ppid) {
                        try { $db->prepare('DELETE FROM produccion_diaria WHERE producto_produccion_id=?')->execute([(int)$ppid]); } catch (Throwable $e) {}
                    }
                    $db->prepare('DELETE FROM productos_produccion WHERE producto_id=?')->execute([$pid]);
                } catch (Throwable $e) {}
            }
            try { $db->prepare('DELETE FROM productos_stock WHERE producto_id=?')->execute([$pid]); } catch (Throwable $e) {}
            try { $db->prepare('UPDATE productos_produccion SET producto_id=NULL WHERE producto_id=?')->execute([$pid]); } catch (Throwable $e) {}
            $db->prepare('DELETE FROM productos WHERE id=?')->execute([$pid]);
            flash($isArchived ? 'Producto archivado eliminado por completo (incluyendo su historial de ventas y producción).' : 'Producto eliminado.', 'warning');
        } catch (\PDOException $e) {
            // FK violation: tiene ventas o producción registrada
            $msg = $e->getMessage();
            if (stripos($msg, 'foreign key') !== false || stripos($msg, '23503') !== false || stripos($msg, '1451') !== false) {
                // Soft delete: poner stock en 0 y nombre con prefijo [ARCHIVADO]
                try {
                    $st = $db->prepare('SELECT nombre FROM productos WHERE id=?');
                    $st->execute([$pid]);
                    $nm = (string)($st->fetchColumn() ?: '');
                    if ($nm !== '' && stripos($nm, '[ARCHIVADO]') === false) {
                        $db->prepare('UPDATE productos SET stock=0, nombre=? WHERE id=?')
                           ->execute(['[ARCHIVADO] ' . $nm, $pid]);
                    } else {
                        $db->prepare('UPDATE productos SET stock=0 WHERE id=?')->execute([$pid]);
                    }
                    try { $db->prepare('DELETE FROM productos_stock WHERE producto_id=?')->execute([$pid]); } catch (Throwable $e2) {}
                    flash('Este producto tiene ventas o producción registrada y no se puede borrar. Se marcó como [ARCHIVADO] con stock 0 para preservar el historial.', 'warning');
                } catch (Throwable $e2) {
                    flash('No se puede eliminar: el producto tiene ventas o producción registrada en el historial.', 'danger');
                }
            } else {
                flash('Error al eliminar: ' . $e->getMessage(), 'danger');
            }
        }
    }
    header('Location: ?p=inventario'); exit;
}

$search = trim($_GET['q'] ?? '');
$where = $search ? "WHERE nombre LIKE ? OR codigo LIKE ?" : '';
$params = $search ? ["%$search%", "%$search%"] : [];
$rows = $db->prepare("SELECT * FROM productos $where ORDER BY nombre");
$rows->execute($params);
$rows = $rows->fetchAll();

// Almacenes + stock por almacén
$almacenes = $db->query("SELECT * FROM almacenes ORDER BY orden, id")->fetchAll();
$ps = $db->query("SELECT producto_id, almacen_id, stock FROM productos_stock")->fetchAll();
$stock_por_alm = []; // [producto_id][almacen_id] => stock
$suma_alm     = []; // [producto_id] => suma stock por almacenes (autoridad)
foreach ($ps as $r) {
    $pid = (int)$r['producto_id']; $aid = (int)$r['almacen_id']; $v = (float)$r['stock'];
    $stock_por_alm[$pid][$aid] = $v;
    $suma_alm[$pid] = ($suma_alm[$pid] ?? 0) + $v;
}
// El stock real de cada producto es la SUMA de sus almacenes (fuente de verdad).
// Si productos.stock está desincronizado, se reescribe aquí para que la próxima visita venga limpio.
foreach ($rows as &$_r) {
    $pid = (int)$_r['id'];
    $real = $suma_alm[$pid] ?? 0.0;
    if (abs((float)$_r['stock'] - $real) > 0.0001) {
        try { $db->prepare('UPDATE productos SET stock=? WHERE id=?')->execute([$real, $pid]); } catch (Throwable $e) {}
    }
    $_r['stock'] = $real;
}
unset($_r);

// ===== Reporte imprimible: inventario por almacén (?p=inventario&pdf=1) =====
if (($_GET['pdf'] ?? '') === '1') {
    $fmt = function($v){ return rtrim(rtrim(number_format((float)$v,3,'.',''),'0'),'.'); };
    $prods = $db->query("SELECT id, nombre, unidad_medida FROM productos WHERE nombre NOT LIKE '%[ARCHIVADO]%' ORDER BY nombre")->fetchAll();
    $fecha = date('d-m-Y H:i');
    $nAlm  = count($almacenes);
    // Totales por almacén
    $tot_alm = []; foreach ($almacenes as $a) $tot_alm[(int)$a['id']] = 0.0;
    $tot_gen = 0.0;
    echo "<!doctype html><html lang='es'><head><meta charset='UTF-8'>";
    echo "<title>Inventario por almacén</title>";
    echo "<style>
      @page { size: Letter portrait; margin: 8mm 8mm 10mm 8mm; }
      html,body{margin:0;padding:0}
      body{font-family:Calibri,Arial,sans-serif;font-size:10pt;color:#000;padding:8mm}
      table{border-collapse:collapse;width:100%;}
      th,td{border:1px solid #6b3a10;padding:3px 6px;font-size:9pt;}
      th{background:#f0d9b8;text-align:center;}
      td.num{text-align:right;}
      .titulo{background:#6b3a10;color:#fff8e8;font-weight:bold;text-align:center;font-size:14pt;padding:7px;border:1px solid #6b3a10;}
      .sub{text-align:center;font-size:9pt;color:#555;margin:4px 0 10px}
      .totales{background:#f0d9b8;font-weight:bold;}
      .prod{font-weight:bold;text-align:left;}
      .toolbar{margin-bottom:10px}
      .toolbar button{padding:6px 14px;font-size:11pt;cursor:pointer;margin-right:8px}
      @media print { .toolbar{display:none} body{padding:0} }
    </style></head><body>";
    echo "<div class='toolbar'>"
       . "<button onclick='window.print()'>Imprimir / Guardar PDF</button>"
       . "<button onclick=\"window.location='?p=inventario'\">Volver</button>"
       . "</div>";
    echo "<div class='titulo'>EL CASTILLO DEL QUESO — INVENTARIO POR ALMACÉN</div>";
    echo "<div class='sub'>Generado el " . h($fecha) . " — " . count($prods) . " productos · " . $nAlm . " almacenes</div>";
    echo "<table><thead><tr><th style='text-align:left'>Producto</th><th>Unidad</th>";
    foreach ($almacenes as $a) echo "<th>" . h($a['nombre']) . "</th>";
    echo "<th>Stock total</th></tr></thead><tbody>";
    foreach ($prods as $p) {
        $pid = (int)$p['id'];
        echo "<tr><td class='prod'>" . h($p['nombre']) . "</td>"
           . "<td style='text-align:center'>" . h($p['unidad_medida']) . "</td>";
        $sumaFila = 0.0;
        foreach ($almacenes as $a) {
            $aid = (int)$a['id'];
            $v = (float)($stock_por_alm[$pid][$aid] ?? 0);
            $tot_alm[$aid] += $v; $sumaFila += $v;
            echo "<td class='num'>" . $fmt($v) . "</td>";
        }
        $tot_gen += $sumaFila;
        echo "<td class='num'><b>" . $fmt($sumaFila) . "</b></td></tr>";
    }
    if (!$prods) {
        echo "<tr><td colspan='" . ($nAlm + 3) . "' style='text-align:center;padding:18px'>Sin productos registrados.</td></tr>";
    }
    echo "</tbody>";
    if ($prods) {
        echo "<tfoot><tr class='totales'><td>TOTALES</td><td></td>";
        foreach ($almacenes as $a) echo "<td class='num'>" . $fmt($tot_alm[(int)$a['id']]) . "</td>";
        echo "<td class='num'>" . $fmt($tot_gen) . "</td></tr></tfoot>";
    }
    echo "</table>";
    echo "<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script>";
    echo "</body></html>";
    exit;
}

// ===== PDF tipo factura de UN solo producto (?p=inventario&pdf=prod&id=N) =====
if (($_GET['pdf'] ?? '') === 'prod' && isset($_GET['id'])) {
    $pid = (int)$_GET['id'];
    $stP = $db->prepare("SELECT * FROM productos WHERE id=?");
    $stP->execute([$pid]);
    $p = $stP->fetch();
    if (!$p) { http_response_code(404); echo "Producto no encontrado."; exit; }
    $fmt = function($v){ return rtrim(rtrim(number_format((float)$v,3,'.',''),'0'),'.'); };
    $t   = get_tasa();
    $usd = $p['moneda']==='USD' ? (float)$p['precio'] : ($t>0 ? (float)$p['precio']/$t : 0);
    $ves = $p['moneda']==='VES' ? (float)$p['precio'] : (float)$p['precio']*$t;
    $stkTotal = $suma_alm[$pid] ?? (float)$p['stock'];
    $umb = (float)$p['umbral_alerta'];
    if     ($umb > 0 && $stkTotal <= $umb)    { $estado='CRÍTICO'; $estCol=[167,29,42];  $estBg=[253,226,226]; }
    elseif ($umb > 0 && $stkTotal <= $umb*2)  { $estado='BAJO';    $estCol=[139,105,20]; $estBg=[255,244,214]; }
    else                                      { $estado='OK';      $estCol=[28,116,48];  $estBg=[226,245,231]; }
    $valUsd = $stkTotal * $usd;
    $valVes = $stkTotal * $ves;
    $fecha  = date('d-m-Y H:i');
    $folio  = 'INV-' . str_pad((string)$pid, 5, '0', STR_PAD_LEFT);
    // Generar PDF descargable con FPDF — descarga directa, sin abrir impresión
    if (!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH', __DIR__ . '/../lib/font/');
    require_once __DIR__ . '/../lib/fpdf.php';
    $tx = function($s){ $r = @iconv('UTF-8','ISO-8859-1//TRANSLIT',(string)$s); return $r === false ? (string)$s : $r; };

    $pdf = new FPDF('P','mm','Letter');
    $pdf->SetMargins(15,15,15);
    $pdf->SetAutoPageBreak(true,15);
    $pdf->AddPage();
    $W = 186;

    // Título
    $pdf->SetFillColor(107,58,16); $pdf->SetTextColor(255,248,232); $pdf->SetFont('Arial','B',16);
    $pdf->Cell($W,12,$tx('EL CASTILLO DEL QUESO'),0,1,'C',true);

    // Cabecera del documento (empresa a la izquierda, folio/fecha a la derecha)
    $yTop = $pdf->GetY() + 2;
    $pdf->SetXY(15,$yTop); $pdf->SetTextColor(90,90,90); $pdf->SetFont('Arial','',9);
    $pdf->MultiCell($W*0.55,5,$tx("Quesos artesanales\nFicha de producto / Inventario"),0,'L');
    $pdf->SetXY(15 + $W*0.55, $yTop);
    $pdf->SetFont('Arial','B',11); $pdf->SetTextColor(107,58,16);
    $pdf->Cell($W*0.45,5,$tx('FICHA '.$folio),0,2,'R');
    $pdf->SetFont('Arial','',9); $pdf->SetTextColor(60,60,60);
    $pdf->Cell($W*0.45,5,$tx('Fecha: '.$fecha),0,2,'R');
    if ($t > 0) $pdf->Cell($W*0.45,5,$tx('Tasa: Bs. '.number_format($t,2,'.',',').' / USD'),0,2,'R');
    $pdf->SetXY(15,$yTop + 18);

    // Nombre del producto + estado (reduce el tamaño si el nombre es muy largo)
    $nombreTx = $tx($p['nombre']);
    $badgeW   = $pdf->GetStringWidth($tx($estado)) + 8;  // ancho aprox del badge (a 9pt)
    $maxNameW = $W - $badgeW - 4;
    $pdf->SetTextColor(58,36,16);
    $nameFs = 15;
    do { $pdf->SetFont('Arial','B',$nameFs); $nameFs--; }
    while ($nameFs >= 9 && $pdf->GetStringWidth($nombreTx) > $maxNameW);
    $pdf->Cell($pdf->GetStringWidth($nombreTx)+2,9,$nombreTx,0,0,'L');
    $pdf->SetFont('Arial','B',9);
    $pdf->SetFillColor($estBg[0],$estBg[1],$estBg[2]); $pdf->SetTextColor($estCol[0],$estCol[1],$estCol[2]);
    $pdf->Cell($badgeW,7,$tx($estado),0,1,'C',true);

    // Meta
    $metaParts = [];
    if (trim((string)$p['codigo']) !== '')        $metaParts[] = 'Código: ' . $p['codigo'];
    if (trim((string)$p['presentacion']) !== '')  $metaParts[] = 'Presentación: ' . $p['presentacion'];
    if (trim((string)$p['unidad_medida']) !== '') $metaParts[] = 'Unidad: ' . $p['unidad_medida'];
    $pdf->SetX(15); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(122,98,56);
    if ($metaParts) $pdf->MultiCell($W,5,$tx(implode('   -   ', $metaParts)),0,'L');
    if (trim((string)$p['descripcion']) !== '') $pdf->MultiCell($W,5,$tx($p['descripcion']),0,'L');
    $pdf->Ln(3);

    // Tabla: precio, existencia y valor de inventario
    $w = [80,38,33,35];
    $pdf->SetDrawColor(107,58,16); $pdf->SetFillColor(240,217,184); $pdf->SetTextColor(0,0,0); $pdf->SetFont('Arial','B',10);
    $pdf->Cell($w[0],8,$tx('Concepto'),1,0,'L',true);
    $pdf->Cell($w[1],8,$tx('Precio unitario'),1,0,'R',true);
    $pdf->Cell($w[2],8,$tx('Existencia'),1,0,'R',true);
    $pdf->Cell($w[3],8,$tx('Valor inventario'),1,1,'R',true);
    $pdf->SetFont('Arial','',10);
    $pdf->Cell($w[0],7,$tx($p['nombre'].' (USD)'),1,0,'L');
    $pdf->Cell($w[1],7,$tx('$'.number_format($usd,2,'.',',')),1,0,'R');
    $pdf->Cell($w[2],7,$tx($fmt($stkTotal).' '.$p['unidad_medida']),1,0,'R');
    $pdf->Cell($w[3],7,$tx('$'.number_format($valUsd,2,'.',',')),1,1,'R');
    $pdf->Cell($w[0],7,$tx($p['nombre'].' (Bs.)'),1,0,'L');
    $pdf->Cell($w[1],7,$tx('Bs. '.number_format($ves,2,'.',',')),1,0,'R');
    $pdf->Cell($w[2],7,$tx($fmt($stkTotal).' '.$p['unidad_medida']),1,0,'R');
    $pdf->Cell($w[3],7,$tx('Bs. '.number_format($valVes,2,'.',',')),1,1,'R');
    $pdf->Ln(5);

    // Tabla: existencias por almacén
    $pdf->SetFont('Arial','B',10); $pdf->SetFillColor(240,217,184);
    $pdf->Cell(146,8,$tx('Existencia por almacén'),1,0,'L',true);
    $pdf->Cell(40,8,$tx('Stock'),1,1,'R',true);
    $pdf->SetFont('Arial','',10);
    $sumChk = 0.0;
    foreach ($almacenes as $a) {
        $aid = (int)$a['id'];
        $v = (float)($stock_por_alm[$pid][$aid] ?? 0);
        $sumChk += $v;
        $pdf->Cell(146,7,$tx($a['nombre']),1,0,'L');
        $pdf->Cell(40,7,$tx($fmt($v)),1,1,'R');
    }
    $pdf->SetFont('Arial','B',10); $pdf->SetFillColor(240,217,184);
    $pdf->Cell(146,8,$tx('STOCK TOTAL'),1,0,'L',true);
    $pdf->Cell(40,8,$tx($fmt($sumChk).' '.$p['unidad_medida']),1,1,'R',true);
    $pdf->Ln(18);

    // Firmas
    $pdf->SetFont('Arial','',9); $pdf->SetTextColor(90,90,90);
    $pdf->Cell(80,6,$tx('Elaborado por'),'T',0,'C');
    $pdf->Cell(26,6,'',0,0,'C');
    $pdf->Cell(80,6,$tx('Recibido / Sello'),'T',1,'C');

    while (ob_get_level()) ob_end_clean();
    $fname = 'Ficha_'.preg_replace('/[^A-Za-z0-9]+/','_', (string)$p['nombre']).'_'.$folio.'.pdf';
    $pdf->Output('D', $fname);
    exit;
}

$edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $st = $db->prepare('SELECT * FROM productos WHERE id=?');
    $st->execute([(int)$_GET['id']]);
    $edit = $st->fetch();
}

layout_start('Inventario de Productos', 'inventario');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <form class="d-flex gap-2" method="get">
    <input type="hidden" name="p" value="inventario">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Buscar producto..." value="<?= h($search) ?>" style="width:220px">
    <button class="btn btn-sm btn-outline-secondary">Buscar</button>
    <?php if ($search): ?><a href="?p=inventario" class="btn btn-sm btn-outline-danger">✕</a><?php endif ?>
  </form>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-success btn-sm" href="?p=inventario&pdf=1" target="_blank" title="Reporte imprimible del inventario por almacén">📄 Imprimir / PDF</a>
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAlm">🏬 Almacenes</button>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalProd">+ Nuevo</button>
  </div>
</div>

<?php if ($edit): ?>
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Editar Producto</h5><a href="?p=inventario" class="btn-close"></a></div>
    <div class="modal-body">
      <?= render_product_form($edit) ?>
    </div>
  </div></div>
</div>
<?php endif ?>

<style>
.tbl-inv{background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.04);border:1px solid #eee5d0}
.tbl-inv table{width:100%;margin:0;border-collapse:separate;border-spacing:0}
.tbl-inv th{background:#3a2410;color:#fff8e8;font-size:.72rem;font-weight:700;letter-spacing:.6px;text-transform:uppercase;padding:11px 12px;text-align:left;white-space:nowrap;position:sticky;top:0}
.tbl-inv td{padding:12px;border-top:1px solid #f1e9d4;vertical-align:middle;font-size:.92rem}
.tbl-inv tbody tr:hover{background:#fff8e8}
.tbl-inv .nombre{font-weight:700;color:#3a2410}
.tbl-inv .big-stock{font-size:1.5rem;font-weight:900;color:#3a2410;line-height:1}
.tbl-inv .unit-small{font-size:.7rem;color:#9a7a3a;text-transform:uppercase;letter-spacing:.4px}
.prod-state{display:inline-block;padding:4px 9px;border-radius:14px;font-size:.66rem;font-weight:800;letter-spacing:.5px;white-space:nowrap}
.prod-state.crit{background:#fde2e2;color:#a71d2a;border:1px solid #f5a7a7}
.prod-state.warn{background:#fff4d6;color:#8b6914;border:1px solid #e5c97b}
.prod-state.ok{background:#e2f5e7;color:#1c7430;border:1px solid #9bd3a7}
.stockbar-wrap{position:relative;height:10px;background:#ebe2cc;border-radius:6px;overflow:hidden;min-width:110px;width:110px}
.stockbar-fill{position:absolute;top:0;left:0;height:100%;transition:width .3s}
.stockbar-fill.crit{background:linear-gradient(90deg,#dc3545,#a71d2a)}
.stockbar-fill.warn{background:linear-gradient(90deg,#ffc107,#e0a800)}
.stockbar-fill.ok{background:linear-gradient(90deg,#28a745,#1c7430)}
.stockbar-meta{font-size:.65rem;color:#7a6238;margin-top:3px}
.alm-row{display:flex;gap:6px;flex-wrap:wrap}
.alm-chip{background:#fff;border:1.5px solid #d4a017;border-radius:8px;padding:5px 8px;text-align:center;min-width:62px;line-height:1}
.alm-chip .name{font-size:.58rem;font-weight:700;letter-spacing:.4px;color:#8b6914;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:60px}
.alm-chip .val{font-size:1rem;font-weight:900;color:#2c1810;margin-top:2px}
.alm-chip.zero{background:#f5f5f5;border-color:#cfc7b2}
.alm-chip.zero .val{color:#a99e7c}
.alm-chip.has{background:linear-gradient(135deg,#fff8d6,#fff)}
.prices-cell .usd{color:#1c7430;font-weight:800;font-size:.92rem;line-height:1.1}
.prices-cell .ves{color:#1f4f87;font-weight:600;font-size:.75rem}
.empty-state{padding:60px 20px;text-align:center;color:#999}
</style>

<div class="tbl-inv">
  <div class="table-responsive">
  <table>
    <thead>
      <tr>
        <th>Producto</th>
        <th>Estado</th>
        <th>Stock total</th>
        <th style="min-width:140px">Nivel</th>
        <th>Por almacén</th>
        <th>Precio</th>
        <th style="text-align:right">Acciones</th>
      </tr>
    </thead>
    <tbody>
  <?php foreach ($rows as $r):
    $t = get_tasa();
    $usd = $r['moneda']==='USD' ? (float)$r['precio'] : ($t>0?(float)$r['precio']/$t:0);
    $ves = $r['moneda']==='VES' ? (float)$r['precio'] : (float)$r['precio']*$t;
    $stk = (float)$r['stock'];
    $umb = (float)$r['umbral_alerta'];
    $ref = $umb > 0 ? $umb * 2 : max($stk, 1);
    $pct = $ref > 0 ? min(100, $stk / $ref * 100) : ($stk > 0 ? 100 : 0);
    if ($umb > 0 && $stk <= $umb)        { $cls='crit'; $estado='CRÍTICO'; }
    elseif ($umb > 0 && $stk <= $umb*2)  { $cls='warn'; $estado='BAJO'; }
    else                                 { $cls='ok';   $estado='OK'; }
    $pid = (int)$r['id'];
  ?>
    <tr>
      <td><div class="nombre"><?= h($r['nombre']) ?></div></td>
      <td><span class="prod-state <?= $cls ?>"><?= $estado ?></span></td>
      <td>
        <span class="big-stock"><?= h(rtrim(rtrim(number_format($stk,3,'.',''),'0'),'.')) ?></span>
        <div class="unit-small"><?= h($r['unidad_medida']) ?></div>
      </td>
      <td>
        <div class="stockbar-wrap"><div class="stockbar-fill <?= $cls ?>" style="width:<?= number_format($pct,1,'.','') ?>%"></div></div>
        <div class="stockbar-meta"><?= number_format($pct,0) ?>%<?php if ($umb > 0): ?> · Mín: <?= h($umb) ?><?php endif ?></div>
      </td>
      <td>
        <div class="alm-row">
          <?php foreach ($almacenes as $a):
            $v = $stock_por_alm[$pid][(int)$a['id']] ?? 0;
            $cz = $v > 0 ? 'has' : 'zero';
          ?>
            <div class="alm-chip <?= $cz ?>" title="<?= h($a['nombre']) ?>">
              <div class="name"><?= h($a['nombre']) ?></div>
              <div class="val"><?= h(rtrim(rtrim(number_format($v,3,'.',''),'0'),'.')) ?></div>
            </div>
          <?php endforeach ?>
        </div>
      </td>
      <td class="prices-cell">
        <div class="usd">$<?= number_format($usd,2,'.', ',') ?></div>
        <div class="ves">Bs. <?= number_format($ves,2,'.',',') ?></div>
      </td>
      <td style="text-align:right;white-space:nowrap">
        <a href="?p=inventario&pdf=prod&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-success" title="Descargar PDF (ficha tipo factura) de este producto">📄 PDF</a>
        <a href="?p=inventario&action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
        <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-sm btn-outline-danger">Eliminar</button>
        </form>
      </td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows): ?>
    <tr><td colspan="7" class="empty-state">Sin productos. Haz clic en <b>+ Nuevo</b> para agregar.</td></tr>
  <?php endif ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Modal almacenes: renombrar + crear -->
<div class="modal fade" id="modalAlm" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">🏬 Mis almacenes (<?= count($almacenes) ?>)</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p class="text-muted small">Cambia el nombre de cada almacén como prefieras. Estos nombres se mostrarán en Producción Diaria y en Ventas. Para borrar uno, usa el botón 🗑 a su derecha; se eliminará de todas las pantallas y, si tenía stock o movimientos, se pasarán automáticamente al primer almacén (no se pierde inventario).</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="alm_rename">
        <?php foreach ($almacenes as $a): ?>
          <div class="mb-2 d-flex align-items-end gap-2">
            <div class="flex-grow-1">
              <label class="form-label small mb-0">Almacén #<?= (int)$a['id'] ?></label>
              <input name="nombre[<?= (int)$a['id'] ?>]" class="form-control" value="<?= h($a['nombre']) ?>" maxlength="80" required>
            </div>
            <?php if (count($almacenes) > 1): ?>
            <button type="button" class="btn btn-outline-danger" title="Eliminar este almacén"
                    onclick="almDel(<?= (int)$a['id'] ?>, <?= h(json_encode($a['nombre'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)">🗑</button>
            <?php endif ?>
          </div>
        <?php endforeach ?>
        <div class="text-end mt-2">
          <button class="btn btn-primary btn-sm">Guardar nombres</button>
        </div>
      </form>
      <form method="post" id="almDelForm" class="d-none">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="alm_delete">
        <input type="hidden" name="id" id="almDelId" value="">
      </form>
      <hr class="my-3">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="alm_add">
        <label class="form-label small mb-1 fw-bold">➕ Crear un nuevo almacén</label>
        <div class="input-group">
          <input name="nombre_nuevo" class="form-control" placeholder="Nombre del nuevo almacén" maxlength="80" required>
          <button class="btn btn-success">Agregar</button>
        </div>
        <div class="form-text">Se añadirá a la lista de arriba y aparecerá en todas las pantallas que usan almacenes.</div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button>
    </div>
  </div></div>
</div>

<!-- Modal nuevo -->
<div class="modal fade" id="modalProd" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Nuevo Producto</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <?= render_product_form() ?>
    </div>
  </div></div>
</div>
<script>
function almDel(id, nombre){
  if(confirm('¿Eliminar el almacén "'+nombre+'"?\n\nSe quitará de todas las pantallas. Si tenía stock o movimientos, se pasarán automáticamente al primer almacén de la lista (no se pierde inventario).\n\nEsta acción no se puede deshacer.')){
    document.getElementById('almDelId').value = id;
    document.getElementById('almDelForm').submit();
  }
}
</script>
<?php layout_end();

function render_product_form(?array $r = null): string {
    $csrf = csrf_token();
    $id   = $r['id'] ?? '';
    ob_start(); ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h($id) ?>">
      <div class="row g-2">
        <div class="col-8"><label class="form-label">Nombre</label><input name="nombre" class="form-control" value="<?= h($r['nombre'] ?? '') ?>" required></div>
        <div class="col-4"><label class="form-label">Código</label><input name="codigo" class="form-control" value="<?= h($r['codigo'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2"><?= h($r['descripcion'] ?? '') ?></textarea></div>
        <div class="col-4"><label class="form-label">Precio</label><input name="precio" type="number" step="0.01" class="form-control" value="<?= h($r['precio'] ?? '0') ?>"></div>
        <div class="col-4"><label class="form-label">Stock</label><input name="stock" type="number" class="form-control" value="<?= h($r['stock'] ?? '0') ?>"></div>
        <div class="col-4"><label class="form-label">Alerta mín.</label><input name="umbral_alerta" type="number" class="form-control" value="<?= h($r['umbral_alerta'] ?? '0') ?>"></div>
        <div class="col-4"><label class="form-label">Moneda</label>
          <select name="moneda" class="form-select">
            <option value="USD" <?= ($r['moneda'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
            <option value="VES" <?= ($r['moneda'] ?? '') === 'VES' ? 'selected' : '' ?>>VES</option>
          </select>
        </div>
        <div class="col-4"><label class="form-label">Unidad de medida</label>
          <?php $u = (string)($r['unidad_medida'] ?? 'unidad');
                $opts = ['unidad'=>'Unidades (uds)','kg'=>'Kilogramos (kg)','g'=>'Gramos (g)','l'=>'Litros (L)','ml'=>'Mililitros (ml)','docena'=>'Docena','paquete'=>'Paquete','caja'=>'Caja'];
                $esEstandar = isset($opts[strtolower($u)]); ?>
          <select name="unidad_medida" class="form-select">
            <?php foreach ($opts as $k=>$lbl): ?>
              <option value="<?= h($k) ?>" <?= strtolower($u)===$k ? 'selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach ?>
            <?php if (!$esEstandar && $u !== ''): ?>
              <option value="<?= h($u) ?>" selected><?= h($u) ?> (personalizado)</option>
            <?php endif ?>
          </select>
        </div>
        <div class="col-4"><label class="form-label">Presentación</label><input name="presentacion" class="form-control" value="<?= h($r['presentacion'] ?? '') ?>"></div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary">Guardar</button>
        <a href="?p=inventario" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
    <?php return ob_get_clean();
}
?>
