<?php
declare(strict_types=1);

/**
 * @var array{id:int, nombre:string, correo:string} $admin
 * @var list<array<string, mixed>> $filas
 * @var string|null $aviso
 */
$aviso = $aviso ?? null;
layout_master_start('Auditoría de maestros', 'auditoria');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div>
    <h5 class="mb-1">Auditoría de Usuarios Maestros</h5>
    <p class="text-muted small mb-0">Solo lectura. El registro no se edita ni se borra, y un Usuario Maestro no puede abrir esta pantalla.</p>
  </div>
  <form method="post" action="<?= e(url('master_respaldo_soporte')) ?>">
    <?= csrf_field() ?>
    <button class="btn btn-dark" type="submit"><i class="bi bi-database-down"></i> Descargar base de soporte</button>
  </form>
</div>

<?php if ($aviso !== null): ?>
<div class="alert alert-warning" role="alert"><?= e($aviso) ?></div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Fecha</th>
          <th>Maestro</th>
          <th>Empresa</th>
          <th>Acción</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($filas === []): ?>
        <tr><td colspan="5" class="text-center text-body-secondary py-4">Todavía no hay movimientos registrados.</td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $fila): ?>
        <tr>
          <td class="small text-nowrap"><?= e(date('d/m/Y H:i:s', strtotime((string) $fila['fecha_hora']))) ?></td>
          <td>
            <div class="fw-semibold"><?= e((string) $fila['maestro']) ?></div>
            <div class="small text-body-secondary"><?= e((string) $fila['correo']) ?></div>
          </td>
          <td><?= e((string) ($fila['nombre_empresa'] ?? '—')) ?></td>
          <td><?= e((string) $fila['accion_realizada']) ?></td>
          <td class="small"><code><?= e((string) $fila['direccion_ip']) ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_master_end(); ?>
