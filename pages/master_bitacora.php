<?php
declare(strict_types=1);

/**
 * @var array{id:int, nombre:string, correo:string} $admin
 * @var string $fecha
 * @var string $hoy
 * @var list<array<string, mixed>> $filas
 * @var list<array<string, mixed>> $clientes
 * @var array<string, string> $errores
 * @var array{id_cliente:string, descripcion_tarea:string, estado_tarea:string} $form
 * @var string|null $aviso
 */
$errores = $errores ?? [];
$aviso = $aviso ?? null;
$estados = [
    'en_curso'  => 'En curso',
    'resuelta'  => 'Resuelta',
    'en_espera' => 'En espera',
];
$err = static function (string $campo) use ($errores): string {
    return isset($errores[$campo]) ? '<div class="invalid-feedback d-block">' . e($errores[$campo]) . '</div>' : '';
};
$esHoy = $fecha === $hoy;
layout_master_start('Bitácora del día', 'bitacora');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
  <div>
    <h5 class="mb-1">Bitácora de soporte</h5>
    <p class="text-muted small mb-0">Cada maestro deja su reporte del día. El panel es compartido: se lee, y no se edita ni se borra.</p>
  </div>
  <form method="get" action="<?= e(url('master_bitacora')) ?>" class="d-flex align-items-end gap-2">
    <div>
      <label class="form-label small mb-1" for="fecha">Día</label>
      <input class="form-control" type="date" id="fecha" name="fecha" value="<?= e($fecha) ?>">
    </div>
    <button class="btn btn-outline-secondary" type="submit">Ver</button>
  </form>
</div>

<?php if ($aviso !== null): ?>
<div class="alert alert-warning" role="alert"><?= e($aviso) ?></div>
<?php endif; ?>
<?php if (isset($errores['general'])): ?>
<div class="alert alert-danger" role="alert"><?= e($errores['general']) ?></div>
<?php endif; ?>

<?php if ($esHoy): ?>
<div class="card card-body mb-4">
  <h6 class="mb-3">Mi reporte de hoy · <?= e($admin['nombre']) ?></h6>
  <form method="post" action="<?= e(url('master_bitacora_guardar')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label" for="id_cliente">Empresa</label>
        <select class="form-select" id="id_cliente" name="id_cliente" required>
          <option value="">Selecciona</option>
          <?php foreach ($clientes as $c): ?>
          <option value="<?= (int) $c['id'] ?>"<?= (string) $c['id'] === $form['id_cliente'] ? ' selected' : '' ?>><?= e((string) $c['nombre_empresa']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('id_cliente') ?>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="estado_tarea">Estado</label>
        <select class="form-select" id="estado_tarea" name="estado_tarea" required>
          <?php foreach ($estados as $codigo => $etiqueta): ?>
          <option value="<?= e($codigo) ?>"<?= $form['estado_tarea'] === $codigo ? ' selected' : '' ?>><?= e($etiqueta) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('estado_tarea') ?>
      </div>
      <div class="col-12">
        <label class="form-label" for="descripcion_tarea">Qué estás haciendo</label>
        <textarea class="form-control" id="descripcion_tarea" name="descripcion_tarea" rows="3" maxlength="500" required><?= e($form['descripcion_tarea']) ?></textarea>
        <?= $err('descripcion_tarea') ?>
      </div>
      <div class="col-12">
        <button class="btn btn-primary" type="submit">Registrar reporte</button>
      </div>
    </div>
  </form>
</div>
<?php else: ?>
<p class="text-muted small">Estás viendo otro día. El reporte nuevo siempre queda con la fecha de hoy.</p>
<?php endif; ?>

<div class="card">
  <div class="card-body pb-0">
    <h6 class="mb-0">Panel del <?= e(date('d/m/Y', strtotime($fecha))) ?></h6>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Maestro</th>
          <th>Empresa</th>
          <th>Tarea</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($filas === []): ?>
        <tr><td colspan="4" class="text-center text-body-secondary py-4">Nadie ha registrado trabajo para este día.</td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $fila): ?>
        <tr>
          <td class="fw-semibold"><?= e((string) $fila['maestro']) ?></td>
          <td><?= e((string) $fila['nombre_empresa']) ?></td>
          <td><?= e((string) $fila['descripcion_tarea']) ?></td>
          <td><span class="badge text-bg-light border"><?= e($estados[(string) $fila['estado_tarea']] ?? (string) $fila['estado_tarea']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_master_end(); ?>
