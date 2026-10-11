<?php
declare(strict_types=1);

/**
 * @var list<array<string, mixed>> $filas
 * @var array<string, string> $tipos
 * @var array<string, string> $metodos
 */
$tipos = $tipos ?? Venta::TIPOS_CLIENTE;
$metodos = $metodos ?? Venta::METODOS_PAGO;
$etiquetaTipo = static function (array $f) use ($tipos): string {
    $base = $tipos[$f['tipo_cliente']] ?? (string) $f['tipo_cliente'];
    if (($f['tipo_cliente'] ?? '') !== 'exclusivo') {
        return $base;
    }
    $entidad = Venta::ENTIDADES[$f['entidad_tipo'] ?? ''] ?? '';
    return $entidad !== '' ? $base . ' (' . $entidad . ')' : $base;
};

layout_start('Historial de facturación', 'ventas');
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link" href="<?= e(url('ventas_nueva')) ?>">Nueva venta</a></li>
  <li class="nav-item"><a class="nav-link active" href="<?= e(url('ventas_historial')) ?>">Historial de facturación</a></li>
</ul>

<div class="card">
  <div class="card-header">Facturas emitidas</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Fecha</th>
          <th>Nro. factura</th>
          <th>Cliente</th>
          <th>Tipo</th>
          <th>Método</th>
          <th class="text-end">Total USD</th>
          <th>Emitido por</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($filas === []): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Aún no hay ventas registradas.</td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $f): ?>
        <tr>
          <td><?= e((new DateTimeImmutable((string) $f['fecha']))->format('d/m/Y H:i')) ?></td>
          <td class="fw-semibold"><?= e($f['numero']) ?></td>
          <td><?= e($f['cliente']) ?></td>
          <td><?= e($etiquetaTipo($f)) ?></td>
          <td><?= e($metodos[$f['metodo_pago']] ?? $f['metodo_pago']) ?></td>
          <td class="text-end">$<?= e(num($f['total_usd'])) ?></td>
          <td><span class="badge-emisor"><?= e($f['emisor']) ?></span></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('ventas_ticket', ['id' => (int) $f['id']])) ?>">Ver ticket PDF</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
layout_end();
