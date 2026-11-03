(function () {
  'use strict';

  var cfgEl = document.getElementById('bancosConfig');
  if (!cfgEl) return;
  var cfg = JSON.parse(cfgEl.textContent);
  var input = document.getElementById('archivoConciliacion');
  var boton = document.getElementById('btnSubir');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function bs(n) {
    var v = Number(n);
    if (!isFinite(v)) return String(n);
    var neg = v < 0;
    var abs = Math.abs(v).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return (neg ? '-' : '') + 'Bs. ' + abs;
  }

  function aviso(icon, title, html) {
    if (!window.Swal) {
      window.alert(title);
      return Promise.resolve();
    }
    return Swal.fire({
      icon: icon,
      title: title,
      html: html || '',
      confirmButtonText: 'Entendido',
      heightAuto: false,
      scrollbarPadding: false
    });
  }

  function htmlDescuadre(json) {
    var lista = json.descuadres || [];
    if (!lista.length) {
      var map = (json && json.errores) || {};
      var texto = Object.keys(map).map(function (k) { return map[k]; }).filter(Boolean).join(' ');
      return '<p>' + esc(texto || 'Revisa el Excel y vuelve a subirlo.') + '</p>';
    }
    var html = '<p>No se guardó ningún movimiento. Corrige el Excel y súbelo de nuevo.</p><ul class="bancos-descuadre">';
    lista.slice(0, 8).forEach(function (d) {
      html += '<li><strong>' + esc(d.cuenta) + '</strong>';
      if (d.mensaje) {
        html += '<br>' + esc(d.mensaje);
      } else {
        html += '<br>Diferencia ' + esc(bs(d.diferencia))
          + '. Declarado ' + esc(bs(d.saldo_final_declarado))
          + ', calculado ' + esc(bs(d.saldo_final_calculado)) + '.';
      }
      html += '</li>';
    });
    html += '</ul>';
    if (lista.length > 8) {
      html += '<p>' + (lista.length - 8) + ' cuenta(s) más también descuadran.</p>';
    }
    return html;
  }

  function token() {
    return (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token() },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().then(function (json) { return { res: res, json: json }; });
    });
  }

  function textoCuenta(c) {
    var lineas = [];
    if (c.nombre_banco) lineas.push('Banco: ' + c.nombre_banco);
    if (c.numero_cuenta) lineas.push('Cuenta: ' + c.numero_cuenta);
    if (c.cedula) lineas.push('CI: ' + c.cedula);
    if (c.telefono) lineas.push('Teléfono: ' + c.telefono);
    return lineas.join('\n');
  }

  function copiarTexto(texto) {
    function hecho() {
      aviso('success', 'Datos copiados', '<p>Listos para pegar en WhatsApp.</p>');
    }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = texto;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '0';
      ta.style.left = '0';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try { ta.setSelectionRange(0, texto.length); } catch (e) {}
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e2) { ok = false; }
      document.body.removeChild(ta);
      return ok;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(hecho).catch(function () {
        if (fallback()) hecho();
        else aviso('error', 'No se copió', '<p>Selecciona el texto y cópialo a mano.</p>');
      });
      return;
    }
    if (fallback()) hecho();
    else aviso('error', 'No se copió', '<p>Selecciona el texto y cópialo a mano.</p>');
  }

  function pintarCuentas(lista) {
    var tbody = document.querySelector('#tablaCuentas tbody');
    if (!tbody) return;
    cfg.cuentas = lista || [];
    if (!cfg.cuentas.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-muted">Todavía no hay cuentas. Agrégalas aquí antes de descargar la plantilla.</td></tr>';
      return;
    }
    tbody.innerHTML = cfg.cuentas.map(function (c) {
      var activa = String(c.activa) === '1' || c.activa === 1;
      var acciones = '<button type="button" class="btn btn-sm btn-outline-primary cuenta-copiar">Copiar datos</button>';
      if (cfg.editar && activa) acciones += '<button type="button" class="btn btn-sm btn-outline-secondary cuenta-editar">Editar</button>';
      if (cfg.anular && activa) acciones += '<button type="button" class="btn btn-sm btn-outline-danger cuenta-off">Desactivar</button>';
      if (cfg.editar && !activa) acciones += '<button type="button" class="btn btn-sm btn-outline-secondary cuenta-on">Activar</button>';
      return '<tr data-id="' + esc(c.id) + '"' + (activa ? '' : ' class="text-muted"') + '>'
        + '<td>' + esc(c.alias_cuenta) + '</td>'
        + '<td>' + esc(c.nombre_banco || '') + '</td>'
        + '<td>' + esc(c.numero_cuenta || '') + '</td>'
        + '<td>' + esc(c.cedula || '') + '</td>'
        + '<td>' + esc(c.telefono || '') + '</td>'
        + '<td>' + esc(c.moneda || 'BS') + '</td>'
        + '<td class="text-end">' + esc(bs(c.saldo_actual_bs)) + '</td>'
        + '<td class="text-end"><div class="bancos-acciones">' + acciones + '</div></td></tr>';
    }).join('');
    tbody.querySelectorAll('.cuenta-copiar').forEach(function (btn) {
      var tr = btn.closest('tr');
      var id = tr && tr.getAttribute('data-id');
      var cuenta = cfg.cuentas.filter(function (c) { return String(c.id) === String(id); })[0];
      if (cuenta) btn.setAttribute('data-copiar', cuenta.texto_compartir || textoCuenta(cuenta));
    });
  }

  function leerFormCuenta() {
    return {
      alias: (document.getElementById('ctaAlias').value || '').trim(),
      nombre_banco: document.getElementById('ctaBanco').value || '',
      numero_cuenta: (document.getElementById('ctaNumero').value || '').trim(),
      cedula: (document.getElementById('ctaCedula').value || '').trim(),
      telefono: (document.getElementById('ctaTelefono').value || '').trim(),
      moneda: document.getElementById('ctaMoneda').value || 'BS'
    };
  }

  function limpiarFormCuenta() {
    var form = document.getElementById('formCuenta');
    if (!form) return;
    form.reset();
    delete form.dataset.id;
    var guardar = document.getElementById('ctaGuardar');
    var cancelar = document.getElementById('ctaCancelar');
    if (guardar) {
      guardar.textContent = 'Agregar';
      guardar.hidden = !cfg.crear;
    }
    if (cancelar) cancelar.hidden = true;
    var moneda = document.getElementById('ctaMoneda');
    if (moneda) moneda.value = 'BS';
  }

  function errorCuenta(json) {
    var map = (json && json.errores) || {};
    var texto = Object.keys(map).map(function (k) { return map[k]; }).filter(Boolean).join(' ');
    return aviso('error', 'No se guardó', '<p>' + esc(texto || 'Revisa los datos de la cuenta.') + '</p>');
  }

  var formCuenta = document.getElementById('formCuenta');
  if (formCuenta && (cfg.crear || cfg.editar)) {
    formCuenta.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var id = formCuenta.dataset.id || '';
      if (!id && !cfg.crear) return;
      var body = leerFormCuenta();
      var url = id ? cfg.url.cuentaEditar : cfg.url.cuentaCrear;
      if (id) body.id = id;
      postJson(url, body).then(function (pair) {
        if (!pair.res.ok || !pair.json.ok) return errorCuenta(pair.json);
        limpiarFormCuenta();
        pintarCuentas(pair.json.cuentas);
      }).catch(function () {
        aviso('error', 'No se guardó', '<p>Revisa la conexión y vuelve a intentar.</p>');
      });
    });
    var cancelar = document.getElementById('ctaCancelar');
    if (cancelar) cancelar.addEventListener('click', limpiarFormCuenta);
  }

  var tablaCuentas = document.getElementById('tablaCuentas');
  if (tablaCuentas) {
    tablaCuentas.addEventListener('click', function (ev) {
      var btn = ev.target.closest('button');
      var tr = ev.target.closest('tr');
      if (!btn || !tr) return;
      var id = tr.getAttribute('data-id');
      var cuenta = (cfg.cuentas || []).filter(function (c) { return String(c.id) === String(id); })[0];
      if (btn.classList.contains('cuenta-copiar')) {
        var texto = btn.getAttribute('data-copiar') || (cuenta ? (cuenta.texto_compartir || textoCuenta(cuenta)) : '');
        if (!texto) {
          aviso('error', 'Sin datos', '<p>Esta cuenta no tiene banco, número, CI ni teléfono.</p>');
          return;
        }
        copiarTexto(texto);
        return;
      }
      if (btn.classList.contains('cuenta-editar') && cuenta && formCuenta) {
        document.getElementById('ctaAlias').value = cuenta.alias_cuenta || '';
        document.getElementById('ctaBanco').value = cuenta.nombre_banco || '';
        document.getElementById('ctaNumero').value = cuenta.numero_cuenta || '';
        document.getElementById('ctaCedula').value = cuenta.cedula || '';
        document.getElementById('ctaTelefono').value = cuenta.telefono || '';
        document.getElementById('ctaMoneda').value = cuenta.moneda === 'USD' ? 'USD' : 'BS';
        formCuenta.dataset.id = id;
        var guardar = document.getElementById('ctaGuardar');
        guardar.hidden = false;
        guardar.textContent = 'Guardar cambios';
        document.getElementById('ctaCancelar').hidden = false;
        formCuenta.scrollIntoView({ block: 'nearest' });
        return;
      }
      if (btn.classList.contains('cuenta-off')) {
        var alias = (tr.querySelector('td') || {}).textContent || 'esta cuenta';
        var confirma = window.Swal
          ? Swal.fire({
              icon: 'warning',
              title: 'Desactivar ' + alias.trim(),
              text: 'Deja de salir en la plantilla. Los cierres ya cargados se quedan.',
              showCancelButton: true,
              confirmButtonText: 'Desactivar',
              cancelButtonText: 'Cancelar',
              heightAuto: false,
              customClass: { confirmButton: 'bancos-swal-quitar', cancelButton: 'bancos-swal-cancelar' }
            })
          : Promise.resolve({ isConfirmed: window.confirm('Desactivar ' + alias.trim() + '?') });
        confirma.then(function (r) {
          if (!r || !r.isConfirmed) return;
          return postJson(cfg.url.cuentaOff, { id: id });
        }).then(function (pair) {
          if (!pair) return;
          if (!pair.res.ok || !pair.json.ok) return errorCuenta(pair.json);
          pintarCuentas(pair.json.cuentas);
        }).catch(function () {});
      }
      if (btn.classList.contains('cuenta-on')) {
        postJson(cfg.url.cuentaOn, { id: id }).then(function (pair) {
          if (!pair.res.ok || !pair.json.ok) return errorCuenta(pair.json);
          pintarCuentas(pair.json.cuentas);
        }).catch(function () {
          aviso('error', 'No se activó', '<p>Revisa la conexión y vuelve a intentar.</p>');
        });
      }
    });
  }

  function fechaCorta(iso) {
    var p = String(iso || '').split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : String(iso || '');
  }

  function pintarAuditoria(json) {
    var hBody = document.querySelector('#tablaHuerfanos tbody');
    var fBody = document.querySelector('#tablaFantasmas tbody');
    if (!hBody || !fBody) return;
    var huerfanos = json.huerfanos || [];
    var fantasmas = json.fantasmas || [];
    hBody.innerHTML = huerfanos.length ? huerfanos.map(function (m) {
      return '<tr><td>' + esc(fechaCorta(m.fecha)) + '</td><td>' + esc(m.alias_cuenta) + '</td><td>'
        + esc(m.categoria) + '</td><td>' + esc(m.referencia || '') + '</td><td class="text-end">'
        + esc(bs(m.monto_bs)) + ' ' + esc(m.moneda || 'BS') + '</td></tr>';
    }).join('') : '<tr><td colspan="5" class="text-muted">No hay movimientos huérfanos.</td></tr>';
    fBody.innerHTML = fantasmas.length ? fantasmas.map(function (m) {
      return '<tr><td>' + esc(fechaCorta(m.fecha)) + '</td><td>' + esc(m.modulo) + '</td><td>'
        + esc(m.entidad || '') + '</td><td>' + esc(m.referencia || '') + '</td><td class="text-end">'
        + esc(bs(m.monto)) + '</td></tr>';
    }).join('') : '<tr><td colspan="5" class="text-muted">No hay pagos fantasmas en los últimos 90 días.</td></tr>';
  }

  function cargarAuditoria() {
    if (!cfg.url.auditoria) return;
    fetch(cfg.url.auditoria, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!json || !json.ok) throw new Error('auditoria');
        pintarAuditoria(json);
      })
      .catch(function () {
        pintarAuditoria({ huerfanos: [], fantasmas: [] });
      });
  }

  var tabAud = document.getElementById('tab-auditoria');
  if (tabAud) {
    tabAud.addEventListener('shown.bs.tab', cargarAuditoria);
  }

  if (input && boton && cfg.crear) {
  boton.addEventListener('click', function () {
    input.value = '';
    input.click();
  });

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) return;
    var fd = new FormData();
    fd.append('_csrf', token());
    fd.append('archivo', file);
    boton.disabled = true;
    fetch(cfg.url.subir, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (res) {
      return res.json().then(function (json) { return { res: res, json: json }; });
    }).then(function (pair) {
      var json = pair.json || {};
      if (!pair.res.ok || !json.ok) {
        return aviso('error', 'No se cargó la conciliación', htmlDescuadre(json));
      }
      var r = json.resumen || {};
      var texto = (r.cierres || 0) + ' cierre(s), ' + (r.movimientos || 0) + ' movimiento(s), '
        + (r.cuentas || 0) + ' cuenta(s).';
      if (r.avisos && r.avisos.length) {
        texto += '<br><br>' + r.avisos.slice(0, 5).map(esc).join('<br>');
      }
      return aviso('success', 'Conciliación cargada', '<p>' + texto + '</p>').then(function () {
        window.location.reload();
      });
    }).catch(function () {
      return aviso('error', 'No se pudo subir', '<p>Revisa la conexión y vuelve a intentar.</p>');
    }).finally(function () {
      boton.disabled = false;
      input.value = '';
    });
  });
  }
})();
