<?php
declare(strict_types=1);

/**
 * @var bool $aceptado
 * @var string|null $fecha
 * @var bool $esAdmin
 * @var bool $enSoporte
 * @var array<string, string> $errores
 */
$errores = $errores ?? [];
layout_start('Términos y Condiciones', 'terminos');
?>
<div class="card">
  <div class="card-body">
    <h5 class="mb-1">Términos y Condiciones</h5>
    <p class="text-muted small mb-3">Contrato de uso de ADARA ERP para esta empresa. La aceptación queda registrada con fecha y no se puede borrar desde aquí.</p>
    <?php require ADARA_ROOT . '/pages/partials/terminos_cliente.php'; ?>

    <?php if ($aceptado): ?>
      <div class="alert alert-success mt-3 mb-0" role="status">
        Esta empresa aceptó los términos<?= $fecha !== null ? ' el ' . e(date('d/m/Y H:i', strtotime($fecha))) : '' ?>.
      </div>
    <?php elseif ($enSoporte): ?>
      <div class="alert alert-warning mt-3 mb-0" role="status">
        El modo soporte no firma este contrato. Tiene que aceptarlo un administrador de la empresa.
      </div>
    <?php elseif ($esAdmin): ?>
      <form method="post" action="<?= e(url('terminos_aceptar')) ?>" class="mt-3">
        <?= csrf_field() ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="acepto" name="acepto" required>
          <label class="form-check-label" for="acepto">He leído y acepto los términos</label>
        </div>
        <?php if (isset($errores['acepto'])): ?>
          <div class="invalid-feedback d-block"><?= e($errores['acepto']) ?></div>
        <?php endif; ?>
        <button class="btn btn-primary mt-3" type="submit">Aceptar y continuar</button>
      </form>
    <?php else: ?>
      <div class="alert alert-info mt-3 mb-0" role="status">
        Un administrador de la empresa tiene que aceptar estos términos antes de operar el sistema.
      </div>
    <?php endif; ?>
  </div>
</div>
<?php layout_end(); ?>
