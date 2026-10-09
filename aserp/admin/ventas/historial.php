<?php require_once __DIR__ . '/../../layout/header.php'; ?>

<h1>Historial de Ventas</h1>

<table border="1" cellpadding="5">
    <thead>
        <tr>
            <th>ID</th>
            <th>Cliente</th>
            <th>Total</th>
            <th>Moneda</th>
            <th>Fecha</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($ventas as $venta): ?>
        <tr>
            <td><?= $venta['id']; ?></td>
            <td><?= htmlspecialchars($venta['cliente']); ?></td>
            <td><?= number_format($venta['total'], 2); ?></td>
            <td><?= $venta['moneda'] ?? 'Bs'; ?></td>
            <td><?= $venta['fecha']; ?></td>
            <td><a href="?url=ventas_detalle&id=<?= $venta['id']; ?>">Ver Detalle</a></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<a href="?url=ventas">Nueva Venta</a>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>