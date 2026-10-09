<?php
require_login();

$error    = '';
$success  = '';
$filename = '';
$sql_out  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sqlfile'])) {
    $f = $_FILES['sqlfile'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error al subir el archivo. Intenta de nuevo.';
    } elseif (!preg_match('/\.sql$/i', $f['name'])) {
        $error = 'Solo se aceptan archivos .sql';
    } elseif ($f['size'] > 50 * 1024 * 1024) {
        $error = 'El archivo es demasiado grande (máximo 50 MB).';
    } else {
        $contenido = file_get_contents($f['tmp_name']);
        if ($contenido === false) {
            $error = 'No se pudo leer el archivo.';
        } else {
            // Líneas problemáticas en XAMPP/MySQL sin timezone tables
            $patrones = [
                '/^SET\s+time_zone\s*=\s*["\']?\+00:00["\']?\s*;?\s*$/im',
                '/^SET\s+time_zone\s*=\s*["\']?UTC["\']?\s*;?\s*$/im',
            ];
            $limpio = $contenido;
            foreach ($patrones as $re) {
                $limpio = preg_replace($re, '-- (línea removida por compatibilidad XAMPP)', $limpio);
            }

            // Si detectamos que el output va a descargarse
            if (isset($_POST['descargar'])) {
                $filename = preg_replace('/\.sql$/i', '', $f['name']) . '_fixed.sql';
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($limpio));
                echo $limpio;
                exit;
            }

            $success  = 'Archivo listo. Haz clic en "Descargar SQL corregido" para obtenerlo.';
            // Guardar en sesión para re-descarga (pequeño workaround)
            $_SESSION['sql_fix_name']    = $f['name'];
            $_SESSION['sql_fix_content'] = $limpio;
        }
    }
}

// Descarga desde sesión
if (isset($_GET['dl']) && !empty($_SESSION['sql_fix_content'])) {
    $filename = preg_replace('/\.sql$/i', '', $_SESSION['sql_fix_name']) . '_fixed.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($_SESSION['sql_fix_content']));
    echo $_SESSION['sql_fix_content'];
    exit;
}

require_once __DIR__ . '/../layout.php';
layout_start('Reparar SQL para XAMPP', '');
?>

<div class="mb-3">
  <h5 class="fw-bold" style="color:#1a2e5a">Reparar archivo SQL para XAMPP</h5>
  <p class="text-muted mb-0" style="font-size:.9rem">
    Sube el archivo <code>.sql</code> exportado desde phpMyAdmin. El sistema eliminará las líneas incompatibles con XAMPP 
    (<code>SET time_zone</code>) y te entregará un archivo listo para importar sin errores.
  </p>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif ?>

<?php if (!empty($_SESSION['sql_fix_content'])): ?>
<div class="alert alert-success d-flex align-items-center gap-3">
  <div>
    Archivo corregido correctamente.
    Archivo original: <strong><?= h($_SESSION['sql_fix_name']) ?></strong>
  </div>
  <a href="?p=sql_fix&dl=1" class="btn btn-success btn-sm ms-auto">
    Descargar SQL corregido
  </a>
</div>
<?php endif ?>

<div class="card" style="max-width:600px">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold">Selecciona tu archivo .sql</label>
        <input type="file" name="sqlfile" accept=".sql" class="form-control" required>
        <div class="form-text">Máximo 50 MB. Solo archivos <code>.sql</code>.</div>
      </div>
      <button type="submit" name="descargar" value="1" class="btn btn-primary w-100"
              style="background:#f97316;border-color:#f97316;font-weight:600">
        Corregir y Descargar
      </button>
    </form>
  </div>
</div>

<div class="mt-4 p-3 rounded" style="background:#f4f7fc;max-width:600px;font-size:.85rem">
  <strong style="color:#1a2e5a">¿Qué corrige esta herramienta?</strong>
  <ul class="mb-0 mt-2 ps-3 text-muted">
    <li>Elimina <code>SET time_zone = "+00:00";</code> (causa error #1298 en XAMPP)</li>
    <li>Elimina <code>SET time_zone = "UTC";</code></li>
    <li>El resto del archivo queda intacto — tus datos no se modifican.</li>
  </ul>
</div>

<?php layout_end(); ?>
