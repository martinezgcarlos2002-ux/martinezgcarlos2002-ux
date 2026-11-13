<?php
declare(strict_types=1);

/**
 * @var string $token
 * @var array<string, string> $errores
 */
$err = static fn (string $campo): string => isset($errores[$campo])
    ? '<div class="invalid-feedback d-block">' . e($errores[$campo]) . '</div>' : '';
?>
<!DOCTYPE html>
<html lang="es" style="<?= e(EmpresaConfig::estilo()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Nueva contraseña – <?= e(marca()['nombre_empresa']) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>"
      integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/login.css')) ?>">
</head>
<body class="login-page" style="<?= fondo_login_estilo() ?>">
<div class="login-overlay">
<div class="card login-card">
  <div class="text-center mb-3">
    <img src="<?= e(asset_v(LOGO_SISTEMA)) ?>" alt="ADARA" class="brand-logo">
    <h4 class="brand-title">Nueva contraseña</h4>
    <div class="brand-sub">Mínimo <?= PASSWORD_MIN_LEN ?> caracteres.</div>
  </div>
  <form method="post" action="<?= e(url('nueva_clave')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="mb-3">
      <label class="form-label" for="nueva">Nueva contraseña</label>
      <input type="password" id="nueva" name="nueva" class="form-control" required autofocus autocomplete="new-password" minlength="<?= PASSWORD_MIN_LEN ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>">
      <?= $err('nueva') ?>
    </div>
    <div class="mb-3">
      <label class="form-label" for="confirmacion">Confirmar contraseña</label>
      <input type="password" id="confirmacion" name="confirmacion" class="form-control" required autocomplete="new-password" maxlength="<?= PASSWORD_MAX_BYTES ?>">
      <?= $err('confirmacion') ?>
    </div>
    <button type="submit" class="btn btn-primary w-100">Guardar contraseña</button>
  </form>
  <a class="login-link" href="<?= e(url('login')) ?>">Volver a iniciar sesión</a>
</div>
</div>
<script src="<?= e(BOOTSTRAP_JS['src']) ?>" integrity="<?= e(BOOTSTRAP_JS['integrity']) ?>"
        crossorigin="anonymous"></script>
<script src="<?= e(asset_v('js/ver_clave.js')) ?>"></script>
</body>
</html>
