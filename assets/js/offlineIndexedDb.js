/**
 * ADARA — cola offline en IndexedDB.
 *
 * Formularios POST nativos: si no hay red, se cancela el envío del navegador y el
 * cuerpo queda en IndexedDB. Los fetch POST/PUT/PATCH/DELETE del mismo origen
 * (facturas, gas, transporte, etc.) siguen el mismo camino y reciben un 202
 * {ok:true, offline:true} para que la pantalla no los trate como error de red.
 *
 * Al volver la conexión, window 'online' reenvía la cola en orden y borra cada
 * entrada solo cuando el servidor responde 2xx.
 */
(function (global) {
  'use strict';

  var DB = 'adara_offline';
  var STORE = 'pendientes';
  var MENSAJE = 'Sin conexión: datos guardados en este equipo. Se enviarán solos al volver el internet.';
  var reenviando = false;

  function meta(nombre) {
    var el = document.querySelector('meta[name="' + nombre + '"]');
    return el && el.content ? el.content : '';
  }

  function uuid() {
    if (global.crypto && typeof global.crypto.randomUUID === 'function') {
      try { return global.crypto.randomUUID(); } catch (e) { /* http sin contexto seguro */ }
    }
    var b = global.crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return (x + 0x100).toString(16).slice(1); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  function abrir() {
    return new Promise(function (resolve, reject) {
      if (!global.indexedDB) {
        reject(new Error('IndexedDB no está disponible'));
        return;
      }
      var req = global.indexedDB.open(DB, 1);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE, { keyPath: 'id' });
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }

  function listar() {
    return abrir().then(function (db) {
      return new Promise(function (resolve, reject) {
        var req = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
        req.onsuccess = function () { resolve(req.result || []); };
        req.onerror = function () { reject(req.error); };
      });
    });
  }

  function guardar(entrada) {
    return abrir().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readwrite');
        tx.oncomplete = function () { resolve(entrada.id); };
        tx.onerror = function () { reject(tx.error); };
        tx.objectStore(STORE).put(entrada);
      });
    });
  }

  function borrar(id) {
    return abrir().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readwrite');
        tx.oncomplete = function () { resolve(); };
        tx.onerror = function () { reject(tx.error); };
        tx.objectStore(STORE).delete(id);
      });
    });
  }

  function aviso(texto) {
    var caja = document.getElementById('adara-offline-aviso');
    if (!caja) {
      caja = document.createElement('div');
      caja.id = 'adara-offline-aviso';
      caja.setAttribute('role', 'status');
      caja.style.cssText = 'position:fixed;left:1rem;right:1rem;bottom:1rem;z-index:1080;background:#0b1f33;color:#fff;border-radius:.75rem;padding:.75rem 1rem;box-shadow:0 8px 24px rgba(0,0,0,.25);font-size:.92rem;';
      document.body.appendChild(caja);
    }
    caja.textContent = texto;
  }

  function esSecreto(nombre) {
    return /password|passwd|clave/i.test(nombre || '');
  }

  function paresDeFormData(fd) {
    var pares = [];
    var archivo = false;
    fd.forEach(function (valor, clave) {
      if (typeof valor !== 'string') archivo = true;
      else pares.push([clave, valor]);
    });
    return { pares: pares, archivo: archivo, secreto: pares.some(function (p) { return esSecreto(p[0]); }) };
  }

  function entradaBase(url, metodo) {
    return {
      id: uuid(),
      url: url,
      metodo: metodo,
      usuario: meta('adara-user'),
      creado: new Date().toISOString(),
      intentos: 0
    };
  }

  function encolar(entrada) {
    return guardar(entrada).then(function () {
      aviso(MENSAJE);
      return entrada.id;
    });
  }

  function respuestaOffline() {
    return new Response(JSON.stringify({
      ok: true,
      offline: true,
      encolado: true,
      mensaje: MENSAJE
    }), {
      status: 202,
      headers: { 'Content-Type': 'application/json', 'X-Adara-Offline': '1' }
    });
  }

  function mismoOrigen(url) {
    try {
      return new URL(url, global.location.href).origin === global.location.origin;
    } catch (e) {
      return false;
    }
  }

  var fetchOriginal = global.fetch.bind(global);
  global.fetch = function (input, init) {
    init = init || {};
    var metodo = String(init.method || (input && input.method) || 'GET').toUpperCase();
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (reenviando || global.navigator.onLine !== false || !/^(POST|PUT|PATCH|DELETE)$/.test(metodo) || !mismoOrigen(url)) {
      return fetchOriginal(input, init);
    }

    var body = init.body;
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      var fd = paresDeFormData(body);
      if (fd.archivo || fd.secreto) return fetchOriginal(input, init);
      var itemFd = entradaBase(new URL(url, global.location.href).href, metodo);
      itemFd.tipo = 'form';
      itemFd.campos = fd.pares;
      return encolar(itemFd).then(respuestaOffline, function () { return fetchOriginal(input, init); });
    }
    if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
      var secretoQs = false;
      body.forEach(function (_valor, clave) { if (esSecreto(clave)) secretoQs = true; });
      if (secretoQs) return fetchOriginal(input, init);
      var itemQs = entradaBase(new URL(url, global.location.href).href, metodo);
      itemQs.tipo = 'urlencoded';
      itemQs.cuerpo = body.toString();
      return encolar(itemQs).then(respuestaOffline, function () { return fetchOriginal(input, init); });
    }
    if (typeof body === 'string') {
      if (/"password"|"passwd"|"clave"\s*:/i.test(body)) return fetchOriginal(input, init);
      var itemTxt = entradaBase(new URL(url, global.location.href).href, metodo);
      itemTxt.tipo = 'texto';
      itemTxt.cuerpo = body;
      itemTxt.contentType = (init.headers && (init.headers['Content-Type'] || init.headers['content-type'])) || 'application/json';
      return encolar(itemTxt).then(respuestaOffline, function () { return fetchOriginal(input, init); });
    }
    return fetchOriginal(input, init);
  };

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (global.navigator.onLine !== false) return;
    if (ev.defaultPrevented) return;
    var metodo = (form.getAttribute('method') || 'get').toUpperCase();
    if (metodo === 'GET') return;
    if (form.querySelector('input[type="password"]')) return;

    var accion = form.getAttribute('action') || global.location.href;
    if (!mismoOrigen(accion)) return;
    var destino = new URL(accion, global.location.href);
    if (/[?&]p=(login|logout|olvide_clave|nueva_clave)(?:&|$)/.test(destino.search)) return;

    var leido = paresDeFormData(new FormData(form));
    if (leido.secreto) return;
    if (leido.archivo) {
      ev.preventDefault();
      aviso('Este formulario lleva archivos y no se puede guardar sin conexión.');
      return;
    }

    ev.preventDefault();
    var item = entradaBase(destino.href, metodo);
    item.tipo = 'form';
    item.campos = leido.pares;
    encolar(item).catch(function () {
      aviso('No se pudo guardar el formulario en este equipo.');
    });
  });

  function cuerpoReenvio(entrada, token) {
    if (entrada.tipo === 'texto') {
      try {
        var obj = JSON.parse(entrada.cuerpo);
        if (obj && typeof obj === 'object' && Object.prototype.hasOwnProperty.call(obj, '_csrf')) obj._csrf = token;
        return { body: JSON.stringify(obj), contentType: entrada.contentType || 'application/json' };
      } catch (e) {
        return { body: entrada.cuerpo, contentType: entrada.contentType || 'text/plain' };
      }
    }
    if (entrada.tipo === 'urlencoded') {
      var params = new URLSearchParams(entrada.cuerpo || '');
      if (params.has('_csrf')) params.set('_csrf', token);
      return { body: params, contentType: null };
    }
    var fd = new FormData();
    (entrada.campos || []).forEach(function (par) {
      fd.append(par[0], par[0] === '_csrf' ? token : par[1]);
    });
    return { body: fd, contentType: null };
  }

  function drenar() {
    if (reenviando || global.navigator.onLine === false) return Promise.resolve();
    var usuario = meta('adara-user');
    reenviando = true;
    return listar().then(function (lista) {
      var pendientes = (lista || []).filter(function (e) { return !usuario || String(e.usuario || '') === String(usuario); });
      pendientes.sort(function (a, b) { return String(a.creado).localeCompare(String(b.creado)); });
      var cadena = Promise.resolve();
      pendientes.forEach(function (entrada) {
        cadena = cadena.then(function () {
          if (global.navigator.onLine === false) return;
          var token = meta('csrf-token');
          var envio = cuerpoReenvio(entrada, token);
          var headers = { Accept: 'application/json', 'X-Idempotency-Key': entrada.id, 'X-Adara-Offline-Replay': '1' };
          if (token) headers['X-CSRF-TOKEN'] = token;
          if (envio.contentType) headers['Content-Type'] = envio.contentType;
          return fetchOriginal(entrada.url, {
            method: entrada.metodo,
            body: envio.body,
            headers: headers,
            credentials: 'same-origin'
          }).then(function (res) {
            if (res.status === 401 || res.status === 403 || res.status === 419) {
              aviso('Hay datos guardados sin conexión. Vuelve a entrar para enviarlos.');
              throw new Error('sesion');
            }
            if (res.ok) return borrar(entrada.id);
            if (res.status >= 400 && res.status < 500) {
              return borrar(entrada.id).then(function () {
                aviso('Un registro guardado sin conexión fue rechazado por el servidor (' + res.status + ').');
              });
            }
            throw new Error('servidor');
          });
        });
      });
      return cadena;
    }).catch(function (err) {
      if (!err || err.message === 'sesion' || err.message === 'servidor') return;
    }).then(function () {
      reenviando = false;
      return listar().then(function (lista) {
        var n = (lista || []).length;
        if (n > 0 && global.navigator.onLine) aviso(n + ' registro(s) siguen pendientes de enviar.');
      }, function () {});
    });
  }

  global.addEventListener('online', function () { drenar(); });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { if (global.navigator.onLine) drenar(); });
  } else if (global.navigator.onLine) {
    drenar();
  }

  global.AdaraIndexedDb = { drenar: drenar, pendientes: listar };
})(window);
