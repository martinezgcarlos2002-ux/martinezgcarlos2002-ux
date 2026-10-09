<?php session_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reparar SQL para XAMPP</title>
<style>
  body { font-family: Arial, sans-serif; max-width: 600px; margin: 60px auto; background: #f0f4fa; }
  .box { background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 2px 16px rgba(0,0,0,.1); }
  h2 { color: #1a2e5a; margin-top: 0; }
  .btn { background: #f97316; color: #fff; border: none; padding: 12px 28px;
         border-radius: 8px; font-size: 1rem; cursor: pointer; width: 100%; margin-top: 10px; }
  .btn:hover { background: #ea6c0a; }
  input[type=file] { width: 100%; padding: 10px; border: 2px dashed #ccc; border-radius: 8px;
                     margin: 12px 0; box-sizing: border-box; font-size: .95rem; }
  .ok  { background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px; padding: 14px; color: #065f46; margin-top: 16px; }
  .err { background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; padding: 14px; color: #991b1b; margin-top: 16px; }
  .info { color: #64748b; font-size: .85rem; margin-top: 10px; }
</style>
</head>
<body>
<div class="box">
  <h2>Reparar SQL para XAMPP</h2>
  <p style="color:#555">Sube tu archivo <strong>.sql</strong> exportado de phpMyAdmin. 
  El sistema elimina la línea <code>SET time_zone</code> que causa el error <strong>#1298</strong> 
  y te devuelve el archivo listo para importar.</p>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['f'])) {
    $f = $_FILES['f'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        echo '<div class="err">Error al subir el archivo.</div>';
    } elseif (!preg_match('/\.sql$/i', $f['name'])) {
        echo '<div class="err">Solo se aceptan archivos .sql</div>';
    } else {
        $contenido = file_get_contents($f['tmp_name']);
        // Eliminar líneas problemáticas
        $lineas = explode("\n", $contenido);
        $limpias = [];
        $eliminadas = 0;
        foreach ($lineas as $linea) {
            if (preg_match('/^\s*SET\s+time_zone\s*=/i', $linea)) {
                $limpias[] = '-- (línea removida para compatibilidad XAMPP): ' . trim($linea);
                $eliminadas++;
            } else {
                $limpias[] = $linea;
            }
        }
        $resultado = implode("\n", $limpias);
        $nombre_salida = preg_replace('/\.sql$/i', '_fixed.sql', $f['name']);

        // Guardar temporal
        $tmp = tempnam(sys_get_temp_dir(), 'sqlfix_');
        file_put_contents($tmp, $resultado);
        $_SESSION['fix_tmp']  = $tmp;
        $_SESSION['fix_name'] = $nombre_salida;

        echo '<div class="ok">';
        echo '<strong>Listo.</strong> Se eliminaron '.$eliminadas.' línea(s) problemática(s).<br>';
        echo 'Archivo original: <strong>'.htmlspecialchars($f['name']).'</strong><br>';
        echo 'Archivo corregido: <strong>'.htmlspecialchars($nombre_salida).'</strong><br><br>';
        echo '<a href="?dl=1" style="display:inline-block;background:#1a2e5a;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:bold">Descargar archivo corregido</a>';
        echo '</div>';
    }
}

if (isset($_GET['dl']) && isset($_SESSION['fix_tmp']) && file_exists($_SESSION['fix_tmp'])) {
    $data = file_get_contents($_SESSION['fix_tmp']);
    @unlink($_SESSION['fix_tmp']);
    unset($_SESSION['fix_tmp']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $_SESSION['fix_name'] . '"');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}
?>

  <form method="post" enctype="multipart/form-data">
    <label style="font-weight:bold">Selecciona tu archivo .sql:</label>
    <input type="file" name="f" accept=".sql" required>
    <button type="submit" class="btn">Reparar y descargar</button>
  </form>

  <p class="info">
    Este script solo modifica la línea <code>SET time_zone = "+00:00"</code>.<br>
    Todos tus datos (productores, entregas, pagos, etc.) quedan exactamente igual.<br>
    Una vez descargado el archivo <em>_fixed.sql</em>, impórtalo normalmente en phpMyAdmin.
  </p>
</div>

<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
</body>
</html>
