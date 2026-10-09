<?php
/* ============================================================================
   Login + Panel privado de Soporte Técnico (control de licencia / bloqueo).
   El acceso al panel es solo mediante el usuario y la clave de soporte; no
   aparece en ninguna otra parte del sistema. Al llegar la fecha programada el
   sistema se bloquea y solo el soporte puede desbloquearlo.
   ========================================================================== */

/* ---- Almacenamiento (tabla config_produccion, clave 'licencia') ---- */
if (!function_exists('ensure_config_produccion_table')) {
    function ensure_config_produccion_table(): void {
        static $done = false; if ($done) return;
        try {
            $db = get_db();
            if (is_mysql()) {
                $db->exec("CREATE TABLE IF NOT EXISTS config_produccion (clave VARCHAR(50) PRIMARY KEY, valor MEDIUMTEXT NOT NULL, actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS config_produccion (clave VARCHAR(50) PRIMARY KEY, valor TEXT NOT NULL, actualizado_en TIMESTAMP NOT NULL DEFAULT NOW())");
            }
            $done = true;
        } catch (Throwable $e) { error_log('[ensure_config_produccion_table] ' . $e->getMessage()); }
    }
}
if (!function_exists('get_config_produccion')) {
    function get_config_produccion(string $clave = 'panel'): ?array {
        ensure_config_produccion_table();
        try {
            $db = get_db();
            $st = $db->prepare("SELECT valor FROM config_produccion WHERE clave=?");
            $st->execute([$clave]);
            $v = $st->fetchColumn();
            if ($v) { $d = json_decode($v, true); if (is_array($d)) return $d; }
        } catch (Throwable $e) { error_log('[get_config_produccion] ' . $e->getMessage()); }
        return null;
    }
}
if (!function_exists('save_config_produccion')) {
    function save_config_produccion(array $data, string $clave = 'panel'): bool {
        ensure_config_produccion_table();
        try {
            $db = get_db();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (is_mysql()) {
                $db->prepare("INSERT INTO config_produccion (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), actualizado_en=CURRENT_TIMESTAMP")->execute([$clave, $json]);
            } else {
                $db->prepare("INSERT INTO config_produccion (clave, valor) VALUES (?,?) ON CONFLICT (clave) DO UPDATE SET valor=EXCLUDED.valor, actualizado_en=NOW()")->execute([$clave, $json]);
            }
            return true;
        } catch (Throwable $e) { error_log('[save_config_produccion] ' . $e->getMessage()); return false; }
    }
}

/* ---- Licencia ---- */
if (!function_exists('aserp_get_licencia')) {
    function aserp_get_licencia(): array {
        $lic = get_config_produccion('licencia');
        if (!is_array($lic)) $lic = [];
        $changed = false;
        if (!array_key_exists('activo', $lic)) { $lic['activo'] = true; $changed = true; }
        if (empty($lic['fecha_bloqueo']))      { $lic['fecha_bloqueo'] = date('Y-m-d', strtotime('+1 month')); $changed = true; }
        if (empty($lic['sup_user']))           { $lic['sup_user'] = 'v-30703203'; $changed = true; }
        if (empty($lic['sup_pass_hash']))      { $lic['sup_pass_hash'] = password_hash('30703203', PASSWORD_DEFAULT); $changed = true; }
        if ($changed) save_config_produccion($lic, 'licencia');
        return $lic;
    }
}
if (!function_exists('aserp_sistema_bloqueado')) {
    function aserp_sistema_bloqueado(array $lic): bool {
        if (empty($lic['activo']) || empty($lic['fecha_bloqueo'])) return false;
        return date('Y-m-d') >= $lic['fecha_bloqueo'];
    }
}

$lic       = aserp_get_licencia();
$bloqueado = aserp_sistema_bloqueado($lic);

/* Si el sistema está bloqueado, ninguna sesión normal puede continuar. */
if ($bloqueado && empty($_SESSION['sup_ok'])) {
    unset($_SESSION['user_id'], $_SESSION['user_nombre'], $_SESSION['user_email'], $_SESSION['user_rol']);
}

/* Usuario normal ya autenticado → al panel principal. */
if (!empty($_SESSION['user_id']) && empty($_SESSION['sup_ok'])) {
    header('Location: ?p=dashboard');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ===== Acciones del panel de soporte ===== */
    if (strncmp($action, 'sup_', 4) === 0) {
        verify_csrf();
        if (empty($_SESSION['sup_ok'])) { header('Location: ?p=login'); exit; }

        if ($action === 'sup_logout') {
            unset($_SESSION['sup_ok']);
            header('Location: ?p=login'); exit;
        }

        if ($action === 'sup_set_fecha') {
            $f = trim($_POST['fecha_bloqueo'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f)) {
                $lic['fecha_bloqueo'] = $f;
                $lic['activo'] = true;
                save_config_produccion($lic, 'licencia');
                flash('Fecha de bloqueo programada para el ' . date('d/m/Y', strtotime($f)) . '.');
            } else {
                flash('Fecha inválida.', 'danger');
            }
            header('Location: ?p=login'); exit;
        }

        if ($action === 'sup_extender') {
            $dias  = (int)($_POST['dias']  ?? 0);
            $meses = (int)($_POST['meses'] ?? 0);
            $base  = ($lic['fecha_bloqueo'] > date('Y-m-d')) ? $lic['fecha_bloqueo'] : date('Y-m-d');
            if ($meses > 0) {
                $lic['fecha_bloqueo'] = date('Y-m-d', strtotime("$base +$meses month"));
            } elseif ($dias > 0) {
                $lic['fecha_bloqueo'] = date('Y-m-d', strtotime("$base +$dias day"));
            }
            $lic['activo'] = true;
            save_config_produccion($lic, 'licencia');
            flash('Tiempo extendido. Nuevo bloqueo: ' . date('d/m/Y', strtotime($lic['fecha_bloqueo'])) . '.');
            header('Location: ?p=login'); exit;
        }

        if ($action === 'sup_toggle') {
            $lic['activo'] = empty($lic['activo']);
            save_config_produccion($lic, 'licencia');
            flash($lic['activo'] ? 'Bloqueo automático ACTIVADO.' : 'Bloqueo automático DESACTIVADO (sin límite).');
            header('Location: ?p=login'); exit;
        }

        if ($action === 'sup_pass') {
            $p1 = (string)($_POST['np1'] ?? '');
            $p2 = (string)($_POST['np2'] ?? '');
            if (strlen($p1) < 4) {
                flash('La nueva clave debe tener al menos 4 caracteres.', 'danger');
            } elseif ($p1 !== $p2) {
                flash('Las claves no coinciden.', 'danger');
            } else {
                $lic['sup_pass_hash'] = password_hash($p1, PASSWORD_DEFAULT);
                save_config_produccion($lic, 'licencia');
                flash('Clave de soporte actualizada correctamente.');
            }
            header('Location: ?p=login'); exit;
        }

        header('Location: ?p=login'); exit;
    }

    /* ===== Inicio de sesión (normal o soporte) ===== */
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (mb_strlen($email) > 120 || mb_strlen($password) > 64) {
        $error = 'Usuario o contraseña demasiado largos.';
    } elseif ($email !== '' && strcasecmp($email, (string)$lic['sup_user']) === 0) {
        /* Acceso privado de soporte técnico */
        if (password_verify($password, (string)$lic['sup_pass_hash'])) {
            $_SESSION['sup_ok'] = true;
            header('Location: ?p=login'); exit;
        }
        $error = 'Credenciales incorrectas.';
    } elseif ($bloqueado) {
        $error = 'Sistema bloqueado. Contacte al soporte técnico.';
    } elseif ($email && $password) {
        $db = get_db();
        $st = $db->prepare('SELECT id, nombre, email, rol, estado, password FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();

        $estado = $u['estado'] ?? null;
        $activo = ($estado === true || $estado === 't' || $estado === 1 || $estado === '1');
        if ($u && $activo && password_verify($password, $u['password'])) {
            $_SESSION['user_id']     = $u['id'];
            $_SESSION['user_nombre'] = $u['nombre'];
            $_SESSION['user_email']  = $u['email'];
            $_SESSION['user_rol']    = $u['rol'];
            audit_session_start([
                'id'     => $u['id'],
                'nombre' => $u['nombre'],
                'email'  => $u['email'],
                'rol'    => $u['rol'],
            ]);
            audit_log('auth', 'login_exitoso', ['usuario_id' => $u['id'], 'rol' => $u['rol']]);
            header('Location: ?p=dashboard');
            exit;
        } else {
            audit_log('auth', 'login_fallido', ['email' => $email, 'razon' => $u ? ($activo ? 'password' : 'inactivo') : 'no_existe']);
            $error = 'Credenciales incorrectas.';
        }
    } else {
        $error = 'Completa todos los campos.';
    }
}

$panel     = !empty($_SESSION['sup_ok']);
$flash     = $panel ? get_flash() : null;
$dias_rest = (int)floor((strtotime($lic['fecha_bloqueo']) - strtotime(date('Y-m-d'))) / 86400);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $panel ? 'Soporte Técnico' : 'Iniciar Sesión' ?> – ASERP</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
html, body { height:100%; margin:0; }
body{
  display:flex; align-items:center; justify-content:center;
  min-height:100vh; padding:1rem;
  background:
    linear-gradient(rgba(20,32,80,.78), rgba(20,32,80,.78)),
    url('public/logo.jpg') center center / cover no-repeat fixed;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
}
.login-card{
  width:100%; max-width:420px;
  border:none; border-radius:20px;
  box-shadow:0 20px 60px rgba(0,0,0,.45);
  backdrop-filter:blur(6px);
  background:rgba(255,255,255,.97);
  padding:2rem 2rem 1.75rem;
}
.panel-card{ max-width:460px; }
.brand-logo{
  width:140px; height:140px; border-radius:50%;
  background:#fff;
  padding:6px;
  box-shadow:0 8px 24px rgba(0,0,0,.25);
  object-fit:cover;
  margin-bottom:.75rem;
  border:3px solid #f0c14b;
}
.panel-card .brand-logo{ width:96px; height:96px; }
.brand-title{color:#1a2e5a;font-weight:800; letter-spacing:.3px; margin:0}
.brand-sub{color:#6c757d; font-size:.8rem; margin-top:.25rem}
.btn-primary{
  background:#f97316;border-color:#f97316; font-weight:600;
  padding:.6rem; border-radius:10px;
}
.btn-primary:hover{background:#ea6c0c;border-color:#ea6c0c}
.form-control{ border-radius:10px; padding:.55rem .75rem; }
.form-label{ font-weight:500; color:#37425a; font-size:.85rem; margin-bottom:.3rem; }
.estado-box{ border-radius:12px; padding:14px; text-align:center; margin-bottom:1rem; }
.estado-box .est-tit{ font-size:1.4rem; font-weight:800; line-height:1.15; }
.estado-box .est-sub{ font-size:.82rem; margin-top:.25rem; opacity:.9; }
.est-ok{ background:#e6f4ea; color:#1b6b34; }
.est-off{ background:#eef0f3; color:#5a6472; }
.est-bloq{ background:#fdeaea; color:#b42318; }
.footer-mark{
  position:fixed; bottom:.6rem; left:0; right:0; text-align:center;
  color:rgba(255,255,255,.7); font-size:.75rem; letter-spacing:.5px;
}
</style>
</head>
<body>
<?php if ($panel): ?>
<!-- ══ PANEL PRIVADO DE SOPORTE TÉCNICO ══════════════════════════════════════ -->
<div class="card login-card panel-card">
  <div class="text-center mb-3">
    <img src="public/logo.jpg" alt="Soporte" class="brand-logo">
    <h4 class="brand-title">Panel de Soporte Técnico</h4>
    <div class="brand-sub">Control de licencia del sistema</div>
    <img src="public/aserp-logo.jpg" alt="ASERP — Admin System Enterprise Resource Planning" style="height:54px;width:auto;object-fit:contain;margin-top:10px">
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] === 'danger' ? 'danger' : 'success' ?> py-2"><?= h($flash['msg']) ?></div>
  <?php endif ?>

  <div class="estado-box <?= $bloqueado ? 'est-bloq' : (empty($lic['activo']) ? 'est-off' : 'est-ok') ?>">
    <?php if ($bloqueado): ?>
      <div class="est-tit">SISTEMA BLOQUEADO</div>
      <div class="est-sub">Fecha programada: <?= date('d/m/Y', strtotime($lic['fecha_bloqueo'])) ?></div>
    <?php elseif (empty($lic['activo'])): ?>
      <div class="est-tit">Bloqueo desactivado</div>
      <div class="est-sub">El sistema no se bloqueará automáticamente.</div>
    <?php else: ?>
      <div class="est-tit"><?= $dias_rest ?> día<?= $dias_rest === 1 ? '' : 's' ?> restante<?= $dias_rest === 1 ? '' : 's' ?></div>
      <div class="est-sub">Se bloqueará el <?= date('d/m/Y', strtotime($lic['fecha_bloqueo'])) ?></div>
    <?php endif ?>
  </div>

  <form method="post" class="mb-3">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="sup_set_fecha">
    <label class="form-label">Programar fecha exacta de bloqueo</label>
    <div class="d-flex gap-2">
      <input type="date" name="fecha_bloqueo" class="form-control" value="<?= h($lic['fecha_bloqueo']) ?>" min="<?= date('Y-m-d') ?>" required>
      <button class="btn btn-primary text-nowrap">Guardar</button>
    </div>
  </form>

  <label class="form-label">Extender tiempo rápido</label>
  <div class="d-flex gap-2 mb-3">
    <form method="post" class="flex-fill"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="sup_extender"><input type="hidden" name="dias" value="15"><button class="btn btn-outline-secondary w-100">+15 días</button></form>
    <form method="post" class="flex-fill"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="sup_extender"><input type="hidden" name="meses" value="1"><button class="btn btn-outline-secondary w-100">+1 mes</button></form>
    <form method="post" class="flex-fill"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="sup_extender"><input type="hidden" name="meses" value="3"><button class="btn btn-outline-secondary w-100">+3 meses</button></form>
  </div>

  <form method="post" class="mb-3">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="sup_toggle">
    <button class="btn w-100 <?= empty($lic['activo']) ? 'btn-success' : 'btn-outline-danger' ?>">
      <?= empty($lic['activo']) ? 'Activar bloqueo automático' : 'Desactivar bloqueo (sin límite)' ?>
    </button>
  </form>

  <hr>

  <details>
    <summary style="cursor:pointer;color:#1a2e5a;font-weight:600;margin-bottom:.5rem">Cambiar mi clave de acceso</summary>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="sup_pass">
      <input type="password" name="np1" class="form-control mb-2" placeholder="Nueva clave" required autocomplete="new-password">
      <input type="password" name="np2" class="form-control mb-2" placeholder="Repetir nueva clave" required autocomplete="new-password">
      <button class="btn btn-primary w-100">Cambiar clave</button>
    </form>
  </details>

  <form method="post" class="mt-3">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="sup_logout">
    <button class="btn btn-link w-100 text-muted text-decoration-none">Salir del panel</button>
  </form>
</div>

<?php else: ?>
<!-- ══ LOGIN ═════════════════════════════════════════════════════════════════ -->
<div class="card login-card">
  <div class="text-center mb-3">
    <img src="public/logo.jpg" alt="El Castillo del Queso" class="brand-logo">
    <h4 class="brand-title">El Castillo del Queso</h4>
    <div class="brand-sub">Artesanal · Sistema ERP</div>
    <img src="public/aserp-logo.jpg" alt="ASERP — Admin System Enterprise Resource Planning" style="height:60px;width:auto;object-fit:contain;margin-top:10px">
  </div>
  <?php if ($bloqueado): ?>
  <div class="alert alert-danger text-center mb-3">
    <strong>Sistema bloqueado</strong><br>Contacte al soporte técnico.
  </div>
  <div class="text-center mb-3">
    <button type="button" id="btn-remoto" class="btn btn-outline-secondary w-100">Generar enlace de acceso remoto</button>
    <div id="remoto-status" class="text-muted mt-2" style="font-size:.74rem; line-height:1.35"></div>
    <div id="remoto-result" class="mt-2" style="display:none">
      <div class="input-group">
        <input type="text" id="remoto-url" class="form-control" readonly onclick="this.select()">
        <button type="button" id="btn-copiar" class="btn btn-primary">Copiar</button>
      </div>
      <div class="text-muted mt-1" style="font-size:.72rem; line-height:1.35">
        Comparta este enlace con el soporte técnico. Mantenga esta PC encendida mientras se usa.
      </div>
      <a href="#" id="btn-nuevo" class="small d-inline-block mt-1">Generar uno nuevo</a>
    </div>
  </div>
  <script>
  (function(){
    var btn=document.getElementById('btn-remoto'),
        st=document.getElementById('remoto-status'),
        res=document.getElementById('remoto-result'),
        inp=document.getElementById('remoto-url'),
        cop=document.getElementById('btn-copiar'),
        nue=document.getElementById('btn-nuevo');
    function gen(nuevo){
      btn.disabled=true; res.style.display='none';
      st.textContent='Generando enlace, espere unos segundos (la primera vez puede tardar más)...';
      fetch('?remoto='+(nuevo?'nuevo':'gen'),{cache:'no-store'})
        .then(function(r){return r.json();})
        .then(function(d){
          btn.disabled=false;
          if(d&&d.ok){ inp.value=d.url; res.style.display='block'; st.textContent=''; btn.style.display='none'; }
          else { st.textContent=(d&&d.error)?d.error:'No se pudo generar el enlace. Intente nuevamente.'; }
        })
        .catch(function(){ btn.disabled=false; st.textContent='No se pudo generar el enlace. Intente nuevamente.'; });
    }
    btn.addEventListener('click',function(){gen(false);});
    if(nue) nue.addEventListener('click',function(e){e.preventDefault();gen(true);});
    cop.addEventListener('click',function(){
      inp.select(); inp.setSelectionRange(0,99999);
      if(navigator.clipboard){ navigator.clipboard.writeText(inp.value).catch(function(){}); }
      else { try{document.execCommand('copy');}catch(e){} }
      cop.textContent='Copiado'; setTimeout(function(){cop.textContent='Copiar';},1500);
    });
  })();
  </script>
  <?php endif ?>
  <?php if ($error && $error !== 'Sistema bloqueado. Contacte al soporte técnico.'): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif ?>
  <form method="post">
    <div class="mb-3">
      <label class="form-label">Correo electrónico</label>
      <input type="text" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>" required autofocus autocomplete="username" maxlength="120">
    </div>
    <div class="mb-3">
      <label class="form-label">Contraseña</label>
      <input type="password" name="password" class="form-control" required autocomplete="current-password" maxlength="64">
    </div>
    <button class="btn btn-primary w-100">Ingresar</button>
  </form>
</div>
<?php endif ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Límite de caracteres en TODOS los campos (evita texto infinito) ────────────
(function(){
  var DEF_NUM = 12, DEF_TXT = 100;
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
  function capByName(el){
    var n = (el.getAttribute('name') || '').toLowerCase().replace(/\[.*$/, '');
    if (!n) return 0;
    for (var i = 0; i < NAME_CAPS.length; i++) if (NAME_CAPS[i][0].test(n)) return NAME_CAPS[i][1];
    return 0;
  }
  function capFor(el){
    var ml = parseInt(el.getAttribute('maxlength'), 10);
    if (!isNaN(ml) && ml > 0) return ml;
    var t = (el.type || '').toLowerCase();
    if (t === 'number') return DEF_NUM;
    if (el.tagName === 'TEXTAREA') return capByName(el) || 300;
    if (t === 'text' || t === 'search' || t === 'email' || t === 'tel' ||
        t === 'password' || t === 'url' || t === '') return capByName(el) || DEF_TXT;
    return 0;
  }
  function enforce(el){
    var cap = capFor(el);
    if (cap <= 0) return;
    if (typeof el.value === 'string' && el.value.length > cap) el.value = el.value.slice(0, cap);
  }
  function attach(el){
    if (el.dataset.capBound) return;
    var cap = capFor(el);
    if (cap <= 0) return;
    el.dataset.capBound = '1';
    var t = (el.type || '').toLowerCase();
    if (el.tagName === 'TEXTAREA' || ['text','search','email','tel','password','url',''].indexOf(t) !== -1) {
      if (!el.getAttribute('maxlength')) el.setAttribute('maxlength', cap);
    }
    el.addEventListener('input', function(){ enforce(el); });
    enforce(el);
  }
  document.querySelectorAll('input, textarea').forEach(attach);
})();
</script>
</body>
</html>
