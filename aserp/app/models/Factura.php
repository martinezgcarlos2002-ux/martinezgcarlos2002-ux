<?php

class Factura
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // Crear una nueva factura
    public function crear($cliente, $total, $items)
    {
        $sql = "INSERT INTO facturas (cliente, total, fecha) VALUES (:cliente, :total, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'cliente' => $cliente,
            'total' => $total
        ]);
        $factura_id = $this->db->lastInsertId();

        // Guardar detalles de factura
        foreach ($items as $item) {
            $sqlDetalle = "INSERT INTO detalle_factura (factura_id, producto_id, cantidad, precio) VALUES (:factura_id, :producto_id, :cantidad, :precio)";
            $stmtDetalle = $this->db->prepare($sqlDetalle);
            $stmtDetalle->execute([
                'factura_id' => $factura_id,
                'producto_id' => $item['producto_id'],
                'cantidad' => $item['cantidad'],
                'precio' => $item['precio']
            ]);
        }

        return $factura_id;
    }

    // Listar todas las facturas
    public function listar()
    {
        $sql = "SELECT * FROM facturas ORDER BY fecha DESC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    // Obtener detalles de una factura
    public function obtenerDetalle($id)
    {
        $sql = "SELECT df.*, p.nombre AS producto FROM detalle_factura df 
                JOIN productos p ON df.producto_id = p.id 
                WHERE df.factura_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }
}