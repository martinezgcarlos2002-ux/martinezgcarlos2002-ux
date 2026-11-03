<?php
declare(strict_types=1);

/**
 * @var array{id:int, nombre:string, correo:string} $admin
 * @var list<array{nombre:string, tipo:string, hijos:array}> $arbol
 * @var string $version
 */
$icono = static function (string $nombre, string $tipo): string {
    if ($tipo === 'dir') {
        return 'bi-folder2';
    }
    return match (strtolower(pathinfo($nombre, PATHINFO_EXTENSION))) {
        'php' => 'bi-filetype-php',
        'css' => 'bi-filetype-css',
        'js' => 'bi-filetype-js',
        'sql' => 'bi-filetype-sql',
        default => 'bi-file-earmark',
    };
};

$pintar = null;
$pintar = static function (array $nodos) use (&$pintar, $icono): void {
    echo '<ul>';
    foreach ($nodos as $nodo) {
        $dir = $nodo['tipo'] === 'dir';
        echo '<li><span class="' . ($dir ? 'arbol-dir' : 'arbol-archivo') . '">';
        echo '<i class="bi ' . e($icono($nodo['nombre'], $nodo['tipo'])) . '" aria-hidden="true"></i> ';
        echo e($nodo['nombre']) . ($dir ? '/' : '');
        echo '</span>';
        if ($dir && $nodo['hijos'] !== []) {
            $pintar($nodo['hijos']);
        }
        echo '</li>';
    }
    echo '</ul>';
};

$contar = null;
$contar = static function (array $nodos) use (&$contar): int {
    $n = 0;
    foreach ($nodos as $nodo) {
        $n++;
        if ($nodo['tipo'] === 'dir') {
            $n += $contar($nodo['hijos']);
        }
    }
    return $n;
};

layout_master_start('Arquitectura del Sistema', 'arquitectura');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
  <div>
    <h4 class="mb-1"><i class="bi bi-diagram-3"></i> Arquitectura del sistema</h4>
    <p class="text-body-secondary mb-0 small">ADARA v<?= e($version) ?> · <?= e((string) $contar($arbol)) ?> elementos en controllers, models, pages, services y config.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <form method="post" action="<?= e(url('master_arquitectura_pdf')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-pdf"></i> Generar Manual Técnico (PDF)</button>
    </form>
    <form method="post" action="<?= e(url('master_usuario_pdf')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-outline-primary"><i class="bi bi-book"></i> Generar Manual de Usuario</button>
    </form>
  </div>
</div>

<div class="card card-body arbol-mvc">
  <?php if ($arbol === []): ?>
    <p class="text-body-secondary mb-0">No se encontraron las carpetas del MVC.</p>
  <?php else: ?>
    <?php $pintar($arbol); ?>
  <?php endif; ?>
</div>
<?php layout_master_end();