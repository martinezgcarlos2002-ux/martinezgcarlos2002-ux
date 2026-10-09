/**
 * Resumen gerencial — filtros de rango y pintado de KPIs/tablas.
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
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    var qs = options.query ? sep + new URLSearchParams(options.query).toString() : '';
    var res = await fetch(url + qs, {
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
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
  }
  function qty(n, d) {
    d = d == null ? 3 : d;
    var x = Number(n || 0).toLocaleString('es-VE', { minimumFractionDigits: 0, maximumFractionDigits: d });
    return x;
  }
  function dmy(iso) {
    if (!iso) return '—';
    var p = String(iso).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
  }

  function init() {
    var app = document.getElementById('rgApp');
    if (!app) return;
    var ep = JSON.parse(app.getAttribute('data-endpoints') || '{}');
    var hoy = app.getAttribute('data-hoy');
    var datosEl = document.getElementById('rgDatos');
    var datos = {};
    try { datos = JSON.parse((datosEl && datosEl.textContent) || '{}'); } catch (e) { datos = {}; }

    function toast(msg, ok) {
      var el = document.getElementById('rgToast');
      if (!el) return;
      el.textContent = msg;
      el.className = 'alert py-2 ' + (ok ? 'alert-success' : 'alert-danger');
    }

    function kpi(icon, label, valor, sub) {
      var col = document.createElement('div');
      col.className = 'col-md col-6';
      var card = document.createElement('div');
      card.className = 'card resumen-card h-100';
      var body = document.createElement('div');
      body.className = 'card-body';
      var et = document.createElement('div');
      et.className = 'etiqueta';
      et.textContent = label;
      var cifra = document.createElement('div');
      cifra.className = 'cifra';
      cifra.textContent = valor;
      body.appendChild(et);
      body.appendChild(cifra);
      if (sub) {
        var s = document.createElement('div');
        s.className = 'sub';
        s.textContent = sub;
        body.appendChild(s);
      }
      card.appendChild(body);
      col.appendChild(card);
      void icon;
      return col;
    }

    function vacio(tbody, cols, texto) {
      tbody.textContent = '';
      var tr = document.createElement('tr');
      var td = document.createElement('td');
      td.colSpan = cols;
      td.className = 'text-center text-muted py-4';
      td.textContent = texto;
      tr.appendChild(td);
      tbody.appendChild(tr);
    }

    function filaPl(label, valor, cls) {
      var tr = document.createElement('tr');
      if (cls) tr.className = cls;
      var a = document.createElement('td');
      a.className = 'pl-label';
      a.textContent = label;
      var b = document.createElement('td');
      b.className = 'text-end';
      b.textContent = valor;
      tr.appendChild(a);
      tr.appendChild(b);
      return tr;
    }

    function pintar(d) {
      datos = d;
      var k = d.kpis || {};
      var pl = d.pl || {};
      var bal = d.balances || {};
      var periodo = document.getElementById('rgPeriodo');
      if (periodo) periodo.textContent = 'Período: ' + dmy(d.inicio) + ' — ' + dmy(d.fin);
      var kpis = document.getElementById('rgKpis');
      kpis.textContent = '';
      var rend = qty(k.rendimiento || 0, 2) + ' L/kg';
      var prodSub = 'KG ' + qty(k.kg);
      if (Number(k.lts || 0) > 0) prodSub += ' · LTS ' + qty(k.lts);
      if (Number(k.und || 0) > 0) prodSub += ' · UND ' + qty(k.und, 0);
      kpis.appendChild(kpi('droplet', 'Litros', qty(k.litros) + ' L', 'Control de leche'));
      kpis.appendChild(kpi('box', 'Producción', money(k.valor_produccion), prodSub + ' · ' + rend));
      kpis.appendChild(kpi('graph', 'Utilidad', money(pl.utilidad || k.utilidad), 'Margen ' + qty(pl.margen_pct || k.margen_pct || 0, 2) + ' %'));
      kpis.appendChild(kpi('receipt', 'CxC cartera', money(bal.cxc_cartera || k.cxc_cartera), 'Vencida ' + money(bal.cxc_vencida || k.cxc_vencida)));
      kpis.appendChild(kpi('wallet', 'CxP total', money(bal.cxp_total || k.cxp_total), 'Productores + fletes'));

      var plBody = document.getElementById('rgPlBody');
      if (plBody) {
        plBody.textContent = '';
        plBody.appendChild(filaPl('Ingresos', '', 'pl-sec'));
        plBody.appendChild(filaPl('Ventas facturadas', money(pl.ingresos_brutos || k.facturado)));
        plBody.appendChild(filaPl('(-) Devoluciones', money(pl.devoluciones || k.devoluciones)));
        plBody.appendChild(filaPl('Ingresos netos', money(pl.ingresos_netos || k.ingresos_netos), 'pl-total'));
        plBody.appendChild(filaPl('Costos operativos', '', 'pl-sec'));
        plBody.appendChild(filaPl('Costo de leche', money(pl.costo_leche || k.costo_leche)));
        plBody.appendChild(filaPl('Fletes / transporte', money(pl.costo_fletes || k.transporte)));
        plBody.appendChild(filaPl('Total costos', money(pl.costos_operativos), 'pl-total'));
        var util = Number(pl.utilidad || k.utilidad || 0);
        plBody.appendChild(filaPl('Utilidad del período', money(pl.utilidad || k.utilidad), util >= 0 ? 'pl-ok' : 'pl-warn'));
        plBody.appendChild(filaPl('Margen de ganancia', qty(pl.margen_pct || k.margen_pct || 0, 2) + ' %'));
      }

      var balBody = document.getElementById('rgBalBody');
      if (balBody) {
        balBody.textContent = '';
        balBody.appendChild(filaPl('Cuentas por cobrar', '', 'pl-sec'));
        balBody.appendChild(filaPl('Cartera pendiente', money(bal.cxc_cartera)));
        balBody.appendChild(filaPl('Cartera vencida', money(bal.cxc_vencida), 'pl-warn'));
        balBody.appendChild(filaPl('Cobrado en el período', money(bal.cxc_cobrado_periodo || k.cobrado)));
        balBody.appendChild(filaPl('Cuentas por pagar', '', 'pl-sec'));
        balBody.appendChild(filaPl('Productores (cierres)', money(bal.cxp_productores)));
        balBody.appendChild(filaPl('Fletes sin pagar', money(bal.cxp_fletes)));
        balBody.appendChild(filaPl('Total CxP', money(bal.cxp_total), 'pl-total'));
      }

      var prodBody = document.getElementById('rgProdBody');
      var prod = d.produccion || [];
      if (!prod.length) {
        vacio(prodBody, 4, 'Sin producción en el período.');
      } else {
        prodBody.textContent = '';
        prod.forEach(function (p) {
          var tr = document.createElement('tr');
          var a = document.createElement('td'); a.textContent = p.nombre;
          var b = document.createElement('td'); b.className = 'text-end'; b.textContent = qty(p.cantidad);
          var u = document.createElement('td'); u.textContent = p.unidad_medida || 'KG';
          var c = document.createElement('td'); c.className = 'text-end fw-semibold'; c.textContent = money(p.total_usd);
          tr.appendChild(a); tr.appendChild(b); tr.appendChild(u); tr.appendChild(c);
          prodBody.appendChild(tr);
        });
      }

      var cobBody = document.getElementById('rgCobBody');
      var cob = d.cobranzas || [];
      if (!cob.length) {
        vacio(cobBody, 3, 'Sin cobranzas en el período.');
      } else {
        cobBody.textContent = '';
        cob.forEach(function (p) {
          var tr = document.createElement('tr');
          var a = document.createElement('td'); a.textContent = p.etiqueta;
          var b = document.createElement('td'); b.className = 'text-end'; b.textContent = String(p.n);
          var c = document.createElement('td'); c.className = 'text-end fw-semibold'; c.textContent = money(p.total);
          tr.appendChild(a); tr.appendChild(b); tr.appendChild(c);
          cobBody.appendChild(tr);
        });
      }

      var pdf = document.getElementById('rgPdf');
      if (pdf && ep.pdf) {
        var u = new URL(ep.pdf, window.location.origin);
        u.searchParams.set('inicio', d.inicio);
        u.searchParams.set('fin', d.fin);
        if (!u.searchParams.get('p')) {
          pdf.href = ep.pdf + (ep.pdf.indexOf('?') >= 0 ? '&' : '?') + 'inicio=' + encodeURIComponent(d.inicio) + '&fin=' + encodeURIComponent(d.fin);
        } else {
          pdf.href = ep.pdf + (ep.pdf.indexOf('?') >= 0 ? '&' : '?') + 'inicio=' + encodeURIComponent(d.inicio) + '&fin=' + encodeURIComponent(d.fin);
        }
      }
      var u = new URL(window.location.href);
      u.searchParams.set('inicio', d.inicio);
      u.searchParams.set('fin', d.fin);
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', u);
      }
    }

    function rangoActual() {
      return {
        inicio: $('#rgDesde').value,
        fin: $('#rgHasta').value
      };
    }

    async function cargar(rango) {
      try {
        var j = await adaraFetch(ep.datos, { query: rango });
        pintar(j.datos);
      } catch (err) {
        toast(err.message || 'No se pudo cargar el resumen.', false);
      }
    }

    document.getElementById('rgFiltro').addEventListener('submit', function (e) {
      e.preventDefault();
      cargar(rangoActual());
    });
    app.querySelectorAll('[data-rango]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tipo = btn.getAttribute('data-rango');
        if (tipo === 'hoy') {
          $('#rgDesde').value = hoy;
          $('#rgHasta').value = hoy;
        } else if (tipo === 'semana') {
          $('#rgDesde').value = app.getAttribute('data-semana-inicio');
          $('#rgHasta').value = app.getAttribute('data-semana-fin');
        } else if (tipo === 'mes') {
          $('#rgDesde').value = app.getAttribute('data-mes-inicio');
          $('#rgHasta').value = app.getAttribute('data-mes-fin');
        }
        cargar(rangoActual());
      });
    });

    pintar(datos);
    window.adaraFetch = adaraFetch;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
