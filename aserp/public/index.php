<?php
// 🔹 CONFIGURACIÓN GENERAL
require_once __DIR__ . '/../app/config.php';

// 🔹 CONTROLADORES
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';
require_once __DIR__ . '/../app/controllers/VentasController.php';
require_once __DIR__ . '/../app/middleware/AuthMiddleware.php';
// 🔹 MIDDLEWARE
require_once __DIR__ . '/../app/middleware/AuthMiddleware.php';

// 🔹 INICIALIZAR CONTROLADORES
$authController = new AuthController($db);
$dashboardController = new DashboardController($db);
$ventasController = new VentasController($db);

// 🔹 OBTENER URI
$uri = $_GET['url'] ?? 'login';

// 🔹 RUTEO PRINCIPAL
switch ($uri) {

    // LOGIN / LOGOUT
    case 'login':
        $authController->login();
        break;

    case 'logout':
        $authController->logout();
        break;

    // DASHBOARD
    case 'dashboard':
        AuthMiddleware::verificar(); // Verifica si hay sesión activa
        $dashboardController->index(); // Llama al método index del Dashboard
        break;

    // VENTAS
    case 'ventas':
        AuthMiddleware::verificar();
        $ventasController->nueva();
        break;

    case 'ventas_historial':
        AuthMiddleware::verificar();
        $ventasController->historial();
        break;

    case 'ventas_detalle':
        AuthMiddleware::verificar();
        $ventasController->detalle();
        break;

    // CASO POR DEFECTO
    default:
        $authController->login(); // Si no hay ruta válida, redirige al login
        break;
}