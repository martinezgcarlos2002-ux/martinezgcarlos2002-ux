/**
 * Catálogo y publicidad — administración.
 * Red: adaraFetch (JSON) y adaraUpload (multipart para fotos).
 */
(function () {
  'use strict';

  class AdaraHttpError extends Error {
    constructor(status, payload) {
      var errores = (payload && payload.errores) || {};
      var msg = Object.keys(errores).map(function (k) { return errores[k]; }).filter(Boolean).join(' ')
        || (payload && payload.mensaje) || 'No se pudo completar la operación.';
      super(msg);
      this.name = 'AdaraHttpError';
      this.status = status;
      this.errores = errores;
    }
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return (meta && meta.content) || '';
  }

  async function adaraFetch(url, options) {
    options = options || {};
    var headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('Content-Type', 'application/json');
    var token = csrfToken();
    if (token) headers.set('X-CSRF-TOKEN', token);
    var res = await fetch(url, {
      method: options.method || 'GET',
      headers: headers,
      body: options.body != null ? JSON.stringify(options.body) : undefined,
      credentials: 'same-origin'
    });
    var json;
    try { json = JSON.parse(await res.text()); } catch (e) { json = { ok: false, errores: { general: 'Respuesta inválida.' } }; }
    if (!res.ok || json.ok !== true) throw new AdaraHttpError(res.status, json);
    return json;
  }

  async function adaraUpload(url, formData) {
    var headers = new Headers();
    headers.set('Accept', 'application/json');
    var token = csrfToken();
    if (token) headers.set('X-CSRF-TOKEN', token);
    var res = await fetch(url, { method: 'POST', headers: headers, body: formData, credentials: 'same-origin' });
    var json;
    try { json = JSON.parse(await res.text()); } catch (e) { json = { ok: false, errores: { general: 'Respuesta inválida.' } }; }
    if (!res.ok || json.ok !== true) throw new AdaraHttpError(res.status, json);
    return json;
  }

  function $(sel, root) { return (root || document).querySelector(sel); }
  function money(n) {
    return '$' + (Math.round(Number(n || 0) * 100) / 100).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function h(tag, attrs, hijos) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') el.textContent = attrs[k];
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), attrs[k]);
      else el.setAttribute(k, attrs[k]);
    });
    (hijos || []).forEach(function (c) {
      if (c != null) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }

  var app = document.getElementById('catApp');
  if (!app) return;
  var ep = JSON.parse(app.dataset.endpoints);
  var puede = app.dataset.editar === '1';
  var productos = JSON.parse(document.getElementById('catDatos').textContent);

  function toast(msg, ok) {
    var t = h('div', { class: 'toast align-items-center border-0 text-bg-' + (ok ? 'success' : 'danger'), role: 'alert' }, [
      h('div', { class: 'd-flex' }, [
        h('div', { class: 'toast-body', text: msg }),
        h('button', { type: 'button', class: 'btn-close btn-close-white me-2 m-auto', 'data-bs-dismiss': 'toast', 'aria-label': 'Cerrar' })
      ])
    ]);
    $('#catToasts').appendChild(t);
    bootstrap.Toast.getOrCreateInstance(t, { delay: 4000 }).show();
    t.addEventListener('hidden.bs.toast', function () { t.remove(); });
  }

  function upsert(p) {
    var i = productos.findIndex(function (x) { return String(x.id) === String(p.id); });
    if (i >= 0) productos[i] = p; else productos.push(p);
  }

  function render() {
    var tb = $('#tbCatalogo');
    tb.textContent = '';
    productos.forEach(function (p) {
      var precio = h('input', {
        class: 'form-control form-control-sm cat-precio text-end',
        inputmode: 'decimal',
        value: Number(p.precio_usd).toFixed(2),
        'aria-label': 'Precio detal de ' + p.nombre
      });
      if (!puede) precio.disabled = true;
      precio.addEventListener('change', function () { guardarPrecio(p.id, precio.value); });

      var file = h('input', { type: 'file', class: 'form-control form-control-sm cat-file', accept: 'image/jpeg,image/png,image/webp' });
      if (!puede) file.disabled = true;
      file.addEventListener('change', function () {
        if (file.files && file.files[0]) subirFoto(p.id, file.files[0]);
      });

      var sw = h('input', { class: 'form-check-input', type: 'checkbox', role: 'switch', 'aria-label': 'Mostrar ' + p.nombre + ' en pantalla' });
      sw.checked = !!p.mostrar_pantalla;
      if (!puede) sw.disabled = true;
      sw.addEventListener('change', function () { toggleTv(p.id); });

      tb.appendChild(h('tr', { 'data-id': String(p.id) }, [
        h('td', {}, [
          p.foto_url
            ? h('img', { src: p.foto_url, alt: '', class: 'cat-thumb' })
            : h('div', { class: 'cat-thumb-vacio', text: 'Sin foto' })
        ]),
        h('td', { class: 'fw-semibold', text: p.nombre }),
        h('td', { class: 'text-end text-muted', text: money(p.precio_produccion) }),
        h('td', {}, [precio]),
        h('td', {}, [file]),
        h('td', { class: 'text-center' }, [h('div', { class: 'form-check form-switch d-flex justify-content-center' }, [sw])])
      ]));
    });
    if (!productos.length) {
      tb.appendChild(h('tr', {}, [h('td', { colspan: '6', class: 'text-center text-muted py-4', text: 'No hay productos activos en Producción / Inventario.' })]));
    }
  }

  async function guardarPrecio(id, valor) {
    try {
      var j = await adaraFetch(ep.precio, { method: 'POST', body: { producto_id: id, precio: valor } });
      upsert(j.producto);
      render();
      toast(j.mensaje || 'Precio guardado.', true);
    } catch (e) {
      toast(e.message || 'No se pudo guardar el precio.', false);
    }
  }

  async function toggleTv(id) {
    try {
      var j = await adaraFetch(ep.pantalla, { method: 'POST', body: { producto_id: id } });
      upsert(j.producto);
      render();
      toast(j.mensaje || 'Pantalla actualizada.', true);
    } catch (e) {
      toast(e.message || 'No se pudo cambiar la visibilidad.', false);
      render();
    }
  }

  async function subirFoto(id, archivo) {
    var fd = new FormData();
    fd.append('producto_id', String(id));
    fd.append('foto', archivo);
    try {
      var j = await adaraUpload(ep.foto, fd);
      upsert(j.producto);
      render();
      toast(j.mensaje || 'Foto actualizada.', true);
    } catch (e) {
      toast(e.message || 'No se pudo subir la foto.', false);
    }
  }

  var btnTasa = document.getElementById('btnGuardarTasa');
  if (btnTasa) {
    btnTasa.addEventListener('click', async function () {
      try {
        var j = await adaraFetch(ep.tasa, { method: 'POST', body: { tasa: $('#catTasa').value } });
        $('#catTasa').value = Number(j.tasa).toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
        toast(j.mensaje || 'Tasa guardada.', true);
      } catch (e) {
        toast(e.message || 'No se pudo guardar la tasa.', false);
      }
    });
  }

  window.adaraFetch = adaraFetch;
  render();
})();
