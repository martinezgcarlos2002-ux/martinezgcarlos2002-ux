<?php
function layout_start(string $title, string $page): void {
    $user = current_user();
    $flash = get_flash();
    $csrf = csrf_token();
    $nav_all = [
        ['p' => 'dashboard',   'icon' => '📊', 'label' => 'Dashboard'],
        ['p' => 'inventario',  'icon' => '📦', 'label' => 'Inventario'],
        ['p' => 'produccion_diaria', 'icon' => '🏭', 'label' => 'Producción Diaria'],
        ['p' => 'ventas',      'icon' => '🧾', 'label' => 'Ventas'],
        ['p' => 'empleados',   'icon' => '👷', 'label' => 'Empleados'],
        ['p' => 'usuarios',    'icon' => '👤', 'label' => 'Usuarios'],
        ['p' => 'transportistas', 'icon' => '🚛', 'label' => 'Transportistas'],
        ['p' => 'productores',  'icon' => '🥛', 'label' => 'Productores'],
        ['p' => 'control_leche',      'icon' => '📋', 'label' => 'Control Leche'],
        ['p' => 'control_diario',  'icon' => '🖨️', 'label' => 'Control Diario'],
        ['p' => 'resumen_diario',  'icon' => '📅', 'label' => 'Resumen Diario'],
        ['p' => 'finanzas',    'icon' => '💰', 'label' => 'Finanzas'],
        ['p' => 'bancos',      'icon' => '🏦', 'label' => 'Bancos'],
        ['p' => 'auditoria',   'icon' => '🔍', 'label' => 'Auditoría'],
        ['p' => 'cierre_diario', 'icon' => '💾', 'label' => 'Cierre Diario'],
    ];
    // Filtrar según permisos del usuario (admin ve todo)
    $nav = array_filter($nav_all, fn($n) => has_permiso($n['p']));
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> – ASERP</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
:root{--navy:#1a2e5a;--orange:#f97316;--orange-hover:#ea6c0c;--sidebar-w:230px;--sidebar-collapsed-w:52px}
body{background:#f0f4fa;font-family:Inter,system-ui,sans-serif;margin:0}
.sidebar{width:var(--sidebar-w);min-height:100vh;background:var(--navy);position:fixed;top:0;left:0;z-index:100;display:flex;flex-direction:column;transition:width .22s ease}
.sidebar-brand{padding:14px 12px 10px;border-bottom:1px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:space-between;gap:10px;overflow:hidden;min-height:64px}
.sidebar-brand-logo{width:42px;height:42px;object-fit:contain;border-radius:8px;background:#fff;padding:2px;flex-shrink:0}
.sidebar-brand-text{flex:1;overflow:hidden;min-width:0}
body.sidebar-collapsed .sidebar-brand-logo{width:36px;height:36px}
.sidebar-brand h6{color:#fff;font-weight:700;font-size:.95rem;margin:0;white-space:nowrap}
.sidebar-brand small{color:rgba(255,255,255,.55);font-size:.72rem;white-space:nowrap}
.sidebar-toggle{background:none;border:none;color:rgba(255,255,255,.65);cursor:pointer;padding:4px 6px;border-radius:5px;flex-shrink:0;transition:color .15s,background .15s;line-height:1;font-size:1rem}
.sidebar-toggle:hover{color:#fff;background:rgba(255,255,255,.12)}
.sidebar-nav{flex:1;padding:8px 0;overflow-y:scroll;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.45) rgba(255,255,255,.08)}
.sidebar-nav::-webkit-scrollbar{width:10px}
.sidebar-nav::-webkit-scrollbar-track{background:rgba(0,0,0,.25)}
.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.35);border-radius:5px;border:2px solid transparent;background-clip:padding-box}
.sidebar-nav::-webkit-scrollbar-thumb:hover{background:var(--orange);background-clip:padding-box;border:2px solid transparent}
.sidebar-nav::-webkit-scrollbar-button:single-button{background:rgba(255,255,255,.12);display:block;height:14px;width:10px;background-repeat:no-repeat;background-position:center}
.sidebar-nav::-webkit-scrollbar-button:single-button:hover{background-color:var(--orange)}
.sidebar-nav::-webkit-scrollbar-button:vertical:decrement{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'><path d='M2 7l3-4 3 4z' fill='white'/></svg>")}
.sidebar-nav::-webkit-scrollbar-button:vertical:increment{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'><path d='M2 3l3 4 3-4z' fill='white'/></svg>")}
.nav-link{color:rgba(255,255,255,.75);padding:8px 16px;display:flex;align-items:center;gap:10px;font-size:.875rem;border-radius:0;transition:.15s;white-space:nowrap;overflow:hidden}
.nav-link:hover,.nav-link.active{color:#fff;background:rgba(255,255,255,.1)}
.nav-link.active{border-left:3px solid var(--orange)}
.nav-link .nav-icon{flex-shrink:0;width:20px;text-align:center}
.nav-link .nav-label{transition:opacity .18s}
.sidebar-footer{padding:12px 16px;border-top:1px solid rgba(255,255,255,.12);font-size:.8rem;color:rgba(255,255,255,.5);overflow:hidden;transition:padding .22s}
.sidebar-footer a{color:rgba(255,255,255,.7);text-decoration:none}
.sidebar-footer-text{white-space:nowrap;overflow:hidden;transition:opacity .18s}
.sidebar-footer-brand{text-align:center;margin-bottom:10px}
.sidebar-footer-logo{width:78px;height:78px;object-fit:contain;background:#fff;border-radius:12px;padding:5px;transition:width .22s,height .22s}
body.sidebar-collapsed .sidebar-footer-brand{margin-bottom:6px}
body.sidebar-collapsed .sidebar-footer-logo{width:38px;height:38px;padding:3px}
.main-wrap{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column;transition:margin-left .22s ease}
.topbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px;display:flex;align-items:center;justify-content:space-between}
.topbar h5{margin:0;font-weight:600;color:#1e293b}
.page-content{padding:24px;flex:1}
/* ── Estado colapsado ── */
body.sidebar-collapsed .sidebar{width:var(--sidebar-collapsed-w)}
body.sidebar-collapsed .sidebar-brand-text{opacity:0;pointer-events:none}
body.sidebar-collapsed .nav-link .nav-label{opacity:0;width:0;pointer-events:none}
body.sidebar-collapsed .sidebar-footer-text{opacity:0;pointer-events:none}
body.sidebar-collapsed .sidebar-footer{padding:12px 6px}
body.sidebar-collapsed .main-wrap{margin-left:var(--sidebar-collapsed-w)}
body.sidebar-collapsed .nav-link{padding:8px 0;justify-content:center;gap:0}
body.sidebar-collapsed .sidebar-brand{justify-content:center;padding:14px 0 10px}
body.sidebar-collapsed .sidebar-toggle{margin:0 auto}
.btn-primary{background:var(--orange);border-color:var(--orange)}
.btn-primary:hover{background:var(--orange-hover);border-color:var(--orange-hover)}
.btn-outline-primary{color:var(--orange);border-color:var(--orange)}
.btn-outline-primary:hover{background:var(--orange);border-color:var(--orange)}
.badge.bg-primary{background:var(--orange)!important}
.card{border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.card-header{background:#fff;border-bottom:1px solid #e2e8f0;font-weight:600}
table thead th{background:#f8fafc;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:600}
.stat-card{background:#fff;border-radius:12px;padding:20px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.stat-card .stat-val{font-size:1.8rem;font-weight:700;color:var(--navy)}
.stat-card .stat-label{color:#64748b;font-size:.85rem}
.stat-card .stat-icon{font-size:1.6rem}
</style>
</head>
<body>
<script>window.ASERP_CAN_CIERRE = <?= has_permiso('cierre_diario') ? 'true' : 'false' ?>;</script>
<div class="sidebar" id="appSidebar">
  <div class="sidebar-brand">
    <img src="public/logo.jpg" alt="El Castillo del Queso" class="sidebar-brand-logo">
    <div class="sidebar-brand-text">
      <h6>El Castillo del Queso</h6>
      <small>ASERP v1.2 PHP</small>
    </div>
    <button class="sidebar-toggle" id="sidebarToggle" title="Contraer menú" onclick="toggleSidebar()">&#9776;</button>
  </div>
  <nav class="sidebar-nav">
    <?php foreach ($nav as $n): ?>
    <a class="nav-link <?= $page === $n['p'] ? 'active' : '' ?>" href="?p=<?= $n['p'] ?>" title="<?= h($n['label']) ?>">
      <span class="nav-icon"><?= $n['icon'] ?></span>
      <span class="nav-label"><?= $n['label'] ?></span>
    </a>
    <?php endforeach ?>
  </nav>
  <div class="sidebar-footer">
    <div class="sidebar-footer-brand">
      <img src="public/aserp-logo.jpg" alt="ASERP — Admin System Enterprise Resource Planning" class="sidebar-footer-logo">
    </div>
    <div class="sidebar-footer-text">
      <div class="fw-semibold" style="color:rgba(255,255,255,.9)"><?= h($user['nombre']) ?></div>
      <?php if ($user['rol'] === 'admin'): ?>
        <div style="color:#f97316;font-size:.72rem;font-weight:700">👑 ADMIN — acceso total</div>
      <?php else:
          $perms_activos = get_permisos();
          $modulos_con_acceso = $perms_activos ? array_keys(array_filter($perms_activos)) : [];
      ?>
        <div style="color:rgba(255,255,255,.55);font-size:.7rem;line-height:1.4">
          👤 Empleado<br>
          <span style="color:rgba(255,200,100,.8)">
            <?= $modulos_con_acceso ? implode(', ', $modulos_con_acceso) : 'sin módulos asignados' ?>
          </span>
        </div>
      <?php endif ?>
    </div>
  </div>
</div>
<div class="main-wrap">
  <div class="topbar">
    <h5><?= h($title) ?></h5>
    <div class="d-flex align-items-center gap-3">
      <?php $tasa_top = get_tasa(); if ($tasa_top > 1): ?>
      <span class="badge rounded-pill text-bg-light border" style="font-size:.8rem;color:#1a2e5a!important">
        💱 <strong>1 USD</strong> = <strong>Bs. <?= number_format($tasa_top, 2, '.', ',') ?></strong>
      </span>
      <?php endif ?>
      <span class="text-muted small" id="reloj-top"></span>
      <a href="?p=logout" class="btn btn-sm btn-outline-danger" style="font-size:.75rem">🔓 Cerrar sesión</a>
      <script>
        (function(){
          function tick(){
            var d=new Date();
            var dd=String(d.getDate()).padStart(2,'0');
            var mm=String(d.getMonth()+1).padStart(2,'0');
            var yy=d.getFullYear();
            var hh=String(d.getHours()).padStart(2,'0');
            var mi=String(d.getMinutes()).padStart(2,'0');
            var ss=String(d.getSeconds()).padStart(2,'0');
            document.getElementById('reloj-top').textContent=dd+'/'+mm+'/'+yy+' '+hh+':'+mi+':'+ss;
          }
          tick(); setInterval(tick,1000);
        })();
      </script>
    </div>
  </div>
  <div class="page-content">
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
      <?= h($flash['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif ?>
<?php } // end layout_start

function layout_end(): void { ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar toggle ────────────────────────────────────────────────────────────
(function(){
  var COLLAPSED_KEY = 'aserp_sidebar_collapsed';
  function applyState(collapsed) {
    if (collapsed) {
      document.body.classList.add('sidebar-collapsed');
    } else {
      document.body.classList.remove('sidebar-collapsed');
    }
  }
  // Restaurar estado guardado sin animación
  var saved = localStorage.getItem(COLLAPSED_KEY);
  if (saved === '1') {
    document.body.style.transition = 'none';
    applyState(true);
    setTimeout(function(){ document.body.style.transition = ''; }, 0);
  }
  window.toggleSidebar = function() {
    var collapsed = document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
  };
})();
(function(){
  // ── Búsqueda en tiempo real ───────────────────────────────────────────────
  // Busca en TODAS las columnas de la tabla visible desde el primer carácter.
  function normalize(str) {
    return str.toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, ''); // quita tildes
  }

  function filterTable(inp) {
    var term  = normalize(inp.value.trim());
    // Buscar la primera tabla con tbody dentro de .page-content
    var table = document.querySelector('.page-content table');
    if (!table) return;
    var rows  = table.querySelectorAll('tbody tr');
    var count = 0;
    rows.forEach(function(row) {
      // Ignorar filas de "sin resultados"
      if (row.dataset.nofilter) { row.style.display = ''; return; }
      if (!term) { row.style.display = ''; count++; return; }
      var text = normalize(row.textContent);
      var show = text.indexOf(term) !== -1;
      row.style.display = show ? '' : 'none';
      if (show) count++;
    });

    // Mostrar mensaje si no hay resultados
    var msg = table.querySelector('tr[data-nofilter]');
    if (!msg) {
      msg = document.createElement('tr');
      msg.dataset.nofilter = '1';
      msg.innerHTML = '<td colspan="99" class="text-center text-muted py-3">Sin resultados para "<strong></strong>"</td>';
      table.querySelector('tbody').appendChild(msg);
    }
    msg.querySelector('strong').textContent = inp.value.trim();
    msg.style.display = (term && count === 0) ? '' : 'none';
  }

  // Conectar a todos los inputs de búsqueda de la página
  document.querySelectorAll('input[name="q"], input[type="search"]').forEach(function(inp) {
    // Ocultar botón "Filtrar" si sólo tiene el filtro de texto
    var btn = inp.closest('form');
    if (btn) {
      var filtrarBtn = btn.querySelector('button[type="submit"], button:not([type])');
      if (filtrarBtn && /filtrar/i.test(filtrarBtn.textContent)) {
        filtrarBtn.style.display = 'none';
      }
    }

    // Filtrar al escribir
    inp.addEventListener('input', function() { filterTable(this); });
    inp.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); filterTable(this); }
    });
    // Si ya hay texto al cargar (viene de URL ?q=...), filtrar inmediatamente
    if (inp.value) filterTable(inp);
  });
})();
(function(){
  // ── Límite de caracteres en TODOS los campos ──────────────────────────────
  // Los navegadores IGNORAN maxlength en <input type="number">, por eso los
  // números permitían dígitos infinitos. Aquí aplicamos un tope a cada campo:
  //  - respeta el maxlength explícito del campo si lo tiene
  //  - números: máx. 12 caracteres (suficiente para montos grandes con decimales)
  //  - texto: tope sensato según el NOMBRE del campo (nombre, código, etc.)
  //  - texto sin coincidencia: 100 caracteres por defecto
  var DEF_NUM = 12, DEF_TXT = 100;

  // Topes por nombre de campo (primer patrón que coincide gana). El nombre se
  // normaliza quitando sufijos de arreglo ("nombre[12]" -> "nombre").
  var NAME_CAPS = [
    [/(descrip|observ|notas?|coment)/, 300],
    [/direccion/,                      150],
    [/(correo|email)/,                 100],
    [/(telefon|celular|movil|whatsapp)/, 20],
    [/(cedula|documento|rif|placa|numero_?cuenta)/, 40],
    [/codigo/,                          30],
    [/(password|clave|contrasen|confirmar)/, 64],
    [/(presentaci|capacidad|cargo|estado|categoria|unidad|medida|color|moneda|tipo)/, 40],
    [/(nombre|apellido|contacto|cliente|beneficiario|titular|proveedor|conductor|chofer|razon|empresa|marca|modelo)/, 80]
  ];

  function capByName(el) {
    var n = (el.getAttribute('name') || '').toLowerCase().replace(/\[.*$/, '');
    if (!n) return 0;
    for (var i = 0; i < NAME_CAPS.length; i++) {
      if (NAME_CAPS[i][0].test(n)) return NAME_CAPS[i][1];
    }
    return 0;
  }

  function capFor(el) {
    var ml = parseInt(el.getAttribute('maxlength'), 10);
    if (!isNaN(ml) && ml > 0) return ml;
    var t = (el.type || '').toLowerCase();
    if (t === 'number') return DEF_NUM;
    if (el.tagName === 'TEXTAREA') return capByName(el) || 300;
    if (t === 'text' || t === 'search' || t === 'email' || t === 'tel' ||
        t === 'password' || t === 'url' || t === '') return capByName(el) || DEF_TXT;
    return 0; // date, checkbox, radio, hidden, etc.: sin tope
  }

  function enforce(el) {
    var cap = capFor(el);
    if (cap <= 0) return;
    if (typeof el.value === 'string' && el.value.length > cap) {
      el.value = el.value.slice(0, cap);
    }
  }

  function attach(el) {
    if (el.dataset.capBound) return; // evitar doble enlace (MutationObserver)
    var cap = capFor(el);
    if (cap <= 0) return;
    el.dataset.capBound = '1';
    var t = (el.type || '').toLowerCase();
    // Para los que sí soportan maxlength, fijarlo también (feedback nativo).
    if (el.tagName === 'TEXTAREA' || ['text','search','email','tel','password','url',''].indexOf(t) !== -1) {
      if (!el.getAttribute('maxlength')) el.setAttribute('maxlength', cap);
    }
    el.addEventListener('input', function(){ enforce(el); });
    enforce(el);
  }

  function scan(root) {
    (root || document).querySelectorAll('input, textarea').forEach(attach);
  }

  scan(document);
  // Campos creados dinámicamente (p. ej. filas nuevas en Control Diario).
  var mo = new MutationObserver(function(muts){
    muts.forEach(function(m){
      m.addedNodes.forEach(function(n){
        if (n.nodeType !== 1) return;
        if (n.matches && n.matches('input, textarea')) attach(n);
        if (n.querySelectorAll) scan(n);
      });
    });
  });
  mo.observe(document.body, { childList: true, subtree: true });
})();
(function(){
  // ── Descarga automática del Cierre del Día (1 vez al día por computadora) ──
  // Un navegador solo puede descargar cuando el sistema está abierto. Por eso
  // la descarga se dispara en la primera visita del día de un usuario con
  // permiso. Se registra la fecha en localStorage para no repetir. El usuario
  // puede desactivarlo desde la página "Cierre Diario".
  if (!window.ASERP_CAN_CIERRE) return;
  if (window.__aserpCierreFired) return;                          // no repetir en la misma página
  if (localStorage.getItem('aserp_cierre_auto') === '0') return;  // desactivado
  var KEY = 'aserp_cierre_last';
  var d = new Date();
  var hoy = d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
  if (localStorage.getItem(KEY) === hoy) return; // ya se descargó hoy
  window.__aserpCierreFired = true;

  var done = false;
  function finish() {
    if (done) return;
    done = true;
    // Marcar el día como hecho SOLO al confirmar (load) o tras el respaldo de
    // tiempo (algunos navegadores no emiten 'load' en descargas). Si hay error
    // de red, NO se marca y se reintenta en la próxima navegación.
    localStorage.setItem(KEY, hoy);
    var n = document.createElement('div');
    n.textContent = '✓ Cierre del día descargado automáticamente (' + hoy + ')';
    n.style.cssText = 'position:fixed;bottom:18px;right:18px;z-index:9999;background:#198754;' +
                      'color:#fff;padding:10px 16px;border-radius:8px;' +
                      'box-shadow:0 4px 12px rgba(0,0,0,.2);font-size:.9rem';
    document.body.appendChild(n);
    setTimeout(function(){
      n.style.transition = 'opacity .5s'; n.style.opacity = '0';
      setTimeout(function(){ n.remove(); }, 500);
    }, 6000);
  }

  var url = location.pathname + '?p=cierre_diario&action=descargar';
  var ifr = document.createElement('iframe');
  ifr.style.display = 'none';
  var t = setTimeout(finish, 4000); // respaldo si no llega 'load'
  ifr.onload  = function(){ clearTimeout(t); finish(); };
  ifr.onerror = function(){ clearTimeout(t); done = true; window.__aserpCierreFired = false; };
  ifr.src = url;
  document.body.appendChild(ifr);
})();
</script>
</body>
</html>
<?php }
