<?php
declare(strict_types=1);

/**
 * @var array{id:int, nombre:string, correo:string, debe_cambiar_clave:bool} $admin
 * @var array<string, string> $errores
 */
$err = static fn (string $campo): string => isset($errores[$campo])
    ? '<div class="invalid-feedback d-block">' . e($errores[$campo]) . '</div>' : '';
layout_master_start('Mi clave', 'clave');
?>
<div class="card card-body shadow-sm" style="max-width: <?= $admin['debe_cambiar_clave'] ? '760px' : '480px' ?>">
    <h5 class="mb-1">Cambiar clave</h5>
    <p class="small text-body-secondary mb-3"><?= e($admin['nombre']) ?> · <?= e($admin['correo']) ?></p>
    <form method="post" action="<?= e(url('master_clave')) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="actual">Clave actual</label>
        <input class="form-control" type="password" id="actual" name="actual" autocomplete="current-password" maxlength="<?= PASSWORD_MAX_BYTES ?>" required autofocus>
        <?= $err('actual') ?>
      </div>
      <div class="mb-3">
        <label class="form-label" for="nueva">Nueva clave</label>
        <input class="form-control" type="password" id="nueva" name="nueva" autocomplete="new-password" minlength="<?= Saas::CLAVE_MIN_SUPER_ADMIN ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>" required>
        <div class="form-text">Mínimo <?= Saas::CLAVE_MIN_SUPER_ADMIN ?> caracteres: esta cuenta controla todas las empresas.</div>
        <?= $err('nueva') ?>
      </div>
      <div class="mb-3">
        <label class="form-label" for="confirmacion">Confirmar nueva clave</label>
        <input class="form-control" type="password" id="confirmacion" name="confirmacion" autocomplete="new-password" maxlength="<?= PASSWORD_MAX_BYTES ?>" required>
        <?= $err('confirmacion') ?>
      </div>
      <?php if ($admin['debe_cambiar_clave']): ?>
      <div class="mb-3">
        <div class="border rounded p-3 bg-body-tertiary small" style="max-height: 280px; overflow: auto" tabindex="0">
          <?= acuerdo_confidencialidad_html() ?>
        </div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" value="1" id="acepto_acuerdo" name="acepto_acuerdo" required<?= (($_POST['acepto_acuerdo'] ?? '') === '1') ? ' checked' : '' ?>>
          <label class="form-check-label" for="acepto_acuerdo">He leído y acepto los términos legales y las penalizaciones por plagio de código</label>
          <?= $err('acepto_acuerdo') ?>
        </div>
        <div class="mt-3">
          <label class="form-label" for="palabra_aceptar">Para confirmar, escriba la palabra ACEPTAR</label>
          <input class="form-control" type="text" id="palabra_aceptar" name="palabra_aceptar" value="<?= e((string) ($_POST['palabra_aceptar'] ?? '')) ?>" autocomplete="off" spellcheck="false" maxlength="20" required>
          <?= $err('palabra_aceptar') ?>
        </div>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary w-100">Guardar nueva clave</button>
    </form>
    <?php if (!$admin['debe_cambiar_clave']): ?>
    <a class="btn btn-outline-secondary w-100 mt-3" href="<?= e(url('master')) ?>"><i class="bi bi-arrow-left"></i> Volver al panel</a>
    <?php endif; ?>
</div>
<?php layout_master_end();
