<?php
declare(strict_types=1);

/** @var list<array{label:string, icon:string, url:string}> $acciones */

$user = current_user();
$nombre = explode(' ', trim((string) $user['nombre']))[0] ?? '';

layout_start('Inicio', 'dashboard');
?>
<div class="movil-saludo">
  <div class="text-muted small">Hola<?= $nombre !== '' ? ',' : '' ?></div>
  <h4 class="mb-0"><?= e($nombre !== '' ? $nombre : 'bienvenido') ?></h4>
</div>

<?php if ($acciones === []): ?>
  <div class="alert alert-info">Tu usuario todavía no tiene módulos asignados. Pídele acceso a un administrador.</div>
<?php else: ?>
<div class="acciones-rapidas">
  <?php foreach ($acciones as $a): ?>
  <a class="accion-rapida" href="<?= e($a['url']) ?>">
    <span class="accion-rapida-icono"><i class="bi <?= e($a['icon']) ?>"></i></span>
    <span class="accion-rapida-texto"><?= e($a['label']) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" action="<?= e(url('vista_web')) ?>" class="mt-4">
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-globo w-100">
    <i class="bi bi-globe2"></i> Ver versión web completa
  </button>
</form>
<?php
layout_end();
