<?php
$allowed = [
    'sistema' => [
        'path' => __DIR__ . '/public/aserp-sistema.tar.gz',
        'name' => 'ASERP-Sistema.tar.gz',
        'mime' => 'application/gzip',
    ],
    'bd' => [
        'path' => __DIR__ . '/public/descarga-bd.sql',
        'name' => 'ASERP-BaseDatos-PostgreSQL.sql',
        'mime' => 'text/plain',
    ],
    'mysql' => [
        'path' => __DIR__ . '/public/aserp-mysql.sql',
        'name' => 'ASERP-BaseDatos-MySQL.sql',
        'mime' => 'text/plain',
    ],
    'csv' => [
        'path' => __DIR__ . '/public/aserp-tablas-csv.zip',
        'name' => 'ASERP-Tablas-CSV.zip',
        'mime' => 'application/zip',
    ],
    'manual' => [
        'path' => __DIR__ . '/public/manual-usuario.html',
        'name' => 'ASERP-Manual-Usuario.html',
        'mime' => 'text/html; charset=utf-8',
    ],
];

$f = $_GET['f'] ?? '';

if (!$f || !isset($allowed[$f])) {
    http_response_code(404);
    echo 'Archivo no encontrado.';
    exit;
}

$item = $allowed[$f];

if (!file_exists($item['path'])) {
    http_response_code(404);
    echo 'Archivo no disponible en el servidor.';
    exit;
}

header('Content-Type: ' . $item['mime']);
header('Content-Disposition: attachment; filename="' . $item['name'] . '"');
header('Content-Length: ' . filesize($item['path']));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
readfile($item['path']);
exit;
