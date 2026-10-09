<?php
// Página pública — no requiere login
$tipo = $_GET['f'] ?? '';

$allowed = [
    'sistema' => [
        'path' => __DIR__ . '/../public/aserp-sistema.tar.gz',
        'name' => 'ASERP-Sistema.tar.gz',
        'mime' => 'application/gzip',
    ],
    'bd' => [
        'path' => __DIR__ . '/../public/descarga-bd.sql',
        'name' => 'ASERP-BaseDatos.sql',
        'mime'  => 'text/plain',
    ],
    'csv' => [
        'path' => __DIR__ . '/../public/aserp-tablas-csv.zip',
        'name' => 'ASERP-Tablas-CSV.zip',
        'mime'  => 'application/zip',
    ],
    'manual' => [
        'path' => __DIR__ . '/../public/manual-usuario.html',
        'name' => 'ASERP-Manual-Usuario.html',
        'mime'  => 'text/html',
    ],
];

if ($tipo && isset($allowed[$tipo]) && file_exists($allowed[$tipo]['path'])) {
    $f = $allowed[$tipo];
    header('Content-Type: ' . $f['mime']);
    header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
    header('Content-Length: ' . filesize($f['path']));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    while (ob_get_level()) ob_end_clean();
    readfile($f['path']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Descargas — ASERP</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body { background:#f0f4ff; min-height:100vh; display:flex; align-items:center; justify-content:center; }
  .box { max-width:460px; width:100%; background:#fff; border-radius:16px; box-shadow:0 4px 28px rgba(0,0,0,.1); overflow:hidden; }
  .hdr { background:linear-gradient(135deg,#1a2e5a,#2a4a8a); padding:22px 28px; display:flex; align-items:center; gap:14px; }
  .logo { height:68px; width:68px; object-fit:contain; background:#fff; border-radius:8px; padding:3px; }
  .item { border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; }
  .btn-dl { font-size:.95rem; padding:12px; border-radius:8px; font-weight:600; }
</style>
</head>
<body>
<div class="box">
  <div class="hdr">
    <img src="public/logo.jpg" class="logo" alt="Logo">
    <div>
      <div style="color:#fff;font-size:1.15rem;font-weight:700">El Castillo del Queso</div>
      <div style="color:rgba(255,255,255,.7);font-size:.78rem">ASERP — Archivos de respaldo</div>
    </div>
  </div>
  <div class="p-4 d-flex flex-column gap-3">

    <div class="item" style="border-color:#0d6efd;background:#f3f8ff">
      <div class="fw-bold mb-1">⬆️ Actualización del sistema <span class="badge ms-1" style="background:#0d6efd;font-size:.7rem">No pierde datos</span></div>
      <div class="text-muted small mb-3">
        Solo los archivos del programa — <strong>no toca tu base de datos</strong> ni tu
        <code>local-config.php</code>. Tus productos, ventas, productores y todo lo demás
        se mantienen igual.<br>
        Descomprime y copia las carpetas dentro de tu sistema, reemplazando los archivos.
        Trae el botón para <strong>eliminar almacenes</strong> y mejoras previas.
        <br><strong>Recomendado:</strong> antes de actualizar, descarga una copia de la base de datos abajo.
      </div>
      <a href="?dl=actualizacion" class="btn btn-dl w-100" style="background:#0d6efd;color:#fff;border:none">
        ⬇️ Descargar Actualización (.zip)
      </a>
    </div>

    <div class="item" style="border-color:#f97316;background:#fff8f3">
      <div class="fw-bold mb-1">📦 Sistema PHP <span class="badge ms-1" style="background:#f97316;font-size:.7rem">✔ Para Windows (ZIP)</span></div>
      <div class="text-muted small mb-3">
        Todos los archivos del sistema organizados por carpeta en formato <code>.zip</code>.<br>
        <strong>Windows lo abre directamente</strong> — sin programas extra.<br>
        Extrae y copia la carpeta <code>aserp-php</code> en <code>C:\xampp\htdocs\</code>
      </div>
      <a href="?dl=zip" class="btn btn-dl w-100" style="background:#f97316;color:#fff;border:none">
        ⬇️ Descargar Sistema (.zip) — Windows
      </a>
    </div>

    <div class="item">
      <div class="fw-bold mb-1 text-muted">📦 Sistema PHP (.tar.gz) <span class="badge bg-secondary ms-1" style="font-size:.7rem">Linux / Mac</span></div>
      <div class="text-muted small mb-3">
        Mismo sistema en formato <code>.tar.gz</code>. Requiere 7-Zip o WinRAR en Windows.
      </div>
      <a href="?dl=sistema" class="btn btn-secondary btn-dl w-100">
        ⬇️ Descargar Sistema (.tar.gz)
      </a>
    </div>

    <div class="item" style="border-color:#28a745;background:#f6fff8">
      <div class="fw-bold mb-1">🗄️ Base de datos MySQL <span class="badge bg-success ms-1" style="font-size:.7rem">✔ Para phpMyAdmin</span></div>
      <div class="text-muted small mb-3">
        Las 23 tablas con todos los datos en formato <code>.sql</code> <strong>compatible con MySQL</strong>.<br>
        En phpMyAdmin: selecciona tu base de datos → pestaña <strong>Importar</strong> → selecciona este archivo → Continuar.
      </div>
      <a href="?dl=mysql" class="btn btn-success btn-dl w-100">
        ⬇️ Descargar Base de Datos MySQL (.sql)
      </a>
    </div>

    <div class="item" style="border-color:#ffc107">
      <div class="fw-bold mb-1">📊 Tablas en CSV <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Para Excel</span></div>
      <div class="text-muted small mb-3">
        Las 23 tablas de la base de datos en archivos CSV dentro de un <code>.zip</code>.<br>
        Ábrelos directamente en <strong>Excel</strong> o Google Sheets.
      </div>
      <a href="?dl=csv" class="btn btn-warning btn-dl w-100">
        ⬇️ Descargar Tablas CSV (Excel)
      </a>
    </div>

    <div class="item" style="border-color:#adb5bd">
      <div class="fw-bold mb-1 text-muted">🗄️ Base de datos PostgreSQL <span class="badge bg-secondary ms-1" style="font-size:.7rem">Solo para PostgreSQL</span></div>
      <div class="text-muted small mb-3">
        Respaldo en formato PostgreSQL — <strong>no funciona en MySQL/phpMyAdmin</strong>. Solo para restaurar en PostgreSQL.
      </div>
      <a href="?dl=bd" class="btn btn-secondary btn-dl w-100">
        ⬇️ Descargar Base de Datos PostgreSQL
      </a>
    </div>

    <div class="item" style="border-color:#1a2e5a">
      <div class="fw-bold mb-1">📖 Manual de Usuario</div>
      <div class="text-muted small mb-3">
        Manual completo del sistema en formato <code>.html</code> — ábrelo en cualquier navegador y usa <strong>Ctrl+P → Guardar como PDF</strong> para imprimirlo.
      </div>
      <a href="?dl=manual" class="btn btn-dl w-100" style="background:#1a2e5a;color:#fff;border:none;">
        ⬇️ Descargar Manual de Usuario
      </a>
    </div>

    <div class="text-center pt-1">
      <a href="?p=login" class="text-muted small">← Ir al sistema</a>
    </div>
  </div>
  <div class="text-center text-muted small py-2 border-top">
    Generado el <?= date('d/m/Y H:i') ?> · ASERP
  </div>
</div>
</body>
</html>
