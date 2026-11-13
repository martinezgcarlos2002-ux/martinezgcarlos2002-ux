<?php
declare(strict_types=1);

/** @var string $email */
$alertClass = ['error' => 'danger', 'success' => 'success', 'info' => 'info'];
// La app Android agrega "ADARA-App" al user agent: ahí la descarga ni se imprime.
$enApp = str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'ADARA-App');
?>
<!DOCTYPE html>
<html lang="es" style="<?= e(EmpresaConfig::estilo()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b1f33">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="<?= e(base_path()) ?>manifest.webmanifest">
<title>Iniciar Sesión – <?= e(marca()['nombre_empresa']) ?></title>
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
    <h4 class="brand-title"><?= e(marca()['nombre_empresa']) ?></h4>
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
  <a class="login-link" href="<?= e(url('olvide_clave')) ?>">¿Olvidaste tu contraseña?</a>
  <?php if (!$enApp): ?>
  <div id="apk-download-section" class="apk-descarga" hidden>
    <a class="btn btn-outline-secondary w-100" href="<?= e(asset('descargas/adara.apk')) ?>" download="ADARA.apk">
      Descargar app Android
    </a>
    <div class="apk-descarga-nota">Para teléfonos y tablets Android.</div>
  </div>
  <?php endif; ?>
</div>
</div>
<script src="<?= e(BOOTSTRAP_JS['src']) ?>" integrity="<?= e(BOOTSTRAP_JS['integrity']) ?>"
        crossorigin="anonymous"></script>
<script src="<?= e(asset_v('js/ver_clave.js')) ?>"></script>
<?php if (!$enApp): ?>
<script src="<?= e(asset_v('js/apk_descarga.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
