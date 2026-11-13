<?php
declare(strict_types=1);

require_once __DIR__ . '/layout_master.php';

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

/** La apertura y el cierre tienen que ser el mismo cascarón, aunque la sesión cambie a mitad de la petición. */
function layout_usa_maestro(bool $fijar = false): bool
{
    static $activo = false;
    if ($fijar) {
        $activo = true;
    }
    return $activo;
}

function layout_start(string $title, string $active = ''): void
{
    if (sesion_es_panel_maestro()) {
        layout_usa_maestro(true);
        layout_master_start($title, $active);
        return;
    }
    $user = current_user();
    $tasa = $user ? TasaCambio::vigente(get_db()) : null;
    $alertClass = ['error' => 'danger', 'success' => 'success', 'info' => 'info', 'warning' => 'warning'];
    $notificaciones = $user ? NotificationHelper::getAlertas($user) : ['cierres' => [], 'atrasados' => [], 'cxc' => []];
    $otrasAlertas = count($notificaciones['cierres']) + count($notificaciones['atrasados']);
    $totalAlertas = $otrasAlertas + count($notificaciones['cxc']);
    $avisosSaas = [];
    $empresaAvisos = $_SESSION['tenant_id'] ?? null;
    if ($user && Database::multiempresa() && is_int($empresaAvisos)) {
        try {
            $avisosSaas = (new SaasComercial(Database::master()))->avisosPendientes($empresaAvisos);
        } catch (Throwable) {
            $avisosSaas = [];
        }
    }
    $avisosSaasTotal = count($avisosSaas);
    $alertasVisibles = $totalAlertas + $avisosSaasTotal;
    $movil = $user !== null && vista_movil();
    $clasesBody = ($user ? 'app' : 'auth')
        . (is_int($_SESSION['impersonado_por'] ?? null) ? ' modo-soporte' : '')
        . ($movil ? ' vista-movil' : '');
    ?>
<!DOCTYPE html>
<html lang="es" style="<?= e(EmpresaConfig::estilo()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b1f33">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="<?= e(base_path()) ?>manifest.webmanifest">
<title><?= e($title) ?> – <?= e(marca()['nombre_empresa']) ?></title>
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<?php if ($user): ?>
<meta name="adara-user" content="<?= (int) $user['id'] ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/adara.css')) ?>">
<script src="<?= e(asset_v('js/contacto.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<?php if ($user): ?>
<script src="<?= e(asset_v('js/offlineSync.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<script src="<?= e(asset_v('js/offlineIndexedDb.js')) ?>" nonce="<?= e(csp_nonce()) ?>"></script>
<?php endif; ?>
</head>
<body class="<?= e($clasesBody) ?>">
<?php if (is_int($_SESSION['impersonado_por'] ?? null)): ?>
<div class="soporte-banner" role="alert">
  <span><i class="bi bi-exclamation-octagon-fill"></i> MODO SOPORTE TÉCNICO ACTIVO. Estás viendo los datos del cliente.</span>
  <form method="post" action="<?= e(url('master_salir_soporte')) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-sm btn-light">Salir del Soporte</button>
  </form>
</div>
<?php endif; ?>
<?php if ($user): ?>
<?php if (!$movil): ?>
<script nonce="<?= e(csp_nonce()) ?>">
if (localStorage.getItem('adara_sidebar_collapsed') === '1') document.body.classList.add('sidebar-collapsed');
</script>
<div class="sidebar" id="appSidebar">
  <div class="sidebar-brand">
    <img src="<?= e(marca_logo_src()) ?>" alt="<?= e(marca()['nombre_empresa']) ?>" class="sidebar-brand-logo">
    <div class="sidebar-brand-text">
      <h6><?= e(marca()['nombre_empresa']) ?></h6>
      <small><?= e(APP_NAME) ?> ERP</small>
    </div>
    <button type="button" class="sidebar-toggle" id="sidebarToggle" title="Contraer menú" aria-label="Contraer menú">
      <i class="bi bi-list"></i>
    </button>
  </div>
  <nav class="sidebar-nav">
    <?php foreach (modulos() as $key => $modulo): ?>
      <?php if ($key === 'respaldo' && Database::multiempresa() && !is_int($_SESSION['impersonado_por'] ?? null)) continue; ?>
      <?php if ($key === 'terminos' && !Database::multiempresa()) continue; ?>
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
      <?php if (Auth::enSoporte()): ?>
      <div class="sidebar-user-name"><?= e((string) ($_SESSION['impersonado_por_nombre'] ?? 'Soporte técnico')) ?></div>
      <div class="sidebar-user-maestro"><i class="bi bi-life-preserver"></i> SOPORTE TÉCNICO — ID Maestro <?= (int) Auth::getAuditUserId() ?></div>
      <div class="sidebar-user-role"><span>Viendo como: <?= e($user['nombre']) ?></span></div>
      <?php else: ?>
      <div class="sidebar-user-name"><?= e($user['nombre']) ?></div>
      <?php endif; ?>
      <?php if (Auth::enSoporte()): ?>
      <?php elseif ($user['rol'] === 'maestro'): ?>
        <div class="sidebar-user-maestro"><i class="bi bi-cpu-fill"></i> USUARIO MAESTRO — Servicio Técnico</div>
      <?php elseif ($user['rol'] === 'admin'): ?>
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
<?php endif; ?>
<div class="main-wrap">
  <div class="topbar">
    <?php if ($movil): ?>
      <?php if ($active !== 'dashboard'): ?>
    <a class="btn-inicio-movil" href="<?= e(url('dashboard')) ?>" aria-label="Volver al inicio">
      <i class="bi bi-house-door-fill"></i>
    </a>
      <?php else: ?>
    <img src="<?= e(marca_logo_src()) ?>" alt="" class="topbar-logo-movil">
      <?php endif; ?>
    <?php else: ?>
    <button type="button" class="btn-menu-movil" id="btnMenuMovil" aria-label="Abrir menú" aria-controls="appSidebar">
      <i class="bi bi-list"></i>
    </button>
    <?php endif; ?>
    <h5><?= e($title) ?></h5>
    <div class="d-flex align-items-center gap-3">
      <div class="dropdown">
        <button type="button" class="btn btn-light border rounded-circle position-relative" id="btnNotificaciones"
                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                aria-label="Notificaciones<?= $alertasVisibles > 0 ? ': ' . $alertasVisibles . ' alertas' : '' ?>">
          <i class="bi bi-bell"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger<?= $alertasVisibles > 0 ? '' : ' d-none' ?>" id="contadorAlertas" data-base="<?= (int) $totalAlertas ?>" data-otras="<?= (int) $otrasAlertas ?>">
            <span id="contadorAlertasNumero"><?= $alertasVisibles > 99 ? '99+' : $alertasVisibles ?></span>
            <span class="visually-hidden">alertas sin leer</span>
          </span>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow p-0" id="menuNotificaciones" aria-labelledby="btnNotificaciones">
          <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-body-tertiary">
            <strong class="small">Notificaciones</strong>
            <span class="badge text-bg-secondary" id="contadorAlertasMenu"><?= $alertasVisibles ?></span>
          </div>
          <div class="notificaciones-scroll">

          <h6 class="dropdown-header d-flex justify-content-between align-items-center gap-4 text-warning-emphasis bg-warning-subtle">
            <span><i class="bi bi-calendar-event"></i> Próximos Cierres (2 días)</span>
            <span class="badge text-bg-warning"><?= count($notificaciones['cierres']) ?></span>
          </h6>
          <ul class="list-unstyled mb-0 dropdown-alertas-lista" id="listaProximosCierres">
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
          <ul class="list-unstyled mb-0 dropdown-alertas-lista" id="listaPagosAtrasados">
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

          <?php if (Database::multiempresa() && is_int($_SESSION['tenant_id'] ?? null)): ?>
          <h6 class="dropdown-header d-flex justify-content-between align-items-center gap-4 text-info-emphasis bg-info-subtle border-top">
            <span><i class="bi bi-bell"></i> Suscripción</span>
            <span class="badge text-bg-info" id="nAvisosSaas"><?= $avisosSaasTotal ?></span>
          </h6>
          <ul class="list-unstyled mb-0 dropdown-alertas-lista" id="listaAvisosSaas">
            <?php foreach ($avisosSaas as $aviso): ?>
            <li>
              <button type="button" class="dropdown-item text-wrap py-2 border-bottom js-aviso-saas" data-id="<?= (int) $aviso['id'] ?>">
                <span class="d-block fw-semibold small"><?= e((string) $aviso['mensaje']) ?></span>
                <span class="d-block text-body-secondary small"><?= e(date('d/m/Y', strtotime((string) $aviso['fecha']))) ?></span>
              </button>
            </li>
            <?php endforeach; ?>
            <?php if ($avisosSaas === []): ?>
            <li><span class="dropdown-item-text small text-body-secondary py-2">Sin avisos de suscripción.</span></li>
            <?php endif; ?>
          </ul>
          <div id="avisosSaas" hidden data-url="<?= e(url('avisos_cliente')) ?>" data-leer="<?= e(url('avisos_cliente_leer')) ?>"></div>
          <?php endif; ?>
          </div>
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
      <?php if (Database::multiempresa() && is_int($_SESSION['tenant_id'] ?? null) && !is_int($_SESSION['impersonado_por'] ?? null)): ?>
      <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalTicketSoporte">
        <i class="bi bi-life-preserver"></i> Soporte
      </button>
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
    if (layout_usa_maestro()) {
        layout_master_end();
        return;
    }
    $user = current_user();
    ?>
<?php if ($user): ?>
  </div>
</div>
<?php if (en_app_android() && forzar_web()): ?>
<form method="post" action="<?= e(url('vista_movil')) ?>" class="volver-movil">
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-globo shadow" aria-label="Volver a la vista móvil">
    <i class="bi bi-phone"></i> Volver a la vista móvil
  </button>
</form>
<?php endif; ?>
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
  var toggle = document.getElementById('sidebarToggle');
  if (toggle) toggle.addEventListener('click', function () {
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
<?php
$ticketPendiente = ticket_form_pendiente();
$puedeReportar = $user && Database::multiempresa() && is_int($_SESSION['tenant_id'] ?? null) && !is_int($_SESSION['impersonado_por'] ?? null);
if ($puedeReportar):
    $ticketForm = $ticketPendiente['form'];
    $ticketErrores = $ticketPendiente['errores'];
?>
<div class="modal fade" id="modalTicketSoporte" tabindex="-1" aria-labelledby="modalTicketSoporteTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(url('soporte_ticket')) ?>">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="modalTicketSoporteTitulo">Reportar a soporte</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="ticketTipo">Tipo</label>
          <select class="form-select" id="ticketTipo" name="tipo_solicitud" required>
            <?php foreach (SaasComercial::TIPOS_TICKET as $codigo => $etiqueta): ?>
            <option value="<?= e($codigo) ?>"<?= ($ticketForm['tipo_solicitud'] ?? '') === $codigo ? ' selected' : '' ?>><?= e($etiqueta) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($ticketErrores['tipo_solicitud'])): ?><div class="invalid-feedback d-block"><?= e($ticketErrores['tipo_solicitud']) ?></div><?php endif; ?>
        </div>
        <div class="mb-3">
          <label class="form-label" for="ticketAsunto">Asunto</label>
          <input class="form-control" id="ticketAsunto" name="asunto" maxlength="140" required value="<?= e((string) ($ticketForm['asunto'] ?? '')) ?>">
          <?php if (isset($ticketErrores['asunto'])): ?><div class="invalid-feedback d-block"><?= e($ticketErrores['asunto']) ?></div><?php endif; ?>
        </div>
        <div class="mb-0">
          <label class="form-label" for="ticketDescripcion">Descripción</label>
          <textarea class="form-control" id="ticketDescripcion" name="descripcion" rows="4" maxlength="2000" required><?= e((string) ($ticketForm['descripcion'] ?? '')) ?></textarea>
          <?php if (isset($ticketErrores['descripcion'])): ?><div class="invalid-feedback d-block"><?= e($ticketErrores['descripcion']) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Enviar</button>
      </div>
    </form>
  </div>
</div>
<?php if ($ticketErrores !== []): ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('modalTicketSoporte');
  if (el && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(el).show();
});
</script>
<?php endif; ?>
<?php endif; ?>
<script src="<?= e(BOOTSTRAP_JS['src']) ?>" integrity="<?= e(BOOTSTRAP_JS['integrity']) ?>" crossorigin="anonymous"></script>
<script src="<?= e(asset_v('js/ver_clave.js')) ?>"></script>
<script src="<?= e(asset_v('js/descargas_app.js')) ?>"></script>
<?php if ($user && Database::multiempresa() && is_int($_SESSION['tenant_id'] ?? null)): ?>
<script src="<?= e(asset_v('js/avisos_cliente.js')) ?>"></script>
<?php endif; ?>
<script nonce="<?= e(csp_nonce()) ?>">
// La flecha Atrás puede restaurar la página desde la memoria del navegador aunque las cabeceras prohíban caché.
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
</script>
<script nonce="<?= e(csp_nonce()) ?>">
// Service Worker (sw.js en la raíz): requiere HTTPS o localhost; en http://IP-de-red el navegador lo ignora.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(<?= json_encode(base_path() . 'sw.js', JSON_THROW_ON_ERROR) ?>, {
      scope: <?= json_encode(base_path(), JSON_THROW_ON_ERROR) ?>,
      updateViaCache: 'none'
    }).catch(function (err) { console.warn('ADARA: no se pudo registrar el Service Worker.', err); });
  });
}
</script>
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
