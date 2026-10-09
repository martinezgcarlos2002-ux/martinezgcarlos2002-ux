<?php
// 🔹 ZONA HORARIA
date_default_timezone_set('America/Caracas');

// 🔹 INICIAR SESIÓN
if(session_status() == PHP_SESSION_NONE){
    session_start();
}

// 🔹 RUTAS BASE
define('BASE_URL', 'http://localhost/aserp/public/');
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app/');
define('PUBLIC_PATH', ROOT_PATH . '/public/');

// 🔹 BASE DE DATOS
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'aserp');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');

// 🔹 CONFIG GENERAL
define('APP_NAME', 'ASERP');
define('APP_DEBUG', true);

// 🔹 FUNCIONES DE SEGURIDAD
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// 🔹 ERRORES
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
}

// 🔹 CONEXIÓN PDO
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("❌ Error de conexión: " . $e->getMessage());
}