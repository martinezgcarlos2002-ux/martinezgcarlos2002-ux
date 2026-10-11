<?php
declare(strict_types=1);

/**
 * Recursos Humanos: nómina semanal, directorio, días libres y cobranzas a terceros.
 *
 * @var string $tab                         nomina|directorio|prestamos|libres|cobranzas
 * @var string $empresa
 * @var string $rif
 * @var string $tipoNomina
 * @var array{inicio:string, fin:string, tasa:?string, filas:list<array<string, mixed>>, totales:array<string, string>, estado:string} $nomina
 * @var array<int, string> $deudas           empleado_id => deuda interna actual (USD)
 * @var list<array<string, mixed>> $empleados
 * @var array<string, int> $cargos           nombre => cobertura mínima
 * @var int $anio
 * @var int $mes
 * @var list<array<string, mixed>> $libres    vigentes del mes, ordenados por cargo
 * @var array<int, array<string, string>> $marcasMes
 * @var array<int, array<string, true>> $excedidosMes
 * @var list<array{fecha:string, cargo:string, presentes:int, vigentes:int, minimo:int, nivel:string, mensaje:string}> $alertas
 * @var list<array<string, mixed>> $cobranzas
 * @var array{pendiente:string, descontado:string, cobrado_historico:string, por_cobrar:string} $totalesCobro
 * @var array{por_cobrar:string, cobrado:string} $totalesPrestamo
 * @var list<array<string, mixed>> $prestamos
 * @var array<string, string> $tiposPrestamo
 * @var ?string $estadoCobranza
 * @var bool $puedeCrear
 * @var bool $puedeEditar
 * @var bool $esAdmin
 * @var array<string, string> $old
 * @var array<string, string> $errors
 */
$fmtFecha = static function (string $iso): string {
    $dt = $iso !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10)) : false;
    return $dt instanceof DateTimeImmutable ? $dt->format('d/m/Y') : '';
};
$usd = static fn (mixed $n, int $d = 2): string => ((float) $n < 0 ? '-$' : '$') . num(abs((float) $n), $d);
$bs = static fn (mixed $n): string => $n === null ? '—' : 'Bs ' . num($n);
$vacio = static fn (mixed $n): string => (float) $n === 0.0 ? '' : (string) $n;

$meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
$inicialDia = [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];
$primerDia = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
$diasMes = (int) $primerDia->format('t');
$hoyIso = date('Y-m-d');
$mesAnterior = $primerDia->modify('-1 month');
$mesSiguiente = $primerDia->modify('+1 month');
$semanaAnterior = (new DateTimeImmutable($nomina['inicio']))->modify('-7 days')->format('Y-m-d');
$semanaSiguiente = (new DateTimeImmutable($nomina['inicio']))->modify('+7 days')->format('Y-m-d');

$diaCritico = [];
foreach ($alertas as $a) {
    if ($a['nivel'] === 'critica') {
        $diaCritico[(int) substr($a['fecha'], 8, 2)] = true;
    }
}
$diaConMarca = [];
foreach ($marcasMes as $dias) {
    foreach (array_keys($dias) as $f) {
        $diaConMarca[(int) substr($f, 8, 2)] = true;
    }
}
$porCargo = [];
foreach ($libres as $emp) {
    $porCargo[$emp['cargo'] !== '' ? $emp['cargo'] : 'SIN CARGO'][] = $emp;
}

$totalesPrestamo = $totalesPrestamo ?? ['por_cobrar' => '0.00', 'cobrado' => '0.00'];
$tiposPrestamo = $tiposPrestamo ?? PrestamoEmpleado::TIPOS;
$prestamos = $prestamos ?? [];
$formEmpleado = ($old['_form'] ?? '') !== 'cobranza' && isset($old['nombres']);
$formCobranza = ($old['_form'] ?? '') === 'cobranza';
$editarNomina = $puedeEditar && $nomina['estado'] !== 'cerrada' && $nomina['estado'] !== 'pagada';
$estadoBadge = ['borrador' => 'text-bg-secondary', 'cerrada' => 'text-bg-success', 'pagada' => 'text-bg-primary', 'mixta' => 'text-bg-warning'];

$pestanas = [
    'nomina'     => ['Nómina Semanal', 'bi-cash-stack'],
    'directorio' => ['Directorio de Empleados', 'bi-person-vcard'],
    'prestamos'  => ['Préstamos', 'bi-cash'],
    'libres'     => ['Control de Días Libres', 'bi-sun'],
    'cobranzas'  => ['Cobranzas a Terceros', 'bi-shop'],
];

layout_start('Recursos Humanos', 'empleados');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
<ul class="nav nav-tabs rh-tabs mb-0" id="rhTabs" role="tablist">
  <?php foreach ($pestanas as $clave => [$etiqueta, $icono]): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link<?= $tab === $clave ? ' active' : '' ?>" id="tab-<?= e($clave) ?>"
            data-bs-toggle="tab" data-bs-target="#pane-<?= e($clave) ?>" type="button" role="tab"
            aria-controls="pane-<?= e($clave) ?>" aria-selected="<?= $tab === $clave ? 'true' : 'false' ?>">
      <i class="bi <?= e($icono) ?>"></i> <?= e($etiqueta) ?>
    </button>
  </li>
  <?php endforeach; ?>
</ul>
<a class="btn btn-sm btn-outline-dark mb-1" href="<?= e(url('hr_planilla_asistencia')) ?>" target="_blank" rel="noopener">🖨️ Imprimir Planilla de Asistencia</a>
</div>

<div class="tab-content" id="rhPanes">

  <!-- Nómina semanal -->
  <div class="tab-pane fade<?= $tab === 'nomina' ? ' show active' : '' ?>" id="pane-nomina" role="tabpanel" aria-labelledby="tab-nomina" tabindex="0">
    <div class="rh-banner d-flex flex-wrap gap-3 align-items-center justify-content-between mb-3">
      <div class="small">
        <div><span class="rh-lbl">EMPRESA:</span> <?= e($empresa) ?></div>
        <div><span class="rh-lbl">RIF:</span> <?= e($rif) ?></div>
        <div><span class="rh-lbl">TIPO DE NÓMINA:</span> <?= e($tipoNomina) ?></div>
        <div>
          <span class="rh-lbl">PERÍODO:</span> <?= e($fmtFecha($nomina['inicio'])) ?> al <?= e($fmtFecha($nomina['fin'])) ?>
          <span class="badge <?= e($estadoBadge[$nomina['estado']] ?? 'text-bg-secondary') ?> ms-1"><?= e(Nomina::ESTADOS[$nomina['estado']] ?? $nomina['estado']) ?></span>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('empleados', ['tab' => 'nomina', 'semana' => $semanaAnterior])) ?>" aria-label="Semana anterior"><i class="bi bi-chevron-left"></i></a>
        <form class="d-flex gap-2 align-items-center" method="get" action="<?= e(base_path() . 'index.php') ?>">
          <input type="hidden" name="p" value="empleados">
          <input type="hidden" name="tab" value="nomina">
          <label class="rh-lbl small mb-0" for="semana">Semana</label>
          <input type="date" class="form-control form-control-sm w-fecha" id="semana" name="semana" value="<?= e($nomina['inicio']) ?>">
          <button type="submit" class="btn btn-sm btn-outline-primary">Ir</button>
        </form>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('empleados', ['tab' => 'nomina', 'semana' => $semanaSiguiente])) ?>" aria-label="Semana siguiente"><i class="bi bi-chevron-right"></i></a>
        <span class="badge rh-tasa">TASA BCV: <?= $nomina['tasa'] !== null ? e(num($nomina['tasa'])) : '—' ?></span>
        <?php if ($editarNomina): ?>
        <button type="submit" form="formNomina" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Guardar cambios</button>
        <?php endif; ?>
        <?php if ($esAdmin && $editarNomina && $nomina['filas'] !== []): ?>
        <button type="submit" form="formNomina" formaction="<?= e(url('nomina_cerrar')) ?>" class="btn btn-sm btn-success"
                data-confirmar="¿Cerrar la nómina de esta semana? Los montos y los días libres de la semana quedarán congelados.">
          <i class="bi bi-lock"></i> Cerrar nómina
        </button>
        <?php endif; ?>
        <?php if ($esAdmin && in_array($nomina['estado'], ['cerrada', 'mixta'], true)): ?>
        <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modalReabrir">
          <i class="bi bi-unlock"></i> Reabrir nómina
        </button>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('nomina_excel', ['semana' => $nomina['inicio']])) ?>">
          <i class="bi bi-file-earmark-excel"></i> Excel completo
        </a>
        <a class="btn btn-sm btn-outline-danger" href="<?= e(url('nomina_pdf', ['semana' => $nomina['inicio']])) ?>" target="_blank" rel="noopener">
          <i class="bi bi-file-earmark-pdf"></i> PDF nómina
        </a>
        <a class="btn btn-sm btn-notaria" href="<?= e(url('rrhh_notaria', ['semana' => $nomina['inicio']])) ?>" target="_blank" rel="noopener">
          🖨️ Imprimir Notaría
        </a>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-center mb-2">
      <div class="input-group input-group-sm rh-buscar">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input id="buscarNomina" type="search" class="form-control" placeholder="Buscar por nombre, cédula o cargo" autocomplete="off">
      </div>
      <span id="contadorNomina" class="text-muted small"></span>
    </div>

    <form method="post" id="formNomina" action="<?= e(url('nomina_guardar')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="semana" value="<?= e($nomina['inicio']) ?>">
      <div class="card">
        <div class="table-responsive">
          <table class="table table-bordered table-sm tabla-nomina mb-0">
            <thead>
              <tr>
                <th>N</th>
                <th>Fecha<br>Entrada</th>
                <th>Cédula</th>
                <th>Nombres</th>
                <th>Apellidos</th>
                <th>Cargo</th>
                <th>Salario<br>Semanal</th>
                <th>Salario<br>Diario</th>
                <th>Días no<br>trabajados</th>
                <th>Pagos<br>Extras</th>
                <th>Salario<br>+ Extras</th>
                <th>Vales /<br>Mercancía</th>
                <th>Desc.<br>Días</th>
                <th>Cuota<br>Préstamo</th>
                <th>Neto<br>Pagar $</th>
                <th>Monto<br>en Bs</th>
                <th>Cobranzas<br>Terceros</th>
                <th>Resto a<br>pagar Bs</th>
                <th>Resto $</th>
                <th>Pendiente<br>(Deuda)</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($nomina['filas'] as $i => $f):
                $id = (int) $f['id'];
                $bloqueada = !$editarNomina || $f['estado'] !== 'borrador';
                $detalleDias = sprintf('Ausencias: %s · Libres excedidos: %s · Fuera de contrato: %s',
                    num($f['dias_ausencia'], 1), num($f['dias_libres_excedidos'], 1), num($f['dias_fuera_contrato'], 1));
                $buscar = mb_strtolower($f['nombres'] . ' ' . $f['apellidos'] . ' ' . $f['cedula'] . ' ' . $f['cargo']);
            ?>
              <tr data-emp="<?= e($buscar) ?>">
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-center"><?= e($fmtFecha($f['fecha_ingreso'])) ?></td>
                <td><?= e($f['cedula']) ?></td>
                <td><?= e($f['nombres']) ?></td>
                <td><?= e($f['apellidos']) ?></td>
                <td><?= e($f['cargo']) ?></td>
                <td class="text-end"><?= e($usd($f['salario_semanal'])) ?></td>
                <td class="text-end"><?= e($usd($f['salario_diario'], 4)) ?></td>
                <td class="text-center <?= (float) $f['dias_no_trabajados'] > 0 ? 'text-danger fw-semibold' : '' ?>" title="<?= e($detalleDias) ?>">
                  <?= e(num($f['dias_no_trabajados'], 1)) ?>
                </td>
                <td>
                  <input class="form-control form-control-sm rh-cell text-end" type="text" inputmode="decimal"
                         name="nom[<?= $id ?>][extras]" value="<?= e($vacio($f['pagos_extras'])) ?>" aria-label="Pagos extras"<?= $bloqueada ? ' disabled' : '' ?>>
                </td>
                <td class="text-end rh-calc"><?= e($usd($f['salario_mas_extras'])) ?></td>
                <td>
                  <input class="form-control form-control-sm rh-cell text-end" type="text" inputmode="decimal"
                         name="nom[<?= $id ?>][vales]" value="<?= e($vacio($f['vales'])) ?>" aria-label="Vales o mercancía"<?= $bloqueada ? ' disabled' : '' ?>>
                </td>
                <td class="text-end text-nowrap">
                  <?= e($usd($f['desc_dias'])) ?>
                  <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-info lh-1" data-bs-toggle="tooltip" data-bs-placement="top"
                          title="Descuento por: <?= e(num($f['dias_ausencia'], 0)) ?> Faltas, <?= e(num($f['dias_libres_excedidos'], 0)) ?> Libres Extra"
                          aria-label="Descuento por: <?= e(num($f['dias_ausencia'], 0)) ?> Faltas, <?= e(num($f['dias_libres_excedidos'], 0)) ?> Libres Extra">
                    <i class="bi bi-info-circle"></i>
                  </button>
                </td>
                <td>
                  <?php if ((float) $f['deuda_saldo'] > 0 || (float) $f['cuota_deuda'] > 0): ?>
                  <input class="form-control form-control-sm rh-cell text-end" type="text" inputmode="decimal"
                         name="nom[<?= $id ?>][cuota]" value="<?= e($vacio($f['cuota_deuda'])) ?>"
                         title="Deuda antes de esta semana: <?= e($usd($f['deuda_saldo'])) ?>"
                         aria-label="Cuota de préstamo a descontar"<?= $bloqueada || !$esAdmin ? ' disabled' : '' ?>>
                  <?php else: ?>
                  <span class="d-block text-end text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-end rh-calc<?= (float) $f['neto'] < 0 ? ' text-danger' : '' ?>"><?= e($usd($f['neto'])) ?></td>
                <td class="text-end rh-calc"><?= e($bs($f['monto_bs'])) ?></td>
                <td class="text-end<?= (float) $f['cobranzas'] > 0 ? ' text-danger' : ' text-muted' ?>"><?= e($usd($f['cobranzas'])) ?></td>
                <td class="text-end rh-calc"><?= e($bs($f['resto_bs'])) ?></td>
                <td class="text-end rh-calc<?= (float) $f['resto_usd'] < 0 ? ' text-danger' : '' ?>"><?= e($usd($f['resto_usd'])) ?></td>
                <td class="text-end<?= (float) $f['pendiente'] > 0 ? ' text-danger fw-semibold' : ' text-muted' ?>"><?= e($usd($f['pendiente'])) ?></td>
                <td class="text-center">
                  <?php if ($puedeEditar): ?>
                  <button type="button" class="btn btn-sm btn-warning py-0 js-editar-emp" data-bs-toggle="modal" data-bs-target="#modalEmpleado"
                          data-emp="<?= e(json_encode($f + ['modo' => 'editar'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
                    <i class="bi bi-pencil"></i> Editar
                  </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($nomina['filas'] === []): ?>
              <tr><td colspan="21" class="text-center text-muted py-4">Sin empleados con contrato vigente en esta semana.</td></tr>
            <?php else: $t = $nomina['totales']; ?>
              <tr class="rh-totales" id="filaTotales">
                <td colspan="6" class="text-end">TOTALES</td>
                <td class="text-end"><?= e($usd($t['salario_semanal'])) ?></td>
                <td class="text-end"><?= e($usd($t['salario_diario'], 4)) ?></td>
                <td class="text-center"><?= e(num($t['dias_no_trabajados'], 1)) ?></td>
                <td class="text-end"><?= e($usd($t['pagos_extras'])) ?></td>
                <td class="text-end"><?= e($usd($t['salario_mas_extras'])) ?></td>
                <td class="text-end"><?= e($usd($t['vales'])) ?></td>
                <td class="text-end"><?= e($usd($t['desc_dias'])) ?></td>
                <td class="text-end"><?= e($usd($t['cuota_deuda'])) ?></td>
                <td class="text-end"><?= e($usd($t['neto'])) ?></td>
                <td class="text-end"><?= e($nomina['tasa'] !== null ? $bs($t['monto_bs']) : '—') ?></td>
                <td class="text-end"><?= e($usd($t['cobranzas'])) ?></td>
                <td class="text-end"><?= e($nomina['tasa'] !== null ? $bs($t['resto_bs']) : '—') ?></td>
                <td class="text-end"><?= e($usd($t['resto_usd'])) ?></td>
                <td class="text-end"><?= e($usd($t['pendiente'])) ?></td>
                <td></td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer small text-muted">
          Neto = (salario semanal − desc. días) + pagos extras − vales − cuota de préstamo. Pendiente es la deuda del empleado
          con la empresa que queda tras la cuota; solo el administrador fija la cuota. Resto = neto − cobranzas a terceros
          marcadas como descontadas esta semana (pago en caja).
        </div>
      </div>
    </form>

    <?php if ($esAdmin): ?>
    <div class="modal fade" id="modalReabrir" tabindex="-1" aria-labelledby="tituloReabrir" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e(url('nomina_reabrir')) ?>" class="modal-content">
          <?= csrf_field() ?>
          <input type="hidden" name="semana" value="<?= e($nomina['inicio']) ?>">
          <div class="modal-header">
            <h5 class="modal-title" id="tituloReabrir"><i class="bi bi-unlock"></i> Reabrir nómina</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <p class="small text-muted">Semana del <?= e($fmtFecha($nomina['inicio'])) ?> al <?= e($fmtFecha($nomina['fin'])) ?>. La nómina vuelve a estar abierta para editar y el motivo queda en la auditoría.</p>
            <label class="form-label" for="motivoReabrir">Motivo de la reapertura</label>
            <textarea class="form-control" id="motivoReabrir" name="motivo" rows="3" minlength="10" maxlength="300" required></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-warning"><i class="bi bi-unlock"></i> Reabrir</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Directorio -->
  <div class="tab-pane fade<?= $tab === 'directorio' ? ' show active' : '' ?>" id="pane-directorio" role="tabpanel" aria-labelledby="tab-directorio" tabindex="0">
    <div class="row g-3 mb-3">
      <div class="col-sm-6 col-xl-3">
        <div class="card h-100 text-center"><div class="card-body py-3">
          <div class="kpi-num kpi-deuda"><?= e($usd($totalesPrestamo['por_cobrar'])) ?></div>
          <div class="text-muted small">Total préstamos por cobrar</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card h-100 text-center"><div class="card-body py-3">
          <div class="kpi-num kpi-ok"><?= e($usd($totalesPrestamo['cobrado'])) ?></div>
          <div class="text-muted small">Total cobrado</div>
        </div></div>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
      <div class="input-group input-group-sm rh-buscar">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input id="buscarDirectorio" type="search" class="form-control" placeholder="Buscar en el directorio" autocomplete="off">
      </div>
      <span id="contadorDirectorio" class="text-muted small"></span>
      <?php if ($puedeCrear): ?>
      <button type="button" class="btn btn-primary ms-lg-auto" data-bs-toggle="modal" data-bs-target="#modalEmpleado" data-emp='{"modo":"nuevo"}'>
        <i class="bi bi-plus-lg"></i> Nuevo Empleado
      </button>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Cédula</th>
              <th>Nombres</th>
              <th>Apellidos</th>
              <th>Cargo</th>
              <th>Teléfono</th>
              <th class="text-end">Salario semanal</th>
              <th class="text-center">Libres/mes</th>
              <th class="text-end">Deuda interna</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($empleados === []): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Sin empleados. Usa Nuevo Empleado para registrar el primero.</td></tr>
          <?php endif; ?>
          <?php foreach ($empleados as $emp): ?>
            <tr data-emp="<?= e(mb_strtolower($emp['nombres'] . ' ' . $emp['apellidos'] . ' ' . $emp['cedula'] . ' ' . $emp['cargo'])) ?>">
              <td class="text-nowrap"><?= e($emp['cedula']) ?></td>
              <td class="fw-semibold"><?= e($emp['nombres']) ?></td>
              <td><?= e($emp['apellidos']) ?></td>
              <td><?= e($emp['cargo']) ?></td>
              <td><?= e($emp['telefono']) ?></td>
              <td class="text-end"><?= e($usd($emp['salario_semanal'])) ?></td>
              <td class="text-center"><?= (int) $emp['dias_libres_mes'] ?></td>
              <?php $deuda = $deudas[$emp['id']] ?? '0.00'; ?>
              <td class="text-end<?= (float) $deuda > 0 ? ' text-danger fw-semibold' : ' text-muted' ?>"><?= e($usd($deuda)) ?></td>
              <td><span class="badge <?= $emp['activo'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $emp['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
              <td class="text-nowrap">
                <?php if ($puedeEditar): ?>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modalEmpleado"
                        data-emp="<?= e(json_encode($emp + ['modo' => 'editar'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
                  <i class="bi bi-pencil"></i> Editar
                </button>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('rrhh_cv_pdf', ['id' => (int) $emp['id']])) ?>" target="_blank" rel="noopener" title="Currículum">
                  📄 CV
                </a>
                <?php if ($puedeCrear && $emp['activo']): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary btn-prestamo" data-bs-toggle="modal" data-bs-target="#modalPrestamo"
                        data-id="<?= (int) $emp['id'] ?>" data-empleado-id="<?= (int) $emp['id'] ?>" data-empleado="<?= (int) $emp['id'] ?>"
                        data-nombre="<?= e($emp['nombres'] . ' ' . $emp['apellidos']) ?>" data-deuda="<?= e($usd($deuda)) ?>">
                  <i class="bi bi-cash"></i> Préstamo
                </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($puedeCrear): ?>
    <div class="modal fade" id="modalPrestamo" tabindex="-1" aria-labelledby="tituloPrestamo" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e(url('prestamos_nuevo')) ?>" class="modal-content" id="formPrestamo">
          <?= csrf_field() ?>
          <input type="hidden" name="empleado_id" id="prestamo-empleado-id" value="">
          <div class="modal-header">
            <h5 class="modal-title" id="tituloPrestamo"><i class="bi bi-cash"></i> Préstamo a <span id="prestamoNombre"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body row g-3">
            <p class="col-12 small text-muted mb-0">Deuda actual: <strong id="prestamoDeuda"></strong>. La cuota semanal se descuenta del neto al cerrar la nómina.</p>
            <div class="col-md-6">
              <label class="form-label" for="prestamoMonto">Monto total USD</label>
              <input class="form-control" id="prestamoMonto" name="monto_usd" inputmode="decimal" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="prestamoCuota">Cuota semanal a descontar</label>
              <input class="form-control" id="prestamoCuota" name="cuota_semanal" inputmode="decimal" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="prestamoTipo">Tipo</label>
              <select class="form-select" id="prestamoTipo" name="tipo" required>
                <?php foreach ($tiposPrestamo as $k => $lbl): ?>
                <?php if ($k === 'mercancia') continue; ?>
                <option value="<?= e($k) ?>"<?= $k === 'prestamo' ? ' selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="prestamoFecha">Fecha</label>
              <input class="form-control" id="prestamoFecha" name="fecha" type="date" max="<?= e($hoyIso) ?>" value="<?= e($hoyIso) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label" for="prestamoConcepto">Concepto</label>
              <input class="form-control" id="prestamoConcepto" name="concepto" maxlength="120" required value="Préstamo interno">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Registrar préstamo</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Préstamos activos -->
  <div class="tab-pane fade<?= $tab === 'prestamos' ? ' show active' : '' ?>" id="pane-prestamos" role="tabpanel" aria-labelledby="tab-prestamos" tabindex="0">
    <p class="small text-muted">Por cobrar: <strong><?= e($usd($totalesPrestamo['por_cobrar'] ?? '0')) ?></strong>. Un registro de mercancía nace en Ventas; quitarlo aquí solo saca el descuento de la nómina.</p>
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Empleado</th>
              <th>Concepto</th>
              <th>Categoría</th>
              <th class="text-end">Monto USD</th>
              <th class="text-end">Cuota semanal</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($prestamos === []): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No hay préstamos activos.</td></tr>
          <?php endif; ?>
          <?php foreach ($prestamos as $pr): ?>
            <?php $esMercancia = ($pr['tipo'] ?? '') === 'mercancia'; ?>
            <tr>
              <td class="text-nowrap"><?= e($fmtFecha((string) $pr['fecha'])) ?></td>
              <td class="fw-semibold"><?= e($pr['empleado']) ?></td>
              <td><?= e($pr['concepto']) ?></td>
              <td>
                <?php if ($esMercancia): ?>
                  <span class="badge" style="background:#e8590c;color:#fff">Mercancía</span>
                <?php else: ?>
                  <span class="badge text-bg-primary">Préstamo</span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= e($usd($pr['monto_total'])) ?></td>
              <td class="text-end"><?= e($usd($pr['cuota_semanal'])) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($puedeEditar): ?>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modalPrestamoEditar"
                        data-id="<?= (int) $pr['id'] ?>"
                        data-nombre="<?= e((string) $pr['empleado']) ?>"
                        data-monto="<?= e((string) $pr['monto_total']) ?>"
                        data-fecha="<?= e(substr((string) $pr['fecha'], 0, 10)) ?>"
                        data-tipo="<?= e((string) $pr['tipo']) ?>">
                  <i class="bi bi-pencil"></i> Editar
                </button>
                <form method="post" action="<?= e(url('prestamos_eliminar')) ?>" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $pr['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" data-confirmar="<?= e($esMercancia
                      ? 'Este registro viene de una venta (Mercancía). Eliminarlo aquí no borra la factura; solo quita el descuento de la nómina. ¿Continuar?'
                      : '¿Eliminar este préstamo? Dejará de descontarse en el cierre de nómina.') ?>">
                    <i class="bi bi-trash"></i> Eliminar
                  </button>
                </form>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($puedeEditar): ?>
    <div class="modal fade" id="modalPrestamoEditar" tabindex="-1" aria-labelledby="tituloPrestamoEditar" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= e(url('prestamos_editar')) ?>" class="modal-content">
          <?= csrf_field() ?>
          <input type="hidden" name="id" id="editPrestamoId" value="">
          <div class="modal-header">
            <h5 class="modal-title" id="tituloPrestamoEditar"><i class="bi bi-pencil"></i> Editar préstamo de <span id="editPrestamoNombre"></span></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body row g-3">
            <div class="col-12">
              <div id="editPrestamoMercancia" class="alert alert-warning py-2 mb-0 d-none">Este descuento viene de Ventas. Cambiar el monto o la fecha no modifica la factura.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="editPrestamoMonto">Monto total USD</label>
              <input class="form-control" id="editPrestamoMonto" name="monto_usd" inputmode="decimal" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="editPrestamoFecha">Fecha</label>
              <input class="form-control" id="editPrestamoFecha" name="fecha" type="date" max="<?= e($hoyIso) ?>" required>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Días libres -->
  <div class="tab-pane fade<?= $tab === 'libres' ? ' show active' : '' ?>" id="pane-libres" role="tabpanel" aria-labelledby="tab-libres" tabindex="0">
    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
      <form class="d-flex flex-wrap gap-2 align-items-center" method="get" action="<?= e(base_path() . 'index.php') ?>">
        <input type="hidden" name="p" value="empleados">
        <input type="hidden" name="tab" value="libres">
        <label class="form-label mb-0 small" for="mesLibre">Mes</label>
        <select class="form-select form-select-sm w-auto" id="mesLibre" name="mes">
          <?php foreach ($meses as $n => $nombreMes): ?>
            <option value="<?= $n ?>"<?= $n === $mes ? ' selected' : '' ?>><?= e($nombreMes) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="form-label mb-0 small" for="anioLibre">Año</label>
        <input type="number" class="form-control form-control-sm rh-anio" id="anioLibre" name="anio" min="2000" max="2100" value="<?= $anio ?>">
        <button type="submit" class="btn btn-sm btn-outline-primary">Ver mes</button>
      </form>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('empleados', ['tab' => 'libres', 'anio' => $mesAnterior->format('Y'), 'mes' => $mesAnterior->format('n')])) ?>">
          <i class="bi bi-chevron-left"></i> <?= e($meses[(int) $mesAnterior->format('n')]) ?>
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('empleados', ['tab' => 'libres', 'anio' => $mesSiguiente->format('Y'), 'mes' => $mesSiguiente->format('n')])) ?>">
          <?= e($meses[(int) $mesSiguiente->format('n')]) ?> <i class="bi bi-chevron-right"></i>
        </a>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= e($meses[$mes] . ' ' . $anio) ?></span>
        <span class="small text-muted fw-normal">Verde: hay marcas. Rojo: un cargo se queda sin personal.</span>
      </div>
      <div class="card-body p-2">
        <table class="table table-bordered table-sm text-center mb-0 rh-cal">
          <thead>
            <tr><?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $corto): ?><th><?= e($corto) ?></th><?php endforeach; ?></tr>
          </thead>
          <tbody>
            <tr>
            <?php
            $col = (int) $primerDia->format('N');
            for ($v = 1; $v < $col; $v++): ?><td class="rh-cal-vacio"></td><?php endfor;
            for ($dia = 1; $dia <= $diasMes; $dia++):
                $clase = isset($diaCritico[$dia]) ? 'rh-cal-alerta' : (isset($diaConMarca[$dia]) ? 'rh-cal-libre' : '');
                if (sprintf('%04d-%02d-%02d', $anio, $mes, $dia) === $hoyIso) {
                    $clase .= ' rh-cal-hoy';
                }
            ?>
              <td class="<?= e(trim($clase)) ?>"><span class="fw-semibold"><?= $dia ?></span></td>
              <?php if ($col === 7 && $dia < $diasMes): $col = 1; ?></tr><tr><?php else: $col++; endif; ?>
            <?php endfor;
            if ($col !== 1) {
                for (; $col <= 7; $col++): ?><td class="rh-cal-vacio"></td><?php endfor;
            }
            ?>
            </tr>
          </tbody>
        </table>
        <p class="small text-muted mb-0 mt-2 px-1">
          <span class="badge text-bg-success">L</span> Día Libre Asignado
          <span class="mx-1">|</span>
          <span class="badge text-bg-danger">F</span> Falta (Descuenta de Nómina)
          <span class="mx-1">|</span>
          <span class="badge text-bg-warning">L</span> Libre Extra (Supera el cupo mensual, descuenta de Nómina)
        </p>
      </div>
    </div>

    <div id="alertasVivas" class="mb-3" aria-live="polite"></div>

    <div class="row g-3">
      <div class="col-xl-9">
        <form method="post" id="formLibres" action="<?= e(url('dias_libres_guardar')) ?>" data-verificar="<?= e(url('dias_libres_verificar')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="anio" value="<?= $anio ?>">
          <input type="hidden" name="mes" value="<?= $mes ?>">
          <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
              <span>Asignación por cargo</span>
              <span class="small fw-normal text-muted">
                Clic en la celda: <span class="rh-dia rh-dia-libre">L</span> libre ·
                <span class="rh-dia rh-dia-falta">F</span> no trabajado ·
                <span class="rh-dia rh-dia-libre rh-dia-exceso">L</span> libre sobre el cupo (se descuenta)
              </span>
              <?php if ($puedeEditar): ?>
              <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Guardar libres</button>
                <button type="submit" class="btn btn-sm btn-outline-danger" formaction="<?= e(url('dias_libres_limpiar')) ?>" formnovalidate
                        data-confirmar="¿Borrar todos los libres y faltas de <?= e($meses[$mes] . ' ' . $anio) ?>? Las semanas con nómina cerrada no se tocan.">
                  <i class="bi bi-trash"></i> Limpiar tabla
                </button>
                <button type="submit" class="btn btn-sm btn-outline-primary" formaction="<?= e(url('dias_libres_aleatorio')) ?>" formnovalidate
                        data-confirmar="¿Repartir al azar los libres del mes según el cupo de cada empleado? Se reemplazan los libres de semanas abiertas; las faltas se conservan.">
                  <i class="bi bi-dice-5"></i> Asignación Aleatoria
                </button>
              </div>
              <?php endif; ?>
            </div>
            <div class="table-responsive">
              <table class="table table-bordered table-sm tabla-libres mb-0">
                <thead>
                  <tr>
                    <th class="col-emp">Empleado</th>
                    <?php for ($dia = 1; $dia <= $diasMes; $dia++):
                        $f = $primerDia->modify('+' . ($dia - 1) . ' days');
                        $clase = (isset($diaCritico[$dia]) ? 'dia-alerta ' : '') . ($f->format('Y-m-d') === $hoyIso ? 'dia-hoy' : '');
                    ?>
                      <th class="<?= e(trim($clase)) ?>"><span class="d-block"><?= e($inicialDia[(int) $f->format('N')]) ?></span><?= $dia ?></th>
                    <?php endfor; ?>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($porCargo as $cargo => $grupo): ?>
                  <tr class="rh-grupo">
                    <th colspan="<?= $diasMes + 1 ?>"><?= e($cargo) ?> (<?= count($grupo) ?>) · mínimo <?= (int) ($cargos[$cargo] ?? 1) ?></th>
                  </tr>
                  <?php foreach ($grupo as $emp):
                      $marcas = $marcasMes[$emp['id']] ?? [];
                      $usados = count(array_filter($marcas, static fn (string $t): bool => $t === 'libre'));
                  ?>
                  <tr data-empleado="<?= (int) $emp['id'] ?>">
                    <th class="col-emp text-start fw-normal">
                      <input type="hidden" name="emps[]" value="<?= (int) $emp['id'] ?>">
                      <span class="d-block fw-semibold"><?= e($emp['nombres'] . ' ' . $emp['apellidos']) ?></span>
                      <span class="small <?= $usados > $emp['dias_libres_mes'] ? 'text-danger' : 'text-muted' ?>"><?= $usados ?>/<?= (int) $emp['dias_libres_mes'] ?> libres</span>
                    </th>
                    <?php for ($dia = 1; $dia <= $diasMes; $dia++):
                        $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
                        $tipo = $marcas[$fecha] ?? '';
                        $fuera = !DiaLibre::enContrato($emp, $fecha);
                        $exceso = isset($excedidosMes[$emp['id']][$fecha]);
                        $clase = (isset($diaCritico[$dia]) ? 'dia-alerta ' : '') . ($fecha === $hoyIso ? 'dia-hoy' : '');
                    ?>
                      <td class="text-center p-0 <?= e(trim($clase)) ?>">
                        <?php if ($fuera): ?>
                          <span class="rh-dia rh-dia-fuera" title="Fuera de contrato">·</span>
                        <?php else: ?>
                          <button type="button" class="rh-dia js-dia<?= $tipo === 'libre' ? ' rh-dia-libre' : ($tipo === 'no_trabajado' ? ' rh-dia-falta' : '') ?><?= $exceso ? ' rh-dia-exceso' : '' ?>"
                                  data-fecha="<?= e($fecha) ?>" data-tipo="<?= e($tipo) ?>"<?= $puedeEditar ? '' : ' disabled' ?>
                                  aria-label="<?= e($emp['nombres']) ?> día <?= $dia ?>"><?= $tipo === 'libre' ? 'L' : ($tipo === 'no_trabajado' ? 'F' : '') ?></button>
                          <input type="hidden" value="<?= e($tipo) ?>"<?= $tipo !== '' ? ' name="dias[' . (int) $emp['id'] . '][' . $dia . ']"' : '' ?> data-name="dias[<?= (int) $emp['id'] ?>][<?= $dia ?>]">
                        <?php endif; ?>
                      </td>
                    <?php endfor; ?>
                  </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($libres === []): ?>
                  <tr><td class="text-center text-muted py-4">No hay empleados con contrato vigente en este mes.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </form>
      </div>
      <div class="col-xl-3">
        <div class="card border-danger">
          <div class="card-header bg-danger text-white">
            <i class="bi bi-exclamation-octagon"></i> Días con el personal comprometido
          </div>
          <ul class="list-group list-group-flush">
            <?php if ($alertas === []): ?>
              <li class="list-group-item small text-muted">Todos los cargos mantienen su cobertura mínima este mes.</li>
            <?php endif; ?>
            <?php foreach ($alertas as $a): ?>
              <li class="list-group-item small <?= $a['nivel'] === 'critica' ? 'list-group-item-danger' : 'list-group-item-warning' ?>"><?= e($a['mensaje']) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Cobranzas a terceros -->
  <div class="tab-pane fade<?= $tab === 'cobranzas' ? ' show active' : '' ?>" id="pane-cobranzas" role="tabpanel" aria-labelledby="tab-cobranzas" tabindex="0">
    <div class="row g-3 mb-3">
      <div class="col-sm-6 col-xl-3">
        <div class="card h-100 text-center"><div class="card-body py-3">
          <div class="kpi-num kpi-deuda"><?= e($usd($totalesCobro['por_cobrar'] ?? $totalesCobro['pendiente'])) ?></div>
          <div class="text-muted small">Total por cobrar ($)</div>
        </div></div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card h-100 text-center"><div class="card-body py-3">
          <div class="kpi-num kpi-ok"><?= e($usd($totalesCobro['cobrado_historico'] ?? $totalesCobro['descontado'])) ?></div>
          <div class="text-muted small">Total cobrado histórico ($)</div>
        </div></div>
      </div>
      <div class="col-xl-6 d-flex align-items-center">
        <p class="text-muted small mb-0">Deudas de empleados con bodegas externas u otros terceros. Se registran aparte y no se restan del neto de la nómina oficial.</p>
      </div>
    </div>

    <?php if ($puedeCrear): ?>
    <form method="post" action="<?= e(url('cobranzas_nueva')) ?>" class="card mb-3" id="formCobranza" data-tasa="<?= e($nomina['tasa'] ?? '0') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="moneda" id="cobMoneda" value="<?= e($formCobranza && ($old['moneda'] ?? '') === 'VES' ? 'VES' : 'USD') ?>">
      <div class="card-header">Nueva cobranza</div>
      <div class="card-body row g-2 align-items-end">
        <div class="col-md-4 col-xl-3">
          <label class="form-label small" for="cobEmpleado">Empleado</label>
          <select class="form-select form-select-sm <?= $formCobranza ? field_class($errors, 'empleado_id') : '' ?>" id="cobEmpleado" name="empleado_id" required>
            <option value="">Selecciona</option>
            <?php foreach ($empleados as $emp): if (!$emp['activo']) continue; ?>
              <option value="<?= (int) $emp['id'] ?>"<?= $formCobranza && ($old['empleado_id'] ?? '') === (string) $emp['id'] ? ' selected' : '' ?>><?= e($emp['nombres'] . ' ' . $emp['apellidos']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= $formCobranza ? field_error($errors, 'empleado_id') : '' ?>
        </div>
        <div class="col-md-2">
          <label class="form-label small" for="cobAcreedor">Tercero</label>
          <input class="form-control form-control-sm <?= $formCobranza ? field_class($errors, 'acreedor') : '' ?>" id="cobAcreedor" name="acreedor" maxlength="80" required
                 value="<?= e($formCobranza ? ($old['acreedor'] ?? '') : 'Bodega') ?>">
          <?= $formCobranza ? field_error($errors, 'acreedor') : '' ?>
        </div>
        <div class="col-md-4 col-xl-2">
          <label class="form-label small" for="cobConcepto">Concepto de deuda</label>
          <input class="form-control form-control-sm <?= $formCobranza ? field_class($errors, 'concepto') : '' ?>" id="cobConcepto" name="concepto" maxlength="120" required
                 value="<?= e($formCobranza ? ($old['concepto'] ?? '') : '') ?>">
          <?= $formCobranza ? field_error($errors, 'concepto') : '' ?>
        </div>
        <div class="col-md-2">
          <label class="form-label small" for="cobMonto">Monto USD</label>
          <input class="form-control form-control-sm <?= $formCobranza ? field_class($errors, 'monto_usd') : '' ?>" id="cobMonto" name="monto_usd" inputmode="decimal" required
                 value="<?= e($formCobranza ? ($old['monto_usd'] ?? '') : '') ?>">
          <?= $formCobranza ? field_error($errors, 'monto_usd') : '' ?>
        </div>
        <div class="col-md-2">
          <label class="form-label small" for="cobMontoBs">Monto Bs</label>
          <input class="form-control form-control-sm <?= $formCobranza ? field_class($errors, 'monto_bs') : '' ?>" id="cobMontoBs" name="monto_bs" inputmode="decimal" autocomplete="off"
                 value="<?= e($formCobranza ? ($old['monto_bs'] ?? '') : '') ?>"<?= ($nomina['tasa'] ?? null) === null ? ' disabled' : '' ?>>
          <?= $formCobranza ? field_error($errors, 'monto_bs') : '' ?>
        </div>
        <div class="col-md-2">
          <label class="form-label small" for="cobFecha">Fecha</label>
          <input class="form-control form-control-sm <?= $formCobranza ? field_class($errors, 'fecha') : '' ?>" id="cobFecha" name="fecha" type="date" max="<?= e($hoyIso) ?>" required
                 value="<?= e($formCobranza ? ($old['fecha'] ?? $hoyIso) : $hoyIso) ?>">
          <?= $formCobranza ? field_error($errors, 'fecha') : '' ?>
        </div>
        <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="cobDescontar" name="descontar" value="1"<?= !$formCobranza || ($old['descontar'] ?? '') === '1' ? ' checked' : '' ?>>
            <label class="form-check-label small" for="cobDescontar">Descontar en el pago de esta semana</label>
          </div>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Registrar</button>
        </div>
      </div>
    </form>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mb-2">
      <?php foreach (['' => 'Todas'] + CobranzaTercero::ESTADOS as $clave => $etiqueta): ?>
        <a class="btn btn-sm <?= ($estadoCobranza ?? '') === $clave ? 'btn-secondary' : 'btn-outline-secondary' ?>"
           href="<?= e(url('empleados', ['tab' => 'cobranzas'] + ($clave !== '' ? ['estado' => $clave] : []))) ?>"><?= e($etiqueta) ?></a>
      <?php endforeach; ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('empleados', ['tab' => 'cobranzas'])) ?>">
        <i class="bi bi-x-lg"></i> Limpiar filtro
      </a>
      <a class="btn btn-sm btn-outline-danger" href="<?= e(url('rrhh_cobranzas_pdf')) ?>" target="_blank" rel="noopener">
        <i class="bi bi-file-earmark-pdf"></i> Reporte PDF
      </a>
    </div>
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Empleado</th>
              <th>Tercero</th>
              <th>Concepto de deuda</th>
              <th class="text-end">Monto USD</th>
              <th>Estado</th>
              <th>Semana de descuento</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($cobranzas === []): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">Sin cobranzas registradas.</td></tr>
          <?php endif; ?>
          <?php foreach ($cobranzas as $c):
              $badge = ['pendiente' => 'text-bg-warning', 'descontado' => 'text-bg-success', 'anulado' => 'text-bg-secondary'][$c['estado']];
          ?>
            <tr>
              <td class="text-nowrap"><?= e($fmtFecha((string) $c['fecha'])) ?></td>
              <td class="fw-semibold"><?= e($c['empleado']) ?></td>
              <td><?= e($c['acreedor']) ?></td>
              <td><?= e($c['concepto']) ?></td>
              <td class="text-end">
                <?= e($usd($c['monto_usd'])) ?>
                <?php if ($c['moneda_origen'] === 'VES'): ?>
                  <span class="d-block small text-muted">Bs <?= e(num($c['monto_origen'])) ?> a <?= e(num($c['tasa_bs_por_usd'])) ?></span>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= e($badge) ?>"><?= e(CobranzaTercero::ESTADOS[$c['estado']]) ?></span></td>
              <td class="text-nowrap small"><?= $c['semana_descuento'] !== null ? e($fmtFecha((string) $c['semana_descuento'])) : '<span class="text-muted">—</span>' ?></td>
              <td class="text-nowrap">
                <?php if ($puedeEditar && $c['estado'] !== 'anulado'): ?>
                <form method="post" action="<?= e(url('cobranzas_estado')) ?>" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <?php if ($c['estado'] === 'pendiente'): ?>
                    <button type="submit" name="estado" value="descontado" class="btn btn-sm btn-outline-success" title="Se retiene del pago en caja de la semana en curso"><i class="bi bi-check2"></i> Descontar esta semana</button>
                  <?php else: ?>
                    <button type="submit" name="estado" value="pendiente" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Pendiente</button>
                  <?php endif; ?>
                  <button type="submit" name="estado" value="anulado" class="btn btn-sm btn-outline-danger" data-confirmar="¿Anular esta cobranza? No se puede revertir."><i class="bi bi-x-lg"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalEmpleado" tabindex="-1" aria-labelledby="tituloEmpleado" aria-hidden="true"
     data-action-nuevo="<?= e(url('empleados_nuevo')) ?>" data-action-editar="<?= e(url('empleados_editar')) ?>"
     data-reabrir="<?= $formEmpleado ? e(json_encode($old + ['modo' => ($old['id'] ?? '') !== '' ? 'editar' : 'nuevo'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) : '' ?>">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="formEmpleado" action="<?= e(url('empleados_nuevo')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="empId" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloEmpleado">Nuevo Empleado</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <?php $err = $formEmpleado ? $errors : []; ?>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="empCedula">Cédula</label>
              <input class="form-control <?= field_class($err, 'cedula') ?>" id="empCedula" name="cedula" required maxlength="12" placeholder="V-12345678">
              <?= field_error($err, 'cedula') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="empNombres">Nombres</label>
              <input class="form-control <?= field_class($err, 'nombres') ?>" id="empNombres" name="nombres" required maxlength="60">
              <?= field_error($err, 'nombres') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="empApellidos">Apellidos</label>
              <input class="form-control <?= field_class($err, 'apellidos') ?>" id="empApellidos" name="apellidos" required maxlength="60">
              <?= field_error($err, 'apellidos') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="empCargo">Cargo / Puesto</label>
              <div class="input-group">
                <select class="form-select <?= field_class($err, 'cargo') ?>" id="empCargo" name="cargo" required>
                  <option value="">Selecciona</option>
                  <?php foreach (array_keys($cargos) as $cargo): ?>
                    <option value="<?= e($cargo) ?>"><?= e($cargo) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-primary" id="btnNuevoCargo" title="Agregar cargo">
                  <i class="bi bi-plus-lg"></i>
                </button>
              </div>
              <div class="input-group input-group-sm mt-2 d-none" id="cajaNuevoCargo" data-url="<?= e(url('cargos_nuevo')) ?>">
                <input type="text" class="form-control" id="nuevoCargoNombre" maxlength="60" placeholder="Nombre del cargo">
                <button type="button" class="btn btn-primary" id="confirmarCargo">Agregar</button>
              </div>
              <div class="invalid-feedback d-block small" id="errorNuevoCargo"></div>
              <?= field_error($err, 'cargo') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="empTelefono">Teléfono</label>
              <input class="form-control <?= field_class($err, 'telefono') ?>" id="empTelefono" name="telefono" maxlength="20">
              <?= field_error($err, 'telefono') ?>
            </div>
            <div class="col-md-2">
              <label class="form-label" for="empSalario">Salario semanal (USD)</label>
              <input class="form-control <?= field_class($err, 'salario_semanal_usd') ?>" id="empSalario" name="salario_semanal_usd" inputmode="decimal" value="0">
              <?= field_error($err, 'salario_semanal_usd') ?>
            </div>
            <div class="col-md-2">
              <label class="form-label" for="empLibres">Libres/mes</label>
              <input class="form-control <?= field_class($err, 'dias_libres_mes') ?>" id="empLibres" name="dias_libres_mes" type="number" min="0" max="31" value="2">
              <?= field_error($err, 'dias_libres_mes') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="empProfesion">Profesión</label>
              <input class="form-control <?= field_class($err, 'profesion') ?>" id="empProfesion" name="profesion" maxlength="80">
              <?= field_error($err, 'profesion') ?>
            </div>
            <div class="col-12">
              <label class="form-label" for="empDireccion">Dirección</label>
              <input class="form-control <?= field_class($err, 'direccion') ?>" id="empDireccion" name="direccion" maxlength="160">
              <?= field_error($err, 'direccion') ?>
            </div>
            <div class="col-12">
              <label class="form-label" for="empDomicilio">Dirección de domicilio</label>
              <input class="form-control <?= field_class($err, 'direccion_domicilio') ?>" id="empDomicilio" name="direccion_domicilio" maxlength="400">
              <?= field_error($err, 'direccion_domicilio') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="empFotoPerfil">Foto del empleado (JPG/PNG)</label>
              <input class="form-control <?= field_class($err, 'foto_perfil') ?>" id="empFotoPerfil" name="foto_perfil" type="file" accept="image/jpeg,image/png">
              <?= field_error($err, 'foto_perfil') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="empFotoDoc">Foto de cédula / identidad (JPG/PNG)</label>
              <input class="form-control <?= field_class($err, 'foto_documento') ?>" id="empFotoDoc" name="foto_documento" type="file" accept="image/jpeg,image/png">
              <?= field_error($err, 'foto_documento') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="empIngreso">Fecha ingreso</label>
              <input class="form-control <?= field_class($err, 'fecha_ingreso') ?>" id="empIngreso" name="fecha_ingreso" type="date">
              <?= field_error($err, 'fecha_ingreso') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="empSalida">Fecha salida</label>
              <input class="form-control <?= field_class($err, 'fecha_salida') ?>" id="empSalida" name="fecha_salida" type="date">
              <?= field_error($err, 'fecha_salida') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="empNacimiento">Fecha nacimiento</label>
              <input class="form-control <?= field_class($err, 'fecha_nacimiento') ?>" id="empNacimiento" name="fecha_nacimiento" type="date">
              <?= field_error($err, 'fecha_nacimiento') ?>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="empPenal" name="expediente_penal" value="1">
                <label class="form-check-label" for="empPenal">Expediente penal</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="empEstado">Estado</label>
              <select class="form-select" id="empEstado" name="activo">
                <option value="1">Activo</option>
                <option value="0">Inactivo</option>
              </select>
            </div>
            <div class="col-12">
              <hr class="my-1">
              <h6 class="mb-2">Datos Bancarios para Pago</h6>
              <div class="d-flex flex-wrap gap-4 mb-2">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="tipo_cuenta" id="empCuentaPropia" value="propia" checked>
                  <label class="form-check-label" for="empCuentaPropia">Cuenta Propia</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="tipo_cuenta" id="empCuentaTercero" value="tercero">
                  <label class="form-check-label" for="empCuentaTercero">Cuenta de Tercero</label>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="empBanco">Banco</label>
              <input class="form-control <?= field_class($err, 'banco') ?>" id="empBanco" name="banco" maxlength="80" placeholder="Banco destinatario">
              <?= field_error($err, 'banco') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="empDocPago">Cédula / RIF</label>
              <input class="form-control <?= field_class($err, 'titular_cedula') ?>" id="empDocPago" name="titular_cedula" maxlength="14" placeholder="V-12345678">
              <?= field_error($err, 'titular_cedula') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="empTelPago">Teléfono (Pago Móvil)</label>
              <input class="form-control <?= field_class($err, 'titular_telefono') ?>" id="empTelPago" name="titular_telefono" maxlength="20" placeholder="0414-0000000">
              <?= field_error($err, 'titular_telefono') ?>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="empNumCuenta">Número de Cuenta</label>
              <input class="form-control <?= field_class($err, 'numero_cuenta') ?>" id="empNumCuenta" name="numero_cuenta" maxlength="24" inputmode="numeric" placeholder="20 dígitos">
              <?= field_error($err, 'numero_cuenta') ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="<?= e(asset_v('js/rrhh.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<?php
layout_end();
