<?php

require_once __DIR__ . '/../services/VentaService.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class VentasController
{
    private $ventaService;
    private $db; //  Guardamos la conexión

    public function __construct($db)
    {
        $this->db = $db; //  Asignar a propiedad
        $this->ventaService = new VentaService($db);
    }

    // NUEVA VENTA
    public function nueva()
    {
        AuthMiddleware::verificar();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Verificar CSRF
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                $_SESSION['error'] = "Token de seguridad inválido";
                header('Location: ?url=ventas');
                exit;
            }

            $cliente = trim($_POST['cliente']) ?? '';
            $items = json_decode($_POST['items'], true) ?? [];

            if (empty($cliente) || empty($items)) {
                $_SESSION['error'] = "Datos inválidos";
                header('Location: ?url=ventas');
                exit;
            }

            $factura_id = $this->ventaService->crearVenta($cliente, $items);

            $_SESSION['success'] = "Venta registrada con ID $factura_id";
            header('Location: ?url=ventas_historial');
            exit;
        }

        // PASAR $db A LA VISTA
        $db = $this->db;
        require_once __DIR__ . '/../../admin/ventas/nueva.php';
    }

    // HISTORIAL DE VENTAS
    public function historial()
    {
        AuthMiddleware::verificar();

        $ventas = $this->ventaService->listarVentas();
        $db = $this->db; //  Pasar db si se necesita en la vista
        require_once __DIR__ . '/../../admin/ventas/historial.php';
    }

    // DETALLE DE VENTA
    public function detalle()
    {
        AuthMiddleware::verificar();

        $id = $_GET['id'] ?? 0;
        $detalle = $this->ventaService->detalleFactura($id);
        $db = $this->db; //  Pasar db si se necesita
        require_once __DIR__ . '/../../admin/ventas/detalle.php';
    }
}