<?php
declare(strict_types=1);

/**
 * Cierre Diario — Respaldo SQL.
 * @var string $motor
 * @var list<string> $tablas
 * @var list<string> $vistas
 * @var array{usuario:?string, fecha:string, detalle:?array}|null $ultimo
 * @var bool $descargadoHoy
 * @var string $ahora
 */
$momento = new DateTimeImmutable($ahora);
$ultimoMomento = $ultimo === null ? null : new DateTimeImmutable($ultimo['fecha']);
$mb = static fn (int $bytes): string => num($bytes / 1048576, 2) . ' MB';

layout_start('Cierre Diario — Respaldo SQL', 'respaldo');
?>
<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h5 class="mb-1 text-navy"><i class="bi bi-database-down"></i> Cierre Diario — Respaldo SQL</h5>
    <p class="text-muted small mb-0">Descarga un archivo SQL completo (estructura, datos, vistas y triggers) para restaurar el sistema.</p>
  </div>
  <form method="post" action="<?= e(url('respaldo_descargar')) ?>" class="m-0">
    <?= csrf_field() ?>
    <input type="hidden" name="origen" value="manual">
    <button type="submit" class="btn btn-primary"><i class="bi bi-download"></i> Descargar Respaldo Manual</button>
  </form>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="text-muted small">Motor actual</div>
        <div class="kpi-num fs-5"><?= e(str_contains(strtolower($motor), 'mariadb') ? 'MariaDB' : 'MySQL') ?></div>
        <div class="small text-muted"><?= e($motor) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="text-muted small">Tablas a respaldar</div>
        <div class="kpi-num fs-4"><?= count($tablas) ?></div>
        <?php if ($vistas): ?><div class="small text-muted">+ <?= count($vistas) ?> vista(s)</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="text-muted small">Fecha de cierre</div>
        <div class="kpi-num fs-4"><?= e($momento->format('d/m/Y')) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <div class="text-muted small">Hora</div>
        <div class="kpi-num fs-4"><?= e($momento->format('H:i')) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <strong>Importante:</strong> Guarda este archivo SQL en un lugar seguro (USB, nube, etc.). Contiene todos los datos del negocio, incluidos los usuarios.</div>

<div class="alert alert-info"><i class="bi bi-pc-display"></i> Descarga automática para administradores: el primer administrador que abra ADARA en el día descargará automáticamente el respaldo.</div>

<?php if ($ultimo !== null): ?>
  <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <?php if ($descargadoHoy): ?>
      <span class="badge rounded-pill text-bg-success"><i class="bi bi-check2-circle"></i> Respaldo de hoy ya descargado</span>
    <?php else: ?>
      <span class="badge rounded-pill text-bg-warning"><i class="bi bi-exclamation-circle"></i> Hoy aún no se ha descargado el respaldo</span>
    <?php endif; ?>
    <span class="small text-muted">
      Último: <?= e($ultimoMomento->format('d/m/Y H:i')) ?> por <strong><?= e($ultimo['usuario'] ?? 'Sistema') ?></strong>
      <?php if (isset($ultimo['detalle']['bytes'])): ?>· <?= e($mb((int) $ultimo['detalle']['bytes'])) ?>, <?= e(num($ultimo['detalle']['filas'] ?? 0, 0)) ?> filas<?php endif; ?>
      <?php if (!empty($ultimo['detalle']['automatica'])): ?>· automático<?php endif; ?>
    </span>
  </div>
  <?php if (isset($ultimo['detalle']['sha256'])): ?>
    <p class="small text-muted">SHA-256 del último archivo: <code class="text-break"><?= e($ultimo['detalle']['sha256']) ?></code></p>
  <?php endif; ?>
<?php else: ?>
  <div class="alert alert-danger"><i class="bi bi-x-octagon"></i> Nunca se ha descargado un respaldo. Descárgalo ahora.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header py-2">Tablas incluidas (<?= count($tablas) ?>)</div>
      <div class="card-body panel-scroll">
        <ul class="mb-0 small">
          <?php foreach ($tablas as $tabla): ?><li><code><?= e($tabla) ?></code></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header py-2">Cómo restaurar en phpMyAdmin</div>
      <div class="card-body">
        <ol class="mb-0">
          <li>Abre phpMyAdmin y crea (o selecciona) una base de datos vacía.</li>
          <li>Entra a la pestaña <strong>Importar</strong>.</li>
          <li>Sube el archivo <code>adara_respaldo_AAAA-MM-DD_HHMMSS.sql</code>.</li>
          <li>Pulsa <strong>Continuar</strong>. Se recrean todas las tablas, los datos y, al final, los triggers.</li>
        </ol>
      </div>
    </div>
  </div>
</div>
<?php
layout_end();
