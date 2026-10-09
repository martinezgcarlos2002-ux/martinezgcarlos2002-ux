<?php
class DashboardController {
    private $db;

    public function __construct($db){
        $this->db = $db;
    }

    public function index(){
        // Estadísticas
        $totalVentas = $this->db->query("SELECT COUNT(*) as total FROM facturas")->fetch()['total'];
        $totalProductos = $this->db->query("SELECT COUNT(*) as total FROM productos")->fetch()['total'];
        $totalUsuarios = $this->db->query("SELECT COUNT(*) as total FROM usuarios")->fetch()['total'];
        $ventasRecientes = $this->db->query("SELECT id, cliente, total, fecha FROM facturas ORDER BY fecha DESC LIMIT 5")->fetchAll();

        // Pasar datos a la vista
        $data = [
            'totalVentas' => $totalVentas,
            'totalProductos' => $totalProductos,
            'totalUsuarios' => $totalUsuarios,
            'ventasRecientes' => $ventasRecientes
        ];

        require_once __DIR__ . '/../../admin/dashboard/index.php';
    }
}