<?php
declare(strict_types=1);

/**
 * @var list<array<string, mixed>> $tickets
 * @var string|null $aviso
 */
$aviso = $aviso ?? null;
layout_master_start('Tickets de soporte', 'tickets');
?>
<div class="mb-3">
  <h5 class="mb-1">Reportes de los clientes</h5>
  <p class="text-muted small mb-0">Llegan desde el formulario del panel de cada empresa. Nuevo y Pendiente son los que cuenta la campana.</p>
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
          <th>Empresa</th>
          <th>Tipo</th>
          <th>Asunto</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($tickets === []): ?>
        <tr><td colspan="5" class="text-center text-body-secondary py-4">Todavía no hay reportes.</td></tr>
      <?php endif; ?>
      <?php foreach ($tickets as $t): ?>
        <tr id="t<?= (int) $t['id'] ?>">
          <td class="small text-nowrap"><?= e(date('d/m/Y H:i', strtotime((string) $t['fecha_creacion']))) ?></td>
          <td>
            <div class="fw-semibold"><?= e((string) $t['nombre_empresa']) ?></div>
            <div class="small text-body-secondary">Usuario #<?= (int) $t['id_usuario_cliente'] ?></div>
          </td>
          <td class="small"><?= e(SaasComercial::TIPOS_TICKET[(string) $t['tipo_solicitud']] ?? (string) $t['tipo_solicitud']) ?></td>
          <td>
            <div class="fw-semibold"><?= e((string) $t['asunto']) ?></div>
            <div class="small text-body-secondary"><?= e((string) $t['descripcion']) ?></div>
          </td>
          <td>
            <form method="post" action="<?= e(url('master_ticket_estado')) ?>" class="d-flex gap-2">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <select class="form-select form-select-sm" name="estado" aria-label="Estado del reporte <?= (int) $t['id'] ?>">
                <?php foreach (SaasComercial::ESTADOS_TICKET as $codigo => $etiqueta): ?>
                <option value="<?= e($codigo) ?>"<?= (string) $t['estado'] === $codigo ? ' selected' : '' ?>><?= e($etiqueta) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary" type="submit">Guardar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_master_end(); ?>
