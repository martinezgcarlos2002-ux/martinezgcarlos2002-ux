<?php require_once __DIR__ . '/../../layout/header.php'; ?>

<div class="login-container">
    <form method="POST">
        <h2>Iniciar Sesión</h2>
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

        <?php if(!empty($_SESSION['error'])): ?>
            <div class="alert-error"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <input type="email" name="correo" placeholder="Correo" required>
        <input type="password" name="contrasena" placeholder="Contraseña" required>
        <button type="submit">Ingresar</button>
    </form>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>