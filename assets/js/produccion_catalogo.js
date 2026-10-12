/**
 * Catálogo de producción — CRUD.
 * Red: adaraFetch (JSON). CSRF: meta[name=csrf-token].
 * El modal se abre con data-bs-toggle (Bootstrap al final del layout) y se rellena aquí.
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
    if (!(res.status === 200 || res.status === 201) || json.ok !== true) {
      throw new AdaraHttpError(res.status, json);
    }
    return json;
  }

  function $(sel, root) { return (root || document).querySelector(sel); }
  function money(n) {
    return '$' + (Math.round(Number(n || 0) * 100) / 100).toLocaleString('es-VE', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }
  function fmtPrecio(n) {
    return (Math.round(Number(n || 0) * 100) / 100).toFixed(2).replace('.', ',');
  }

  function init() {
    var app = document.getElementById('prodCatApp');
    if (!app) return;

    var ep = JSON.parse(app.getAttribute('data-endpoints') || '{}');
    var puedeCrear = app.getAttribute('data-crear') === '1';
    var puedeEditar = app.getAttribute('data-editar') === '1';
    var datosEl = document.getElementById('catDatos');
    var items = [];
    try { items = JSON.parse((datosEl && datosEl.textContent) || '[]'); } catch (e) { items = []; }

    var modalEl = document.getElementById('modalCatalogo');
    var form = document.getElementById('formCatalogo');
    var editandoId = null;

    function getModal() {
      if (!modalEl) return null;
      if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
      return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    function mostrarModal() {
      var m = getModal();
      if (m) {
        m.show();
        return;
      }
      if (modalEl) {
        modalEl.classList.add('show', 'd-block');
        modalEl.removeAttribute('aria-hidden');
        modalEl.setAttribute('aria-modal', 'true');
      }
    }

    function ocultarModal() {
      var m = getModal();
      if (m) {
        m.hide();
        return;
      }
      if (modalEl) {
        modalEl.classList.remove('show', 'd-block');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.removeAttribute('aria-modal');
      }
    }

    function toast(msg, ok) {
      var el = document.getElementById('catToast');
      if (!el) return;
      el.textContent = msg;
      el.className = 'alert py-2 ' + (ok ? 'alert-success' : 'alert-danger');
    }

    function limpiarErrores() {
      if (!modalEl) return;
      modalEl.querySelectorAll('.is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
      modalEl.querySelectorAll('[data-error]').forEach(function (n) { n.textContent = ''; });
    }

    function mostrarErrores(errores) {
      Object.keys(errores || {}).forEach(function (k) {
        var inp = modalEl.querySelector('[name="' + k + '"]');
        if (inp) inp.classList.add('is-invalid');
        var fb = modalEl.querySelector('[data-error="' + k + '"]');
        if (fb) fb.textContent = errores[k];
      });
    }

    function payloadDelFormulario() {
      var payload = {
        nombre: ($('#cat-nombre') && $('#cat-nombre').value || '').trim(),
        orden: ($('#cat-orden') && $('#cat-orden').value || '').trim() || '0',
        precio_produccion: ($('#cat-prod') && $('#cat-prod').value || '').trim(),
        precio_detal: ($('#cat-det') && $('#cat-det').value || '').trim(),
        descripcion: ($('#cat-desc') && $('#cat-desc').value || '').trim(),
        unidad_medida: ($('#cat-uom') && $('#cat-uom').value) || 'KG',
        unidad_secundaria: ($('#cat-sec') && $('#cat-sec').value) || ''
      };
      var idHidden = $('#cat-id');
      var idVal = editandoId || (idHidden && idHidden.value ? idHidden.value : '');
      if (idVal) payload.id = idVal;
      return payload;
    }

    function pintar() {
      var tbody = document.getElementById('catBody');
      if (!tbody) return;
      tbody.textContent = '';
      if (!items.length) {
        var vacio = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = 9;
        td.className = 'text-center text-muted py-4';
        td.textContent = 'Sin productos en el catálogo.';
        vacio.appendChild(td);
        tbody.appendChild(vacio);
        return;
      }
      items.forEach(function (it) {
        var tr = document.createElement('tr');
        tr.dataset.id = String(it.id);
        if (!it.activo) tr.className = 'table-secondary';

        function cell(cls, text) {
          var c = document.createElement('td');
          if (cls) c.className = cls;
          c.textContent = text;
          return c;
        }
        tr.appendChild(cell('', String(it.orden)));
        var nom = document.createElement('td');
        nom.className = 'fw-semibold';
        nom.textContent = it.nombre;
        if (it.stock != null) {
          var st = document.createElement('div');
          st.className = 'small text-muted fw-normal';
          st.textContent = String(it.stock).replace('.', ',') + ' ' + (it.unidad_medida || 'KG') + ' neto';
          nom.appendChild(st);
        }
        tr.appendChild(nom);
        tr.appendChild(cell('cat-desc text-muted small', it.descripcion || '—'));
        tr.appendChild(cell('text-muted', (it.unidad_medida || 'KG') + (it.unidad_secundaria && it.unidad_secundaria !== 'Piezas' ? ' · tara ' + it.unidad_secundaria : '')));
        tr.appendChild(cell('precio-prod', money(it.precio_produccion)));
        tr.appendChild(cell('precio-det', money(it.precio_detal)));

        var tv = document.createElement('td');
        var bTv = document.createElement('span');
        bTv.className = 'badge ' + (it.visible ? 'badge-visible' : 'text-bg-light');
        bTv.textContent = it.visible ? 'Visible' : 'Oculto';
        tv.appendChild(bTv);
        tr.appendChild(tv);

        var est = document.createElement('td');
        var bEst = document.createElement('span');
        bEst.className = 'badge ' + (it.activo ? 'badge-activo' : 'text-bg-secondary');
        bEst.textContent = it.activo ? 'Activo' : 'Inactivo';
        est.appendChild(bEst);
        tr.appendChild(est);

        var acc = document.createElement('td');
        acc.className = 'text-end text-nowrap';
        if (puedeEditar) {
          var ed = document.createElement('button');
          ed.type = 'button';
          ed.className = 'btn btn-sm btn-outline-primary me-1 js-cat-editar';
          ed.setAttribute('data-accion', 'editar');
          ed.setAttribute('data-id', String(it.id));
          ed.textContent = 'Editar';
          acc.appendChild(ed);
        }
        if (puedeEditar) {
          var el = document.createElement('button');
          el.type = 'button';
          el.className = 'btn btn-sm btn-outline-danger js-cat-eliminar';
          el.setAttribute('data-accion', 'eliminar');
          el.setAttribute('data-id', String(it.id));
          el.textContent = 'Eliminar';
          acc.appendChild(el);
        }
        tr.appendChild(acc);
        tbody.appendChild(tr);
      });
    }

    function itemPorId(id) {
      var n = Number(id);
      for (var i = 0; i < items.length; i++) {
        if (Number(items[i].id) === n) return items[i];
      }
      return null;
    }

    function abrir(it) {
      limpiarErrores();
      editandoId = it ? it.id : null;
      var titulo = $('#catTitulo');
      if (titulo) titulo.textContent = it ? 'Editar producto' : 'Agregar producto';
      if ($('#cat-id')) $('#cat-id').value = it ? String(it.id) : '';
      if ($('#cat-nombre')) $('#cat-nombre').value = it ? it.nombre : '';
      if ($('#cat-orden')) $('#cat-orden').value = it ? String(it.orden) : '0';
      if ($('#cat-prod')) $('#cat-prod').value = it ? fmtPrecio(it.precio_produccion) : '0,00';
      if ($('#cat-det')) $('#cat-det').value = it ? fmtPrecio(it.precio_detal) : '0,00';
      if ($('#cat-desc')) $('#cat-desc').value = it ? (it.descripcion || '') : '';
      if ($('#cat-uom')) $('#cat-uom').value = it ? (it.unidad_medida || 'KG') : 'KG';
      if ($('#cat-sec')) $('#cat-sec').value = it && it.unidad_secundaria ? it.unidad_secundaria : '';
      mostrarModal();
    }

    async function borrar(it) {
      if (!it) return;
      if (!window.confirm('¿Dar de baja «' + it.nombre + '» del catálogo de producción?')) return;
      try {
        var j = await adaraFetch(ep.eliminar, { method: 'POST', body: { id: it.id } });
        items = items.map(function (x) {
          return Number(x.id) === Number(it.id) ? Object.assign({}, x, { activo: false }) : x;
        });
        pintar();
        toast(j.mensaje || 'Producto inactivado.', true);
      } catch (err) {
        toast(err.message || 'No se pudo eliminar.', false);
      }
    }

    var btnNuevo = document.getElementById('btnNuevoCat');
    if (btnNuevo) {
      btnNuevo.addEventListener('click', function (ev) {
        ev.preventDefault();
        abrir(null);
      });
    }

    var tabla = document.getElementById('tablaCatalogo');
    if (tabla) {
      tabla.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-accion]');
        if (!btn || !tabla.contains(btn)) return;
        var it = itemPorId(btn.getAttribute('data-id'));
        if (btn.getAttribute('data-accion') === 'editar') {
          ev.preventDefault();
          abrir(it);
        } else if (btn.getAttribute('data-accion') === 'eliminar') {
          ev.preventDefault();
          borrar(it);
        }
      });
    }

    if (form) {
      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        limpiarErrores();
        var payload = payloadDelFormulario();
        var btn = document.getElementById('btnGuardarCat');
        if (btn) btn.disabled = true;
        try {
          var j = await adaraFetch(ep.guardar, { method: 'POST', body: payload });
          var item = j.item;
          if (!item) throw new AdaraHttpError(500, { errores: { general: 'El servidor no devolvió el producto.' } });
          if (payload.id) {
            items = items.map(function (x) { return Number(x.id) === Number(item.id) ? item : x; });
          } else {
            items.push(item);
            items.sort(function (a, b) { return a.orden - b.orden || String(a.nombre).localeCompare(b.nombre); });
          }
          pintar();
          ocultarModal();
          toast(j.mensaje, true);
        } catch (err) {
          if (err instanceof AdaraHttpError) mostrarErrores(err.errores);
          toast(err.message || 'No se pudo guardar.', false);
        } finally {
          if (btn) btn.disabled = false;
        }
      });
    }

    pintar();
    window.adaraFetch = adaraFetch;
    void puedeCrear;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
