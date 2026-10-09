<?php
class VentaService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // Crear venta y actualizar stock
    public function crearVenta($cliente, $items)
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['cantidad'] * $item['precio'];
        }

        // Insertar factura
        $stmt = $this->db->prepare("INSERT INTO facturas (cliente, total, fecha, moneda) VALUES (?, ?, NOW(), ?)");
        $stmt->execute([$cliente, $total, 'Bs']); // Por defecto Bs

        $factura_id = $this->db->lastInsertId();

        // Insertar detalles y actualizar stock
        $stmtDetalle = $this->db->prepare("INSERT INTO detalle_factura (factura_id, producto_id, cantidad, precio) VALUES (?, ?, ?, ?)");
        $stmtStock = $this->db->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");

        foreach ($items as $item) {
            $stmtDetalle->execute([
                $factura_id,
                $item['producto_id'],
                $item['cantidad'],
                $item['precio']
            ]);

            $stmtStock->execute([$item['cantidad'], $item['producto_id']]);
        }

        return $factura_id;
    }

    // Listar todas las ventas
    public function listarVentas()
    {
        $stmt = $this->db->query("SELECT * FROM facturas ORDER BY fecha DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Obtener detalle de factura
    public function detalleFactura($factura_id)
    {
        $stmt = $this->db->prepare("
            SELECT df.*, p.nombre AS producto 
            FROM detalle_factura df
            JOIN productos p ON df.producto_id = p.id
            WHERE df.factura_id = ?
        ");
        $stmt->execute([$factura_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}