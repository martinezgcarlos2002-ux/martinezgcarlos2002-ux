/**
 * Logística y transporte — ficha, rutas, fletes, cierres y pagos.
 * Red: adaraFetch (JSON). CSRF: meta[name=csrf-token].
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
    if (options.body != null) headers.set('Content-Type', 'application/json');
    var token = csrfToken();
    if (token) headers.set('X-CSRF-TOKEN', token);
    var body = options.body;
    if (body != null && typeof body === 'object') body = JSON.stringify(body);
    var res;
    try {
      res = await fetch(url, {
        method: options.method || 'GET',
        headers: headers,
        body: body,
        credentials: 'same-origin'
      });
    } catch (e) {
      throw new AdaraHttpError(0, { ok: false, errores: { general: 'Error de conexión. Intenta de nuevo.' } });
    }
    var json;
    try { json = JSON.parse(await res.text()); } catch (e) { json = { ok: false, errores: { general: 'Respuesta inválida.' } }; }
    if (!res.ok || json.ok !== true) throw new AdaraHttpError(res.status, json);
    return json;
  }

  function $(sel, root) { return (root || document).querySelector(sel); }
  function money(n) {
    return '$' + (Math.round(Number(n || 0) * 100) / 100).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function litros(n) {
    return (Math.round(Number(n || 0) * 100) / 100).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' L';
  }
  function dmy(iso) {
    if (!iso) return '—';
    var p = String(iso).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
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

  function submitBtn(form) {
    return form.querySelector('[type=submit]');
  }

  function bloquearSubmit(form) {
    var btn = submitBtn(form);
    if (btn) {
      if (btn.disabled) return false;
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
    }
    return true;
  }

  function desbloquearSubmit(form) {
    var btn = submitBtn(form);
    if (btn) {
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
    }
  }

  var app = document.getElementById('trApp');
  if (!app) return;

  var ep = JSON.parse(app.dataset.endpoints);
  var hoy = app.dataset.hoy;
  var puedeCrear = app.dataset.crear === '1';
  var puedeEditar = app.dataset.editar === '1';
  var seed = JSON.parse(document.getElementById('trDatos').textContent);
  var lista = seed.transportistas || [];
  var productores = seed.productores || [];
  var vehiculos = seed.vehiculos || {};
  var tiposPago = seed.tiposPago || { efectivo: 'Efectivo', transferencia: 'Transferencia', pago_movil: 'Pago móvil', mercancia: 'Mercancía', otros: 'Otros' };
  var actualId = null;

  function toast(msg, ok) {
    var box = document.getElementById('trToasts');
    if (!box) return;
    var tono = ok === 'warn' ? 'warning' : (ok ? 'success' : 'danger');
    var el = h('div', { class: 'toast align-items-center text-bg-' + tono + ' border-0 show', role: 'alert' }, [
      h('div', { class: 'd-flex' }, [
        h('div', { class: 'toast-body', text: msg }),
        h('button', { type: 'button', class: 'btn-close ' + (tono === 'warning' ? '' : 'btn-close-white ') + 'me-2 m-auto', 'data-bs-dismiss': 'toast' })
      ])
    ]);
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, 6500);
  }

  function pintarKpis(m) {
    m = m || {};
    var gS = $('[data-kpi="gasto_semanal"]');
    var gD = $('[data-kpi="gasto_diario"]');
    var pC = $('[data-kpi="promedio_carga"]');
    if (gS) gS.textContent = money(m.gasto_semanal);
    if (gD) gD.textContent = money(m.gasto_diario);
    if (pC) pC.textContent = litros(m.promedio_carga);
    var ph = $('[data-kpi="pagado_historico"]');
    var dp = $('[data-kpi="deuda_pendiente"]');
    var lh = $('[data-kpi="litros_historico"]');
    if (ph) ph.textContent = money(m.pagado_historico);
    if (dp) dp.textContent = money(m.deuda_pendiente);
    if (lh) lh.textContent = litros(m.litros_historico);
  }

  function reemplazar(tr) {
    var i = lista.findIndex(function (x) { return x.id === tr.id; });
    if (i >= 0) lista[i] = tr;
    else lista.push(tr);
    lista.sort(function (a, b) { return String(a.nombre).localeCompare(String(b.nombre), 'es'); });
  }

  function actual() {
    return lista.find(function (x) { return x.id === actualId; }) || null;
  }

  function pintarLista() {
    var tb = document.getElementById('tbTransportistas');
    tb.replaceChildren();
    if (!lista.length) {
      tb.appendChild(h('tr', {}, [h('td', { colspan: '7', class: 'text-muted text-center py-4', text: 'Sin transportistas.' })]));
      return;
    }
    lista.forEach(function (t) {
      var btn = h('button', { type: 'button', class: 'btn btn-sm btn-outline-primary rounded-pill', text: 'Abrir' });
      btn.addEventListener('click', function () { abrirFicha(t.id); });
      var acc = [btn];
      if (puedeEditar) {
        var est = t.estado === 'activo' ? 'inactivo' : 'activo';
        var tog = h('button', {
          type: 'button',
          class: 'btn btn-sm ' + (t.estado === 'activo' ? 'btn-outline-danger' : 'btn-outline-success') + ' rounded-pill',
          text: t.estado === 'activo' ? 'Desactivar' : 'Activar'
        });
        tog.addEventListener('click', function () { cambiarEstado(t, est); });
        acc.push(tog);
      }
      tb.appendChild(h('tr', {}, [
        h('td', { class: 'fw-semibold', text: t.nombre }),
        h('td', { text: t.documento || '—' }),
        h('td', { text: t.modalidad === 'cooperativa' ? 'Cooperativa' : 'Individual' }),
        h('td', { text: String(t.cantidad_vehiculos || (t.vehiculos || []).length || 0) + ' unid.' }),
        h('td', { class: 'text-end', text: Number(t.capacidad_total || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 }) }),
        h('td', {}, [h('span', { class: 'badge ' + (t.estado === 'activo' ? 'text-bg-success' : 'text-bg-secondary'), text: t.estado })]),
        h('td', { class: 'text-end' }, [h('div', { class: 'd-flex flex-wrap gap-1 justify-content-end' }, acc)])
      ]));
    });
  }

  function abrirFicha(id) {
    actualId = id;
    document.getElementById('vistaLista').classList.add('d-none');
    document.getElementById('vistaFicha').classList.remove('d-none');
    pintarFicha();
  }

  function volver() {
    actualId = null;
    document.getElementById('vistaFicha').classList.add('d-none');
    document.getElementById('vistaLista').classList.remove('d-none');
    pintarLista();
  }

  function pintarFicha() {
    var t = actual();
    if (!t) { volver(); return; }
    document.getElementById('trNombre').textContent = t.nombre;
    document.getElementById('trMeta').textContent = (t.documento || 'sin documento') + ' · ' +
      (t.modalidad === 'cooperativa' ? 'Cooperativa' : 'Individual') +
      ' · ' + (t.cantidad_vehiculos || (t.vehiculos || []).length || 0) + ' vehículo(s)' +
      ' · capacidad ' + Number(t.capacidad_total || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 }) + ' kg';
    pintarRutas(t);
    pintarFletes(t);
    pintarPagos(t);
  }

  function pintarRutas(t) {
    var tb = document.getElementById('tbRutas');
    tb.replaceChildren();
    var rutas = (t.rutas || []).filter(function (r) { return r.estado !== 'inactivo'; });
    if (!rutas.length) {
      tb.appendChild(h('tr', {}, [h('td', { colspan: '6', class: 'text-muted text-center py-3', text: 'Sin rutas. Usa Armar Ruta.' })]));
      return;
    }
    rutas.forEach(function (r) {
      var nombres = (r.productores || []).map(function (p) { return p.nombre; }).join(', ') || '—';
      var acc = [];
      if (puedeEditar || puedeCrear) {
        var ed = h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary rounded-pill', text: 'Editar' });
        ed.addEventListener('click', function () { abrirEditarRuta(r); });
        acc.push(ed);
      }
      var nVeh = Number(r.vehiculos_asignados || 1);
      var sem = r.costo_semanal != null ? Number(r.costo_semanal) : Number(r.precio_viaje || 0) * nVeh;
      tb.appendChild(h('tr', {}, [
        h('td', { class: 'fw-semibold', text: r.nombre_zona }),
        h('td', { class: 'text-end', text: money(r.precio_viaje) }),
        h('td', { class: 'text-end', text: String(nVeh) }),
        h('td', { class: 'text-end fw-semibold', text: money(sem) }),
        h('td', { class: 'small', text: nombres }),
        h('td', { class: 'text-end' }, acc)
      ]));
    });
  }

  function pintarFletes(t) {
    var tb = document.getElementById('tbFletes');
    tb.replaceChildren();
    var rows = t.fletes || [];
    if (!rows.length) {
      tb.appendChild(h('tr', {}, [h('td', { colspan: '7', class: 'text-muted text-center py-3', text: 'Sin viajes registrados.' })]));
      return;
    }
    var leche = precioLeche();
    rows.forEach(function (f) {
      var fleteL = Number(f.costo_litro || 0);
      var totalL = leche + fleteL;
      var alerta = !!f.alerta_flete || fleteL > 0.10;
      tb.appendChild(h('tr', {}, [
        h('td', { text: dmy(f.fecha) }),
        h('td', { text: f.nombre_zona || '—' }),
        h('td', { class: 'text-end', text: litros(f.litros) }),
        h('td', { class: 'text-end', text: money(f.costo) }),
        h('td', { class: 'text-end' }, [
          h('span', { class: 'badge ' + (alerta ? 'badge-flete-alerta' : 'text-bg-secondary'), text: money(fleteL) + '/L' })
        ]),
        h('td', { class: 'text-end', text: money(totalL) + '/L' }),
        h('td', {}, [h('span', { class: 'badge ' + (f.pago_id ? 'text-bg-secondary' : 'text-bg-warning'), text: f.pago_id ? 'Cerrado' : 'Pendiente' })])
      ]));
    });
    pintarResumenLeche();
  }

  function pintarPagos(t) {
    var tb = document.getElementById('tbPagos');
    tb.replaceChildren();
    var rows = t.pagos || [];
    if (!rows.length) {
      tb.appendChild(h('tr', {}, [h('td', { colspan: '7', class: 'text-muted text-center py-3', text: 'Sin cierres semanales.' })]));
      return;
    }
    rows.forEach(function (p) {
      var pdf = h('a', {
        class: 'btn btn-sm btn-outline-secondary rounded-pill',
        href: ep.pdf + (ep.pdf.indexOf('?') >= 0 ? '&' : '?') + 'pago_id=' + encodeURIComponent(p.id),
        target: '_blank',
        rel: 'noopener'
      }, [document.createTextNode('PDF')]);
      var ver = h('button', { type: 'button', class: 'btn btn-sm btn-outline-primary rounded-pill', text: 'Ver detalles' });
      ver.addEventListener('click', function () { verDetalle(p.id); });
      var acc = [ver, pdf];
      if (puedeEditar || puedeCrear) {
        var ico = document.createElement('i');
        ico.className = 'bi bi-unlock';
        var reab = h('button', { type: 'button', class: 'btn btn-sm btn-outline-warning rounded-pill', title: 'Reabrir semana' }, [
          ico,
          document.createTextNode(' Reabrir semana')
        ]);
        reab.addEventListener('click', function () { reabrirSemana(p.id, t.nombre); });
        acc.push(reab);
      }
      if (puedeCrear && p.estado !== 'pagado') {
        var pag = h('button', { type: 'button', class: 'btn btn-sm btn-success rounded-pill', text: 'Pago / adelanto' });
        pag.addEventListener('click', function () { abrirPago(p.id); });
        acc.unshift(pag);
      }
      var wrap = h('div', { class: 'd-flex flex-wrap gap-1 justify-content-end' }, acc);
      tb.appendChild(h('tr', {}, [
        h('td', { text: dmy(p.fecha_inicio) + ' – ' + dmy(p.fecha_fin) }),
        h('td', { class: 'text-end', text: litros(p.total_litros) }),
        h('td', { class: 'text-end', text: money(p.total_pagar) }),
        h('td', { class: 'text-end', text: money(p.pagado_usd) }),
        h('td', { class: 'text-end fw-semibold', text: money(p.neto_usd) }),
        h('td', {}, [h('span', { class: 'badge ' + (p.estado === 'pagado' ? 'text-bg-success' : 'text-bg-warning'), text: p.estado })]),
        h('td', { class: 'text-end' }, [wrap])
      ]));
    });
  }

  function llenarProductores(seleccionados) {
    var caja = document.getElementById('cajaProductores');
    var err = document.getElementById('errProductores');
    err.textContent = '';
    caja.replaceChildren();
    var set = {};
    (seleccionados || []).forEach(function (id) { set[id] = true; });
    if (!productores.length) {
      caja.appendChild(h('div', { class: 'col-12 text-muted small', text: 'No hay productores activos.' }));
      return;
    }
    productores.forEach(function (p) {
      var id = 'prod_' + p.id;
      var chk = h('input', { class: 'form-check-input', type: 'checkbox', value: String(p.id), id: id });
      if (set[p.id]) chk.checked = true;
      chk.addEventListener('change', programarAnalisis);
      caja.appendChild(h('div', { class: 'col-md-6' }, [
        h('div', { class: 'form-check' }, [
          chk,
          h('label', { class: 'form-check-label', for: id, text: p.nombre + (p.documento ? ' · ' + p.documento : '') })
        ])
      ]));
    });
    programarAnalisis();
  }

  function idsProductores() {
    return Array.prototype.slice.call(document.querySelectorAll('#cajaProductores input[type=checkbox]:checked'))
      .map(function (el) { return parseInt(el.value, 10); })
      .filter(function (n) { return n > 0; });
  }

  function llenarRutasSelect() {
    var t = actual();
    var sel = document.getElementById('fleteRuta');
    sel.replaceChildren(h('option', { value: '', text: 'Selecciona…' }));
    (t && t.rutas ? t.rutas : []).filter(function (r) { return r.estado !== 'inactivo'; }).forEach(function (r) {
      sel.appendChild(h('option', { value: String(r.id), text: r.nombre_zona + ' · ' + money(r.precio_viaje) + ' × ' + String(r.vehiculos_asignados || 1) }));
    });
  }

  function aplicarTrasGuardar(j) {
    if (j.metricas) pintarKpis(j.metricas);
    if (j.transportista) {
      reemplazar(j.transportista);
      if (actualId === j.transportista.id || actualId === null) {
        if (actualId === j.transportista.id) pintarFicha();
      }
    }
    pintarLista();
    if (actualId) pintarFicha();
  }

  function abrirEditarRuta(r) {
    document.getElementById('formRuta').reset();
    document.querySelector('#formRuta [name=ruta_id]').value = String(r.id);
    document.getElementById('rutaZona').value = r.nombre_zona || '';
    document.getElementById('rutaPrecio').value = String(r.precio_viaje || '');
    document.getElementById('rutaNveh').value = String(r.vehiculos_asignados || 1);
    document.getElementById('tituloRuta').textContent = 'Editar ruta';
    llenarProductores((r.productores || []).map(function (p) { return p.id; }));
    pintarCostoRuta();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRuta')).show();
  }

  function abrirPago(pagoId) {
    document.getElementById('formPago').reset();
    document.getElementById('pagoCierreId').value = String(pagoId);
    document.getElementById('pagoFecha').value = hoy;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPago')).show();
  }

  async function verDetalle(pagoId) {
    try {
      var j = await adaraFetch(ep.detalle, { method: 'POST', body: { pago_id: pagoId } });
      pintarDetalle(j.detalle);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDetalle')).show();
    } catch (e) {
      toast(e.message || 'No se pudo cargar el detalle.', false);
    }
  }

  function pintarDetalle(d) {
    var caja = document.getElementById('cajaDetalleCierre');
    caja.replaceChildren();
    if (!d) return;
    var tr = d.transportista || {};
    caja.appendChild(h('p', { class: 'mb-2' }, [
      h('strong', { text: tr.nombre || '' }),
      document.createTextNode(' · ' + (tr.documento || '') + ' · ' + (tr.cantidad_vehiculos || 1) + ' vehículo(s)')
    ]));
    var tblR = h('table', { class: 'table table-sm' }, [
      h('thead', {}, [h('tr', {}, [
        h('th', { text: 'Ruta' }),
        h('th', { class: 'text-end', text: 'Precio / veh.' }),
        h('th', { class: 'text-end', text: 'Veh.' }),
        h('th', { class: 'text-end', text: 'Semanal' }),
        h('th', { class: 'text-end', text: 'Litros semana' })
      ])]),
      h('tbody')
    ]);
    var tbR = tblR.querySelector('tbody');
    (d.rutas || []).forEach(function (r) {
      tbR.appendChild(h('tr', {}, [
        h('td', { text: r.nombre_zona }),
        h('td', { class: 'text-end', text: money(r.precio_viaje) }),
        h('td', { class: 'text-end', text: String(r.vehiculos_asignados || 1) }),
        h('td', { class: 'text-end', text: money(r.costo_semanal != null ? r.costo_semanal : r.precio_viaje) }),
        h('td', { class: 'text-end', text: litros(r.litros) })
      ]));
    });
    caja.appendChild(h('h6', { class: 'mt-3', text: 'Rutas' }));
    caja.appendChild(tblR);
    caja.appendChild(h('h6', { class: 'mt-3', text: 'Pagos y adelantos' }));
    var tblP = h('table', { class: 'table table-sm' }, [
      h('thead', {}, [h('tr', {}, [h('th', { text: 'Fecha' }), h('th', { text: 'Tipo' }), h('th', { text: 'Descripción' }), h('th', { class: 'text-end', text: 'Monto' })])]),
      h('tbody')
    ]);
    var tbP = tblP.querySelector('tbody');
    var adel = d.adelantos || [];
    if (!adel.length) {
      tbP.appendChild(h('tr', {}, [h('td', { colspan: '4', class: 'text-muted text-center', text: 'Sin pagos registrados.' })]));
    }
    adel.forEach(function (a) {
      tbP.appendChild(h('tr', {}, [
        h('td', { text: dmy(a.fecha) }),
        h('td', { text: tiposPago[a.tipo] || a.tipo }),
        h('td', { class: 'small', text: a.descripcion_mercancia || '—' }),
        h('td', { class: 'text-end', text: money(a.monto) })
      ]));
    });
    caja.appendChild(tblP);
    caja.appendChild(h('p', { class: 'mb-0' }, [
      document.createTextNode('Bruto ' + money(d.bruto) + ' · Pagos ' + money(d.pagado) + ' · '),
      h('strong', { text: 'Neto ' + money(d.neto) })
    ]));
  }

  document.getElementById('btnVolver').addEventListener('click', volver);

  var btnArmar = document.getElementById('btnArmarRuta');
  if (btnArmar) {
    btnArmar.addEventListener('click', function () {
      if (!actual()) return;
      document.getElementById('formRuta').reset();
      document.querySelector('#formRuta [name=ruta_id]').value = '';
      document.getElementById('tituloRuta').textContent = 'Armar ruta';
      document.getElementById('rutaNveh').value = '1';
      llenarProductores([]);
      pintarCostoRuta();
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRuta')).show();
    });
  }

  var btnViaje = document.getElementById('btnViaje');
  if (btnViaje) {
    btnViaje.addEventListener('click', function () {
      var t = actual();
      if (!t) return;
      if (!(t.rutas || []).some(function (r) { return r.estado !== 'inactivo'; })) {
        toast('Arma al menos una ruta antes de registrar el viaje.', false);
        return;
      }
      document.getElementById('formFlete').reset();
      document.getElementById('fleteFecha').value = hoy;
      llenarRutasSelect();
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFlete')).show();
    });
  }

  var btnCerrar = document.getElementById('btnCerrar');
  if (btnCerrar) {
    btnCerrar.addEventListener('click', async function () {
      var t = actual();
      if (!t) return;
      if (!window.confirm('¿Generar el cierre semanal con los viajes pendientes de ' + t.nombre + '?')) return;
      try {
        var j = await adaraFetch(ep.cierre, { method: 'POST', body: { transportista_id: t.id } });
        toast(j.mensaje || 'Cierre generado.', true);
        aplicarTrasGuardar(j);
      } catch (e) {
        toast(e.message || 'No se pudo cerrar.', false);
      }
    });
  }

  document.getElementById('formTransportista').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (window.AdaraContacto) AdaraContacto.sync(this);
    if (!bloquearSubmit(this)) return;
    var fd = new FormData(this);
    var nombre = String(fd.get('nombre') || '').trim();
    var doc = String(fd.get('documento') || '').trim();
    var modalidad = String(fd.get('modalidad') || 'individual');
    var listaVeh = leerVehiculos();
    if (nombre.length < 2) { toast('El nombre es obligatorio.', false); desbloquearSubmit(this); return; }
    if (!doc) { toast('El documento es obligatorio y único.', false); desbloquearSubmit(this); return; }
    if (!listaVeh.length) { toast('Registra al menos un vehículo.', false); desbloquearSubmit(this); return; }
    try {
      var j = await adaraFetch(ep.guardar, {
        method: 'POST',
        body: {
          id: String(fd.get('id') || ''),
          nombre: nombre,
          documento: doc,
          telefono: String(fd.get('telefono') || '').trim(),
          modalidad: modalidad,
          vehiculos: listaVeh
        }
      });
      toast(j.mensaje || 'Guardado.', true);
      aplicarTrasGuardar(j);
      bootstrap.Modal.getInstance(document.getElementById('modalTransportista')).hide();
      this.reset();
    } catch (e) {
      toast(e.message || 'No se pudo guardar.', false);
    } finally {
      desbloquearSubmit(this);
    }
  });

  document.getElementById('formRuta').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (!bloquearSubmit(this)) return;
    var t = actual();
    if (!t) { desbloquearSubmit(this); return; }
    var fd = new FormData(this);
    var zona = String(fd.get('nombre_zona') || '').trim();
    var precio = String(fd.get('precio_viaje') || '').trim();
    var nVeh = String(fd.get('vehiculos_asignados') || '1').trim();
    var ids = idsProductores();
    var err = document.getElementById('errProductores');
    err.textContent = '';
    if (zona.length < 2) { toast('Indica el nombre de la zona.', false); desbloquearSubmit(this); return; }
    if (!precio) { toast('Indica el precio por vehículo.', false); desbloquearSubmit(this); return; }
    if (parseInt(nVeh, 10) < 1) { toast('Indica los vehículos asignados.', false); desbloquearSubmit(this); return; }
    if (ids.length < 1) {
      err.textContent = 'Selecciona al menos un productor.';
      toast('La ruta debe incluir al menos un productor.', false);
      desbloquearSubmit(this);
      return;
    }
    try {
      var j = await adaraFetch(ep.ruta, {
        method: 'POST',
        body: {
          transportista_id: t.id,
          ruta_id: String(fd.get('ruta_id') || ''),
          nombre_zona: zona,
          precio_viaje: precio,
          vehiculos_asignados: nVeh,
          productor_ids: ids
        }
      });
      toast(j.mensaje || 'Ruta armada.', true);
      aplicarTrasGuardar(j);
      bootstrap.Modal.getInstance(document.getElementById('modalRuta')).hide();
    } catch (e) {
      toast(e.message || 'No se pudo guardar la ruta.', false);
    } finally {
      desbloquearSubmit(this);
    }
  });

  document.getElementById('formFlete').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (!bloquearSubmit(this)) return;
    var fd = new FormData(this);
    var rutaId = String(fd.get('ruta_id') || '').trim();
    if (!rutaId) { toast('Selecciona una ruta.', false); desbloquearSubmit(this); return; }
    try {
      var j = await adaraFetch(ep.flete, {
        method: 'POST',
        body: {
          ruta_id: rutaId,
          fecha: String(fd.get('fecha') || hoy)
        }
      });
      toast(j.mensaje || 'Viaje registrado.', true);
      if (j.warning) toast(j.warning, 'warn');
      aplicarTrasGuardar(j);
      bootstrap.Modal.getInstance(document.getElementById('modalFlete')).hide();
    } catch (e) {
      toast(e.message || 'No se pudo registrar el viaje.', false);
    } finally {
      desbloquearSubmit(this);
    }
  });

  function precioLeche() {
    var el = document.getElementById('precioLecheRef');
    if (!el) return 0.6;
    var t = String(el.value || '0.60').replace(/\./g, '').replace(',', '.');
    var n = parseFloat(t);
    return isFinite(n) && n >= 0 ? n : 0.6;
  }

  function pintarResumenLeche() {
    var el = document.getElementById('resumenCostoLeche');
    if (!el) return;
    var t = actual();
    var leche = precioLeche();
    var flete = 0;
    ((t && t.fletes) || []).forEach(function (f) {
      if (Number(f.costo_litro || 0) > flete) flete = Number(f.costo_litro || 0);
    });
    el.textContent = 'Ej: ' + money(leche) + ' (Leche) + ' + money(flete) + ' (Flete) = ' + money(leche + flete) + ' / L';
  }

  function modalidadActual() {
    var r = document.querySelector('#formTransportista [name=modalidad]:checked');
    return (r && r.value) || 'individual';
  }

  function syncModalidadUi() {
    var coop = modalidadActual() === 'cooperativa';
    var btn = document.getElementById('btnAgregarVeh');
    if (btn) btn.classList.toggle('d-none', !coop);
    var rows = document.querySelectorAll('#cajaVehiculos .tr-veh-row');
    if (!coop) {
      while (rows.length > 1) {
        rows[rows.length - 1].remove();
        rows = document.querySelectorAll('#cajaVehiculos .tr-veh-row');
      }
      if (!rows.length) agregarVehiculo(null);
    }
    document.querySelectorAll('#cajaVehiculos .btn-quitar-veh').forEach(function (b) {
      b.classList.toggle('d-none', !coop);
    });
  }

  function agregarVehiculo(data) {
    var tpl = document.getElementById('tplVehiculo');
    var caja = document.getElementById('cajaVehiculos');
    if (!tpl || !caja) return;
    var nodo = tpl.content.firstElementChild.cloneNode(true);
    if (data) {
      nodo.querySelector('[name=placa]').value = data.placa || '';
      nodo.querySelector('[name=tipo_vehiculo]').value = data.tipo_vehiculo || 'otro';
      nodo.querySelector('[name=marca]').value = data.marca || '';
      nodo.querySelector('[name=modelo]').value = data.modelo || '';
      nodo.querySelector('[name=color]').value = data.color || '';
      nodo.querySelector('[name=limite_carga_kg]').value = data.limite_carga_kg != null ? String(data.limite_carga_kg) : '';
    }
    nodo.querySelector('.btn-quitar-veh').addEventListener('click', function () {
      if (modalidadActual() !== 'cooperativa') return;
      if (document.querySelectorAll('#cajaVehiculos .tr-veh-row').length < 2) return;
      nodo.remove();
    });
    caja.appendChild(nodo);
    syncModalidadUi();
  }

  function leerVehiculos() {
    return Array.prototype.slice.call(document.querySelectorAll('#cajaVehiculos .tr-veh-row')).map(function (row) {
      return {
        placa: (row.querySelector('[name=placa]') || {}).value || '',
        tipo_vehiculo: (row.querySelector('[name=tipo_vehiculo]') || {}).value || 'otro',
        marca: (row.querySelector('[name=marca]') || {}).value || '',
        modelo: (row.querySelector('[name=modelo]') || {}).value || '',
        color: (row.querySelector('[name=color]') || {}).value || '',
        limite_carga_kg: (row.querySelector('[name=limite_carga_kg]') || {}).value || ''
      };
    });
  }

  function abrirFormTransportista(t) {
    var form = document.getElementById('formTransportista');
    form.reset();
    document.getElementById('cajaVehiculos').replaceChildren();
    document.getElementById('tituloTr').textContent = t ? 'Editar transportista' : 'Nuevo transportista';
    form.querySelector('[name=id]').value = t ? String(t.id) : '';
    document.getElementById('trNom').value = t ? t.nombre : '';
    document.getElementById('trDoc').value = t ? (t.documento || '') : '';
    document.getElementById('trTel').value = t ? (t.telefono || '') : '';
    if (window.AdaraContacto) {
      AdaraContacto.setDocumento('trDoc', t ? (t.documento || '') : '');
      AdaraContacto.setTelefono('trTel', t ? (t.telefono || '') : '');
    }
    var mod = t && t.modalidad === 'cooperativa' ? 'cooperativa' : 'individual';
    document.getElementById(mod === 'cooperativa' ? 'modCoop' : 'modInd').checked = true;
    var listaVeh = (t && t.vehiculos && t.vehiculos.length) ? t.vehiculos : [null];
    listaVeh.forEach(function (v) { agregarVehiculo(v); });
    syncModalidadUi();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTransportista')).show();
  }

  var analisisTimer = null;
  function programarAnalisis() {
    clearTimeout(analisisTimer);
    analisisTimer = setTimeout(consultarAnalisis, 350);
  }

  async function consultarAnalisis() {
    var panel = document.getElementById('panelPlanRuta');
    var t = actual();
    if (!panel || !t) return;
    var ids = idsProductores();
    try {
      var j = await adaraFetch(ep.analisis, {
        method: 'POST',
        body: { transportista_id: t.id, productor_ids: ids }
      });
      var a = j.analisis || {};
      panel.textContent = 'Volumen estimado: ' + litros(a.volumen_estimado) +
        '. Esta cooperativa tiene ' + String(a.vehiculos || 0) +
        ' vehículos con capacidad de ' + Number(a.capacidad_total || 0).toLocaleString('es-VE', { maximumFractionDigits: 2 }) +
        ' kg. Sugerencia: Se requieren ' + String(a.vehiculos_requeridos || 0) + ' vehículos para esta ruta.';
    } catch (e) {
      panel.textContent = e.message || 'No se pudo estimar el volumen.';
    }
  }

  async function cambiarEstado(t, estado) {
    var verbo = estado === 'activo' ? 'activar' : 'desactivar';
    if (!window.confirm('¿' + (estado === 'activo' ? 'Activar' : 'Desactivar') + ' a ' + t.nombre + '?')) return;
    try {
      var j = await adaraFetch(ep.estado, { method: 'POST', body: { id: t.id, estado: estado } });
      toast(j.mensaje || 'Estado actualizado.', true);
      aplicarTrasGuardar(j);
    } catch (e) {
      toast(e.message || 'No se pudo ' + verbo + '.', false);
    }
  }

  function parseMontoUi(s) {
    var t = String(s || '').trim();
    if (!t) return 0;
    if (t.indexOf(',') >= 0) t = t.replace(/\./g, '').replace(',', '.');
    var n = parseFloat(t);
    return isFinite(n) && n >= 0 ? n : 0;
  }

  function pintarCostoRuta() {
    var el = document.getElementById('rutaCostoResumen');
    if (!el) return;
    var p = parseMontoUi(document.getElementById('rutaPrecio').value);
    var n = parseInt(document.getElementById('rutaNveh').value, 10) || 1;
    var sem = p * n;
    var dia = sem / 7;
    el.textContent = 'Costo semanal: ' + money(sem) + ' (' + money(p) + ' × ' + n +
      '). Flete diario: ' + money(dia) + ' (semanal / 7).';
  }

  async function reabrirSemana(pagoId, nombre) {
    if (!window.confirm('¿Reabrir la semana de ' + nombre + '? Se anularán los pagos del cierre y los viajes volverán a pendiente.')) return;
    try {
      var j = await adaraFetch(ep.reabrir, { method: 'POST', body: { pago_id: pagoId } });
      toast(j.mensaje || 'Semana reabierta.', true);
      aplicarTrasGuardar(j);
    } catch (e) {
      toast(e.message || 'No se pudo reabrir.', false);
    }
  }

  document.getElementById('formPago').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (!bloquearSubmit(this)) return;
    var fd = new FormData(this);
    var tipo = String(fd.get('tipo') || '');
    var desc = String(fd.get('descripcion_mercancia') || '').trim();
    if (tipo === 'mercancia' && !desc) {
      toast('Describe la mercancía entregada.', false);
      desbloquearSubmit(this);
      return;
    }
    try {
      var j = await adaraFetch(ep.pago, {
        method: 'POST',
        body: {
          pago_id: String(fd.get('pago_id') || ''),
          fecha: String(fd.get('fecha') || hoy),
          monto: String(fd.get('monto') || '').trim(),
          tipo: tipo,
          descripcion_mercancia: desc
        }
      });
      toast(j.mensaje || 'Pago registrado.', true);
      aplicarTrasGuardar(j);
      bootstrap.Modal.getInstance(document.getElementById('modalPago')).hide();
    } catch (e) {
      toast(e.message || 'No se pudo registrar el pago.', false);
    } finally {
      desbloquearSubmit(this);
    }
  });

  var btnNuevo = document.getElementById('btnNuevoTr');
  if (btnNuevo) {
    btnNuevo.addEventListener('click', function () { abrirFormTransportista(null); });
  }
  var btnEditar = document.getElementById('btnEditarTr');
  if (btnEditar) {
    btnEditar.addEventListener('click', function () {
      var t = actual();
      if (t) abrirFormTransportista(t);
    });
  }
  var btnAgregarVeh = document.getElementById('btnAgregarVeh');
  if (btnAgregarVeh) {
    btnAgregarVeh.addEventListener('click', function () { agregarVehiculo(null); });
  }
  document.querySelectorAll('#formTransportista [name=modalidad]').forEach(function (r) {
    r.addEventListener('change', syncModalidadUi);
  });
  var precioRef = document.getElementById('precioLecheRef');
  if (precioRef) {
    precioRef.addEventListener('input', function () {
      var t = actual();
      if (t) pintarFletes(t);
      else pintarResumenLeche();
    });
  }
  ['rutaPrecio', 'rutaNveh'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', pintarCostoRuta);
  });

  pintarKpis(seed.metricas);
  pintarLista();
})();
