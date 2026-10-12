<?php
declare(strict_types=1);

const BOOTSTRAP_CSS = [
    'href'      => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'integrity' => 'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
];
const BOOTSTRAP_ICONS_CSS = [
    'href'      => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'integrity' => 'sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+',
];
const BOOTSTRAP_JS = [
    'src'       => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'integrity' => 'sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz',
];
const LOGO_EMPRESA = 'img/logo.jpg';
const LOGO_SISTEMA = 'img/ADARA LOGO.jpg';

/** URL de un archivo de assets/ con ?v=fecha de modificación, para que el navegador no sirva una versión vieja de caché. */
function asset_v(string $path): string
{
    $file = ADARA_ROOT . '/assets/' . $path;
    $url = asset(implode('/', array_map('rawurlencode', explode('/', $path))));
    return is_file($file) ? $url . '?v=' . filemtime($file) : $url;
}

function layout_start(string $title, string $active = ''): void
{
    $user = current_user();
    $tasa = $user ? TasaCambio::vigente(get_db()) : null;
    $alertClass = ['error' => 'danger', 'success' => 'success', 'info' => 'info', 'warning' => 'warning'];
    $notificaciones = $user ? NotificationHelper::getAlertas($user) : ['cierres' => [], 'atrasados' => [], 'cxc' => []];
    $otrasAlertas = count($notificaciones['cierres']) + count($notificaciones['atrasados']);
    $totalAlertas = $otrasAlertas + count($notificaciones['cxc']);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> – <?= e(APP_NAME) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/adara.css')) ?>">
<script src="<?= e(asset_v('js/contacto.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
</head>
<body class="<?= $user ? 'app' : 'auth' ?>">
<?php if ($user): ?>
<script nonce="<?= e(csp_nonce()) ?>">
if (localStorage.getItem('adara_sidebar_collapsed') === '1') document.body.classList.add('sidebar-collapsed');
</script>
<div class="sidebar" id="appSidebar">
  <div class="sidebar-brand">
    <img src="<?= e(asset_v(LOGO_EMPRESA)) ?>" alt="El Castillo del Queso" class="sidebar-brand-logo">
    <div class="sidebar-brand-text">
      <h6>El Castillo del Queso</h6>
      <small><?= e(APP_NAME) ?> ERP</small>
    </div>
    <button type="button" class="sidebar-toggle" id="sidebarToggle" title="Contraer menú" aria-label="Contraer menú">
      <i class="bi bi-list"></i>
    </button>
  </div>
  <nav class="sidebar-nav">
    <?php foreach (modulos() as $key => $modulo): ?>
      <?php if (has_permiso($user, $key)): ?>
    <a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= e(url($modulo['p'])) ?>" title="<?= e($modulo['label']) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>>
      <span class="nav-icon"><i class="bi <?= e($modulo['icon']) ?>"></i></span>
      <span class="nav-label"><?= e($modulo['label']) ?></span>
    </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="sidebar-footer-brand">
      <img src="<?= e(asset_v(LOGO_SISTEMA)) ?>" alt="ADARA Enterprise Software" class="sidebar-footer-logo">
    </div>
    <div class="sidebar-footer-text">
      <div class="sidebar-user-name"><?= e($user['nombre']) ?></div>
      <?php if ($user['rol'] === 'admin'): ?>
        <div class="sidebar-user-admin"><i class="bi bi-shield-lock-fill"></i> ADMIN — acceso total</div>
      <?php else: ?>
        <div class="sidebar-user-role">
          <i class="bi bi-person-fill"></i> Empleado<br>
          <span><?= $user['permisos'] ? e(implode(', ', array_keys($user['permisos']))) : 'sin módulos asignados' ?></span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<div class="sidebar-overlay" id="sidebarOverlay" hidden></div>
<div class="main-wrap">
  <div class="topbar">
    <button type="button" class="btn-menu-movil" id="btnMenuMovil" aria-label="Abrir menú" aria-controls="appSidebar">
      <i class="bi bi-list"></i>
    </button>
    <h5><?= e($title) ?></h5>
    <div class="d-flex align-items-center gap-3">
      <div class="dropdown">
        <button type="button" class="btn btn-light border rounded-circle position-relative" id="btnNotificaciones"
                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                aria-label="Notificaciones<?= $totalAlertas > 0 ? ': ' . $totalAlertas . ' alertas' : '' ?>">
          <i class="bi bi-bell"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger<?= $totalAlertas > 0 ? '' : ' d-none' ?>" id="contadorAlertas" data-otras="<?= (int) $otrasAlertas ?>">
            <?= $totalAlertas > 99 ? '99+' : $totalAlertas ?>
            <span class="visually-hidden">alertas sin leer</span>
          </span>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow p-0 overflow-hidden" id="menuNotificaciones" aria-labelledby="btnNotificaciones">
          <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-body-tertiary">
            <strong class="small">Notificaciones</strong>
            <span class="badge text-bg-secondary" id="contadorAlertasMenu"><?= $totalAlertas ?></span>
          </div>

          <h6 class="dropdown-header d-flex justify-content-between align-items-center gap-4 text-warning-emphasis bg-warning-subtle">
            <span><i class="bi bi-calendar-event"></i> Próximos Cierres (2 días)</span>
            <span class="badge text-bg-warning"><?= count($notificaciones['cierres']) ?></span>
          </h6>
          <ul class="list-unstyled mb-0" id="listaProximosCierres">
            <?php foreach ($notificaciones['cierres'] as $n): ?>
            <li>
              <a class="dropdown-item d-flex gap-2 py-2 border-bottom" href="<?= e($n['url']) ?>">
                <i class="bi bi-hourglass-split text-warning"></i>
                <span class="text-wrap">
                  <span class="d-block fw-semibold small"><?= e($n['titulo']) ?></span>
                  <span class="d-block text-body-secondary small"><?= e($n['detalle']) ?></span>
                </span>
              </a>
            </li>
            <?php endforeach; ?>
            <?php if ($notificaciones['cierres'] === []): ?>
            <li><span class="dropdown-item-text small text-body-secondary py-2">Sin cierres en los próximos 2 días.</span></li>
            <?php endif; ?>
          </ul>

          <h6 class="dropdown-header d-flex justify-content-between align-items-center gap-4 text-danger-emphasis bg-danger-subtle border-top">
            <span><i class="bi bi-exclamation-octagon"></i> Pagos Atrasados</span>
            <span class="badge text-bg-danger"><?= count($notificaciones['atrasados']) ?></span>
          </h6>
          <ul class="list-unstyled mb-0" id="listaPagosAtrasados">
            <?php foreach ($notificaciones['atrasados'] as $n): ?>
            <li>
              <a class="dropdown-item d-flex gap-2 py-2 border-bottom" href="<?= e($n['url']) ?>">
                <i class="bi bi-cash-coin text-danger"></i>
                <span class="text-wrap">
                  <span class="d-block fw-semibold small"><?= e($n['titulo']) ?></span>
                  <span class="d-block text-body-secondary small"><?= e($n['detalle']) ?></span>
                </span>
              </a>
            </li>
            <?php endforeach; ?>
            <?php if ($notificaciones['atrasados'] === []): ?>
            <li><span class="dropdown-item-text small text-body-secondary py-2">No hay pagos atrasados.</span></li>
            <?php endif; ?>
          </ul>

          <?php if (has_permiso($user, 'facturas')): ?>
          <h6 class="dropdown-header d-flex justify-content-between align-items-center gap-4 text-primary-emphasis bg-primary-subtle border-top">
            <span><i class="bi bi-receipt"></i> Facturas CxC</span>
            <span class="badge text-bg-primary" id="nAlertasCxc"><?= count($notificaciones['cxc']) ?></span>
          </h6>
          <ul class="list-unstyled mb-0 dropdown-alertas-lista" id="listaFacturasCxC">
            <?php foreach ($notificaciones['cxc'] as $n): ?>
            <li>
              <a class="dropdown-item d-flex gap-2 py-2 border-bottom js-alerta-cxc" href="<?= e($n['url']) ?>"
                 data-cliente-id="<?= (int) $n['cliente_id'] ?>" data-factura-id="<?= (int) $n['factura_id'] ?>">
                <i class="bi <?= $n['estado'] === 'vencida' ? 'bi-exclamation-circle text-danger' : 'bi-hourglass-split text-warning' ?>"></i>
                <span class="text-wrap">
                  <span class="d-block fw-semibold small"><?= e($n['titulo']) ?></span>
                  <span class="d-block text-body-secondary small"><?= e($n['detalle']) ?></span>
                </span>
              </a>
            </li>
            <?php endforeach; ?>
            <?php if ($notificaciones['cxc'] === []): ?>
            <li><span class="dropdown-item-text small text-body-secondary py-2">Sin facturas vencidas ni por vencer.</span></li>
            <?php endif; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($tasa !== null): ?>
      <span class="badge rounded-pill text-bg-light border rate-pill" title="Vigente desde <?= e(date('d/m/Y H:i', strtotime($tasa['vigente_desde']))) ?>">
        <i class="bi bi-currency-exchange"></i> <strong>1 USD</strong> = <strong>Bs. <?= e(num($tasa['tasa'])) ?></strong>
      </span>
      <script nonce="<?= e(csp_nonce()) ?>">window.TASA_BCV = <?= json_encode((float) $tasa['tasa'], JSON_THROW_ON_ERROR) ?>;</script>
      <?php else: ?>
      <script nonce="<?= e(csp_nonce()) ?>">window.TASA_BCV = null;</script>
      <?php endif; ?>
      <span class="text-muted small topbar-clock" id="relojTop"><?= e(date('d/m/Y H:i:s')) ?></span>
      <form method="post" action="<?= e(url('logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
      </form>
    </div>
  </div>
  <div class="page-content">
<?php else: ?>
<main class="auth-content">
<?php endif; ?>
    <?php foreach (flash_take() as $msg): ?>
    <div class="alert alert-<?= e($alertClass[$msg['type']] ?? 'info') ?> alert-dismissible fade show" role="alert">
      <?= e($msg['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php endforeach; ?>
<?php
}

function layout_end(): void
{
    $user = current_user();
    ?>
<?php if ($user): ?>
  </div>
</div>
<?php if (RespaldoController::consumirAutoDescarga()): ?>
<form method="post" action="<?= e(url('respaldo_descargar')) ?>" id="formAutoRespaldo" class="d-none">
  <?= csrf_field() ?>
  <input type="hidden" name="origen" value="auto">
</form>
<script nonce="<?= e(csp_nonce()) ?>">
// Respaldo del día: la respuesta es un adjunto, así que el navegador descarga sin abandonar la página.
window.addEventListener('load', function () { document.getElementById('formAutoRespaldo').submit(); });
</script>
<?php endif; ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var sidebar = document.getElementById('appSidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var btnMovil = document.getElementById('btnMenuMovil');
  function esMovil() { return window.matchMedia('(max-width: 768px)').matches; }
  function cerrarMenuMovil() {
    document.body.classList.remove('sidebar-abierto');
    if (sidebar) sidebar.classList.remove('activo');
    if (overlay) overlay.hidden = true;
  }
  function abrirMenuMovil() {
    document.body.classList.add('sidebar-abierto');
    if (sidebar) sidebar.classList.add('activo');
    if (overlay) overlay.hidden = false;
  }
  document.getElementById('sidebarToggle').addEventListener('click', function () {
    if (esMovil()) {
      if (sidebar && sidebar.classList.contains('activo')) cerrarMenuMovil();
      else abrirMenuMovil();
      return;
    }
    var collapsed = document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem('adara_sidebar_collapsed', collapsed ? '1' : '0');
  });
  if (btnMovil) btnMovil.addEventListener('click', function () {
    if (sidebar && sidebar.classList.contains('activo')) cerrarMenuMovil();
    else abrirMenuMovil();
  });
  if (overlay) overlay.addEventListener('click', cerrarMenuMovil);
  if (sidebar) {
    sidebar.querySelectorAll('.nav-link').forEach(function (a) {
      a.addEventListener('click', function () { if (esMovil()) cerrarMenuMovil(); });
    });
  }
  window.addEventListener('resize', function () { if (!esMovil()) cerrarMenuMovil(); });

  var reloj = document.getElementById('relojTop');
  function pad(n) { return String(n).padStart(2, '0'); }
  function tick() {
    var d = new Date();
    reloj.textContent = pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear()
      + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
<?php else: ?>
</main>
<?php endif; ?>
<script src="<?= e(BOOTSTRAP_JS['src']) ?>" integrity="<?= e(BOOTSTRAP_JS['integrity']) ?>" crossorigin="anonymous"></script>
</body>
</html>
<?php
}

function render_forbidden(): void
{
    http_response_code(403);
    layout_start('Acceso restringido');
    ?>
    <div class="card empty">
        <div class="display-6 text-danger"><i class="bi bi-lock-fill"></i></div>
        <h4 class="mt-3 text-danger">Acceso restringido</h4>
        <p class="text-muted">No tienes permiso para acceder a este módulo.<br>Contacta al administrador.</p>
        <a href="<?= e(url('dashboard')) ?>" class="btn btn-outline-primary mt-2">← Ir al inicio</a>
    </div>
    <?php
    layout_end();
}

function render_not_found(): void
{
    http_response_code(404);
    layout_start('Página no encontrada');
    ?>
    <div class="card empty">
        <h4>Página no encontrada</h4>
        <p class="text-muted">Este módulo aún no está disponible.</p>
        <a href="<?= e(url(current_user() ? 'dashboard' : 'login')) ?>" class="btn btn-outline-primary mt-2">← Volver</a>
    </div>
    <?php
    layout_end();
}
