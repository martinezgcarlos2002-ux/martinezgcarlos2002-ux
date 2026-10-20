/**
 * Control de Facturas (CxC) — módulo de pantalla.
 *
 * Carga: <script src="assets/js/facturas.js"> al final de views/facturas/index.php.
 * Config: #cfApp[data-endpoints][data-hoy][data-crear][data-anular] y #cfDatos (JSON).
 * CSRF: <meta name="csrf-token"> en el <head> (layout.php); cabecera X-CSRF-TOKEN.
 *
 * Event listeners (conectarEventos):
 *   #formBuscar, #qClientes, #btnLimpiar, #pillsEstado, #btnVolver
 *   #formCliente / #modalCliente          → alta/edición de cliente
 *   #formFactura / #modalFactura          → nueva factura
 *   input[name=modo_numero]               → número aleatorio (FacturaController::numero)
 *   #facEmision, #facCliente
 *   .js-agregar-fila, #tablaFactura, #tablaDevolucion
 *   #formPago / #modalPagoRapido / #qPago / #btnLimpiarPago
 *   #formAbono / #abonoCliente / #abonoMonto
 *   #formDevolucion / #devCliente / #devOrigen / #devDestino / #modalDevolucion
 *   #modalDetalleFactura / #modalDetalleDevolucion / #btnAnularFacturaDetalle / #btnEstadoCuenta
 *   #btnNotificaciones / #listaFacturasCxC / #formPago saldo a favor
 */
(function (global) {
  'use strict';

  /* =====================================================================
   * 1. Wrapper central de Fetch
   * ===================================================================== */

  class AdaraHttpError extends Error {
    /**
     * @param {number} status
     * @param {{ok?: boolean, errores?: Object<string, string>, mensaje?: string}} payload
     */
    constructor(status, payload) {
      var errores = (payload && payload.errores) || {};
      var msg = Object.keys(errores).map(function (k) { return errores[k]; }).filter(Boolean).join(' ')
        || (payload && payload.mensaje) || mensajeHttp(status);
      super(msg);
      this.name = 'AdaraHttpError';
      this.status = status;
      this.errores = errores;
      this.payload = payload || { ok: false, errores: errores };
    }
  }

  function mensajeHttp(status) {
    if (status === 401) return 'Sesión expirada. Inicia sesión de nuevo.';
    if (status === 403) return 'No tienes permiso o el formulario expiró. Recarga la página.';
    if (status === 409) return 'Conflicto al guardar (dato duplicado o en uso). Intenta de nuevo.';
    if (status === 422) return 'Revisa los campos marcados.';
    if (status === 500) return 'Error interno del servidor. No se aplicaron cambios.';
    if (status === 503) return 'La base de datos no está disponible. Intenta de nuevo.';
    return 'No se pudo completar la operación.';
  }

  function tipoHttp(status) {
    return status === 401 || status === 403 || status === 409 ? 'warning' : 'danger';
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    var app = document.getElementById('cfApp');
    return (app && app.dataset.csrf) || '';
  }

  /**
   * Única puerta de red del módulo. Inyecta Accept, Content-Type y X-CSRF-TOKEN.
   * Respuestas 401 / 403 / 500 (y cualquier !ok) lanzan AdaraHttpError para el toast.
   *
   * @param {string} url
   * @param {{method?: string, body?: Object|string, headers?: HeadersInit, signal?: AbortSignal}} [options]
   *        body: objeto plano → JSON.stringify. No pasar FormData (el backend espera JSON).
   * @returns {Promise<Object>}
   */
  async function adaraFetch(url, options) {
    options = options || {};
    var headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('Content-Type', 'application/json');
    var token = csrfToken();
    if (token) headers.set('X-CSRF-TOKEN', token);

    var body = options.body;
    if (body != null && typeof body === 'object') {
      body = JSON.stringify(body);
    }

    var res;
    try {
      res = await fetch(url, {
        method: options.method || 'GET',
        headers: headers,
        body: body,
        credentials: 'same-origin',
        signal: options.signal || undefined
      });
    } catch (e) {
      if (e && e.name === 'AbortError') throw e;
      throw new AdaraHttpError(0, { ok: false, errores: { general: 'Error de conexión. Intenta de nuevo.' } });
    }

    var json;
    try {
      json = JSON.parse(await res.text());
    } catch (e) {
      json = { ok: false, errores: { general: mensajeHttp(res.status) } };
    }
    if (!json.errores) json.errores = { general: json.mensaje || mensajeHttp(res.status) };
    json.status = res.status;

    if (!res.ok || json.ok !== true) {
      throw new AdaraHttpError(res.status, json);
    }
    return json;
  }

  /* =====================================================================
   * 2. Aplicación de pantalla
   * ===================================================================== */

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  class CxcFacturas {
    constructor() {
      this.app = document.getElementById('cfApp');
      this.ep = JSON.parse(this.app.dataset.endpoints);
      this.hoy = this.app.dataset.hoy;
      this.tasaVigente = this.num(this.app.dataset.tasa || '');
      this.perm = { crear: this.app.dataset.crear === '1', anular: this.app.dataset.anular === '1' };
      this.S = JSON.parse(document.getElementById('cfDatos').textContent);
      this.ui = { cliente: null, q: '', estado: '', resaltar: null };
      this.pagoCalc = '';
      this.buscarTimer = null;
      this.numeroAbort = null;
    }

    init() {
      this.conectarEventos();
      this.render();
      this.cargarAlertasCampana();
      this.abrirDesdeConsulta();
    }

    /* ---------- utilidades de dominio ---------- */

    r2(n) { return Math.round(Number(n || 0) * 100) / 100; }
    money(n) { return '$' + this.r2(n).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    dmy(iso) { return iso ? iso.split('-').reverse().join('/') : ''; }
    num(v) {
      var s = String(v == null ? '' : v).replace(/[\s$]|bs/gi, '');
      var c = s.lastIndexOf(','), p = s.lastIndexOf('.');
      if (c !== -1 && p !== -1) s = c > p ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
      else if (c !== -1) s = (s.match(/,/g) || []).length > 1 ? s.replace(/,/g, '') : s.replace(',', '.');
      else if ((s.match(/\./g) || []).length > 1) s = s.replace(/\./g, '');
      var n = parseFloat(s);
      return isFinite(n) ? n : 0;
    }
    diasEntre(a, b) { return Math.round((new Date(b + 'T00:00:00') - new Date(a + 'T00:00:00')) / 86400000); }
    esFecha(iso) { return /^\d{4}-\d{2}-\d{2}$/.test(iso) && !isNaN(new Date(iso + 'T00:00:00').getTime()); }
    montoCrudo(v) { return String(v == null ? '' : v).replace(/[\s$]/g, '').replace(/bs/gi, '').trim(); }

    h(tag, attrs, hijos) {
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

    byId(lista, id) { return lista.filter(function (x) { return String(x.id) === String(id); })[0] || null; }
    cliente(id) { return this.byId(this.S.clientes, id); }
    factura(id) { return this.byId(this.S.facturas, id); }
    saldo(f) {
      if (f.estado === 'anulada') return 0;
      return this.r2(Number(f.monto) - Number(f.pagado) - Number(f.devuelto) - Number(f.credito || 0));
    }

    estadoFactura(f) {
      if (f.estado === 'anulada') return { clave: 'anulada', color: 'secondary', texto: 'Anulada', icono: 'bi-slash-circle' };
      if (this.saldo(f) <= 0.009) return { clave: 'pagada', color: 'success', texto: 'Pagada', icono: 'bi-check2' };
      var d = this.diasEntre(this.hoy, f.vence);
      if (d < 0) return { clave: 'vencida', color: 'danger', texto: 'Vencida', icono: 'bi-exclamation-circle', atraso: -d };
      if (d <= 3) return { clave: 'por_vencer', color: 'warning', texto: d === 0 ? 'Vence hoy' : 'Vence en ' + d + 'd', icono: 'bi-hourglass-split' };
      return { clave: 'vigente', color: 'primary', texto: 'Vigente', icono: 'bi-clock' };
    }
    badge(f) {
      var e = this.estadoFactura(f);
      return this.h('span', { class: 'badge rounded-pill text-bg-' + e.color }, [this.h('i', { class: 'bi ' + e.icono }), ' ' + e.texto]);
    }
    facturasDe(id) { return this.S.facturas.filter(function (f) { return String(f.cliente_id) === String(id); }); }
    pendientes(id) {
      var self = this;
      return this.S.facturas.filter(function (f) { return self.saldo(f) > 0.009 && (!id || String(f.cliente_id) === String(id)); })
        .sort(function (a, b) { return a.vence === b.vence ? (a.emision < b.emision ? -1 : 1) : (a.vence < b.vence ? -1 : 1); });
    }
    resumenCliente(id) {
      var r = { saldo: 0, vencido: 0, vencidas: 0, atraso: 0, cobrado: 0, estado: 'pagada' };
      var self = this;
      this.facturasDe(id).forEach(function (f) {
        var e = self.estadoFactura(f), s = self.saldo(f);
        r.cobrado += Number(f.pagado);
        if (s > 0.009) { r.saldo += s; r.estado = r.estado === 'vencida' ? 'vencida' : 'pendiente'; }
        if (e.clave === 'vencida') { r.vencido += s; r.vencidas++; r.atraso = Math.max(r.atraso, e.atraso); r.estado = 'vencida'; }
      });
      return r;
    }

    upsert(lista, obj) {
      var i = lista.findIndex(function (x) { return String(x.id) === String(obj.id); });
      if (i >= 0) lista[i] = obj; else lista.push(obj);
    }

    /** Fuente de verdad: el JSON 201 del servidor reemplaza o agrega entidades. */
    sincronizar(j) {
      if (!j) return;
      var self = this;
      if (j.cliente) {
        this.upsert(this.S.clientes, j.cliente);
        this.S.facturas.forEach(function (f) {
          if (String(f.cliente_id) === String(j.cliente.id)) f.cliente = j.cliente.nombre;
        });
      }
      if (j.factura) this.upsert(this.S.facturas, j.factura);
      (j.facturas || []).forEach(function (f) { self.upsert(self.S.facturas, f); });
      (j.pagos || []).forEach(function (p) { self.upsert(self.S.pagos, p); });
      if (j.devolucion) this.upsert(this.S.devoluciones, j.devolucion);
      (j.devoluciones || []).forEach(function (d) { self.upsert(self.S.devoluciones, d); });
    }

    /* ---------- 2. Actualizaciones de DOM aisladas (tras 201) ---------- */

    /** Tarjetas KPI + tabla "A quiénes cobrar primero". */
    actualizarKPIs(data) {
      this.sincronizar(data);
      this.renderKpis();
      this.renderPrioridad();
    }

    /** Upsert de cliente y re-pinta la tabla principal + selects. */
    actualizarTablaClientes(data) {
      this.sincronizar(data);
      this.renderClientes();
      this.renderSelectClientes();
    }

    /** Pestañas Facturas / Pagos / Devoluciones de la ficha abierta. */
    refrescarFichaCliente(data) {
      this.sincronizar(data);
      this.renderFicha();
    }

    aplicar201(data) {
      this.actualizarKPIs(data);
      this.actualizarTablaClientes(data);
      this.refrescarFichaCliente(data);
      this.cargarAlertasCampana();
    }

    /* ---------- toasts, validación, payload JSON ---------- */

    toast(msg, ok) {
      var tipo = ok === true ? 'success' : (typeof ok === 'string' ? ok : 'danger');
      var icono = tipo === 'success' ? 'bi-check-circle' : (tipo === 'warning' ? 'bi-exclamation-triangle' : 'bi-x-circle');
      var t = this.h('div', { class: 'toast align-items-center border-0 text-bg-' + tipo, role: 'alert', 'aria-atomic': 'true' }, [
        this.h('div', { class: 'd-flex' }, [
          this.h('div', { class: 'toast-body' }, [this.h('i', { class: 'bi ' + icono + ' me-1' }), msg]),
          this.h('button', { type: 'button', class: 'btn-close btn-close-white me-2 m-auto', 'data-bs-dismiss': 'toast', 'aria-label': 'Cerrar' })
        ])
      ]);
      $('#cfToasts').appendChild(t);
      t.addEventListener('hidden.bs.toast', function () { t.remove(); });
      bootstrap.Toast.getOrCreateInstance(t, { delay: 5500 }).show();
    }

    toastError(err) {
      var status = err && err.status;
      this.toast((err && err.message) || mensajeHttp(status), tipoHttp(status));
    }

    marcarErrores(form, errores) {
      $$('.is-invalid', form).forEach(function (el) { el.classList.remove('is-invalid'); });
      Object.keys(errores || {}).forEach(function (campo) {
        if (campo === 'general' || campo === 'renglones') return;
        var input = form.querySelector('[name="' + campo + '"]');
        if (!input) return;
        input.classList.add('is-invalid');
        var group = input.closest('.js-doc-group, .js-tel-group');
        if (group) {
          var visible = group.querySelector('.js-doc-numero, .js-tel-numero');
          if (visible) visible.classList.add('is-invalid');
        }
        var wrap = group || input.parentElement;
        var fb = wrap.querySelector('.invalid-feedback');
        if (fb) fb.textContent = errores[campo];
      });
    }

    /**
     * Incluye campos disabled (p. ej. #facNumero en modo aleatorio).
     * FormData los omite; el payload JSON debe llevar numero = input.value.
     */
    formToJson(form) {
      var data = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.tagName === 'BUTTON' || el.type === 'submit') return;
        if (el.type === 'radio' && !el.checked) return;
        if (el.type === 'checkbox') {
          if (el.checked) data[el.name] = el.value;
          return;
        }
        data[el.name] = el.value;
      });
      return data;
    }

    validarRenglones(tabla) {
      var err = {};
      if (!tabla) return err;
      var self = this;
      var esDevolucion = tabla.id === 'tablaDevolucion';
      $$('tbody tr', tabla).forEach(function (tr) {
        var cantEl = $('.js-cant', tr), precEl = $('.js-precio', tr);
        var c = self.montoCrudo(cantEl.value), p = self.montoCrudo(precEl.value);
        if (!c && !p) return;
        /* En una devolución, cantidad vacía o cero significa "este producto no se devuelve". */
        if (esDevolucion && !(self.num(c) > 0)) return;
      if (!(self.num(c) > 0)) { cantEl.classList.add('is-invalid'); err.monto = 'Cada producto debe tener cantidad y precio positivos, o deja la fila en blanco.'; }
      if (!(self.num(p) > 0)) { precEl.classList.add('is-invalid'); err.monto = err.monto || 'Cada producto debe tener cantidad y precio positivos, o deja la fila en blanco.'; }
      var max = parseFloat(cantEl.getAttribute('data-max') || '0');
      if (max > 0 && self.num(c) > max + 0.009) {
        cantEl.classList.add('is-invalid');
        err.monto = 'La cantidad no puede superar lo facturado (' + max + ').';
      }
      });
      return err;
    }

    validarFormulario(form) {
      var err = {};
      var self = this;
      $$('[required]', form).forEach(function (el) {
        if (!el.name || el.disabled) return;
        if (String(el.value || '').trim() === '') err[el.name] = 'Este campo es obligatorio.';
      });
      var montoEl = form.elements.monto;
      if (montoEl && !montoEl.disabled) {
        var raw = this.montoCrudo(montoEl.value);
        if (raw === '') err.monto = 'El monto es obligatorio.';
        else if (!(this.num(raw) > 0)) err.monto = 'El monto debe ser un número positivo.';
      }
      var emision = form.elements.emision, vence = form.elements.vence;
      if (emision) {
        if (!this.esFecha(emision.value)) err.emision = 'Fecha de emisión inválida.';
        else if (emision.value > this.hoy) err.emision = 'La fecha de emisión no puede ser futura.';
      }
      if (vence) {
        if (!this.esFecha(vence.value)) err.vence = 'Fecha de vencimiento inválida.';
        else if (emision && this.esFecha(emision.value) && vence.value < emision.value) err.vence = 'El vencimiento no puede ser anterior a la emisión.';
      }
      var fecha = form.elements.fecha;
      if (fecha && fecha.type === 'date') {
        if (!this.esFecha(fecha.value)) err.fecha = 'Fecha inválida.';
        else if (fecha.value > this.hoy) err.fecha = 'La fecha no puede ser futura.';
      }
      if (form.id === 'formCliente' && form.elements.nombre) {
        var nom = form.elements.nombre.value.trim();
        if (nom.length > 0 && nom.length < 2) err.nombre = 'El nombre es obligatorio (2 a 120 caracteres).';
      }
      return err;
    }

    cerrarModal(form) { bootstrap.Modal.getOrCreateInstance(form.closest('.modal')).hide(); }

    async enviar(form, endpoint, extraBody, extraErr) {
      var err = Object.assign({}, extraErr || this.validarFormulario(form));
      if (!form.checkValidity()) {
        $$(':invalid', form).forEach(function (el) {
          if (el.name && !err[el.name]) err[el.name] = el.validationMessage || 'Campo inválido.';
        });
      }
      if (Object.keys(err).length) {
        this.marcarErrores(form, err);
        this.toast(Object.keys(err).map(function (k) { return err[k]; }).join(' '), 'danger');
        return;
      }
      if (window.AdaraContacto) AdaraContacto.sync(form);
      var payload = Object.assign(this.formToJson(form), extraBody || {});
      var btn = form.querySelector('[type="submit"]');
      btn.disabled = true;
      try {
        var j = await adaraFetch(this.ep[endpoint], { method: 'POST', body: payload });
        this.aplicar201(j);
        form.reset();
        this.marcarErrores(form, {});
        this.cerrarModal(form);
        this.toast(j.mensaje || 'Guardado.', true);
      } catch (e) {
        if (e && e.name === 'AbortError') return;
        if (e instanceof AdaraHttpError) {
          this.marcarErrores(form, e.errores);
          this.toastError(e);
          return;
        }
        this.toast('Error de conexión. Intenta de nuevo.', 'danger');
      } finally {
        btn.disabled = false;
      }
    }

    /* ---------- render ---------- */

    renderKpis() {
      var k = { clientes: this.S.clientes.length, por_cobrar: 0, pagadas: 0, facturado: 0, a_cobrar: 0, cobrado: 0, vencido: 0, vencidas_n: 0, por_vencer: 0, credito: 0, devoluciones: 0 };
      var self = this;
      this.S.facturas.forEach(function (f) {
        var e = self.estadoFactura(f), s = self.saldo(f);
        if (e.clave === 'anulada') return;
        var pendiente = Number(f.saldo);
        k.facturado += Number(f.monto);
        k.cobrado += Number(f.pagado);
        if (pendiente > 0.009) { k.por_cobrar++; k.a_cobrar += Math.round(pendiente * 100); } else { k.pagadas++; }
        if (e.clave === 'vencida') { k.vencido += s; k.vencidas_n++; }
        if (e.clave === 'por_vencer') k.por_vencer++;
      });
      this.S.devoluciones.forEach(function (d) {
        if (d.estado !== 'anulada') k.devoluciones += Number(d.monto);
      });
      this.S.clientes.forEach(function (c) { k.credito += Math.round(Number(c.credito || 0) * 100); });
      k.a_cobrar /= 100;
      k.credito /= 100;
      ['facturado', 'a_cobrar', 'cobrado', 'vencido', 'credito', 'devoluciones'].forEach(function (x) { k[x] = self.money(k[x]); });
      k.vencidas_n = k.vencidas_n + ' factura(s) vencida(s)';
      $$('[data-kpi]').forEach(function (el) { el.textContent = k[el.dataset.kpi]; });
      this.renderSaldoFavor();
    }

    /** Clientes de cxc_clientes con saldo_favor > 0 (campo credito del JSON). */
    renderSaldoFavor() {
      var tb = $('#tbSaldoFavor');
      if (!tb) return;
      tb.textContent = '';
      var self = this;
      var filas = this.S.clientes.filter(function (c) { return Number(c.credito || 0) > 0.009; })
        .sort(function (a, b) { return Number(b.credito) - Number(a.credito) || String(a.nombre).localeCompare(String(b.nombre), 'es'); });
      var totalC = 0;
      filas.forEach(function (c) {
        var saldo = Number(c.credito || 0);
        totalC += Math.round(saldo * 100);
        tb.appendChild(self.h('tr', {}, [
          self.h('td', { text: c.nombre || '' }),
          self.h('td', { class: 'text-end fw-semibold', text: self.money(saldo) })
        ]));
      });
      if (!filas.length) {
        tb.appendChild(this.h('tr', {}, [this.h('td', { colspan: '2', class: 'text-center text-muted py-3', text: 'Ningún cliente tiene saldo a favor.' })]));
      }
      var pie = $('#totalSaldoFavor');
      if (pie) pie.textContent = this.money(totalC / 100);
    }

    renderPrioridad() {
      var tb = $('#tbPrioridad');
      tb.textContent = '';
      var self = this;
      var filas = this.S.clientes.map(function (c) { return { c: c, r: self.resumenCliente(c.id) }; })
        .filter(function (x) { return x.r.vencidas > 0; })
        .sort(function (a, b) { return b.r.atraso - a.r.atraso || b.r.vencido - a.r.vencido; });
      filas.forEach(function (x) {
        tb.appendChild(self.h('tr', {}, [
          self.h('td', { class: 'fw-semibold' }, [self.h('a', { href: '#', class: 'link-dark text-decoration-none', onclick: function (e) { e.preventDefault(); self.abrirCliente(x.c.id); } }, [x.c.nombre])]),
          self.h('td', { class: 'text-end', text: self.money(x.r.saldo) }),
          self.h('td', { class: 'text-end text-danger fw-semibold', text: self.money(x.r.vencido) }),
          self.h('td', { class: 'text-center', text: String(x.r.vencidas) }),
          self.h('td', { class: 'text-end text-danger fw-semibold', text: x.r.atraso + (x.r.atraso === 1 ? ' día' : ' días') })
        ]));
      });
      if (!filas.length) tb.appendChild(this.h('tr', {}, [this.h('td', { colspan: '5', class: 'text-center text-muted py-3', text: 'No hay facturas vencidas.' })]));
    }

    renderClientes() {
      var tb = $('#tbClientes');
      tb.textContent = '';
      var q = this.ui.q.toLowerCase();
      var visibles = 0;
      var self = this;
      this.S.clientes.slice().sort(function (a, b) { return a.nombre.localeCompare(b.nombre); }).forEach(function (c) {
        var r = self.resumenCliente(c.id);
        var texto = (c.nombre + ' ' + (c.documento || '') + ' ' + (c.telefono || '') + ' ' + (c.domicilio || '') + ' ' + self.facturasDe(c.id).map(function (f) { return f.numero; }).join(' ')).toLowerCase();
        var okEstado = !self.ui.estado || r.estado === self.ui.estado || (self.ui.estado === 'pendiente' && r.estado === 'vencida');
        if ((q && texto.indexOf(q) === -1) || !okEstado) return;
        visibles++;
        tb.appendChild(self.h('tr', { 'data-cliente-id': String(c.id), class: String(self.ui.resaltar) === String(c.id) ? 'table-warning' : '' }, [
          self.h('td', { class: 'fw-semibold', text: c.nombre }),
          self.h('td', { text: c.documento || '—' }),
          self.h('td', { text: c.telefono || '—' }),
          self.h('td', { class: 'small', text: c.domicilio || '—' }),
          self.h('td', { class: 'text-end fw-semibold ' + (r.saldo > 0.009 ? 'text-danger' : 'text-success'), text: self.money(r.saldo) }),
          self.h('td', { class: 'text-end text-nowrap' }, [
            self.h('button', { type: 'button', class: 'btn btn-sm btn-warning rounded-pill', onclick: function () { self.abrirCliente(c.id); } }, [self.h('i', { class: 'bi bi-folder2-open' }), ' Facturas']),
            ' ',
            self.perm.crear ? self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-warning rounded-circle', title: 'Editar', 'aria-label': 'Editar ' + c.nombre, onclick: function () { self.abrirModalCliente(c); } }, [self.h('i', { class: 'bi bi-pencil' })]) : null,
            ' ',
            self.perm.anular ? self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger rounded-circle', title: 'Eliminar', 'aria-label': 'Eliminar ' + c.nombre, onclick: function () { self.eliminarCliente(c); } }, [self.h('i', { class: 'bi bi-trash' })]) : null
          ])
        ]));
      });
      if (!visibles) tb.appendChild(this.h('tr', {}, [this.h('td', { colspan: '6', class: 'text-center text-muted py-4', text: 'No hay clientes que coincidan con el filtro.' })]));
      $('#contadorClientes').textContent = visibles + ' de ' + this.S.clientes.length + ' clientes';
    }

    renderFicha() {
      if (this.ui.cliente == null) return;
      var c = this.cliente(this.ui.cliente);
      if (!c) { this.volver(); return; }
      var r = this.resumenCliente(c.id);
      $('#detNombre').textContent = c.nombre;
      $('#detDoc').textContent = [c.documento, c.telefono].filter(Boolean).join(' · ');
      $('#detDebe').textContent = this.money(r.saldo);
      $('#detCobrado').textContent = this.money(r.cobrado);
      $('#detVencido').textContent = this.money(r.vencido);
      $('#detCredito').textContent = this.money(c.credito || 0);
      $('#btnEstadoCuenta').href = this.ep.estado_cuenta + '&cliente_id=' + encodeURIComponent(c.id);

      var self = this;
      var fs = this.facturasDe(c.id).sort(function (a, b) { return a.emision < b.emision ? 1 : -1; });
      var tb = $('#tbDetFacturas');
      tb.textContent = '';
      fs.forEach(function (f) {
        var s = self.saldo(f), e = self.estadoFactura(f);
        tb.appendChild(self.h('tr', { class: e.clave === 'por_vencer' ? 'table-warning' : (e.clave === 'vencida' ? 'table-danger' : '') }, [
          self.h('td', {}, [self.badge(f)]),
          self.h('td', { class: 'fw-semibold', text: f.numero }),
          self.h('td', { class: 'small', text: f.domicilio || '' }),
          self.h('td', { text: self.dmy(f.emision) }),
          self.h('td', { text: self.dmy(f.vence) }),
          self.h('td', { class: 'text-end', text: self.money(f.monto) }),
          self.h('td', { class: 'text-end text-success', text: self.money(f.pagado) }),
          self.h('td', { class: 'text-end text-primary', text: self.money(f.devuelto) }),
          self.h('td', { class: 'text-end fw-semibold ' + (s > 0.009 ? 'text-danger' : 'text-success') }, [self.money(s), f.credito ? self.h('div', { class: 'small text-muted fw-normal', text: 'Crédito aplicado: ' + self.money(f.credito) }) : null]),
          self.h('td', { class: 'text-end text-nowrap' }, [
            self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary rounded-circle', title: 'Ver detalle', 'aria-label': 'Ver detalle de ' + f.numero, onclick: function () { self.abrirDetalle(f.id); } }, [self.h('i', { class: 'bi bi-eye' })]),
            ' ',
            self.h('a', { class: 'btn btn-sm btn-outline-primary rounded-circle', title: 'Imprimir factura', 'aria-label': 'Imprimir ' + f.numero, href: self.ep.imprimir + '&factura_id=' + encodeURIComponent(f.id), target: '_blank', rel: 'noopener' }, [self.h('i', { class: 'bi bi-printer' })]),
            ' ',
            s > 0.009 && self.perm.crear ? self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-info rounded-circle', title: 'Registrar pago', 'aria-label': 'Registrar pago de ' + f.numero, onclick: function () { self.abrirPago(f); } }, [self.h('i', { class: 'bi bi-cash' })]) : null,
            ' ',
            self.perm.anular && e.clave !== 'anulada' ? self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger rounded-circle', title: 'Anular factura', 'aria-label': 'Anular ' + f.numero, onclick: function () { self.anularFactura(f); } }, [self.h('i', { class: 'bi bi-slash-circle' })]) : null
          ])
        ]));
      });
      if (!fs.length) tb.appendChild(this.h('tr', {}, [this.h('td', { colspan: '10', class: 'text-center text-muted py-4', text: 'Este cliente no tiene facturas.' })]));
      $('#nFacturas').textContent = fs.length;

      var ids = fs.map(function (f) { return String(f.id); });
      var ps = this.S.pagos.filter(function (p) { return ids.indexOf(String(p.factura_id)) !== -1; }).sort(function (a, b) { return a.fecha < b.fecha ? 1 : -1; });
      var tp = $('#tbDetPagos');
      tp.textContent = '';
      ps.forEach(function (p) {
        var f = self.factura(p.factura_id);
        var anulado = p.estado === 'anulado';
        tp.appendChild(self.h('tr', { class: anulado ? 'text-muted' : '' }, [
          self.h('td', { text: self.dmy(p.fecha) }), self.h('td', { class: 'fw-semibold', text: f ? f.numero : '' }),
          self.h('td', { text: p.metodo || '—' }), self.h('td', { text: p.referencia || '—' }), self.h('td', { class: 'small', text: p.notas || '' }),
          self.h('td', { class: 'text-end text-success fw-semibold', text: self.money(p.monto) }),
          self.h('td', { class: 'text-end' }, [
            anulado ? self.h('span', { class: 'badge rounded-pill text-bg-secondary', text: 'Anulado' })
              : (self.perm.anular ? self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger rounded-circle', title: 'Anular pago', 'aria-label': 'Anular pago', onclick: function () { self.anularPago(p); } }, [self.h('i', { class: 'bi bi-slash-circle' })]) : null)
          ])
        ]));
      });
      if (!ps.length) tp.appendChild(this.h('tr', {}, [this.h('td', { colspan: '7', class: 'text-center text-muted py-4', text: 'Sin pagos registrados.' })]));
      $('#nPagos').textContent = ps.length;

      var ds = this.S.devoluciones.filter(function (d) { return String(d.cliente_id) === String(c.id); }).sort(function (a, b) { return a.fecha < b.fecha ? 1 : -1; });
      var td = $('#tbDetDevoluciones');
      td.textContent = '';
      ds.forEach(function (d) {
        var o = self.factura(d.factura_id), dest = self.factura(d.factura_destino);
        td.appendChild(self.h('tr', {}, [
          self.h('td', { text: self.dmy(d.fecha) }),
          self.h('td', { class: 'text-end text-primary fw-semibold', text: self.money(d.monto) }),
          self.h('td', { text: d.motivo || '' }),
          self.h('td', { text: o ? o.numero : '—' }),
          self.h('td', {}, [d.destino === 'credito' ? self.h('span', { class: 'badge rounded-pill text-bg-secondary', text: 'Crédito a favor' }) : self.h('span', { class: 'badge rounded-pill text-bg-success', text: dest ? dest.numero : '—' })]),
          self.h('td', { class: 'text-end' }, [self.h('span', { class: 'badge rounded-pill ' + (d.estado === 'pendiente' ? 'text-bg-warning' : (d.estado === 'anulada' ? 'text-bg-secondary' : 'text-bg-light border')), text: d.estado === 'pendiente' ? 'Pendiente' : (d.estado === 'anulada' ? 'Anulada' : 'Aplicada') })]),
          self.h('td', { class: 'text-end text-nowrap' }, [
            self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary rounded-circle', title: 'Ver detalle', 'aria-label': 'Ver detalle de devolución', onclick: function () { self.abrirDetalleDevolucion(d.id); } }, [self.h('i', { class: 'bi bi-eye' })]),
            ' ',
            self.perm.anular && d.estado !== 'anulada' ? self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger rounded-circle', title: 'Anular devolución', 'aria-label': 'Anular devolución', onclick: function () { self.anularDevolucion(d); } }, [self.h('i', { class: 'bi bi-slash-circle' })]) : null
          ])
        ]));
      });
      if (!ds.length) td.appendChild(this.h('tr', {}, [this.h('td', { colspan: '7', class: 'text-center text-muted py-4', text: 'Sin devoluciones.' })]));
      $('#nDevoluciones').textContent = ds.length;
    }

    renderSelectClientes() {
      var self = this;
      $$('select.js-clientes').forEach(function (sel) {
        var actual = sel.value;
        var conDeuda = sel.dataset.conDeuda === '1';
        sel.textContent = '';
        sel.appendChild(self.h('option', { value: '', text: 'Selecciona…' }));
        self.S.clientes.slice().sort(function (a, b) { return a.nombre.localeCompare(b.nombre); }).forEach(function (c) {
          var r = self.resumenCliente(c.id);
          if (conDeuda && r.saldo <= 0.009) return;
          sel.appendChild(self.h('option', {
            value: c.id,
            text: c.nombre + (conDeuda ? ' — debe ' + self.money(r.saldo) : ''),
            'data-domicilio': c.domicilio || ''
          }));
        });
        sel.value = actual;
      });
    }

    render() {
      this.renderKpis();
      this.renderPrioridad();
      this.renderClientes();
      this.renderSelectClientes();
      this.renderFicha();
    }

    /* ---------- navegación ---------- */

    abrirCliente(id) {
      this.ui.cliente = id;
      this.ui.resaltar = id;
      this.renderClientes();
      $('#vistaLista').classList.add('d-none');
      $('#vistaCliente').classList.remove('d-none');
      bootstrap.Tab.getOrCreateInstance($('[data-bs-target="#tabFacturas"]')).show();
      this.renderFicha();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    abrirDesdeConsulta() {
      var q = new URLSearchParams(window.location.search);
      var id = q.get('cliente_id');
      if (id && this.cliente(id)) this.abrirCliente(id);
    }

    /* ---------- 4. Campana de alertas CxC ---------- */

    async cargarAlertasCampana() {
      if (!this.ep.alertas_campana) return;
      try {
        var j = await adaraFetch(this.ep.alertas_campana);
        this.pintarAlertasCampana(j.total || 0, j.lista || []);
      } catch (e) {
        /* La campana de productores no debe romperse si CxC falla. */
      }
    }

    pintarAlertasCampana(totalCxc, lista) {
      var badge = $('#contadorAlertas');
      var otras = badge ? parseInt(badge.getAttribute('data-otras') || '0', 10) : 0;
      if (!isFinite(otras)) otras = 0;
      var total = otras + Number(totalCxc || 0);
      if (badge) {
        badge.replaceChildren(
          document.createTextNode(total > 99 ? '99+' : String(total)),
          this.h('span', { class: 'visually-hidden', text: 'alertas sin leer' })
        );
        badge.classList.toggle('d-none', total <= 0);
      }
      var menu = $('#contadorAlertasMenu');
      if (menu) menu.textContent = String(total);
      var n = $('#nAlertasCxc');
      if (n) n.textContent = String(totalCxc);
      var ul = $('#listaFacturasCxC');
      if (!ul) return;
      ul.textContent = '';
      var self = this;
      (lista || []).forEach(function (a) {
        var vencida = a.estado === 'vencida';
        ul.appendChild(self.h('li', {}, [
          self.h('a', {
            class: 'dropdown-item d-flex gap-2 py-2 border-bottom js-alerta-cxc',
            href: a.url || (self.ep.alertas_campana ? '#' : '#'),
            'data-cliente-id': String(a.cliente_id),
            'data-factura-id': String(a.factura_id || ''),
            onclick: function (e) { e.preventDefault(); self.abrirClienteDesdeAlerta(a.cliente_id); }
          }, [
            self.h('i', { class: 'bi ' + (vencida ? 'bi-exclamation-circle text-danger' : 'bi-hourglass-split text-warning') }),
            self.h('span', { class: 'text-wrap' }, [
              self.h('span', { class: 'd-block fw-semibold small', text: a.cliente + ' · ' + a.numero }),
              self.h('span', { class: 'd-block text-body-secondary small', text: a.detalle || (vencida ? a.dias + ' días de atraso' : 'Vence en ' + a.dias + ' días') })
            ])
          ])
        ]));
      });
      if (!(lista || []).length) {
        ul.appendChild(this.h('li', {}, [this.h('span', { class: 'dropdown-item-text small text-body-secondary py-2', text: 'Sin facturas vencidas ni por vencer.' })]));
      }
    }

    abrirClienteDesdeAlerta(clienteId) {
      var btn = $('#btnNotificaciones');
      if (btn) bootstrap.Dropdown.getOrCreateInstance(btn).hide();
      if (this.cliente(clienteId)) {
        this.abrirCliente(clienteId);
        return;
      }
      window.location.href = (this.ep.inicio || 'index.php?p=facturas') + '&cliente_id=' + encodeURIComponent(clienteId);
    }
    volver() {
      this.ui.cliente = null;
      $('#vistaCliente').classList.add('d-none');
      $('#vistaLista').classList.remove('d-none');
    }
    setEstado(estado) {
      this.ui.estado = estado;
      $$('#pillsEstado button').forEach(function (b) {
        var activo = b.dataset.estado === estado;
        b.classList.toggle('btn-warning', activo);
        b.classList.toggle('btn-outline-warning', !activo);
        b.setAttribute('aria-pressed', activo ? 'true' : 'false');
      });
      this.renderClientes();
    }

    /* ---------- renglones ---------- */

    agregarFila(tabla) {
      var self = this;
      var esDevolucion = tabla.id === 'tablaDevolucion';
      var tr = this.h('tr', {}, [
        this.h('td', {}, [this.h('input', { class: 'form-control form-control-sm js-cant', inputmode: 'decimal', placeholder: 'Cant.' })]),
        this.h('td', {}, [this.h('input', { class: 'form-control form-control-sm js-precio', inputmode: 'decimal', placeholder: 'Precio' })]),
        this.h('td', { class: 'text-end small js-sub', text: this.money(0) }),
        this.h('td', {}, [this.h('input', { class: 'form-control form-control-sm js-desc', maxlength: '120', placeholder: 'Descripción (Ej: queso de año)' })]),
        this.h('td', { class: 'text-end' }, [this.h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger' + (esDevolucion ? '' : ' rounded-circle'), 'aria-label': esDevolucion ? 'Eliminar' : 'Quitar producto', title: esDevolucion ? 'Eliminar' : 'Quitar producto', onclick: function () { tr.remove(); self.recalcular(tabla); } }, [this.h('i', { class: esDevolucion ? 'bi bi-trash' : 'bi bi-x-lg' })])])
      ]);
      $('tbody', tabla).appendChild(tr);
      this.recalcular(tabla);
      $('.js-cant', tr).focus();
    }
    recalcular(tabla) {
      var self = this, total = 0, hay = false;
      $$('tbody tr', tabla).forEach(function (tr) {
        var cant = self.num($('.js-cant', tr).value);
        var prec = self.num($('.js-precio', tr).value);
        var sub = cant * prec;
        $('.js-sub', tr).textContent = self.money(sub);
        if (cant > 0 && prec > 0) {
          hay = true;
          total += sub;
        }
      });
      var input = document.getElementById(tabla.dataset.total);
      if (!input) return;
      if (tabla.id === 'tablaDevolucion') {
        input.value = hay ? this.r2(total).toFixed(2) : '';
        input.readOnly = hay;
        return;
      }
      if (hay) input.value = this.r2(total).toFixed(2);
      input.readOnly = hay;
    }
    renglones(tabla) {
      var self = this;
      return $$('tbody tr', tabla).map(function (tr) {
        return { cantidad: self.num($('.js-cant', tr).value), precio: self.num($('.js-precio', tr).value), descripcion: $('.js-desc', tr).value.trim() };
      }).filter(function (x) { return x.cantidad > 0 && x.precio > 0; });
    }
    limpiarFilas(tabla) {
      $('tbody', tabla).textContent = '';
      document.getElementById(tabla.dataset.total).readOnly = false;
    }

    /* ---------- cliente ---------- */

    abrirModalCliente(c) {
      var form = $('#formCliente');
      form.reset();
      this.marcarErrores(form, {});
      $('#tituloCliente').textContent = c ? 'Editar Cliente' : 'Nuevo Cliente';
      form.elements.id.value = c ? c.id : '';
      if (c) ['nombre', 'documento', 'telefono', 'domicilio'].forEach(function (k) { form.elements[k].value = c[k] || ''; });
      if (c && window.AdaraContacto) {
        AdaraContacto.setDocumento(form.elements.documento, c.documento || '');
        AdaraContacto.setTelefono(form.elements.telefono, c.telefono || '');
      }
      bootstrap.Modal.getOrCreateInstance($('#modalCliente')).show();
    }

    async abrirDetalle(facturaId) {
      try {
        var j = await adaraFetch(this.ep.detalle + '&factura_id=' + encodeURIComponent(facturaId));
        var f = j.factura;
        this.ui.detalleId = f.id;
        $('#tituloDetalleFactura').textContent = 'Detalle · ' + f.numero;
        $('#detFacResumen').textContent = f.cliente + ' · emisión ' + this.dmy(f.emision) + ' · vence ' + this.dmy(f.vence) + ' · ' + this.estadoFactura(f).texto + ' · saldo ' + this.money(this.saldo(f));
        var tb = $('#tbDetRenglones');
        tb.textContent = '';
        var self = this;
        (j.renglones || []).forEach(function (r) {
          tb.appendChild(self.h('tr', {}, [
            self.h('td', { text: String(r.cantidad) }),
            self.h('td', { class: 'text-end', text: self.money(r.precio) }),
            self.h('td', { class: 'text-end', text: self.money(r.subtotal) }),
            self.h('td', { text: r.descripcion || '' })
          ]));
        });
        if (!(j.renglones || []).length) tb.appendChild(this.h('tr', {}, [this.h('td', { colspan: '4', class: 'text-center text-muted', text: 'Sin renglones (monto capturado a mano).' })]));
        var tp = $('#tbDetPagosFac');
        tp.textContent = '';
        (j.pagos || []).forEach(function (p) {
          var anulado = p.estado === 'anulado';
          tp.appendChild(self.h('tr', { class: anulado ? 'text-muted' : '' }, [
            self.h('td', { text: self.dmy(p.fecha) }),
            self.h('td', { text: p.metodo || '—' }),
            self.h('td', { text: p.referencia || '—' }),
            self.h('td', { class: 'text-end', text: self.money(p.monto) }),
            self.h('td', { class: 'text-end small' }, [
              p.monto_bs ? ('Bs ' + self.r2(p.monto_bs).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })) : '—',
              p.tasa ? self.h('div', { class: 'badge rounded-pill text-bg-light border', text: 'Tasa ' + self.r2(p.tasa).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) }) : null
            ]),
            self.h('td', { class: 'text-end' }, [self.h('span', { class: 'badge rounded-pill ' + (anulado ? 'text-bg-secondary' : 'text-bg-success'), text: anulado ? 'Anulado' : 'Aplicado' })]),
            self.h('td', { class: 'text-end' }, [
              !anulado && self.perm.anular ? self.h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger rounded-circle', title: 'Anular pago', onclick: function () { self.anularPago(p); } }, [self.h('i', { class: 'bi bi-slash-circle' })]) : null
            ])
          ]));
        });
        if (!(j.pagos || []).length) tp.appendChild(this.h('tr', {}, [this.h('td', { colspan: '7', class: 'text-center text-muted', text: 'Sin pagos.' })]));
        var btnAn = $('#btnAnularFacturaDetalle');
        btnAn.classList.toggle('d-none', !this.perm.anular || f.estado === 'anulada');
        btnAn.onclick = function () { self.anularFactura(f); };
        $('#btnImprimirFactura').href = this.ep.imprimir + '&factura_id=' + encodeURIComponent(f.id);
        bootstrap.Modal.getOrCreateInstance($('#modalDetalleFactura')).show();
      } catch (e) {
        this.toastError(e instanceof AdaraHttpError ? e : new AdaraHttpError(0, { errores: { general: 'No se pudo cargar el detalle.' } }));
      }
    }

    async abrirDetalleDevolucion(devolucionId) {
      try {
        var j = await adaraFetch(this.ep.detalle_devolucion + '&id=' + encodeURIComponent(devolucionId));
        var d = j.devolucion;
        var estados = { aplicada: 'Aplicada', pendiente: 'Pendiente', anulada: 'Anulada' };
        $('#tituloDetalleDevolucion').textContent = 'Devolución · ' + this.dmy(d.fecha) + ' · ' + this.money(d.monto);
        $('#detDevResumen').textContent = ((j.cliente && j.cliente.nombre) ? j.cliente.nombre + ' · ' : '')
          + (estados[d.estado] || d.estado);
        $('#detDevMotivo').textContent = d.motivo || '—';
        $('#detDevOrigen').textContent = j.origen ? j.origen.numero : 'Sin factura de origen';
        $('#detDevAplicacion').textContent = j.aplicacion || (d.destino === 'credito' ? 'Quedó como saldo a favor del cliente' : '—');
        var tb = $('#tbDetDevRenglones');
        tb.textContent = '';
        var self = this;
        (j.renglones || []).forEach(function (r) {
          tb.appendChild(self.h('tr', {}, [
            self.h('td', { text: String(r.cantidad) }),
            self.h('td', { class: 'text-end', text: self.money(r.precio_origen != null ? r.precio_origen : r.precio) }),
            self.h('td', { class: 'text-end', text: self.money(r.precio) }),
            self.h('td', { class: 'text-end', text: self.money(r.subtotal) }),
            self.h('td', { text: r.descripcion || '' })
          ]));
        });
        if (!(j.renglones || []).length) {
          tb.appendChild(this.h('tr', {}, [this.h('td', { colspan: '5', class: 'text-center text-muted', text: 'Sin desglose de productos (monto capturado a mano).' })]));
        }
        bootstrap.Modal.getOrCreateInstance($('#modalDetalleDevolucion')).show();
      } catch (e) {
        this.toastError(e instanceof AdaraHttpError ? e : new AdaraHttpError(0, { errores: { general: 'No se pudo cargar el detalle de la devolución.' } }));
      }
    }

    async anularPago(p) {
      if (!window.confirm('¿Anular este pago de ' + this.money(p.monto) + '? El saldo de la factura se restaurará. Esta acción no se puede deshacer.')) return;
      try {
        var j = await adaraFetch(this.ep.anular_pago, { method: 'POST', body: { id: p.id } });
        this.aplicar201(j);
        this.toast(j.mensaje || 'Pago anulado.', true);
        if ($('#modalDetalleFactura').classList.contains('show') && this.ui.detalleId) this.abrirDetalle(this.ui.detalleId);
      } catch (e) {
        this.toastError(e instanceof AdaraHttpError ? e : new AdaraHttpError(0, { errores: { general: 'No se pudo anular el pago.' } }));
      }
    }

    async anularDevolucion(d) {
      if (!window.confirm('¿Anular esta devolución de ' + this.money(d.monto) + '? Se revertirá el efecto sobre la factura o el saldo a favor.')) return;
      try {
        var j = await adaraFetch(this.ep.anular_devolucion, { method: 'POST', body: { id: d.id } });
        this.aplicar201(j);
        this.toast(j.mensaje || 'Devolución anulada.', true);
      } catch (e) {
        this.toastError(e instanceof AdaraHttpError ? e : new AdaraHttpError(0, { errores: { general: 'No se pudo anular la devolución.' } }));
      }
    }

    async anularFactura(f) {
      if (!window.confirm('¿Anular la factura ' + f.numero + '? Solo procede si no tiene pagos ni devoluciones aplicadas. No se puede deshacer.')) return;
      try {
        var j = await adaraFetch(this.ep.anular_factura, { method: 'POST', body: { id: f.id } });
        this.aplicar201(j);
        var modal = bootstrap.Modal.getInstance($('#modalDetalleFactura'));
        if (modal) modal.hide();
        this.toast(j.mensaje || 'Factura anulada.', true);
      } catch (e) {
        this.toastError(e instanceof AdaraHttpError ? e : new AdaraHttpError(0, { errores: { general: 'No se pudo anular la factura.' } }));
      }
    }

    async cargarRenglonesOrigen(facturaId) {
      var tabla = $('#tablaDevolucion');
      if (!facturaId) {
        this.limpiarFilas(tabla);
        this.agregarFila(tabla);
        return;
      }
      try {
        var j = await adaraFetch(this.ep.detalle + '&factura_id=' + encodeURIComponent(facturaId));
        this.limpiarFilas(tabla);
        var self = this;
        (j.renglones || []).forEach(function (r) {
          self.agregarFilaOrigen(tabla, r);
        });
        if (!(j.renglones || []).length) {
          this.agregarFila(tabla);
          if (j.factura && j.factura.monto) {
            $('#devMonto').value = Number(j.factura.monto).toFixed(2);
            $('#devMonto').readOnly = false;
          }
          return;
        }
        this.recalcular(tabla);
      } catch (e) {
        this.toastError(e instanceof AdaraHttpError ? e : new AdaraHttpError(0, { errores: { general: 'No se pudieron cargar los renglones.' } }));
      }
    }

    agregarFilaOrigen(tabla, r) {
      var cant = this.h('input', { class: 'form-control form-control-sm js-cant', inputmode: 'decimal', value: String(r.cantidad) });
      cant.setAttribute('data-max', String(r.cantidad));
      var prec = this.h('input', { class: 'form-control form-control-sm js-precio', inputmode: 'decimal', value: Number(r.precio).toFixed(2), readonly: 'readonly' });
      var desc = this.h('input', { class: 'form-control form-control-sm js-desc', maxlength: '120', value: r.descripcion || '', readonly: 'readonly' });
      var self = this;
      var tr = this.h('tr', {}, [
        this.h('td', {}, [cant]),
        this.h('td', {}, [prec]),
        this.h('td', { class: 'text-end small js-sub', text: this.money(r.subtotal) }),
        this.h('td', {}, [desc]),
        this.h('td', { class: 'text-end' }, [this.h('button', {
          type: 'button',
          class: 'btn btn-sm btn-outline-danger',
          'aria-label': 'Eliminar',
          title: 'Eliminar',
          onclick: function () { tr.remove(); self.recalcular(tabla); }
        }, [this.h('i', { class: 'bi bi-trash' })])])
      ]);
      $('tbody', tabla).appendChild(tr);
    }

    async eliminarCliente(c) {
      if (this.facturasDe(c.id).length) {
        this.toast(c.nombre + ' tiene facturas registradas: no se puede eliminar.', false);
        return;
      }
      if (!window.confirm('¿Eliminar a ' + c.nombre + '?')) return;
      try {
        var j = await adaraFetch(this.ep.cliente_eliminar, { method: 'POST', body: { id: c.id } });
        this.S.clientes = this.S.clientes.filter(function (x) { return String(x.id) !== String(c.id); });
        this.render();
        this.toast(j.mensaje || 'Cliente eliminado.', true);
      } catch (e) {
        this.toastError(e instanceof AdaraHttpError ? e : new AdaraHttpError(0, { errores: { general: 'Error de conexión.' } }));
      }
    }

    /* ---------- 3. Número aleatorio ---------- */

    btnGuardarFactura() { return $('#formFactura [type="submit"]'); }

    setNumeroManual() {
      var numero = $('#facNumero');
      numero.disabled = false;
      numero.readOnly = false;
      numero.value = '';
      $('#facNumeroEstado').classList.add('d-none');
      this.btnGuardarFactura().disabled = false;
      $('#numManual').checked = true;
    }

    /**
     * #numAleatorio / #numManual (name=modo_numero).
     * Aleatorio: readonly+disabled, spinner "Generando…", GET facturas_numero, escribe R-####.
     */
    async onModoNumeroChange() {
      var numero = $('#facNumero');
      var estado = $('#facNumeroEstado');
      var guardar = this.btnGuardarFactura();
      if (this.numeroAbort) this.numeroAbort.abort();

      if (!$('#numAleatorio').checked) {
        this.setNumeroManual();
        numero.focus();
        return;
      }

      numero.disabled = true;
      numero.readOnly = true;
      numero.value = '';
      estado.classList.remove('d-none');
      guardar.disabled = true;

      this.numeroAbort = new AbortController();
      try {
        var j = await adaraFetch(this.ep.numero, { method: 'GET', signal: this.numeroAbort.signal });
        numero.value = j.numero;
        estado.classList.add('d-none');
        guardar.disabled = false;
      } catch (e) {
        if (e && e.name === 'AbortError') return;
        this.setNumeroManual();
        this.toastError(e instanceof AdaraHttpError ? e : new AdaraHttpError(0, { errores: { general: 'No se pudo generar el número aleatorio.' } }));
      }
    }

    resetNumeroFactura() {
      if (this.numeroAbort) this.numeroAbort.abort();
      var numero = $('#facNumero');
      numero.disabled = false;
      numero.readOnly = false;
      $('#facNumeroEstado').classList.add('d-none');
      this.btnGuardarFactura().disabled = false;
    }

    /* ---------- pago rápido ---------- */

    pintarListaPago(lista) {
      var box = $('#listaPago');
      box.textContent = '';
      var self = this;
      lista.forEach(function (f) {
        box.appendChild(self.h('div', { class: 'list-group-item d-flex justify-content-between align-items-center gap-3' }, [
          self.h('div', { class: 'min-w-0' }, [
            self.h('div', { class: 'fw-semibold', text: 'Factura ' + f.numero }),
            self.h('div', { class: 'small text-muted text-truncate' }, [
              (f.cliente || (self.cliente(f.cliente_id) || {}).nombre || '') + ' · vence ' + self.dmy(f.vence) + ' ',
              self.badge(f),
              (self.cliente(f.cliente_id) && Number((self.cliente(f.cliente_id) || {}).credito) > 0.009)
                ? self.h('span', { class: 'd-block text-info', text: 'Saldo a favor: ' + self.money(self.cliente(f.cliente_id).credito) })
                : null
            ])
          ]),
          self.h('div', { class: 'd-flex align-items-center gap-3 flex-shrink-0' }, [
            self.h('div', { class: 'text-end' }, [self.h('div', { class: 'small text-muted', text: 'Saldo pendiente' }), self.h('div', { class: 'fw-bold text-danger', text: self.money(self.saldo(f)) })]),
            self.h('button', { type: 'button', class: 'btn btn-sm btn-info text-white rounded-pill', onclick: function () { self.abrirPago(self.factura(f.id) || f); } }, [self.h('i', { class: 'bi bi-plus-lg' }), ' Agregar pago'])
          ])
        ]));
      });
      if (!lista.length) box.appendChild(this.h('div', { class: 'list-group-item text-muted text-center py-4', text: 'No hay facturas pendientes que coincidan.' }));
    }

    buscarPago() {
      var q = $('#qPago').value.trim().toLowerCase();
      var self = this;
      var local = this.pendientes(null).filter(function (f) {
        return !q || (f.numero + ' ' + (self.cliente(f.cliente_id) || {}).nombre).toLowerCase().indexOf(q) !== -1;
      });
      this.pintarListaPago(local);
    }

    abrirPago(f) {
      var form = $('#formPago');
      form.reset();
      this.marcarErrores(form, {});
      form.elements.factura_id.value = f.id;
      var c = this.cliente(f.cliente_id);
      $('#tituloPago').textContent = 'Registrar Pago — ' + f.numero + ' · ' + (c ? c.nombre : '');
      $('#pagoSaldo').textContent = this.money(this.saldo(f));
      $('#pagoMonto').value = this.saldo(f).toFixed(2);
      $('#pagoFecha').value = this.hoy;
      this.mostrarCreditoPago(c, this.saldo(f));
      this.prefijarTasaPago();
      var rapido = bootstrap.Modal.getInstance($('#modalPagoRapido'));
      if (rapido && $('#modalPagoRapido').classList.contains('show')) {
        $('#modalPagoRapido').addEventListener('hidden.bs.modal', function once() {
          $('#modalPagoRapido').removeEventListener('hidden.bs.modal', once);
          bootstrap.Modal.getOrCreateInstance($('#modalPago')).show();
        });
        rapido.hide();
      } else {
        bootstrap.Modal.getOrCreateInstance($('#modalPago')).show();
      }
    }

    toggleOpcionSaldoFavor(select, credito) {
      if (!select) return;
      var opt = select.querySelector('option[value="saldo_favor"]');
      if (credito > 0.009) {
        if (!opt) {
          opt = document.createElement('option');
          opt.value = 'saldo_favor';
          opt.textContent = 'Saldo a favor';
          select.appendChild(opt);
        }
      } else if (opt) {
        if (select.value === 'saldo_favor') select.value = '';
        opt.remove();
      }
    }

    mostrarCreditoPago(cliente, saldoFactura) {
      var credito = cliente ? Number(cliente.credito || 0) : 0;
      var caja = $('#cajaPagoCredito');
      if (caja) caja.classList.toggle('d-none', credito <= 0.009);
      var info = $('#pagoCreditoInfo');
      if (info) info.textContent = credito > 0.009
        ? 'El cliente dispone de un saldo a favor de ' + this.money(credito) + '.'
        : '';
      this.toggleOpcionSaldoFavor($('#pagoMetodo'), credito);
      this.limitarMontoSaldoFavor($('#pagoMetodo'), $('#pagoMonto'), credito, saldoFactura);
    }

    mostrarCreditoAbono(clienteId) {
      var c = this.cliente(clienteId);
      var credito = c ? Number(c.credito || 0) : 0;
      var caja = $('#cajaAbonoCredito');
      if (caja) caja.classList.toggle('d-none', credito <= 0.009);
      var info = $('#abonoCreditoInfo');
      if (info) info.textContent = credito > 0.009
        ? 'El cliente dispone de un saldo a favor de ' + this.money(credito) + '.'
        : '';
      this.toggleOpcionSaldoFavor($('#abonoMetodo'), credito);
    }

    prefijarTasaPago() {
      var tasa = $('#pagoTasa');
      if (!tasa) return;
      if (!tasa.value && this.tasaVigente > 0) tasa.value = this.tasaVigente.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
      this.recalcularPagoDesdeUsd();
    }

    tasaPago() {
      var n = this.num($('#pagoTasa') ? $('#pagoTasa').value : '');
      return n > 0 ? n : 0;
    }

    recalcularPagoDesdeUsd() {
      var tasa = this.tasaPago();
      var usd = this.num($('#pagoMonto').value);
      if (!$('#pagoMontoBs') || tasa <= 0 || !(usd > 0)) return;
      if (this.pagoCalc === 'bs') return;
      this.pagoCalc = 'usd';
      $('#pagoMontoBs').value = this.r2(usd * tasa).toFixed(2);
      this.pagoCalc = '';
    }

    recalcularPagoDesdeBs() {
      var tasa = this.tasaPago();
      var bs = this.num($('#pagoMontoBs').value);
      if (!$('#pagoMonto') || tasa <= 0 || !(bs > 0)) return;
      if (this.pagoCalc === 'usd') return;
      this.pagoCalc = 'bs';
      $('#pagoMonto').value = this.r2(bs / tasa).toFixed(2);
      this.pagoCalc = '';
    }

    limitarMontoSaldoFavor(select, input, credito, tope) {
      if (!select || !input || select.value !== 'saldo_favor' || credito <= 0.009) return;
      var max = this.r2(Math.min(credito, tope == null ? credito : tope));
      if (this.num(input.value) > max + 0.009) input.value = max.toFixed(2);
    }

    /* ---------- abono / devolución ---------- */

    previewAbono() {
      var id = $('#abonoCliente').value, box = $('#abonoPreview');
      box.textContent = '';
      if (!id) { $('#abonoDeuda').textContent = 'Selecciona un cliente'; box.textContent = 'Selecciona un cliente para ver sus facturas pendientes.'; return; }
      $('#abonoDeuda').textContent = 'Deuda acumulada: ' + this.money(this.resumenCliente(id).saldo);
      var resto = this.r2(this.num($('#abonoMonto').value)), partes = [];
      var self = this;
      this.pendientes(id).forEach(function (f) {
        var aplica = self.r2(Math.min(resto, self.saldo(f)));
        partes.push({ f: f, aplica: aplica });
        resto = self.r2(resto - aplica);
      });
      partes.forEach(function (x) {
        box.appendChild(self.h('div', { class: 'd-flex justify-content-between align-items-center border-bottom py-1' }, [
          self.h('span', {}, [self.badge(x.f), ' ', self.h('strong', { text: x.f.numero }), ' · vence ' + self.dmy(x.f.vence) + ' · saldo ' + self.money(self.saldo(x.f))]),
          self.h('span', { class: x.aplica > 0 ? 'text-success fw-semibold' : 'text-muted', text: x.aplica > 0 ? '− ' + self.money(x.aplica) + (x.aplica >= self.saldo(x.f) ? ' (salda)' : '') : 'sin aplicar' })
        ]));
      });
      if (!partes.length) box.textContent = 'Este cliente no tiene facturas pendientes.';
      if (resto > 0) box.appendChild(this.h('div', { class: 'text-primary mt-1', text: 'Sobran ' + this.money(resto) + ': quedarán como saldo a favor del cliente.' }));
    }

    opcionesFacturas(sel, id, soloConSaldo, vacio) {
      var actual = sel.value;
      var self = this;
      sel.textContent = '';
      sel.appendChild(this.h('option', { value: '', text: vacio }));
      this.facturasDe(id).forEach(function (f) {
        if (f.estado === 'anulada') return;
        if (soloConSaldo && self.saldo(f) <= 0.009) return;
        sel.appendChild(self.h('option', { value: f.id, text: f.numero + ' · ' + self.estadoFactura(f).texto + ' · saldo ' + self.money(self.saldo(f)) }));
      });
      sel.value = actual;
    }

    prepararDevolucion() {
      var form = $('#formDevolucion');
      form.reset();
      this.marcarErrores(form, {});
      this.limpiarFilas($('#tablaDevolucion'));
      $('#devCliente').value = this.ui.cliente != null ? this.ui.cliente : '';
      $('#devCliente').dispatchEvent(new Event('change'));
      $('#devFecha').value = this.hoy;
      $('#devDestino').dispatchEvent(new Event('change'));
    }

    /* ---------- cableado de listeners ---------- */

    conectarEventos() {
      var self = this;

      var campana = $('#btnNotificaciones');
      if (campana) {
        campana.addEventListener('show.bs.dropdown', function () { self.cargarAlertasCampana(); });
      }
      var listaCxc = $('#listaFacturasCxC');
      if (listaCxc) {
        listaCxc.addEventListener('click', function (e) {
          var a = e.target.closest('a.js-alerta-cxc');
          if (!a) return;
          e.preventDefault();
          self.abrirClienteDesdeAlerta(a.getAttribute('data-cliente-id'));
        });
      }

      /* Lista: #formBuscar, #qClientes, #btnLimpiar, #pillsEstado, #btnVolver */
      $('#formBuscar').addEventListener('submit', function (e) { e.preventDefault(); self.ui.q = $('#qClientes').value.trim(); self.renderClientes(); });
      $('#qClientes').addEventListener('input', function () { self.ui.q = this.value.trim(); self.renderClientes(); });
      $('#btnLimpiar').addEventListener('click', function () { $('#qClientes').value = ''; self.ui.q = ''; self.setEstado(''); });
      $('#pillsEstado').addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) self.setEstado(b.dataset.estado); });
      $('#btnVolver').addEventListener('click', function () { self.volver(); });

      /* Productos: .js-agregar-fila, #tablaFactura, #tablaDevolucion */
      $$('.js-agregar-fila').forEach(function (b) {
        b.addEventListener('click', function () { self.agregarFila(document.getElementById(b.dataset.tabla)); });
      });
      $$('#tablaFactura, #tablaDevolucion').forEach(function (t) {
        t.addEventListener('input', function () { self.recalcular(t); });
      });

      /* #formCliente — submit; #modalCliente — reset si se abre con el botón del header */
      var formCliente = $('#formCliente');
      $('#modalCliente').addEventListener('show.bs.modal', function (e) {
        if (e.relatedTarget) {
          formCliente.reset();
          formCliente.elements.id.value = '';
          $('#tituloCliente').textContent = 'Nuevo Cliente';
          self.marcarErrores(formCliente, {});
        }
      });
      formCliente.addEventListener('submit', function (e) {
        e.preventDefault();
        self.enviar(formCliente, 'cliente', null, self.validarFormulario(formCliente));
      });

      /* #formFactura, #facEmision, #facCliente, input[name=modo_numero] */
      var formFactura = $('#formFactura');
      $('#modalFactura').addEventListener('show.bs.modal', function () {
        formFactura.reset();
        self.marcarErrores(formFactura, {});
        self.resetNumeroFactura();
        self.limpiarFilas($('#tablaFactura'));
        self.agregarFila($('#tablaFactura'));
        if (self.ui.cliente != null) { $('#facCliente').value = self.ui.cliente; $('#facCliente').dispatchEvent(new Event('change')); }
        $('#facCreditoInfo').textContent = '';
        $('#facVence').min = $('#facEmision').value || self.hoy;
      });
      $('#facEmision').addEventListener('change', function () { $('#facVence').min = this.value || self.hoy; });
      $('#facCliente').addEventListener('change', function () {
        var opt = this.selectedOptions[0];
        var c = self.cliente(this.value);
        var dom = (opt && opt.dataset.domicilio) || (c && c.domicilio) || '';
        $('#facDom').value = dom;
        var credito = c ? Number(c.credito || 0) : 0;
        $('#facCredito').disabled = credito <= 0;
        if (credito <= 0) $('#facCredito').checked = false;
        $('#facCreditoInfo').textContent = credito > 0 ? 'Saldo a favor disponible: ' + self.money(credito) : '';
      });
      $$('input[name="modo_numero"]').forEach(function (radio) {
        radio.addEventListener('change', function () { self.onModoNumeroChange(); });
      });
      formFactura.addEventListener('submit', function (e) {
        e.preventDefault();
        var numero = $('#facNumero');
        var extra = self.validarFormulario(formFactura);
        Object.assign(extra, self.validarRenglones($('#tablaFactura')));
        if (!$('#numAleatorio').checked && !numero.value.trim()) extra.numero = 'Escribe el número de factura o elige aleatorio.';
        /* numero se toma de input.value aunque esté disabled (formToJson). */
        self.enviar(formFactura, 'factura', { numero: numero.value.trim(), renglones: self.renglones($('#tablaFactura')) }, extra);
      });

      /* #modalPagoRapido, #qPago, #btnLimpiarPago, #formPago */
      var formPago = $('#formPago');
      $('#modalPagoRapido').addEventListener('show.bs.modal', function () {
        $('#qPago').value = '';
        self.buscarPago();
        setTimeout(function () { $('#qPago').focus(); }, 300);
      });
      $('#qPago').addEventListener('input', function () {
        clearTimeout(self.buscarTimer);
        self.buscarTimer = setTimeout(function () { self.buscarPago(); }, 150);
      });
      $('#btnLimpiarPago').addEventListener('click', function () { $('#qPago').value = ''; self.buscarPago(); });
      formPago.addEventListener('submit', function (e) {
        e.preventDefault();
        var f = self.factura(formPago.elements.factura_id.value);
        var extra = self.validarFormulario(formPago);
        if (f && self.num($('#pagoMonto').value) > self.saldo(f) + 0.009) extra.monto = 'El pago supera el saldo de la factura (' + self.money(self.saldo(f)) + ').';
        var cli = f ? self.cliente(f.cliente_id) : null;
        if (formPago.elements.metodo.value === 'saldo_favor') {
          var favor = cli ? Number(cli.credito || 0) : 0;
          if (favor <= 0.009) extra.metodo = 'Este cliente no tiene saldo a favor.';
          else if (self.num($('#pagoMonto').value) > favor + 0.009) extra.monto = 'El monto supera el saldo a favor (' + self.money(favor) + ').';
        }
        self.enviar(formPago, 'pago', null, extra);
      });
      $('#pagoMetodo').addEventListener('change', function () {
        var f = self.factura(formPago.elements.factura_id.value);
        var cli = f ? self.cliente(f.cliente_id) : null;
        self.limitarMontoSaldoFavor($('#pagoMetodo'), $('#pagoMonto'), cli ? Number(cli.credito || 0) : 0, f ? self.saldo(f) : null);
        self.recalcularPagoDesdeUsd();
      });
      $('#pagoMonto').addEventListener('input', function () { self.recalcularPagoDesdeUsd(); });
      $('#pagoMontoBs').addEventListener('input', function () { self.recalcularPagoDesdeBs(); });
      $('#pagoTasa').addEventListener('input', function () {
        if (self.pagoCalc === 'bs') self.recalcularPagoDesdeBs();
        else self.recalcularPagoDesdeUsd();
      });

      /* #formAbono, #abonoCliente, #abonoMonto */
      var formAbono = $('#formAbono');
      $('#modalAbono').addEventListener('show.bs.modal', function () {
        formAbono.reset();
        self.marcarErrores(formAbono, {});
        self.renderSelectClientes();
        if (self.ui.cliente != null) $('#abonoCliente').value = self.ui.cliente;
        self.mostrarCreditoAbono($('#abonoCliente').value);
        self.previewAbono();
      });
      $('#abonoCliente').addEventListener('change', function () {
        self.mostrarCreditoAbono($('#abonoCliente').value);
        self.previewAbono();
      });
      $('#abonoMetodo').addEventListener('change', function () {
        var c = self.cliente($('#abonoCliente').value);
        self.limitarMontoSaldoFavor($('#abonoMetodo'), $('#abonoMonto'), c ? Number(c.credito || 0) : 0, c ? self.resumenCliente(c.id).saldo : null);
      });
      $('#abonoMonto').addEventListener('input', function () { self.previewAbono(); });
      formAbono.addEventListener('submit', function (e) {
        e.preventDefault();
        var extra = self.validarFormulario(formAbono);
        if (formAbono.elements.metodo.value === 'saldo_favor') {
          var cAb = self.cliente($('#abonoCliente').value);
          var favorAb = cAb ? Number(cAb.credito || 0) : 0;
          if (favorAb <= 0.009) extra.metodo = 'Este cliente no tiene saldo a favor.';
          else if (self.num($('#abonoMonto').value) > favorAb + 0.009) extra.monto = 'El monto supera el saldo a favor (' + self.money(favorAb) + ').';
        }
        self.enviar(formAbono, 'abono', null, extra);
      });

      /* #formDevolucion, #devCliente, #devDestino, #modalDevolucion */
      var formDev = $('#formDevolucion');
      $('#devCliente').addEventListener('change', function () {
        self.opcionesFacturas($('#devOrigen'), this.value, false, '—');
        self.opcionesFacturas($('#devOtra'), this.value, true, 'Selecciona…');
        self.cargarRenglonesOrigen($('#devOrigen').value);
      });
      $('#devOrigen').addEventListener('change', function () { self.cargarRenglonesOrigen(this.value); });
      $('#devDestino').addEventListener('change', function () { $('#cajaOtra').classList.toggle('d-none', this.value !== 'otra'); });
      $('#modalDevolucion').addEventListener('show.bs.modal', function () { self.prepararDevolucion(); });
      formDev.addEventListener('submit', function (e) {
        e.preventDefault();
        var tablaDev = $('#tablaDevolucion');
        self.recalcular(tablaDev);
        var destino = $('#devDestino').value, errores = self.validarFormulario(formDev);
        Object.assign(errores, self.validarRenglones(tablaDev));
        if (destino === 'origen' && !$('#devOrigen').value) errores.factura_id = 'Para descontar de la factura de origen, selecciónala.';
        if (destino === 'otra' && !$('#devOtra').value) errores.factura_destino = 'Selecciona la factura vigente a descontar.';
        var destinoF = self.factura(destino === 'origen' ? $('#devOrigen').value : $('#devOtra').value);
        if (destinoF && destino !== 'credito' && self.num($('#devMonto').value) > self.saldo(destinoF) + 0.009) {
          errores[destino === 'origen' ? 'factura_id' : 'factura_destino'] = 'La devolución supera el saldo de ' + destinoF.numero + ' (' + self.money(self.saldo(destinoF)) + '). Déjala como crédito.';
        }
        self.enviar(formDev, 'devolucion', { renglones: self.renglones(tablaDev) }, errores);
      });
    }
  }

  function boot() {
    if (!document.getElementById('cfApp')) return;
    var app = new CxcFacturas();
    app.init();
    global.adaraFetch = adaraFetch;
    global.AdaraHttpError = AdaraHttpError;
    global.CxcFacturas = app;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
