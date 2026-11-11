<?php
declare(strict_types=1);

/** @var list<array{label:string, icon:string, url:string}> $acciones */

$user = current_user();
$visible = Auth::enSoporte() ? (string) ($_SESSION['impersonado_por_nombre'] ?? 'Soporte técnico') : (string) $user['nombre'];
$nombre = explode(' ', trim($visible))[0] ?? '';

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
<script nonce="<?= e(csp_nonce()) ?>">
// Con señal, el Service Worker guarda los módulos de las tarjetas para abrirlos después sin conexión.
(function () {
  if (!('serviceWorker' in navigator) || navigator.onLine === false) return;
  var urls = <?= json_encode(array_column($acciones, 'url'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ?>;
  navigator.serviceWorker.ready.then(function (reg) {
    if (reg.active) reg.active.postMessage({ tipo: 'PRECARGAR_PAGINAS', urls: urls });
  });
})();
</script>
<?php
layout_end();
