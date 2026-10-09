<?php
declare(strict_types=1);

/**
 * Auditoría del Sistema (solo lectura: la bitácora es inmutable).
 * @var array{q:string, modulo:?string, desde:?string, hasta:?string} $filtros
 * @var list<array> $registros
 * @var int $total
 * @var int $totalGeneral
 * @var int $pagina
 * @var int $paginas
 * @var int $porPagina
 * @var list<string> $modulos
 * @var array{usuario:string, fecha:string, hora:string}|null $ultimoRespaldo
 */
$hayFiltro = $filtros['q'] !== '' || $filtros['modulo'] !== null || $filtros['desde'] !== null || $filtros['hasta'] !== null;
$query = array_filter($filtros, static fn ($v) => $v !== null && $v !== '');
$enlacePagina = static fn (int $n): string => url('auditoria', $query + ['pagina' => $n]);

layout_start('Auditoría del Sistema', 'auditoria');
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-2">
  <form class="d-flex flex-wrap align-items-center gap-2" method="get" action="<?= e(base_path() . 'index.php') ?>">
    <input type="hidden" name="p" value="auditoria">
    <input type="search" name="q" class="form-control form-control-sm w-filtro" placeholder="Buscar acción, usuario o dato" maxlength="80" value="<?= e($filtros['q']) ?>" aria-label="Buscar en auditoría">
    <select name="modulo" class="form-select form-select-sm w-tipo" aria-label="Módulo">
      <option value="">Todos los módulos</option>
      <?php foreach ($modulos as $m): ?>
        <option value="<?= e($m) ?>" <?= $filtros['modulo'] === $m ? 'selected' : '' ?>><?= e(modulos()[$m]['label'] ?? $m) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="desde" class="form-control form-control-sm w-fecha" value="<?= e($filtros['desde'] ?? '') ?>" aria-label="Desde">
    <input type="date" name="hasta" class="form-control form-control-sm w-fecha" value="<?= e($filtros['hasta'] ?? '') ?>" aria-label="Hasta">
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel"></i> Filtrar</button>
    <?php if ($hayFiltro): ?><a class="btn btn-sm btn-link" href="<?= e(url('auditoria')) ?>">Limpiar</a><?php endif; ?>
  </form>
  <span class="badge rounded-pill text-bg-dark ms-lg-auto"><?= e(num(count($registros), 0)) ?> / <?= e(num($hayFiltro ? $total : $totalGeneral, 0)) ?> registros</span>
  <span class="badge rounded-pill text-bg-light border" title="La bitácora no se puede editar ni borrar"><i class="bi bi-shield-lock"></i> Inmutable</span>
</div>

<?php if ($ultimoRespaldo !== null): ?>
  <div class="alert alert-info py-2 d-flex align-items-center gap-2">
    <i class="bi bi-database-check"></i>
    <span>Último respaldo descargado por: <strong><?= e($ultimoRespaldo['usuario']) ?></strong> — <?= e($ultimoRespaldo['fecha']) ?> <?= e($ultimoRespaldo['hora']) ?></span>
  </div>
<?php else: ?>
  <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> Aún no se ha descargado ningún respaldo.</div>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead>
        <tr class="small text-uppercase text-muted">
          <th>Fecha</th>
          <th>Módulo</th>
          <th>Acción</th>
          <th>Método</th>
          <th>Ruta</th>
          <th>IP</th>
          <th>Usuario</th>
          <th>Detalle</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($registros === []): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Sin registros para el filtro.</td></tr>
      <?php endif; ?>
      <?php foreach ($registros as $r):
          $detalle = $r['detalle'] === null ? null : json_decode((string) $r['detalle'], true);
          $metodoCls = match ($r['metodo']) { 'POST' => 'text-bg-success', 'CLI' => 'text-bg-dark', default => 'text-bg-secondary' };
          $alerta = str_contains($r['accion'], 'fallid') || str_contains($r['accion'], 'denegado') || str_contains($r['accion'], 'csrf');
      ?>
        <tr class="<?= $alerta ? 'table-warning' : '' ?>">
          <td class="small text-nowrap"><?= e(substr((string) $r['creado_en'], 0, 19)) ?></td>
          <td><span class="badge text-bg-primary"><?= e($r['modulo']) ?></span></td>
          <td class="small"><?= e($r['accion']) ?></td>
          <td><span class="badge <?= $metodoCls ?>"><?= e($r['metodo']) ?></span></td>
          <td class="small text-muted"><?= e($r['ruta'] !== '' ? '?p=' . $r['ruta'] : '—') ?></td>
          <td class="small"><?= e($r['ip']) ?></td>
          <td class="small"><?= e($r['usuario'] ?? '—') ?></td>
          <td>
            <button type="button" class="btn btn-sm btn-outline-warning"
                    data-bs-toggle="modal" data-bs-target="#modalDetalle"
                    data-usuario="<?= e(trim(($r['usuario'] ?? 'Sin sesión') . ' ' . ($r['usuario_email'] ?? '') . ($r['usuario_rol'] ? ' (' . $r['usuario_rol'] . ')' : ''))) ?>"
                    data-fecha="<?= e(substr((string) $r['creado_en'], 0, 19)) ?>"
                    data-accion="<?= e($r['accion'] . ' · ' . $r['modulo']) ?>"
                    data-desde="<?= e($r['ip'] . ' · ' . $r['metodo']) ?>"
                    data-ruta="<?= e($r['ruta'] !== '' ? '?p=' . $r['ruta'] : '—') ?>"
                    data-entidad="<?= e($r['entidad'] ? $r['entidad'] . ($r['entidad_id'] ? ' #' . $r['entidad_id'] : '') : '') ?>"
                    data-detalle="<?= e($detalle === null ? '{}' : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
              Ver qué cambió
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($paginas > 1): ?>
<nav class="mt-3" aria-label="Paginación de auditoría">
  <ul class="pagination pagination-sm justify-content-center flex-wrap">
    <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($enlacePagina(max(1, $pagina - 1))) ?>">‹ Anterior</a></li>
    <?php foreach (array_unique([1, max(1, $pagina - 2), max(1, $pagina - 1), $pagina, min($paginas, $pagina + 1), min($paginas, $pagina + 2), $paginas]) as $n): ?>
      <li class="page-item <?= $n === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= e($enlacePagina($n)) ?>"><?= $n ?></a></li>
    <?php endforeach; ?>
    <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($enlacePagina(min($paginas, $pagina + 1))) ?>">Siguiente ›</a></li>
  </ul>
  <p class="text-center small text-muted">Página <?= $pagina ?> de <?= $paginas ?> · <?= $porPagina ?> por página</p>
</nav>
<?php endif; ?>

<!-- ============================================================
     MODAL: Detalle del movimiento
     ============================================================ -->
<div class="modal fade" id="modalDetalle" tabindex="-1" aria-labelledby="tituloDetalle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="tituloDetalle">Detalle del movimiento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 small mb-3 bg-light border rounded p-2 mx-0">
          <div class="col-md-7"><strong>Quién:</strong> <span id="detUsuario"></span></div>
          <div class="col-md-5"><strong>Cuándo:</strong> <span id="detFecha"></span></div>
          <div class="col-md-7"><strong>Qué hizo:</strong> <span id="detAccion"></span></div>
          <div class="col-md-5"><strong>Desde:</strong> <span id="detDesde"></span></div>
          <div class="col-md-7"><strong>Ruta:</strong> <span id="detRuta"></span></div>
          <div class="col-md-5"><strong>Registro:</strong> <span id="detEntidad"></span></div>
        </div>
        <div class="alert alert-warning py-2 small" id="detPorque"></div>
        <div class="fw-semibold mb-2">Datos que se colocaron o cambiaron</div>
        <table class="table table-sm mb-2"><tbody id="detDatos"></tbody></table>
        <details class="small">
          <summary class="text-muted">Ver JSON original</summary>
          <pre class="bg-light border rounded p-2 mt-2 mb-0 text-break" id="detJson"></pre>
        </details>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('modalDetalle').addEventListener('show.bs.modal', function (ev) {
  var btn = ev.relatedTarget;
  if (!btn) return;
  var attr = function (n) { return btn.getAttribute('data-' + n) || ''; };
  document.getElementById('detUsuario').textContent = attr('usuario');
  document.getElementById('detFecha').textContent = attr('fecha');
  document.getElementById('detAccion').textContent = attr('accion');
  document.getElementById('detDesde').textContent = attr('desde');
  document.getElementById('detRuta').textContent = attr('ruta');
  document.getElementById('detEntidad').textContent = attr('entidad') || '—';

  var datos = {};
  try { datos = JSON.parse(attr('detalle') || '{}') || {}; } catch (e) { datos = {}; }
  document.getElementById('detJson').textContent = JSON.stringify(datos, null, 2);

  var motivo = datos.motivo || datos.razon || '';
  document.getElementById('detPorque').textContent = 'Por qué: ' + (motivo ? String(motivo) : 'no se indicó un motivo específico en este formulario.');

  var cuerpo = document.getElementById('detDatos');
  cuerpo.replaceChildren();
  var claves = Object.keys(datos);
  if (claves.length === 0) {
    var tr = document.createElement('tr');
    var td = document.createElement('td');
    td.className = 'text-muted';
    td.textContent = 'Sin datos adicionales.';
    tr.appendChild(td);
    cuerpo.appendChild(tr);
    return;
  }
  claves.forEach(function (k) {
    var v = datos[k];
    var texto = v !== null && typeof v === 'object'
      ? (Object.prototype.hasOwnProperty.call(v, 'antes') ? String(v.antes) + ' → ' + String(v.despues) : JSON.stringify(v))
      : String(v);
    var tr = document.createElement('tr');
    var th = document.createElement('th');
    th.className = 'text-muted fw-normal text-nowrap';
    th.textContent = k.replace(/_/g, ' ');
    var td = document.createElement('td');
    td.className = 'text-break';
    td.textContent = texto;
    tr.append(th, td);
    cuerpo.appendChild(tr);
  });
});
</script>
<?php
layout_end();
