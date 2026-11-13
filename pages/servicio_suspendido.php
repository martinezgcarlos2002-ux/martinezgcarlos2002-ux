<?php
declare(strict_types=1);

/** @var bool $enSoporte */
$alertClass = ['error' => 'danger', 'success' => 'success', 'info' => 'info', 'warning' => 'warning'];
$ticket = ticket_form_pendiente();
$ticketForm = $ticket['form'];
$ticketErrores = $ticket['errores'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Servicio Suspendido – ADARA</title>
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/adara.css')) ?>">
</head>
<body class="app">
<main class="container py-5" style="max-width: 640px">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 mb-2">Servicio Suspendido - Contacte a Soporte</h1>
      <p class="text-muted">El acceso de esta empresa está suspendido. Los datos siguen guardados. Cuando soporte reactive la suscripción, podrán volver a entrar.</p>
      <?php foreach (flash_take() as $msg): ?>
      <div class="alert alert-<?= e($alertClass[$msg['type']] ?? 'info') ?>" role="alert"><?= e($msg['message']) ?></div>
      <?php endforeach; ?>
      <?php if (!$enSoporte): ?>
      <form method="post" action="<?= e(url('soporte_ticket')) ?>" class="mt-3">
        <?= csrf_field() ?>
        <input type="hidden" name="tipo_solicitud" value="asistencia">
        <div class="mb-3">
          <label class="form-label" for="asunto">Asunto</label>
          <input class="form-control" id="asunto" name="asunto" maxlength="140" required value="<?= e($ticketForm['asunto'] ?? 'Servicio suspendido') ?>">
          <?php if (isset($ticketErrores['asunto'])): ?><div class="invalid-feedback d-block"><?= e($ticketErrores['asunto']) ?></div><?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="form-label" for="descripcion">Mensaje para soporte</label>
          <textarea class="form-control" id="descripcion" name="descripcion" rows="4" maxlength="2000" required><?= e($ticketForm['descripcion'] ?? '') ?></textarea>
          <?php if (isset($ticketErrores['descripcion'])): ?><div class="invalid-feedback d-block"><?= e($ticketErrores['descripcion']) ?></div><?php endif; ?>
        </div>
        <button class="btn btn-primary" type="submit">Contactar a soporte</button>
      </form>
      <?php endif; ?>
      <div class="d-flex gap-2 mt-4">
        <?php if ($enSoporte): ?>
        <form method="post" action="<?= e(url('master_salir_soporte')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-outline-secondary" type="submit">Salir del soporte</button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('logout')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-outline-danger" type="submit">Cerrar sesión</button>
        </form>
      </div>
    </div>
  </div>
</main>
</body>
</html>
