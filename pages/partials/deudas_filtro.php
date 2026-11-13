<?php
declare(strict_types=1);

/**
 * Tabla de cuentas que componen una tarjeta de deudas arrastradas.
 *
 * @var list<array{cuenta_id:int, cuenta:string, concepto:string, desde:string, hasta:string, deuda:string, href:?string}> $deudasFilas
 * @var string $deudasTotal
 * @var string $deudasCorte
 * @var string $deudasTitulo
 * @var string $deudasVolver
 * @var string $deudasNota
 */
$deudasFilas = $deudasFilas ?? [];
$fmt = static function (string $iso): string {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10));
    return $dt instanceof DateTimeImmutable ? $dt->format('d/m/Y') : $iso;
};
?>
<div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
  <div>
    <strong><?= e($deudasTitulo) ?></strong>
    <div class="small mb-0"><?= e($deudasNota) ?> Corte del dashboard: <?= e($fmt($deudasCorte)) ?>.</div>
  </div>
  <div class="text-end">
    <div class="fs-5 fw-semibold text-danger mb-0">$<?= e(num($deudasTotal)) ?></div>
    <div class="small text-muted"><?= count($deudasFilas) ?> cuenta<?= count($deudasFilas) === 1 ? '' : 's' ?></div>
  </div>
</div>
<div class="card mb-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Cuenta</th>
          <th>Concepto</th>
          <th>Semana</th>
          <th class="text-end">Saldo pendiente</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($deudasFilas === []): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No hay cuentas con saldo pendiente anterior a esta semana.</td></tr>
      <?php endif; ?>
      <?php foreach ($deudasFilas as $fila): ?>
        <tr>
          <td class="fw-semibold">
            <?php if (!empty($fila['href'])): ?>
              <a href="<?= e($fila['href']) ?>"><?= e($fila['cuenta']) ?></a>
            <?php else: ?>
              <?= e($fila['cuenta']) ?>
            <?php endif; ?>
          </td>
          <td><?= e($fila['concepto']) ?></td>
          <td class="text-nowrap"><?= e($fmt($fila['desde'])) ?> – <?= e($fmt($fila['hasta'])) ?></td>
          <td class="text-end fw-semibold text-danger">$<?= e(num($fila['deuda'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($deudasFilas !== []): ?>
      <tfoot>
        <tr>
          <th colspan="3" class="text-end">Total (mismo criterio que el dashboard)</th>
          <th class="text-end text-danger">$<?= e(num($deudasTotal)) ?></th>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<p class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="<?= e($deudasVolver) ?>"><i class="bi bi-x-lg"></i> Ver el módulo completo</a></p>
