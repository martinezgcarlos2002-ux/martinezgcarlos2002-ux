<?php require_once __DIR__ . '/../../layout/header.php'; ?>

<h1>Nueva Venta</h1>

<?php if(!empty($_SESSION['error'])): ?>
    <div class="alert-error"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<?php if(!empty($_SESSION['success'])): ?>
    <div class="alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>

<form method="POST" id="formVenta">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <label>Cliente:</label>
    <input type="text" name="cliente" required>

    <h3>Productos</h3>
    <table border="1" cellpadding="5">
        <thead>
            <tr>
                <th>Producto</th>
                <th>Stock</th>
                <th>Precio</th>
                <th>Cantidad</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // ✅ Ahora $db está definido desde el controller
            $productos = $db->query("SELECT * FROM productos WHERE stock > 0")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($productos as $p):
            ?>
            <tr>
                <td><?= htmlspecialchars($p['nombre']); ?></td>
                <td><?= $p['stock']; ?></td>
                <td><?= number_format($p['precio'], 2); ?></td>
                <td><input type="number" name="cantidad[<?= $p['id']; ?>]" min="0" max="<?= $p['stock']; ?>" value="0"></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <input type="hidden" name="items" id="items">
    <button type="submit">Registrar Venta</button>
</form>

<a href="?url=ventas_historial">Ver Historial de Ventas</a>

<script>
document.getElementById('formVenta').addEventListener('submit', function(e){
    e.preventDefault();
    let items = [];
    <?php foreach ($productos as $p): ?>
        let cantidad = parseInt(document.querySelector('input[name="cantidad[<?= $p['id']; ?>]"]').value);
        if(cantidad > 0){
            items.push({
                producto_id: <?= $p['id']; ?>,
                cantidad: cantidad,
                precio: <?= $p['precio']; ?>
            });
        }
    <?php endforeach; ?>
    document.getElementById('items').value = JSON.stringify(items);
    this.submit();
});
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>