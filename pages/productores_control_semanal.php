<?php
declare(strict_types=1);

/**
 * Carga masiva de la semana sábado–viernes.
 * @var string $semana        sábado de la semana
 * @var string $semanaFin
 * @var string $semanaActual
 * @var list<string> $dias    7 fechas Y-m-d
 * @var string $hoy
 * @var list<array> $filas
 * @var array<string, array<string, string>> $diario
 * @var array<string, string> $netaDia
 * @var string $totNeta
 */
$dinero = static fn (mixed $n): string => ((float) $n < 0 ? '-' : '') . '$' . num(abs((float) $n), 2);
$fecha = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m/Y');
$fechaCorta = static fn (string $f): string => (new DateTimeImmutable($f))->format('d/m');
$nombresDia = ['Sáb', 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie'];

$puedeCrear = has_permiso(current_user(), 'productores', 'crear');
$inicio = new DateTimeImmutable($semana);
$semanaAnterior = $inicio->modify('-7 days')->format('Y-m-d');
$semanaSiguiente = $inicio->modify('+7 days')->format('Y-m-d');
$diasCargables = array_values(array_filter($dias, static fn (string $d): bool => $d <= $hoy));
$diaModal = in_array($hoy, $diasCargables, true) ? $hoy : (end($diasCargables) ?: $semana);

$diario = $diario ?? [];
$netaDia = $netaDia ?? [];
$totNeta = $totNeta ?? '0';
$totDia = array_fill_keys($dias, 0.0);
$tot = ['litros' => 0.0, 'pagar' => 0.0, 'saldo' => 0.0, 'merc' => 0.0, 'pm' => 0.0, 'efec' => 0.0, 'abonos' => 0.0, 'pend' => 0.0];
foreach ($filas as $fila) {
    foreach ($fila['litros_dia'] as $d => $l) {
        $totDia[$d] += (float) $l;
    }
    $tot['litros'] += (float) $fila['total_litros'];
    $tot['pagar'] += (float) $fila['total_pagar'];
    $tot['saldo'] += (float) ($fila['saldo_previo'] ?? 0);
    $tot['merc'] += (float) $fila['mercancia'];
    $tot['pm'] += (float) $fila['pm_trans'];
    $tot['efec'] += (float) $fila['efectivo'];
    $tot['abonos'] += (float) $fila['total_abonos'];
    $tot['pend'] += (float) $fila['pendiente'];
}

layout_start('Control Semanal de Productores', 'productores');
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productores')) ?>"><i class="bi bi-arrow-left"></i> Volver</a>
  <h5 class="mb-0">Control Semanal de Productores</h5>
  <form class="ms-lg-auto d-flex flex-wrap align-items-center gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>" id="formIrSemana">
    <input type="hidden" name="p" value="productores_control_semanal">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productores_control_semanal', ['semana' => $semanaAnterior])) ?>"><i class="bi bi-chevron-left"></i> Ant.</a>
    <input type="date" name="semana" id="inpSemana" class="form-control form-control-sm w-fecha" value="<?= e($semana) ?>" max="<?= e($hoy) ?>" aria-label="Semana">
    <?php if ($semanaSiguiente <= $semanaActual): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('productores_control_semanal', ['semana' => $semanaSiguiente])) ?>">Sig. <i class="bi bi-chevron-right"></i></a>
    <?php else: ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Sig. <i class="bi bi-chevron-right"></i></button>
    <?php endif; ?>
    <a class="btn btn-sm btn-primary" href="<?= e(url('productores_control_semanal')) ?>">Hoy</a>
  </form>
</div>

<div class="semana-banner d-flex flex-wrap align-items-center gap-2 px-3 py-2 mb-3">
  <strong><i class="bi bi-calendar3"></i> Semana:</strong>
  <span><?= e($fecha($semana)) ?> (Sábado) — <?= e($fecha($semanaFin)) ?> (Viernes)</span>
  <span class="ms-auto text-muted"><?= count($filas) ?> productores activos</span>
</div>

<div class="d-flex align-items-center flex-wrap gap-2 mb-2">
  <div class="input-group input-group-sm w-filtro">
    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
    <input type="search" id="buscarProductor" class="form-control" placeholder="Buscar productor..." aria-label="Buscar productor">
  </div>
  <a class="btn btn-sm btn-notaria" target="_blank" rel="noopener" href="<?= e(url('productores_notaria', ['semana' => $semana])) ?>"><i class="bi bi-journal-text"></i> Notaría</a>
  <div class="ms-lg-auto d-flex flex-wrap gap-2">
    <?php if ($puedeCrear): ?>
    <form method="post" action="<?= e(url('productores_cerrar_semanas')) ?>" class="m-0">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-lock"></i> Cerrar semanas terminadas</button>
    </form>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalLitraje" <?= $diasCargables === [] ? 'disabled' : '' ?>>
      <i class="bi bi-pencil-square"></i> Cargar por día
    </button>
    <button type="submit" form="formSemana" class="btn btn-sm btn-success"><i class="bi bi-save"></i> Guardar Semana</button>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-outline-success js-export-xlsx"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</button>
    <button type="button" class="btn btn-sm btn-outline-danger js-export-pdf"><i class="bi bi-file-earmark-pdf"></i> Exportar PDF</button>
  </div>
</div>
<p class="text-muted small">Escribe o corrige los litros y pulsa <strong>Guardar Semana</strong>. Si un día ya tenía entrega, sus litros se sobrescriben (el precio queda el congelado al registrarla). Los días futuros y las semanas cerradas <i class="bi bi-lock-fill"></i> no se editan.</p>

<form method="post" action="<?= e(url('productores_semana_guardar')) ?>" id="formSemana" autocomplete="off">
<?= csrf_field() ?>
<input type="hidden" name="semana" value="<?= e($semana) ?>">
<div class="table-responsive mb-3">
  <table class="table table-sm table-bordered table-hover align-middle mb-0 table-navy table-masiva" id="tblMasiva"
         data-export-title="Control Semanal de Productores"
         data-export-subtitle="Semana: <?= e($fecha($semana)) ?> (Sábado) — <?= e($fecha($semanaFin)) ?> (Viernes)"
         data-export-filename="Control_Semanal_Productores_<?= e((new DateTimeImmutable($semana))->format('d-m-Y')) ?>">
    <thead>
      <tr>
        <th class="text-center">#</th>
        <th>Nombre</th>
        <th>Cédula</th>
        <th class="text-end">Precio $/L</th>
        <?php foreach ($dias as $i => $d): ?>
          <th class="text-center"><?= e($nombresDia[$i]) ?><br><span class="fw-normal"><?= e($fechaCorta($d)) ?></span></th>
        <?php endforeach; ?>
        <th class="text-end">Tot. Litros</th>
        <th class="text-end">Total a Pagar $</th>
        <th class="text-end">Saldo Pend.</th>
        <th class="text-end">Merc. $</th>
        <th class="text-end">PM/Trans $</th>
        <th class="text-end">Efec. $</th>
        <th class="text-end">Tot. Abonos</th>
        <th class="text-end">Pendiente a Pagar</th>
      </tr>
    </thead>
    <tbody>
    <?php if ($filas === []): ?>
      <tr><td colspan="19" class="text-center text-muted py-4">No hay productores activos.</td></tr>
    <?php endif; ?>
    <?php foreach ($filas as $i => $fila):
        $pid = (int) $fila['id'];
        $saldo = $fila['saldo_previo'];
        $saldoCls = $saldo === null ? 'text-muted' : ((float) $saldo > 0 ? 'text-danger fw-semibold' : 'text-success fw-semibold');
        $pendCls = (float) $fila['pendiente'] > 0 ? 'text-danger' : 'text-success';
    ?>
      <tr data-nombre="<?= e(mb_strtolower($fila['nombre'])) ?>">
        <td class="text-center text-muted"><?= $i + 1 ?></td>
        <td class="nombre-prod text-truncate" title="<?= e($fila['nombre']) ?>">
          <?php if ($fila['cerrada']): ?><i class="bi bi-lock-fill text-muted" title="Semana cerrada: un administrador puede reabrirla"></i><?php endif; ?>
          <a class="link-productor" href="<?= e(url('productor_control', ['id' => $pid, 'semana' => $semana])) ?>"><?= e($fila['nombre']) ?></a>
        </td>
        <td class="text-muted"><?= e($fila['documento'] ?: '—') ?></td>
        <td class="text-end bg-col-dia num"><?= e(num($fila['precio'], 4)) ?></td>
        <?php foreach ($dias as $j => $d):
            $litros = $fila['litros_dia'][$d];
        ?>
          <td class="text-end bg-col-dia">
            <?php if ($fila['cerrada'] || $d > $hoy || !$puedeCrear): ?>
              <input type="number" class="form-control form-control-sm text-end inp-celda ms-auto bg-light" value="<?= $litros !== null ? e((float) $litros) : '' ?>" placeholder="—" disabled
                     aria-label="<?= e($nombresDia[$j]) ?> <?= e($fila['nombre']) ?><?= $fila['cerrada'] ? ' (semana cerrada)' : '' ?>">
            <?php else: ?>
              <input type="number" name="litros[<?= $pid ?>][<?= e($d) ?>]" class="form-control form-control-sm text-end inp-celda ms-auto <?= $litros !== null ? 'fw-semibold' : '' ?>"
                     min="0" max="100000" step="0.001" placeholder="0" value="<?= $litros !== null ? e((float) $litros) : '' ?>"
                     aria-label="<?= e($nombresDia[$j]) ?> <?= e($fila['nombre']) ?>">
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
        <td class="text-end num bg-col-lit"><?= e(num($fila['total_litros'], 1)) ?></td>
        <td class="text-end num bg-col-pagar"><?= e($dinero($fila['total_pagar'])) ?></td>
        <td class="text-end num bg-col-saldo <?= e($saldoCls) ?>"><?= $saldo === null ? '—' : e($dinero($saldo)) ?></td>
        <td class="text-end num bg-col-merc"><?= (float) $fila['mercancia'] > 0 ? e($dinero($fila['mercancia'])) : '—' ?></td>
        <td class="text-end num bg-col-pm"><?= (float) $fila['pm_trans'] > 0 ? e($dinero($fila['pm_trans'])) : '—' ?></td>
        <td class="text-end num bg-col-efec"><?= (float) $fila['efectivo'] > 0 ? e($dinero($fila['efectivo'])) : '—' ?></td>
        <td class="text-end num bg-col-abono"><?= e($dinero($fila['total_abonos'])) ?></td>
        <td class="text-end num bg-col-pend <?= e($pendCls) ?>"><?= e($dinero($fila['pendiente'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td>Totales</td>
        <td></td>
        <td></td>
        <td></td>
        <?php foreach ($dias as $d): ?>
          <td class="text-end"><?= e(num($totDia[$d], 1)) ?></td>
        <?php endforeach; ?>
        <td class="text-end"><?= e(num($tot['litros'], 1)) ?></td>
        <td class="text-end"><?= e($dinero($tot['pagar'])) ?></td>
        <td class="text-end"><?= e($dinero($tot['saldo'])) ?></td>
        <td class="text-end"><?= e($dinero($tot['merc'])) ?></td>
        <td class="text-end"><?= e($dinero($tot['pm'])) ?></td>
        <td class="text-end"><?= e($dinero($tot['efec'])) ?></td>
        <td class="text-end"><?= e($dinero($tot['abonos'])) ?></td>
        <td class="text-end"><?= e($dinero($tot['pend'])) ?></td>
      </tr>
    </tfoot>
  </table>
</div>

<h6 class="text-navy fw-bold mb-2"><i class="bi bi-bar-chart"></i> Resumen Diario de Leche</h6>
<div class="table-responsive mb-2">
  <table class="table table-sm table-bordered align-middle mb-0 table-navy" id="tblResumenLeche">
    <thead>
      <tr>
        <th>Concepto</th>
        <?php foreach ($dias as $i => $d): ?>
          <th class="text-center"><?= e($nombresDia[$i]) ?> <?= e($fechaCorta($d)) ?></th>
        <?php endforeach; ?>
        <th class="text-center">Total semanal</th>
      </tr>
    </thead>
    <tbody>
      <tr id="filaRecibida">
        <td>Leche recibida (productores)</td>
        <?php foreach ($dias as $d): ?>
          <td class="text-end num js-recibida" data-fecha="<?= e($d) ?>" data-litros="<?= e((string) $totDia[$d]) ?>"><?= e(num($totDia[$d], 1)) ?></td>
        <?php endforeach; ?>
        <td class="text-end num fw-bold"><?= e(num($tot['litros'], 1)) ?></td>
      </tr>
      <?php
        $filasMerma = [
            'ventas_leche'    => 'Ventas Leche',
            'cortesias_leche' => 'Cortesías Leche',
            'vales_leche'     => 'Vales Leche',
            'leche_faltante'  => 'Leche Faltante',
        ];
        foreach ($filasMerma as $campo => $etiqueta):
            $totCampo = 0.0;
      ?>
      <tr data-campo="<?= e($campo) ?>">
        <td><?= e($etiqueta) ?></td>
        <?php foreach ($dias as $d):
            $val = (float) ($diario[$d][$campo] ?? 0);
            $totCampo += $val;
            $bloqueado = !$puedeCrear || $d > $hoy;
        ?>
          <td class="text-end">
            <input type="number" class="form-control form-control-sm text-end inp-celda ms-auto js-merma" min="0" max="100000" step="0.01"
                   name="diario[<?= e($d) ?>][<?= e($campo) ?>]" data-fecha="<?= e($d) ?>" data-campo="<?= e($campo) ?>"
                   value="<?= $val > 0 ? e(number_format($val, 2, '.', '')) : '' ?>" placeholder="0"
                   <?= $bloqueado ? 'disabled' : '' ?> aria-label="<?= e($etiqueta) ?> <?= e($fechaCorta($d)) ?>">
          </td>
        <?php endforeach; ?>
        <td class="text-end num fw-semibold js-tot-merma" data-campo="<?= e($campo) ?>"><?= $totCampo > 0 ? e(num($totCampo, 1)) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="table-warning">
        <td><strong>LECHE PROCESADA NETA (Litros)</strong></td>
        <?php foreach ($dias as $d): ?>
          <td class="text-end num fw-bold js-neta" data-fecha="<?= e($d) ?>"><?= e(num($netaDia[$d] ?? 0, 1)) ?></td>
        <?php endforeach; ?>
        <td class="text-end num fw-bold js-neta-total"><?= e(num($totNeta, 1)) ?></td>
      </tr>
    </tbody>
  </table>
</div>
<p class="text-muted small">Neta = recibida − (ventas + cortesías + vales + faltante). Ese valor es el que usa el costo de producción de queso, no la leche bruta.</p>

<div class="d-flex justify-content-end flex-wrap gap-2 mb-4">
  <button type="button" class="btn btn-outline-success js-export-xlsx"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</button>
  <button type="button" class="btn btn-outline-danger js-export-pdf"><i class="bi bi-file-earmark-pdf"></i> Exportar PDF</button>
  <?php if ($puedeCrear): ?>
  <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Guardar Semana</button>
  <?php endif; ?>
</div>
</form>

<?php if ($puedeCrear && $diasCargables !== []): ?>
<!-- ============================================================
     MODAL: Cargar litraje por día
     Cada fila se guarda al pulsar la flecha o Enter (fetch); "Guardar día" envía todas las filas.
     ============================================================ -->
<div class="modal fade" id="modalLitraje" tabindex="-1" aria-labelledby="tituloLitraje" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content modal-navy">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloLitraje"><i class="bi bi-pencil-square"></i> Cargar litraje por día</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Escribe el litraje y pulsa la flecha (o Enter): se guarda esa fila y pasa a la siguiente. Si el día ya tenía litros, se sobrescriben.</p>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="diaLitraje">Día de la semana</label>
            <select class="form-select" id="diaLitraje">
              <?php foreach ($dias as $i => $d): if ($d > $hoy) { continue; } ?>
                <option value="<?= e($d) ?>" <?= $d === $diaModal ? 'selected' : '' ?>><?= e($nombresDia[$i]) ?> <?= e($fecha($d)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="input-group input-group-sm mb-3">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" id="buscarLitraje" class="form-control" placeholder="Buscar productor..." aria-label="Buscar en la carga por día">
          </div>
          <div class="list-group" id="listaLitraje">
            <?php foreach ($filas as $i => $fila):
                $pid = (int) $fila['id'];
                $registrados = array_map(static fn ($l): float => (float) $l, array_filter($fila['litros_dia'], static fn ($l): bool => $l !== null));
                $valor = $registrados[$diaModal] ?? null;
            ?>
            <div class="list-group-item d-flex align-items-center gap-2 py-2" data-nombre="<?= e(mb_strtolower($fila['nombre'])) ?>">
              <span class="text-muted small text-nowrap">#<?= $i + 1 ?></span>
              <span class="flex-grow-1 text-truncate fw-semibold">
                <?php if ($fila['cerrada']): ?><i class="bi bi-lock-fill text-muted" title="Semana cerrada"></i><?php endif; ?>
                <?= e($fila['nombre']) ?>
              </span>
              <span class="small text-nowrap js-estado" aria-live="polite"></span>
              <input type="number" form="formLitraje" class="form-control form-control-sm text-end inp-litros" min="0" max="100000" step="0.001" placeholder="0"
                     data-pid="<?= $pid ?>"
                     data-litros="<?= e(json_encode((object) $registrados, JSON_THROW_ON_ERROR)) ?>"
                     value="<?= $valor !== null ? e($valor) : '' ?>"
                     <?= $fila['cerrada'] ? 'disabled' : 'name="litros[' . $pid . '][' . e($diaModal) . ']"' ?>
                     aria-label="Litros <?= e($fila['nombre']) ?>">
              <button type="button" class="btn btn-sm btn-cian js-guardar-fila" <?= $fila['cerrada'] ? 'disabled' : '' ?> aria-label="Guardar litros de <?= e($fila['nombre']) ?> y continuar"><i class="bi bi-arrow-right"></i></button>
            </div>
            <?php endforeach; ?>
          </div>
          <p class="text-muted small mt-3 mb-0">Los productores con la semana cerrada <i class="bi bi-lock-fill"></i> no se pueden editar.</p>
        </div>
        <div class="modal-footer">
          <form method="post" action="<?= e(url('productores_semana_guardar')) ?>" id="formLitraje" autocomplete="off" class="d-flex gap-2 m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="semana" value="<?= e($semana) ?>">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cerrar</button>
            <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Guardar día</button>
          </form>
        </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
<script src="<?= e(asset_v('js/control_semanal_export.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  function filtrar(input, filas) {
    if (!input) return;
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      filas.forEach(function (fila) {
        var nombre = fila.getAttribute('data-nombre') || '';
        fila.classList.toggle('d-none', q !== '' && nombre.indexOf(q) === -1);
      });
    });
  }
  filtrar(document.getElementById('buscarProductor'), Array.prototype.slice.call(document.querySelectorAll('#tblMasiva tbody tr')));
  filtrar(document.getElementById('buscarLitraje'), Array.prototype.slice.call(document.querySelectorAll('#listaLitraje .list-group-item')));

  document.getElementById('inpSemana').addEventListener('change', function () {
    if (this.value) document.getElementById('formIrSemana').submit();
  });

  function recalcNeta() {
    var totCampo = { ventas_leche: 0, cortesias_leche: 0, vales_leche: 0, leche_faltante: 0 };
    var totNeta = 0;
    document.querySelectorAll('#filaRecibida .js-recibida').forEach(function (cell) {
      var fecha = cell.getAttribute('data-fecha');
      var rec = parseFloat(cell.getAttribute('data-litros') || '0') || 0;
      var merma = 0;
      document.querySelectorAll('.js-merma[data-fecha="' + fecha + '"]').forEach(function (inp) {
        var n = parseFloat(inp.value || '0') || 0;
        merma += n;
        var c = inp.getAttribute('data-campo');
        if (totCampo[c] !== undefined) totCampo[c] += n;
      });
      var neta = Math.max(0, rec - merma);
      totNeta += neta;
      var out = document.querySelector('.js-neta[data-fecha="' + fecha + '"]');
      if (out) out.textContent = neta.toFixed(1);
    });
    Object.keys(totCampo).forEach(function (c) {
      var el = document.querySelector('.js-tot-merma[data-campo="' + c + '"]');
      if (el) el.textContent = totCampo[c] > 0 ? totCampo[c].toFixed(1) : '—';
    });
    var totEl = document.querySelector('.js-neta-total');
    if (totEl) totEl.textContent = totNeta.toFixed(1);
  }
  document.querySelectorAll('.js-merma').forEach(function (inp) {
    inp.addEventListener('input', recalcNeta);
  });

  var modal = document.getElementById('modalLitraje');
  if (!modal) return;
  var form = document.getElementById('formLitraje');
  var dia = document.getElementById('diaLitraje');
  var inputs = Array.prototype.slice.call(document.querySelectorAll('#listaLitraje .inp-litros'));
  var huboCambios = false;

  function estado(inp, texto, clase) {
    var el = inp.closest('.list-group-item').querySelector('.js-estado');
    el.className = 'small text-nowrap js-estado ' + (clase || '');
    el.textContent = texto;
  }

  function aplicarDia() {
    inputs.forEach(function (inp) {
      var litros = JSON.parse(inp.getAttribute('data-litros') || '{}');
      inp.value = Object.prototype.hasOwnProperty.call(litros, dia.value) ? litros[dia.value] : '';
      if (!inp.disabled) inp.name = 'litros[' + inp.getAttribute('data-pid') + '][' + dia.value + ']';
      estado(inp, '', '');
    });
  }
  dia.addEventListener('change', aplicarDia);

  function siguiente(actual) {
    var i = inputs.indexOf(actual);
    for (var j = i + 1; j < inputs.length; j++) {
      if (!inputs[j].disabled && !inputs[j].closest('.list-group-item').classList.contains('d-none')) {
        inputs[j].focus();
        inputs[j].select();
        return;
      }
    }
  }

  function guardarFila(inp) {
    var valor = inp.value.trim();
    if (inp.disabled || valor === '' || parseFloat(valor) === 0) { siguiente(inp); return; }

    var datos = new FormData();
    datos.append('_csrf', form.querySelector('input[name="_csrf"]').value);
    datos.append('semana', form.querySelector('input[name="semana"]').value);
    datos.append(inp.name, valor);
    estado(inp, 'Guardando…', 'text-muted');

    fetch(form.action, {
      method: 'POST',
      body: datos,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) {
      var tipo = r.headers.get('Content-Type') || '';
      if (tipo.indexOf('application/json') === -1) throw new Error('La sesión expiró. Recarga la página.');
      return r.json();
    }).then(function (res) {
      if (!res.ok) throw new Error(res.mensajes.join(' '));
      var litros = JSON.parse(inp.getAttribute('data-litros') || '{}');
      litros[dia.value] = parseFloat(valor);
      inp.setAttribute('data-litros', JSON.stringify(litros));
      var accion = res.guardadas.length ? res.guardadas[0].accion : 'sin_cambios';
      estado(inp, accion === 'actualizada' ? '✓ Sobrescrito' : (accion === 'creada' ? '✓ Guardado' : '✓ Sin cambios'), 'text-success');
      if (accion !== 'sin_cambios') huboCambios = true;
      siguiente(inp);
    }).catch(function (err) {
      estado(inp, '✗', 'text-danger');
      inp.closest('.list-group-item').querySelector('.js-estado').title = err.message;
      inp.classList.add('is-invalid');
      window.alert(err.message);
    });
  }

  document.getElementById('listaLitraje').addEventListener('click', function (ev) {
    var btn = ev.target.closest('.js-guardar-fila');
    if (btn) guardarFila(btn.parentNode.querySelector('.inp-litros'));
  });
  inputs.forEach(function (inp) {
    inp.addEventListener('input', function () { inp.classList.remove('is-invalid'); });
    inp.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        guardarFila(inp);
      }
    });
  });
  modal.addEventListener('hidden.bs.modal', function () {
    if (huboCambios) window.location.reload();
  });
})();
</script>
<?php
layout_end();
