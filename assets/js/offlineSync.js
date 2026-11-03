/**
 * ADARA ERP — Cola local de sincronización (modo offline).
 *
 * Carga: layout_end() en layout.php, en todas las páginas con sesión (antes de los scripts de módulo
 *        que lo usen, o consultando window.AdaraOfflineSync en tiempo de ejecución).
 * API global (script clásico, igual que el resto de assets/js):
 *   window.fetchConRespaldo(url, options)   → Promise<Response>
 *   window.AdaraOfflineSync.{fetchConRespaldo, procesarColaSync, pendientes, fallidos,
 *                            reintentarFallido, descartarFallido}
 * Evento DOM: document 'adara:sync' (detail = resumen de cada pasada del sincronizador).
 *
 * Contrato con el backend PHP (endpoints que se llamen con fetchConRespaldo):
 *   1. Responder JSON: json_response(200|201, ['ok' => true, ...]) en éxito y
 *      json_response(4xx, ['ok' => false, 'errores' => [...]]) en validación. Un 2xx con ok=false
 *      se trata como rechazo definitivo.
 *   2. index.php ya devuelve 401 {codigo:'sesion_expirada'} y 403 {codigo:'csrf_invalido'} cuando la
 *      petición trae Accept: application/json. Esos casos NO descartan la cola: quedan en espera
 *      hasta que el usuario recargue o vuelva a iniciar sesión.
 *   3. Cabeceras que envía cada petición encolable (original y reintentos):
 *        X-Idempotency-Key     UUID estable por transacción. El backend DEBE usarla para no duplicar
 *                              facturas cuando la petición original sí llegó pero la respuesta se perdió:
 *                              guardar (clave, usuario_id, respuesta) en una tabla con UNIQUE(clave) y, si
 *                              ya existe, devolver la respuesta guardada en lugar de volver a insertar.
 *        X-Adara-Offline-Replay  '1' solo en reintentos desde la cola.
 *        X-Adara-Capturado-En    ISO-8601 del momento en que el usuario guardó sin conexión; útil
 *                              para auditoría o para fechar el documento con la hora real de captura.
 *   4. CSRF: el token NO se guarda en la cola (rota en cada login). Se inyecta el vigente del
 *      <meta name="csrf-token"> al reenviar, tanto en X-CSRF-TOKEN como en el campo _csrf del cuerpo.
 *   5. Usuario: cada entrada se asocia al <meta name="adara-user"> y solo se reenvía en una sesión
 *      del mismo usuario (equipos compartidos).
 *
 * Límites: localStorage (~5 MB por origen) y solo cuerpos serializables: JSON, texto, URLSearchParams
 * y FormData sin archivos. Un FormData con File/Blob no se encola y se informa al usuario.
 */
(function (global) {
  'use strict';

  var CLAVE_COLA = 'adara_sync_queue';
  var CLAVE_FALLIDOS = 'adara_sync_failed';
  var METODOS_ENCOLABLES = { POST: true, PUT: true, PATCH: true, DELETE: true };
  var MAX_INTENTOS_SERVIDOR = 8;
  var INTERVALO_REINTENTO_MS = 30000;
  var MENSAJE_OFFLINE = 'Sin conexión: Datos guardados localmente. Se sincronizarán automáticamente al regresar el internet.';

  /* =====================================================================
   * 1. Almacenamiento
   * ===================================================================== */

  function leer(clave) {
    try {
      var datos = JSON.parse(global.localStorage.getItem(clave) || '[]');
      return Array.isArray(datos) ? datos : [];
    } catch (e) {
      return [];
    }
  }

  /** Lanza QuotaExceededError si no hay espacio; el llamador decide cómo informarlo. */
  function escribir(clave, lista) {
    global.localStorage.setItem(clave, JSON.stringify(lista));
  }

  /** Lectura-modificación-escritura en un solo bloque síncrono para no pisar cambios de otras pestañas. */
  function mutarCola(fn) {
    var cola = leer(CLAVE_COLA);
    var nueva = fn(cola);
    escribir(CLAVE_COLA, nueva);
    return nueva;
  }

  function quitarDeCola(id) {
    mutarCola(function (cola) { return cola.filter(function (e) { return e.id !== id; }); });
  }

  function existeEnCola(id) {
    return leer(CLAVE_COLA).some(function (e) { return e.id === id; });
  }

  function moverAFallidos(entrada, motivo, status) {
    var fallidos = leer(CLAVE_FALLIDOS);
    fallidos.push(Object.assign({}, entrada, { motivo: motivo, status: status || 0, fallidoEn: new Date().toISOString() }));
    escribir(CLAVE_FALLIDOS, fallidos);
    quitarDeCola(entrada.id);
  }

  /* =====================================================================
   * 2. Contexto de la página
   * ===================================================================== */

  function meta(nombre) {
    var el = document.querySelector('meta[name="' + nombre + '"]');
    return el && el.content ? el.content : '';
  }

  function csrfToken() { return meta('csrf-token'); }
  function usuarioActual() { return meta('adara-user'); }

  /** crypto.randomUUID solo existe en contexto seguro; getRandomValues funciona también en http LAN. */
  function uuid() {
    if (global.crypto && typeof global.crypto.randomUUID === 'function') {
      try { return global.crypto.randomUUID(); } catch (e) { /* contexto no seguro */ }
    }
    var b = global.crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return (x + 0x100).toString(16).slice(1); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  function esObjetoPlano(v) {
    return v !== null && typeof v === 'object' && Object.getPrototypeOf(v) === Object.prototype;
  }

  /* =====================================================================
   * 3. Serialización del cuerpo
   * ===================================================================== */

  function NoEncolableError(mensaje) {
    var e = new Error(mensaje);
    e.name = 'NoEncolableError';
    return e;
  }

  /** @returns {{tipo: 'vacio'|'texto'|'urlencoded'|'formdata', datos?: *}} */
  function serializarCuerpo(body) {
    if (body == null) return { tipo: 'vacio' };
    if (typeof body === 'string') return { tipo: 'texto', datos: body };
    if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
      return { tipo: 'urlencoded', datos: body.toString() };
    }
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      var pares = [];
      body.forEach(function (valor, clave) {
        if (typeof valor !== 'string') {
          throw NoEncolableError('El formulario incluye archivos adjuntos y no puede guardarse sin conexión.');
        }
        pares.push([clave, valor]);
      });
      return { tipo: 'formdata', datos: pares };
    }
    throw NoEncolableError('Este tipo de envío no puede guardarse sin conexión.');
  }

  function deserializarCuerpo(cuerpo, token) {
    switch (cuerpo.tipo) {
      case 'texto':
        return reemplazarCsrfEnJson(cuerpo.datos, token);
      case 'urlencoded':
        var params = new URLSearchParams(cuerpo.datos);
        if (params.has('_csrf')) params.set('_csrf', token);
        return params;
      case 'formdata':
        var fd = new FormData();
        cuerpo.datos.forEach(function (par) {
          fd.append(par[0], par[0] === '_csrf' ? token : par[1]);
        });
        return fd;
      default:
        return undefined;
    }
  }

  function reemplazarCsrfEnJson(texto, token) {
    try {
      var obj = JSON.parse(texto);
      if (esObjetoPlano(obj) && Object.prototype.hasOwnProperty.call(obj, '_csrf')) {
        obj._csrf = token;
        return JSON.stringify(obj);
      }
    } catch (e) { /* no es JSON: se envía tal cual */ }
    return texto;
  }

  /** Cabeceras persistibles: sin CSRF (rota) ni Content-Type de FormData (el navegador genera el boundary). */
  function cabecerasPersistibles(headers, tipoCuerpo) {
    var salida = {};
    headers.forEach(function (valor, clave) {
      if (clave === 'x-csrf-token') return;
      if (clave === 'content-type' && tipoCuerpo === 'formdata') return;
      salida[clave] = valor;
    });
    return salida;
  }

  /* =====================================================================
   * 4. fetchConRespaldo
   * ===================================================================== */

  /**
   * Reemplazo de fetch para envíos (POST/PUT/PATCH/DELETE). GET y demás métodos pasan directo a fetch.
   *
   * - Con red: igual que fetch (devuelve la Response real del servidor, incluidos 4xx/5xx).
   * - Sin red (navigator.onLine === false o fetch lanza TypeError de red): encola y resuelve con una
   *   Response sintética 202 {ok: true, offline: true, encolado: true, id} + cabecera X-Adara-Offline: 1.
   *   El llamador debe tratar el 202 como "pendiente" (no hay ID de factura del servidor todavía).
   * - Si no se puede encolar (archivos, cuota llena) rechaza con Error name='NoEncolableError'.
   *
   * @param {string} url
   * @param {RequestInit & {body?: *, timeoutMs?: number, etiqueta?: string}} [options]
   *        body: objeto plano → JSON automáticamente.
   *        timeoutMs: aborta la petición original y la encola si el servidor no responde a tiempo.
   *        etiqueta: texto corto para mostrar al usuario en la lista de pendientes ("Factura 0012").
   * @returns {Promise<Response>}
   */
  async function fetchConRespaldo(url, options) {
    options = options || {};
    var metodo = String(options.method || 'GET').toUpperCase();
    if (!METODOS_ENCOLABLES[metodo]) {
      return fetch(url, options);
    }

    var id = uuid();
    var headers = new Headers(options.headers || {});
    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
    headers.set('X-Idempotency-Key', id);
    var token = csrfToken();
    if (token && !headers.has('X-CSRF-TOKEN')) headers.set('X-CSRF-TOKEN', token);

    var body = options.body;
    if (esObjetoPlano(body) || Array.isArray(body)) {
      body = JSON.stringify(body);
      if (!headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
    }

    var contexto = { id: id, url: url, metodo: metodo, headers: headers, body: body, etiqueta: options.etiqueta };

    if (global.navigator.onLine === false) {
      return encolar(contexto);
    }

    var controlador = new AbortController();
    var porTimeout = false;
    var timer = null;
    if (options.signal) {
      if (options.signal.aborted) controlador.abort();
      else options.signal.addEventListener('abort', function () { controlador.abort(); }, { once: true });
    }
    if (options.timeoutMs > 0) {
      timer = setTimeout(function () { porTimeout = true; controlador.abort(); }, options.timeoutMs);
    }

    var init = Object.assign({}, options, {
      method: metodo,
      headers: headers,
      body: body,
      credentials: options.credentials || 'same-origin',
      signal: controlador.signal
    });
    delete init.timeoutMs;
    delete init.etiqueta;

    try {
      return await fetch(url, init);
    } catch (e) {
      if (e && e.name === 'AbortError' && !porTimeout) throw e;
      return encolar(contexto);
    } finally {
      if (timer) clearTimeout(timer);
    }
  }

  function encolar(ctx) {
    var destino = new URL(ctx.url, global.location.href);
    if (destino.origin !== global.location.origin) {
      return Promise.reject(NoEncolableError('Solo se pueden guardar sin conexión envíos al servidor de ADARA.'));
    }

    var entrada;
    try {
      var cuerpo = serializarCuerpo(ctx.body);
      entrada = {
        id: ctx.id,
        url: destino.href,
        metodo: ctx.metodo,
        headers: cabecerasPersistibles(ctx.headers, cuerpo.tipo),
        cuerpo: cuerpo,
        usuario: usuarioActual(),
        etiqueta: ctx.etiqueta || '',
        creado: new Date().toISOString(),
        intentos: 0,
        ultimoError: ''
      };
      mutarCola(function (cola) { cola.push(entrada); return cola; });
    } catch (e) {
      var err = e && e.name === 'NoEncolableError'
        ? e
        : NoEncolableError('No hay espacio en el almacenamiento local para guardar este registro sin conexión.');
      notificar('error', err.message);
      return Promise.reject(err);
    }

    notificar('offline', MENSAJE_OFFLINE);

    return Promise.resolve(new Response(JSON.stringify({
      ok: true,
      offline: true,
      encolado: true,
      id: entrada.id,
      mensaje: MENSAJE_OFFLINE
    }), {
      status: 202,
      headers: { 'Content-Type': 'application/json', 'X-Adara-Offline': '1' }
    }));
  }

  /* =====================================================================
   * 5. Sincronizador
   * ===================================================================== */

  var enCursoLocal = false;
  var detenidoPorSesion = false;

  /**
   * Reenvía la cola en orden FIFO. Se detiene en el primer fallo de red, error 5xx o sesión inválida
   * para respetar dependencias (p. ej. un abono capturado después de su factura).
   * Varias pestañas: navigator.locks garantiza una sola pasada a la vez por origen; además cada
   * entrada se vuelve a comprobar en localStorage antes de enviarla.
   */
  async function procesarColaSync() {
    if (global.navigator.onLine === false) return null;
    if (global.navigator.locks && typeof global.navigator.locks.request === 'function') {
      return global.navigator.locks.request('adara_sync_lock', { ifAvailable: true }, function (lock) {
        return lock ? ejecutarPasada() : null;
      });
    }
    if (enCursoLocal) return null;
    enCursoLocal = true;
    try {
      return await ejecutarPasada();
    } finally {
      enCursoLocal = false;
    }
  }

  async function ejecutarPasada() {
    var usuario = usuarioActual();
    if (!usuario) return null;

    var pendientes = leer(CLAVE_COLA).filter(function (e) { return e.usuario === usuario; });
    var resumen = { enviados: 0, rechazados: 0, pendientes: pendientes.length, detenido: '' };
    if (pendientes.length === 0) {
      actualizarBanner();
      return resumen;
    }

    detenidoPorSesion = false;
    mostrarBanner('sincronizando', 'Sincronizando ' + pendientes.length + ' registro(s) guardado(s) sin conexión…');

    for (var i = 0; i < pendientes.length; i++) {
      var entrada = pendientes[i];
      if (!existeEnCola(entrada.id)) continue;

      var r = await reenviar(entrada);
      if (r.estado === 'ok') {
        quitarDeCola(entrada.id);
        resumen.enviados++;
        continue;
      }
      if (r.estado === 'rechazado') {
        moverAFallidos(entrada, r.mensaje, r.status);
        resumen.rechazados++;
        continue;
      }
      if (r.estado === 'reintentar') {
        var intentos = (entrada.intentos || 0) + 1;
        if (intentos >= MAX_INTENTOS_SERVIDOR) {
          moverAFallidos(entrada, r.mensaje, r.status);
          resumen.rechazados++;
          continue;
        }
        mutarCola(function (cola) {
          return cola.map(function (e) {
            return e.id === entrada.id ? Object.assign({}, e, { intentos: intentos, ultimoError: r.mensaje }) : e;
          });
        });
      }
      if (r.estado === 'sesion') detenidoPorSesion = true;
      resumen.detenido = r.estado;
      break;
    }

    resumen.pendientes = leer(CLAVE_COLA).filter(function (e) { return e.usuario === usuario; }).length;
    document.dispatchEvent(new CustomEvent('adara:sync', { detail: resumen }));

    if (resumen.enviados > 0 && resumen.pendientes === 0 && resumen.rechazados === 0) {
      notificar('ok', 'Conexión restablecida: ' + resumen.enviados + ' registro(s) sincronizado(s) con el servidor.');
    } else if (resumen.rechazados > 0) {
      notificar('error', resumen.rechazados + ' registro(s) guardado(s) sin conexión fueron rechazados por el servidor. Revísalos antes de volver a capturarlos.');
    }
    actualizarBanner();
    return resumen;
  }

  /** @returns {Promise<{estado: 'ok'|'rechazado'|'reintentar'|'red'|'sesion', mensaje?: string, status?: number}>} */
  async function reenviar(entrada) {
    var token = csrfToken();
    var headers = new Headers(entrada.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('X-Idempotency-Key', entrada.id);
    headers.set('X-Adara-Offline-Replay', '1');
    headers.set('X-Adara-Capturado-En', entrada.creado);
    if (token) headers.set('X-CSRF-TOKEN', token);

    var res;
    try {
      res = await fetch(entrada.url, {
        method: entrada.metodo,
        headers: headers,
        body: deserializarCuerpo(entrada.cuerpo, token),
        credentials: 'same-origin',
        cache: 'no-store'
      });
    } catch (e) {
      return { estado: 'red', mensaje: 'Sin conexión con el servidor.' };
    }

    // Un redirect a HTML (p. ej. al login) termina en 200: no es un éxito real.
    if (res.redirected) {
      return { estado: 'sesion', mensaje: 'El servidor redirigió la petición; inicia sesión de nuevo.', status: res.status };
    }

    var json = null;
    if ((res.headers.get('Content-Type') || '').indexOf('application/json') !== -1) {
      try { json = await res.json(); } catch (e) { json = null; }
    }
    var mensaje = mensajeServidor(json, res.status);

    if (res.status === 401 || (json && json.codigo === 'csrf_invalido')) {
      return { estado: 'sesion', mensaje: mensaje, status: res.status };
    }
    if (res.ok) {
      return json && json.ok === false
        ? { estado: 'rechazado', mensaje: mensaje, status: res.status }
        : { estado: 'ok', status: res.status };
    }
    if (res.status === 408 || res.status === 429 || res.status >= 500) {
      return { estado: 'reintentar', mensaje: mensaje, status: res.status };
    }
    return { estado: 'rechazado', mensaje: mensaje, status: res.status };
  }

  function mensajeServidor(json, status) {
    if (json && json.errores && typeof json.errores === 'object') {
      var textos = Object.keys(json.errores).map(function (k) { return json.errores[k]; }).filter(Boolean);
      if (textos.length) return textos.join(' ');
    }
    if (json && json.mensaje) return String(json.mensaje);
    return 'Respuesta HTTP ' + status + ' del servidor.';
  }

  /* =====================================================================
   * 6. Interfaz: banner flotante + SweetAlert2 si la página lo cargó
   * ===================================================================== */

  var banner = null;
  var ocultarTimer = null;

  function obtenerBanner() {
    if (banner) return banner;
    banner = document.createElement('div');
    banner.id = 'adaraSyncBanner';
    banner.className = 'adara-sync-banner';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    banner.hidden = true;

    var icono = document.createElement('i');
    icono.className = 'bi adara-sync-icono';
    var texto = document.createElement('span');
    texto.className = 'adara-sync-texto';
    var boton = document.createElement('button');
    boton.type = 'button';
    boton.className = 'btn btn-sm btn-light adara-sync-accion';
    boton.hidden = true;
    boton.addEventListener('click', function () {
      if (detenidoPorSesion) global.location.reload();
      else procesarColaSync();
    });

    banner.appendChild(icono);
    banner.appendChild(texto);
    banner.appendChild(boton);
    document.body.appendChild(banner);
    return banner;
  }

  var ICONOS = {
    offline: 'bi-wifi-off',
    sincronizando: 'bi-arrow-repeat',
    ok: 'bi-check-circle-fill',
    error: 'bi-exclamation-triangle-fill',
    sesion: 'bi-shield-lock-fill'
  };

  /** @param {'offline'|'sincronizando'|'ok'|'error'|'sesion'} estado */
  function mostrarBanner(estado, mensaje, accion) {
    if (!document.body) return;
    var el = obtenerBanner();
    clearTimeout(ocultarTimer);
    el.className = 'adara-sync-banner adara-sync-' + estado;
    el.querySelector('.adara-sync-icono').className = 'bi adara-sync-icono ' + (ICONOS[estado] || 'bi-info-circle');
    el.querySelector('.adara-sync-texto').textContent = mensaje;
    var boton = el.querySelector('.adara-sync-accion');
    boton.hidden = !accion;
    boton.textContent = accion || '';
    el.hidden = false;
    if (estado === 'ok') {
      ocultarTimer = setTimeout(function () { el.hidden = true; }, 5000);
    }
  }

  function ocultarBanner() {
    if (banner) banner.hidden = true;
  }

  /** Estado persistente según la cola; se llama tras cada cambio y al cargar la página. */
  function actualizarBanner() {
    var usuario = usuarioActual();
    if (!usuario) return;
    var n = pendientes().length;
    var nFallidos = fallidos().length;

    if (global.navigator.onLine === false) {
      mostrarBanner('offline', n > 0
        ? 'Sin conexión: ' + n + ' registro(s) guardado(s) localmente. Se sincronizarán al regresar el internet.'
        : 'Sin conexión: trabajando en modo local.');
      return;
    }
    if (n > 0 && detenidoPorSesion) {
      mostrarBanner('sesion', n + ' registro(s) pendiente(s): recarga la página o inicia sesión de nuevo para sincronizarlos.', 'Recargar');
      return;
    }
    if (n > 0) {
      mostrarBanner('offline', n + ' registro(s) pendiente(s) de sincronizar.', 'Reintentar ahora');
      return;
    }
    if (nFallidos > 0) {
      mostrarBanner('error', nFallidos + ' registro(s) sin conexión fueron rechazados por el servidor (ver AdaraOfflineSync.fallidos()).');
      return;
    }
    if (banner && !banner.classList.contains('adara-sync-ok')) ocultarBanner();
  }

  function notificar(estado, mensaje) {
    if (global.Swal && typeof global.Swal.fire === 'function') {
      global.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: estado === 'ok' ? 'success' : estado === 'error' ? 'error' : 'warning',
        title: estado === 'offline' ? 'Sin conexión' : estado === 'ok' ? 'Sincronizado' : 'Atención',
        text: mensaje,
        timer: 6000,
        timerProgressBar: true,
        showConfirmButton: false
      });
    }
    mostrarBanner(estado, mensaje);
  }

  /* =====================================================================
   * 7. Consultas y gestión manual
   * ===================================================================== */

  function pendientes() {
    var usuario = usuarioActual();
    return leer(CLAVE_COLA).filter(function (e) { return e.usuario === usuario; });
  }

  function fallidos() {
    var usuario = usuarioActual();
    return leer(CLAVE_FALLIDOS).filter(function (e) { return e.usuario === usuario; });
  }

  /** Devuelve una entrada rechazada a la cola (p. ej. tras corregir un catálogo en el servidor). */
  function reintentarFallido(id) {
    var lista = leer(CLAVE_FALLIDOS);
    var entrada = lista.find(function (e) { return e.id === id; });
    if (!entrada) return false;
    escribir(CLAVE_FALLIDOS, lista.filter(function (e) { return e.id !== id; }));
    var limpia = Object.assign({}, entrada, { intentos: 0, ultimoError: '' });
    delete limpia.motivo;
    delete limpia.status;
    delete limpia.fallidoEn;
    mutarCola(function (cola) { cola.push(limpia); return cola; });
    actualizarBanner();
    return true;
  }

  function descartarFallido(id) {
    escribir(CLAVE_FALLIDOS, leer(CLAVE_FALLIDOS).filter(function (e) { return e.id !== id; }));
    actualizarBanner();
  }

  /* =====================================================================
   * 8. Disparadores
   * ===================================================================== */

  global.addEventListener('online', procesarColaSync);
  global.addEventListener('offline', actualizarBanner);

  // 'online' no se dispara si el internet nunca cayó pero el servidor sí; el sondeo cubre ese caso
  // y los reintentos tras un 5xx. Con la sesión inválida no se insiste: cada intento con un token CSRF
  // vencido lo rota otra vez en el servidor y genera una entrada de auditoría.
  function hayQueSondear() {
    return global.navigator.onLine !== false && !detenidoPorSesion && pendientes().length > 0;
  }
  setInterval(function () {
    if (hayQueSondear()) procesarColaSync();
  }, INTERVALO_REINTENTO_MS);

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && hayQueSondear()) procesarColaSync();
  });

  global.addEventListener('storage', function (e) {
    if (e.key === CLAVE_COLA || e.key === CLAVE_FALLIDOS) actualizarBanner();
  });

  function alCargar() {
    actualizarBanner();
    if (pendientes().length > 0) procesarColaSync();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', alCargar);
  else alCargar();

  /* =====================================================================
   * 9. Exportación
   * ===================================================================== */

  global.fetchConRespaldo = fetchConRespaldo;
  global.AdaraOfflineSync = {
    fetchConRespaldo: fetchConRespaldo,
    procesarColaSync: procesarColaSync,
    pendientes: pendientes,
    fallidos: fallidos,
    reintentarFallido: reintentarFallido,
    descartarFallido: descartarFallido
  };
})(window);
