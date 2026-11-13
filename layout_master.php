<?php
declare(strict_types=1);

/**
 * Cascarón exclusivo del Panel Maestro SaaS. No carga módulos, tasas ni datos de ninguna empresa.
 * Lo usan las pantallas del panel y, como red de seguridad, layout_start() cuando la sesión es de super admin.
 *
 * @param 'master'|'accesos'|'clave'|'contrato'|'arquitectura'|'bitacora'|'auditoria'|'tickets' $active
 */
function layout_master_start(string $title, string $active = 'master'): void
{
    $admin = super_admin_actual();
    $alertClass = ['error' => 'danger', 'success' => 'success', 'info' => 'info', 'warning' => 'warning'];
    $esPropietario = $admin !== null && $admin['id'] === Saas::PROPIETARIO_ID;
    $ticketsSoporte = [];
    $totalTicketsSoporte = 0;
    try {
        $buzon = new SaasComercial(Database::master());
        $totalTicketsSoporte = $buzon->contarAbiertos();
        $ticketsSoporte = $buzon->ticketsAbiertos(8);
    } catch (Throwable) {
        $ticketsSoporte = [];
        $totalTicketsSoporte = 0;
    }
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> – Panel Maestro</title>
<link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(BOOTSTRAP_ICONS_CSS['href']) ?>" integrity="<?= e(BOOTSTRAP_ICONS_CSS['integrity']) ?>" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset_v('css/adara.css')) ?>">
</head>
<body class="app master-app">
<div class="sidebar" id="appSidebar">
  <div class="sidebar-brand">
    <img src="<?= e(asset_v(LOGO_SISTEMA)) ?>" alt="ADARA" class="sidebar-brand-logo">
    <div class="sidebar-brand-text">
      <h6>ADARA SaaS</h6>
      <small>Panel Maestro</small>
    </div>
    <button type="button" class="sidebar-toggle" id="sidebarToggle" title="Contraer menú" aria-label="Contraer menú">
      <i class="bi bi-list"></i>
    </button>
  </div>
  <nav class="sidebar-nav">
    <a class="nav-link<?= $active === 'master' ? ' active' : '' ?>" href="<?= e(url('master')) ?>" title="Inicio">
      <span class="nav-icon"><i class="bi bi-buildings"></i></span>
      <span class="nav-label">Inicio</span>
    </a>
    <a class="nav-link<?= $active === 'contrato' ? ' active' : '' ?>" href="<?= e(url('master_contrato')) ?>" title="Contrato">
      <span class="nav-icon"><i class="bi bi-file-earmark-text"></i></span>
      <span class="nav-label">Contrato</span>
    </a>
    <a class="nav-link<?= $active === 'arquitectura' ? ' active' : '' ?>" href="<?= e(url('master_arquitectura')) ?>" title="Arquitectura del Sistema">
      <span class="nav-icon"><i class="bi bi-diagram-3"></i></span>
      <span class="nav-label">Arquitectura</span>
    </a>
    <a class="nav-link" href="<?= e(url('master')) ?>#suscripciones" title="Suscripciones">
      <span class="nav-icon"><i class="bi bi-hourglass-split"></i></span>
      <span class="nav-label">Suscripciones</span>
    </a>
    <a class="nav-link<?= $active === 'tickets' ? ' active' : '' ?>" href="<?= e(url('master_tickets')) ?>" title="Tickets de soporte">
      <span class="nav-icon"><i class="bi bi-life-preserver"></i></span>
      <span class="nav-label">Tickets</span>
    </a>
    <a class="nav-link<?= $active === 'bitacora' ? ' active' : '' ?>" href="<?= e(url('master_bitacora')) ?>" title="Bitácora del día">
      <span class="nav-icon"><i class="bi bi-journal-text"></i></span>
      <span class="nav-label">Bitácora</span>
    </a>
    <?php if ($esPropietario): ?>
    <a class="nav-link<?= $active === 'auditoria' ? ' active' : '' ?>" href="<?= e(url('master_auditoria')) ?>" title="Auditoría de maestros">
      <span class="nav-icon"><i class="bi bi-shield-check"></i></span>
      <span class="nav-label">Auditoría</span>
    </a>
    <?php endif; ?>
    <?php if ($esPropietario): ?>
    <a class="nav-link<?= $active === 'accesos' ? ' active' : '' ?>" href="<?= e(url('master')) ?>#accesos" title="Gestión de Accesos">
      <span class="nav-icon"><i class="bi bi-person-lock"></i></span>
      <span class="nav-label">Gestión de Accesos</span>
    </a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <div class="sidebar-footer-text">
      <div class="sidebar-user-name"><?= e($admin['nombre'] ?? 'Super admin') ?></div>
      <div class="sidebar-user-role"><i class="bi bi-shield-lock-fill"></i> <?= e($admin['correo'] ?? '') ?></div>
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
      <div class="dropdown" id="campanaSoporte" data-url="<?= e(url('master_tickets_aviso')) ?>">
        <button type="button" class="btn btn-light border rounded-circle position-relative" id="btnTicketsSoporte"
                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                aria-label="Reportes de soporte">
          <i class="bi bi-bell"></i>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger<?= $totalTicketsSoporte > 0 ? '' : ' d-none' ?>" id="contadorTicketsSoporte">
            <?= $totalTicketsSoporte > 99 ? '99+' : (int) $totalTicketsSoporte ?>
          </span>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow p-0" style="min-width: 320px" aria-labelledby="btnTicketsSoporte">
          <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-body-tertiary">
            <strong class="small">Soporte</strong>
            <span class="badge text-bg-secondary" id="contadorTicketsMenu"><?= (int) $totalTicketsSoporte ?></span>
          </div>
          <ul class="list-unstyled mb-0 notificaciones-scroll" id="listaTicketsSoporte">
            <?php if ($ticketsSoporte === []): ?>
            <li><span class="dropdown-item-text small text-body-secondary py-2">No hay reportes nuevos ni pendientes.</span></li>
            <?php endif; ?>
            <?php foreach ($ticketsSoporte as $t): ?>
            <li>
              <a class="dropdown-item d-flex flex-column py-2 border-bottom" href="<?= e(url('master_tickets') . '#t' . (int) $t['id']) ?>">
                <span class="d-flex justify-content-between gap-2">
                  <span class="fw-semibold small"><?= e((string) $t['nombre_empresa']) ?></span>
                  <span class="badge text-bg-light border"><?= e(SaasComercial::TIPOS_TICKET[(string) $t['tipo_solicitud']] ?? (string) $t['tipo_solicitud']) ?></span>
                </span>
                <span class="small text-wrap"><?= e((string) $t['asunto']) ?></span>
                <span class="small text-body-secondary"><?= e(date('d/m/Y H:i', strtotime((string) $t['fecha_creacion']))) ?></span>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>
          <a class="dropdown-item text-center small py-2 border-top" href="<?= e(url('master_tickets')) ?>">Ver todos los reportes</a>
        </div>
      </div>
      <?php if ($esPropietario): ?><span class="badge text-bg-dark">Propietario</span><?php endif; ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('master_clave')) ?>"><i class="bi bi-key"></i> Mi clave</a>
      <form method="post" action="<?= e(url('master_salir')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</button>
      </form>
    </div>
  </div>
  <div class="page-content">
    <?php foreach (flash_take() as $msg): ?>
    <div class="alert alert-<?= e($alertClass[$msg['type']] ?? 'info') ?> alert-dismissible fade show" role="alert">
      <?= e($msg['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
    <?php endforeach; ?>
<?php
}

function layout_master_end(): void
{
    ?>
  </div>
</div>
<script src="<?= e(BOOTSTRAP_JS['src']) ?>" integrity="<?= e(BOOTSTRAP_JS['integrity']) ?>" crossorigin="anonymous"></script>
<script src="<?= e(asset_v('js/master_campana.js')) ?>"></script>
<script src="<?= e(asset_v('js/ver_clave.js')) ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>">
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
</script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var sidebar = document.getElementById('appSidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var btnMovil = document.getElementById('btnMenuMovil');
  var toggle = document.getElementById('sidebarToggle');
  function esMovil() { return window.matchMedia('(max-width: 768px)').matches; }
  function cerrar() {
    document.body.classList.remove('sidebar-abierto');
    if (sidebar) sidebar.classList.remove('activo');
    if (overlay) overlay.hidden = true;
  }
  function abrir() {
    document.body.classList.add('sidebar-abierto');
    if (sidebar) sidebar.classList.add('activo');
    if (overlay) overlay.hidden = false;
  }
  if (toggle) toggle.addEventListener('click', function () {
    if (esMovil()) { sidebar && sidebar.classList.contains('activo') ? cerrar() : abrir(); return; }
    document.body.classList.toggle('sidebar-collapsed');
  });
  if (btnMovil) btnMovil.addEventListener('click', function () {
    sidebar && sidebar.classList.contains('activo') ? cerrar() : abrir();
  });
  if (overlay) overlay.addEventListener('click', cerrar);
  document.querySelectorAll('form.js-confirmar').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      var msg = f.getAttribute('data-confirmar');
      if (msg && !window.confirm(msg)) ev.preventDefault();
    });
  });
})();
</script>
</body>
</html>
<?php
}
