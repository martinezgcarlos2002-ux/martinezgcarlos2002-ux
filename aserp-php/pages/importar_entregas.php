<?php
require_login();
$db = get_db();

// ── Descargar plantilla CSV ───────────────────────────────────────────────────
if (isset($_GET['plantilla'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plantilla_entregas.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 para que Excel abra bien
    echo "Productor,Fecha,Litros,Precio_USD\n";
    // Obtener productores activos como ejemplo
    $ps = $db->query("SELECT nombre, precio_litro_usd FROM productores WHERE estado=true ORDER BY nombre LIMIT 5")->fetchAll();
    $fecha_ej = date('d/m/Y');
    foreach ($ps as $p) {
        echo '"'.str_replace('"','""',$p['nombre']).'","'.$fecha_ej.'","100.0","'.number_format((float)$p['precio_litro_usd'],4,'.',',')."\";\n";
    }
    if (!$ps) {
        echo '"Juan Perez","'.date('d/m/Y').'","120.0","0.8000"'."\n";
        echo '"Maria Lopez","'.date('d/m/Y').'","85.5","0.8000"'."\n";
    }
    exit;
}

// ── Cargar mapa de productores (nombre → id + precio) ────────────────────────
$prod_rows = $db->query("SELECT id, nombre, precio_litro_usd FROM productores")->fetchAll();
$prod_map  = [];
foreach ($prod_rows as $r) {
    $prod_map[mb_strtolower(trim($r['nombre']))] = $r;
}

$errores  = [];
$filas_ok = [];
$filas_raw = [];
$importado = 0;

// ── POST: parsear CSV ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    verify_csrf();
    $f = $_FILES['csv'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash('Error al subir el archivo.', 'error');
    } elseif (!preg_match('/\.(csv|txt)$/i', $f['name'])) {
        flash('Solo se aceptan archivos .csv', 'error');
    } else {
        $handle = fopen($f['tmp_name'], 'r');
        // Quitar BOM si existe
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $linea_num = 0;
        $encabezado_saltado = false;
        while (($cols = fgetcsv($handle, 1000, ',')) !== false) {
            $linea_num++;
            // Saltar encabezado
            if (!$encabezado_saltado) {
                $encabezado_saltado = true;
                $primera = mb_strtolower(trim($cols[0] ?? ''));
                if ($primera === 'productor' || $primera === 'nombre') continue;
            }
            if (count(array_filter($cols, fn($c) => trim($c) !== '')) === 0) continue;

            $nombre_raw = trim($cols[0] ?? '');
            $fecha_raw  = trim($cols[1] ?? '');
            $litros_raw = trim(str_replace(',', '.', $cols[2] ?? ''));
            $precio_raw = trim(str_replace(',', '.', $cols[3] ?? ''));

            $fila = ['linea' => $linea_num, 'nombre' => $nombre_raw,
                     'fecha_raw' => $fecha_raw, 'litros_raw' => $litros_raw, 'precio_raw' => $precio_raw];
            $fila_errs = [];

            // Buscar productor
            $pk = mb_strtolower(trim($nombre_raw));
            if (!isset($prod_map[$pk])) {
                // Búsqueda parcial
                $match = null;
                foreach ($prod_map as $k => $v) {
                    if (str_contains($k, $pk) || str_contains($pk, $k)) { $match = $v; break; }
                }
                if ($match) {
                    $fila['productor'] = $match;
                } else {
                    $fila_errs[] = 'Productor "'.h($nombre_raw).'" no encontrado en el sistema';
                }
            } else {
                $fila['productor'] = $prod_map[$pk];
            }

            // Parsear fecha: acepta d/m/Y, d-m-Y, Y-m-d
            $fecha_parsed = null;
            foreach (['d/m/Y','d-m-Y','Y-m-d','d/m/y','j/n/Y'] as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $fecha_raw);
                if ($dt && $dt->format('d/m/Y') && checkdate((int)$dt->format('m'),(int)$dt->format('d'),(int)$dt->format('Y'))) {
                    $fecha_parsed = $dt->format('Y-m-d');
                    break;
                }
            }
            if (!$fecha_parsed) {
                $fila_errs[] = 'Fecha "'.h($fecha_raw).'" inválida (usa dd/mm/aaaa)';
            } else {
                $fila['fecha'] = $fecha_parsed;
            }

            // Litros
            $litros = (float)$litros_raw;
            if ($litros <= 0) {
                $fila_errs[] = 'Litros debe ser mayor que 0';
            } else {
                $fila['litros'] = $litros;
            }

            // Precio (opcional — usa el del productor si vacío)
            if ($precio_raw !== '' && $precio_raw !== '0') {
                $precio = (float)$precio_raw;
                if ($precio <= 0) $fila_errs[] = 'Precio debe ser mayor que 0';
                else $fila['precio'] = $precio;
            } else {
                $fila['precio'] = isset($fila['productor']) ? (float)$fila['productor']['precio_litro_usd'] : 0;
            }

            $fila['errores'] = $fila_errs;
            $filas_raw[] = $fila;
            if (empty($fila_errs)) $filas_ok[] = $fila;
            else $errores[] = $fila;
        }
        fclose($handle);
    }
}

// ── POST: confirmar importación ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
    verify_csrf();
    $rows_json = json_decode($_POST['rows_json'] ?? '[]', true);
    $imp_sync = []; // ["pid|semana_inicio" => [pid, fecha]] para sincronizar cierres
    foreach ($rows_json as $r) {
        $prod_id = (int)$r['productor_id'];
        $fecha   = $r['fecha'];
        $litros  = (float)$r['litros'];
        $precio  = (float)$r['precio'];
        $sub     = round($litros * $precio, 4);

        // Upsert: si ya existe → actualizar, si no → insertar
        $exist = $db->prepare('SELECT id FROM productores_entregas WHERE productor_id=? AND fecha=?');
        $exist->execute([$prod_id, $fecha]);
        $eid = $exist->fetchColumn();
        if ($eid) {
            $db->prepare('UPDATE productores_entregas SET litros=?,precio_litro_usd=?,subtotal_usd=? WHERE id=?')
               ->execute([$litros, $precio, $sub, $eid]);
        } else {
            $db->prepare('INSERT INTO productores_entregas(productor_id,fecha,litros,precio_litro_usd,subtotal_usd) VALUES(?,?,?,?,?)')
               ->execute([$prod_id, $fecha, $litros, $precio, $sub]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fecha)) {
            $imp_sync[$prod_id . '|' . snap_sat($fecha)->format('Y-m-d')] = [$prod_id, $fecha];
        }
        $importado++;
    }
    // Si se importó sobre semanas ya cerradas, refresca sus snapshots (una vez por semana).
    foreach ($imp_sync as $t) sync_cierre_from_entregas($db, (int)$t[0], $t[1]);
    flash("Se importaron $importado entregas correctamente.", 'success');
    header('Location: ?p=importar_entregas'); exit;
}

require_once __DIR__ . '/../layout.php';
layout_start('Importar Entregas desde CSV', 'productores');
?>

<style>
  .step-badge { display:inline-flex; align-items:center; justify-content:center;
                width:28px; height:28px; border-radius:50%; background:#1a2e5a; color:#fff;
                font-weight:700; font-size:.85rem; flex-shrink:0; }
  .preview-ok  { background:#f0fdf4; }
  .preview-err { background:#fff1f2; }
</style>

<div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
  <a href="?p=productores" class="btn btn-sm btn-outline-secondary">← Productores</a>
  <h5 class="mb-0 fw-bold" style="color:#1a2e5a">Importar Entregas de Leche desde CSV</h5>
</div>

<?php if (!$filas_raw): ?>
<!-- ══ PASO 1: Instrucciones + subida ══════════════════════════════════════ -->
<div class="row g-3">
  <div class="col-md-7">
    <div class="card">
      <div class="card-header py-2 fw-semibold" style="background:#1a2e5a;color:#fff">
        Subir archivo CSV
      </div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold">Selecciona tu archivo CSV</label>
            <input type="file" name="csv" accept=".csv,.txt" class="form-control" required>
            <div class="form-text">Máximo 10 MB. El archivo debe tener: <strong>Productor, Fecha, Litros, Precio_USD</strong></div>
          </div>

          <button type="submit" class="btn btn-primary" style="background:#f97316;border-color:#f97316">
            Cargar y previsualizar
          </button>
          <a href="?p=importar_entregas&plantilla=1" class="btn btn-outline-secondary ms-2">
            Descargar plantilla Excel/CSV
          </a>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card">
      <div class="card-header py-2 fw-semibold">Instrucciones paso a paso</div>
      <div class="card-body" style="font-size:.875rem">
        <div class="d-flex gap-2 mb-2">
          <span class="step-badge">1</span>
          <div>Descarga la <strong>plantilla</strong> haciendo clic en el botón naranja. Se abre en Excel.</div>
        </div>
        <div class="d-flex gap-2 mb-2">
          <span class="step-badge">2</span>
          <div>Rellena los datos en Excel:<br>
            <code>Productor</code> — nombre exacto como aparece en el sistema<br>
            <code>Fecha</code> — formato <strong>dd/mm/aaaa</strong> (ej: 12/05/2026)<br>
            <code>Litros</code> — cantidad (ej: 120.5)<br>
            <code>Precio_USD</code> — precio por litro (déjalo vacío para usar el precio del productor)
          </div>
        </div>
        <div class="d-flex gap-2 mb-2">
          <span class="step-badge">3</span>
          <div>Guarda como <strong>CSV (separado por comas)</strong> desde Excel:<br>
            Archivo → Guardar como → CSV UTF-8
          </div>
        </div>
        <div class="d-flex gap-2 mb-2">
          <span class="step-badge">4</span>
          <div>Sube el archivo y revisa la previsualización antes de confirmar.</div>
        </div>
        <div class="d-flex gap-2">
          <span class="step-badge">5</span>
          <div>Si ya existe una entrega para la misma fecha y productor, <strong>se actualiza</strong> (no se duplica).</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══ PASO 2: Previsualización ════════════════════════════════════════════ -->

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <div class="d-flex gap-2">
    <span class="badge bg-success fs-6"><?= count($filas_ok) ?> listas</span>
    <?php if ($errores): ?>
    <span class="badge bg-danger fs-6"><?= count($errores) ?> con error</span>
    <?php endif ?>
  </div>
  <?php if ($filas_ok): ?>
  <form method="post" class="ms-auto">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="confirmar" value="1">
    <input type="hidden" name="rows_json" value='<?= h(json_encode(array_map(fn($r) => [
        'productor_id' => $r['productor']['id'],
        'fecha'        => $r['fecha'],
        'litros'       => $r['litros'],
        'precio'       => $r['precio'],
    ], $filas_ok))) ?>'>
    <button type="submit" class="btn btn-success" style="background:#16a34a;border-color:#16a34a">
      Importar <?= count($filas_ok) ?> entrega<?= count($filas_ok)!==1?'s':'' ?> válidas
    </button>
  </form>
  <?php endif ?>
  <a href="?p=importar_entregas" class="btn btn-outline-secondary btn-sm">← Subir otro archivo</a>
</div>

<div class="card">
  <div class="card-header py-2 fw-semibold">Previsualización de datos</div>
  <div class="card-body p-0" style="max-height:65vh;overflow-y:auto">
    <table class="table table-sm mb-0">
      <thead style="position:sticky;top:0;z-index:1">
        <tr>
          <th>#</th><th>Productor</th><th>Fecha</th>
          <th class="text-end">Litros</th><th class="text-end">Precio/L</th>
          <th class="text-end">Subtotal</th><th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($filas_raw as $f): ?>
      <tr class="<?= empty($f['errores']) ? 'preview-ok' : 'preview-err' ?>">
        <td class="text-muted"><?= $f['linea'] ?></td>
        <td><?= h($f['nombre']) ?>
          <?php if (isset($f['productor']) && mb_strtolower($f['nombre']) !== mb_strtolower($f['productor']['nombre'])): ?>
            <br><small class="text-success">→ <?= h($f['productor']['nombre']) ?></small>
          <?php endif ?>
        </td>
        <td><?= isset($f['fecha']) ? date('d/m/Y', strtotime($f['fecha'])) : h($f['fecha_raw']) ?></td>
        <td class="text-end"><?= isset($f['litros']) ? number_format($f['litros'],1) : h($f['litros_raw']) ?></td>
        <td class="text-end"><?= isset($f['precio']) ? '$'.number_format($f['precio'],4) : '—' ?></td>
        <td class="text-end fw-semibold">
          <?php if (isset($f['litros'], $f['precio'])): ?>
            $<?= number_format($f['litros'] * $f['precio'], 2) ?>
          <?php else: ?>—<?php endif ?>
        </td>
        <td>
          <?php if (empty($f['errores'])): ?>
            <span class="badge bg-success">OK</span>
          <?php else: ?>
            <?php foreach ($f['errores'] as $e): ?>
              <span class="badge bg-danger d-block mb-1" style="white-space:normal;text-align:left"><?= h($e) ?></span>
            <?php endforeach ?>
          <?php endif ?>
        </td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($errores): ?>
<div class="alert alert-warning mt-3 mb-0" style="font-size:.875rem">
  Las filas marcadas en rojo <strong>no se importarán</strong>. Corrige los errores en tu Excel,
  guarda el CSV de nuevo y vuelve a subir el archivo.
</div>
<?php endif ?>
<?php endif ?>

<?php layout_end(); ?>
