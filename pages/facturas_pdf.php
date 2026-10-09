<?php
declare(strict_types=1);

/**
 * Reporte general CxC imprimible (A4 horizontal).
 * Sin atributos style="": la CSP del ERP bloquea style-src inline.
 *
 * @var string $hoy
 * @var string $empresa
 * @var array<string, mixed> $metricas
 * @var list<array<string, mixed>> $prioridad
 * @var list<array<string, mixed>> $clientes
 * @var list<array<string, mixed>> $facturas
 * @var list<array<string, mixed>> $pagos
 * @var list<array<string, mixed>> $devoluciones
 * @var array<int, array<string, mixed>> $clientePorId
 * @var array<int, array<string, mixed>> $facturaPorId
 */
$usd = static fn (mixed $n): string => '$' . num($n);
$estados = ['pagada' => 'Pagada', 'vencida' => 'Vencida', 'por_vencer' => 'Por vencer', 'vigente' => 'Vigente', 'anulada' => 'Anulada'];
$destinos = ['origen' => 'Misma factura', 'otra' => 'Otra factura', 'credito' => 'Crédito a favor'];
$dmy = static fn (string $iso): string => $iso !== '' ? date('d/m/Y', strtotime($iso)) : '';
$tf = ['monto' => 0.0, 'pagado' => 0.0, 'devuelto' => 0.0, 'credito' => 0.0, 'saldo' => 0.0];
foreach ($facturas as $f) {
    foreach (array_keys($tf) as $k) {
        $tf[$k] += (float) $f[$k];
    }
}
$tp = array_sum(array_map(static fn (array $p): float => (float) $p['monto'], $pagos));
$td = array_sum(array_map(static fn (array $d): float => (float) $d['monto'], $devoluciones));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CxC <?= e($dmy($hoy)) ?> – <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/facturas_pdf.css')) ?>">
</head>
<body>
<div class="barra no-print d-flex gap-2 p-2">
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('facturas')) ?>"><i class="bi bi-arrow-left"></i> Volver</a>
  <button type="button" class="btn btn-primary btn-sm" id="btnImprimir"><i class="bi bi-printer"></i> Imprimir / Guardar PDF</button>
</div>

<section class="hoja">
  <header class="d-flex align-items-center gap-3 mb-2">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div class="flex-grow-1">
      <h1 class="h6 mb-0"><?= e($empresa) ?></h1>
      <div class="small">Control de Facturas de Clientes · Reporte general · <?= e($dmy($hoy)) ?></div>
    </div>
    <div class="small text-end"><?= e(APP_NAME) ?></div>
  </header>

  <table class="kpis gerencial">
    <colgroup>
      <col width="25%"><col width="25%"><col width="25%"><col width="25%">
    </colgroup>
    <thead>
      <tr>
        <th>Cartera vigente</th><th>Cartera vencida</th><th>Total pagado</th><th>Total devoluciones</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td align="right" class="num"><?= e($usd($metricas['cartera_vigente'] ?? 0)) ?></td>
        <td align="right" class="num"><?= e($usd($metricas['cartera_vencida'] ?? $metricas['vencido'])) ?></td>
        <td align="right" class="num"><?= e($usd($metricas['cobrado'])) ?></td>
        <td align="right" class="num"><?= e($usd($metricas['total_devoluciones'] ?? 0)) ?></td>
      </tr>
    </tbody>
  </table>

  <table class="kpis">
    <colgroup>
      <col width="16%"><col width="17%"><col width="16%"><col width="17%"><col width="16%"><col width="18%">
    </colgroup>
    <thead>
      <tr>
        <th>Clientes</th><th>Por cobrar</th><th>Pagadas</th>
        <th>Facturado</th><th>A cobrar</th><th>Cobrado</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td align="center" class="num"><?= (int) $metricas['clientes'] ?></td>
        <td align="center" class="num"><?= (int) $metricas['por_cobrar'] ?></td>
        <td align="center" class="num"><?= (int) $metricas['pagadas'] ?></td>
        <td align="right" class="num"><?= e($usd($metricas['facturado'])) ?></td>
        <td align="right" class="num"><?= e($usd($metricas['a_cobrar'])) ?></td>
        <td align="right" class="num"><?= e($usd($metricas['cobrado'])) ?></td>
      </tr>
      <tr>
        <th>Vencido</th><th>Fact. vencidas</th><th>Por vencer</th>
        <th colspan="2">Crédito sin aplicar</th><th>Facturas</th>
      </tr>
      <tr>
        <td align="right" class="num"><?= e($usd($metricas['vencido'])) ?></td>
        <td align="center" class="num"><?= (int) $metricas['vencidas'] ?></td>
        <td align="center" class="num"><?= (int) $metricas['por_vencer'] ?></td>
        <td align="right" class="num" colspan="2"><?= e($usd($metricas['credito'])) ?></td>
        <td align="center" class="num"><?= (int) $metricas['facturas'] ?></td>
      </tr>
    </tbody>
  </table>

  <h2 class="sec">A quiénes cobrar primero</h2>
  <table class="cxc">
    <colgroup>
      <col width="40%"><col width="16%"><col width="16%"><col width="14%"><col width="14%">
    </colgroup>
    <thead>
      <tr>
        <th>Cliente</th><th>Saldo total</th><th>Vencido</th><th>Fact. vencidas</th><th>Días de atraso</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($prioridad as $p): ?>
      <tr>
        <td><?= e((string) $p['cliente']) ?></td>
        <td align="right"><?= e($usd($p['saldo_total'])) ?></td>
        <td align="right"><?= e($usd($p['vencido'])) ?></td>
        <td align="center"><?= (int) $p['facturas_vencidas'] ?></td>
        <td align="right"><?= (int) $p['dias_atraso'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($prioridad === []): ?>
      <tr><td colspan="5" align="center">No hay facturas vencidas.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<section class="hoja salto">
  <header class="d-flex align-items-center gap-3 mb-2">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div class="flex-grow-1">
      <h1 class="h6 mb-0"><?= e($empresa) ?></h1>
      <div class="small">Facturas · <?= e($dmy($hoy)) ?></div>
    </div>
  </header>
  <table class="cxc">
    <colgroup>
      <col width="10%"><col width="18%"><col width="8%"><col width="8%"><col width="9%">
      <col width="8%"><col width="8%"><col width="8%"><col width="8%"><col width="8%"><col width="7%">
    </colgroup>
    <thead>
      <tr>
        <th>N°</th><th>Cliente</th><th>Emisión</th><th>Vence</th><th>Estado</th>
        <th>Monto</th><th>Pagado</th><th>Devuelto</th><th>Crédito</th><th>Saldo</th><th>Domicilio</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($facturas as $f): ?>
      <tr class="<?= $f['estado'] === 'vencida' ? 'vencida' : ($f['estado'] === 'por_vencer' ? 'por-vencer' : '') ?>">
        <td><?= e((string) $f['numero']) ?></td>
        <td><?= e((string) $f['cliente']) ?></td>
        <td align="center"><?= e($dmy($f['emision'])) ?></td>
        <td align="center"><?= e($dmy($f['vence'])) ?></td>
        <td align="center"><?= e($estados[$f['estado']] ?? (string) $f['estado']) ?></td>
        <td align="right"><?= e($usd($f['monto'])) ?></td>
        <td align="right"><?= e($usd($f['pagado'])) ?></td>
        <td align="right"><?= e($usd($f['devuelto'])) ?></td>
        <td align="right"><?= e($usd($f['credito'])) ?></td>
        <td align="right"><?= e($usd($f['saldo'])) ?></td>
        <td><?= e((string) $f['domicilio']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($facturas === []): ?>
      <tr><td colspan="11" align="center">Sin facturas registradas.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="5" align="right">TOTALES</th>
        <th align="right"><?= e($usd($tf['monto'])) ?></th>
        <th align="right"><?= e($usd($tf['pagado'])) ?></th>
        <th align="right"><?= e($usd($tf['devuelto'])) ?></th>
        <th align="right"><?= e($usd($tf['credito'])) ?></th>
        <th align="right"><?= e($usd($tf['saldo'])) ?></th>
        <th></th>
      </tr>
    </tfoot>
  </table>
</section>

<section class="hoja salto">
  <header class="d-flex align-items-center gap-3 mb-2">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="" class="logo">
    <div class="flex-grow-1">
      <h1 class="h6 mb-0"><?= e($empresa) ?></h1>
      <div class="small">Pagos y devoluciones · <?= e($dmy($hoy)) ?></div>
    </div>
  </header>

  <h2 class="sec">Pagos</h2>
  <table class="cxc">
    <colgroup>
      <col width="10%"><col width="12%"><col width="20%"><col width="12%"><col width="16%"><col width="18%"><col width="12%">
    </colgroup>
    <thead>
      <tr><th>Fecha</th><th>Factura</th><th>Cliente</th><th>Método</th><th>Referencia</th><th>Notas</th><th>Monto</th></tr>
    </thead>
    <tbody>
    <?php foreach ($pagos as $p): ?>
      <?php $fac = $facturaPorId[$p['factura_id']] ?? null; ?>
      <tr>
        <td align="center"><?= e($dmy($p['fecha'])) ?></td>
        <td><?= e((string) ($fac['numero'] ?? '')) ?></td>
        <td><?= e((string) ($fac['cliente'] ?? '')) ?></td>
        <td><?= e((string) $p['metodo']) ?></td>
        <td><?= e((string) $p['referencia']) ?></td>
        <td><?= e((string) $p['notas']) ?></td>
        <td align="right"><?= e($usd($p['monto'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pagos === []): ?>
      <tr><td colspan="7" align="center">Sin pagos registrados.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="6" align="right">TOTALES</th><th align="right"><?= e($usd($tp)) ?></th></tr>
    </tfoot>
  </table>

  <h2 class="sec">Devoluciones</h2>
  <table class="cxc">
    <colgroup>
      <col width="9%"><col width="16%"><col width="9%"><col width="20%"><col width="12%"><col width="12%"><col width="12%"><col width="10%">
    </colgroup>
    <thead>
      <tr>
        <th>Fecha</th><th>Cliente</th><th>Monto</th><th>Motivo</th>
        <th>Origen</th><th>Destino</th><th>Aplicada a</th><th>Estado</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($devoluciones as $d): ?>
      <tr>
        <td align="center"><?= e($dmy($d['fecha'])) ?></td>
        <td><?= e((string) ($clientePorId[$d['cliente_id']]['nombre'] ?? '')) ?></td>
        <td align="right"><?= e($usd($d['monto'])) ?></td>
        <td><?= e((string) $d['motivo']) ?></td>
        <td><?= e((string) (($d['factura_id'] !== null ? ($facturaPorId[$d['factura_id']]['numero'] ?? '') : ''))) ?></td>
        <td><?= e($destinos[$d['destino']] ?? (string) $d['destino']) ?></td>
        <td><?= e((string) (($d['factura_destino'] !== null ? ($facturaPorId[$d['factura_destino']]['numero'] ?? '') : ''))) ?></td>
        <td align="center"><?= $d['estado'] === 'pendiente' ? 'Pendiente' : 'Aplicada' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($devoluciones === []): ?>
      <tr><td colspan="8" align="center">Sin devoluciones.</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="2" align="right">TOTALES</th><th align="right"><?= e($usd($td)) ?></th><th colspan="5"></th></tr>
    </tfoot>
  </table>

  <h2 class="sec">Clientes</h2>
  <table class="cxc">
    <colgroup>
      <col width="24%"><col width="16%"><col width="14%"><col width="32%"><col width="14%">
    </colgroup>
    <thead>
      <tr><th>Nombre</th><th>Documento</th><th>Teléfono</th><th>Domicilio</th><th>Saldo a favor</th></tr>
    </thead>
    <tbody>
    <?php foreach ($clientes as $c): ?>
      <tr>
        <td><?= e((string) $c['nombre']) ?></td>
        <td><?= e((string) $c['documento']) ?></td>
        <td><?= e((string) $c['telefono']) ?></td>
        <td><?= e((string) $c['domicilio']) ?></td>
        <td align="right"><?= e($usd($c['credito'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($clientes === []): ?>
      <tr><td colspan="5" align="center">Sin clientes.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('btnImprimir').addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
