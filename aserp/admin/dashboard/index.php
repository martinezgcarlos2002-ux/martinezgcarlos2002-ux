<?php require_once __DIR__ . '/../../layout/header.php'; ?>

<h2>Dashboard ASERP</h2>
<p>Bienvenido, <?php echo htmlspecialchars($_SESSION['user']['nombre'] ?? 'Usuario'); ?>.</p>

<!-- Estadísticas -->
<div class="stats-container">
    <div class="stat-card">
        <h3>Total Ventas</h3>
        <p><?php echo $data['totalVentas']; ?></p>
    </div>
    <div class="stat-card">
        <h3>Total Productos</h3>
        <p><?php echo $data['totalProductos']; ?></p>
    </div>
    <div class="stat-card">
        <h3>Total Usuarios</h3>
        <p><?php echo $data['totalUsuarios']; ?></p>
    </div>
</div>

<!-- Módulos -->
<div class="modules-container">
    <h3>Módulos del Sistema</h3>
    <div class="module-grid">
        <a href="?url=ventas" class="module-card">
            <h4>Nueva Venta</h4>
            <p>Registrar una nueva venta</p>
        </a>
        <a href="?url=ventas_historial" class="module-card">
            <h4>Historial de Ventas</h4>
            <p>Ver todas las ventas realizadas</p>
        </a>
    </div>
</div>

<!-- Ventas Recientes -->
<div class="recent-sales">
    <h3>Ventas Recientes</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Cliente</th>
                <th>Total</th>
                <th>Fecha</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data['ventasRecientes'] as $venta): ?>
            <tr>
                <td><?php echo $venta['id']; ?></td>
                <td><?php echo htmlspecialchars($venta['cliente']); ?></td>
                <td><?php echo number_format($venta['total'], 2); ?> Bs</td>
                <td><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></td>
                <td><a href="?url=ventas_detalle&id=<?php echo $venta['id']; ?>" class="btn-detail">Ver Detalle</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>