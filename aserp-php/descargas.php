<?php
$file = $_GET['f'] ?? '';
$allowed = [
    'sistema' => ['path' => __DIR__ . '/public/aserp-sistema.tar.gz', 'name' => 'ASERP-Sistema.tar.gz',  'mime' => 'application/gzip'],
    'bd'      => ['path' => __DIR__ . '/public/descarga-bd.sql',      'name' => 'ASERP-BaseDatos.sql',   'mime' => 'text/plain'],
];

if ($file && isset($allowed[$file])) {
    $f = $allowed[$file];
    if (file_exists($f['path'])) {
        header('Content-Type: ' . $f['mime']);
        header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
        header('Content-Length: ' . filesize($f['path']));
        header('Cache-Control: no-cache');
        readfile($f['path']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Descargas — ASERP El Castillo del Queso</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body { background: #f0f4ff; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { max-width: 480px; width: 100%; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.1); }
  .card-header { background: linear-gradient(135deg,#1a2e5a,#2a4a8a); border-radius: 16px 16px 0 0; padding: 24px; }
  .logo { height: 70px; width: 70px; object-fit: contain; background: #fff; border-radius: 8px; padding: 4px; }
  .btn-dl { font-size: 1rem; padding: 14px 24px; border-radius: 10px; font-weight: 600; }
</style>
</head>
<body>
<div class="card">
  <div class="card-header text-white d-flex align-items-center gap-3">
    <img src="public/logo.jpg" class="logo" alt="Logo">
    <div>
      <div style="font-size:1.2rem;font-weight:700">El Castillo del Queso</div>
      <div style="font-size:.8rem;opacity:.8">ASERP — Archivos de descarga</div>
    </div>
  </div>
  <div class="card-body p-4 d-flex flex-column gap-3">

    <div class="border rounded-3 p-3">
      <div class="fw-bold mb-1">📦 Sistema PHP</div>
      <div class="text-muted small mb-3">Todo el código fuente de la aplicación ASERP comprimido en <code>.tar.gz</code>.<br>
        Para abrirlo en Windows usa <strong>7-Zip</strong> (gratis).</div>
      <a href="descargas.php?f=sistema" class="btn btn-primary btn-dl w-100">
        ⬇️ Descargar Sistema
      </a>
    </div>

    <div class="border rounded-3 p-3">
      <div class="fw-bold mb-1">🗄️ Base de datos SQL</div>
      <div class="text-muted small mb-3">Respaldo completo de la base de datos en formato <code>.sql</code>, listo para restaurar en PostgreSQL.</div>
      <a href="descargas.php?f=bd" class="btn btn-success btn-dl w-100">
        ⬇️ Descargar Base de Datos
      </a>
    </div>

    <div class="text-center">
      <a href="index.php" class="text-muted small">← Volver al sistema</a>
    </div>
  </div>
  <div class="card-footer text-center text-muted small py-2">
    Generado el <?= date('d/m/Y H:i') ?> — ASERP
  </div>
</div>
</body>
</html>
