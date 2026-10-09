<?php
declare(strict_types=1);

/** @var string $email */
$alertClass = ['error' => 'danger', 'success' => 'success', 'info' => 'info'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Iniciar Sesión – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>"
      integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/login.css')) ?>">
</head>
<body class="login-page" style="background-image:url('<?= e(asset('img/fondo_empresa.jpg')) ?>');background-size:cover;background-position:center;background-repeat:no-repeat;">
<div class="login-overlay">
<div class="card login-card">
  <div class="text-center mb-3">
    <img src="<?= e(asset('img/logo.jpg')) ?>" alt="El Castillo del Queso" class="brand-logo">
    <h4 class="brand-title">El Castillo del Queso</h4>
    <div class="brand-sub">Artesanal · Sistema ERP</div>
    <div class="brand-mark">ADARA ERP</div>
  </div>
  <?php foreach (flash_take() as $msg): ?>
  <div class="alert alert-<?= e($alertClass[$msg['type']] ?? 'info') ?>" role="alert"><?= e($msg['message']) ?></div>
  <?php endforeach; ?>
  <form method="post" action="<?= e(url('login')) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label" for="email">Correo electrónico</label>
      <input type="email" id="email" name="email" class="form-control" value="<?= e($email) ?>" required autofocus autocomplete="username" maxlength="100">
    </div>
    <div class="mb-3">
      <label class="form-label" for="password">Contraseña</label>
      <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password" maxlength="<?= PASSWORD_MAX_BYTES ?>">
    </div>
    <button type="submit" class="btn btn-primary w-100">Ingresar</button>
  </form>
</div>
</div>
<script src="<?= e(BOOTSTRAP_JS['src']) ?>" integrity="<?= e(BOOTSTRAP_JS['integrity']) ?>"
        crossorigin="anonymous"></script>
</body>
</html>
