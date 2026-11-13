<?php
declare(strict_types=1);

/** @var array{id:int, nombre:string, correo:string} $admin */
layout_master_start('Acceso denegado', 'master');
?>
<div class="alert alert-danger" role="alert">
  <h5 class="alert-heading">Solo el Propietario</h5>
  <p class="mb-0">Tu sesión es de Usuario Maestro. El respaldo de la base de soporte y la auditoría de maestros están reservados al Propietario. Este intento quedó registrado.</p>
</div>
<?php layout_master_end(); ?>
