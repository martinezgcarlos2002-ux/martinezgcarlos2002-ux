<?php require_once __DIR__ . '/../../layout/header.php'; ?>

<h1>Detalle de Factura #<?= $id ?></h1>

<table border="1" cellpadding="5">
    <thead>
        <tr>
            <th>Producto</th>
            <th>Cantidad</th>
            <th>Precio</th>
            <th>Subtotal</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $total = 0;
        foreach ($detalle as $item):
            $subtotal = $item['cantidad'] * $item['precio'];
            $total += $subtotal;
        ?>
        <tr>
            <td><?= htmlspecialchars($item['producto']); ?></td>
            <td><?= $item['cantidad']; ?></td>
            <td><?= number_format($item['precio'], 2); ?></td>
            <td><?= number_format($subtotal, 2); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h3>Total: <?= number_format($total, 2); ?></h3>

<a href="?url=ventas_historial">Volver al Historial</a>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>