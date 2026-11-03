<?php
declare(strict_types=1);

/**
 * @var array{id:int, nombre:string, correo:string} $admin
 * @var bool $esPropietario
 * @var list<array{creado_en:string, ip:string, nombre:string, correo:string, texto:?string}> $firmas
 */
layout_master_start('Contrato', 'contrato');
?>
<div class="card card-body shadow-sm mb-4">
  <h5 class="mb-1">Acuerdo de confidencialidad</h5>
  <p class="small text-body-secondary mb-3">Puedes leerlo cuando quieras. El texto es el mismo que se firma en el primer ingreso.</p>
  <div class="border rounded p-3 bg-body-tertiary" style="max-height: 480px; overflow: auto">
    <?= acuerdo_confidencialidad_html() ?>
  </div>
</div>
<?php if ($esPropietario): ?>
<div class="card">
  <div class="card-body pb-0">
    <h5 class="mb-1">Firmas registradas</h5>
    <p class="small text-body-secondary">Quién aceptó el acuerdo, cuándo y desde qué dirección.</p>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr><th>Super admin</th><th>Fecha</th><th>IP</th><th>Registro</th></tr>
      </thead>
      <tbody>
      <?php if ($firmas === []): ?>
        <tr><td colspan="4" class="text-body-secondary">Todavía no hay firmas.</td></tr>
      <?php endif; ?>
      <?php foreach ($firmas as $f): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= e($f['nombre']) ?></div>
            <div class="small text-body-secondary"><?= e($f['correo']) ?></div>
          </td>
          <td class="small"><?= e(date('d/m/Y H:i', strtotime((string) $f['creado_en']))) ?></td>
          <td class="small"><code><?= e($f['ip']) ?></code></td>
          <td class="small"><?= e((string) ($f['texto'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php layout_master_end();