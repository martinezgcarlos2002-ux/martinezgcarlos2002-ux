/**
 * Recursos Humanos: directorio, nómina, días libres, cobranzas y préstamos.
 * Red: fetch / adaraFetch. CSRF: input[name=_csrf].
 */
(function () {
  'use strict';

  function csrfToken() {
    var el = document.querySelector('input[name="_csrf"]');
    return (el && el.value) || '';
  }

  function inyectarPrestamo(btn) {
    if (!btn) return false;
    var id = btn.getAttribute('data-empleado-id') || btn.getAttribute('data-empleado') || btn.getAttribute('data-id') || '';
    console.log('[ADARA] préstamo modal', { id: id, dataId: btn.getAttribute('data-id') });
    var hidden = document.getElementById('prestamo-empleado-id') || document.getElementById('prestamoEmpleado');
    if (hidden) hidden.value = id;
    var formPr = document.getElementById('formPrestamo');
    if (formPr && id) {
      try {
        var url = new URL(formPr.getAttribute('action') || window.location.href, window.location.origin);
        url.searchParams.set('empleado_id', id);
        formPr.setAttribute('action', url.pathname + url.search);
      } catch (e) {}
    }
    var nombre = document.getElementById('prestamoNombre');
    var deuda = document.getElementById('prestamoDeuda');
    if (nombre) nombre.textContent = btn.getAttribute('data-nombre') || '';
    if (deuda) deuda.textContent = btn.getAttribute('data-deuda') || '$0,00';
    return id !== '';
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest && ev.target.closest('.btn-prestamo');
    if (btn) inyectarPrestamo(btn);
  }, true);

  document.addEventListener('show.bs.modal', function (ev) {
    if (!ev.target || ev.target.id !== 'modalPrestamo') return;
    inyectarPrestamo(ev.relatedTarget && ev.relatedTarget.closest ? ev.relatedTarget.closest('.btn-prestamo') : ev.relatedTarget);
  });

  document.addEventListener('show.bs.modal', function (ev) {
    if (!ev.target || ev.target.id !== 'modalPrestamoEditar') return;
    var btn = ev.relatedTarget;
    if (!btn || !btn.getAttribute) return;
    var id = document.getElementById('editPrestamoId');
    var nombre = document.getElementById('editPrestamoNombre');
    var monto = document.getElementById('editPrestamoMonto');
    var fecha = document.getElementById('editPrestamoFecha');
    var aviso = document.getElementById('editPrestamoMercancia');
    if (id) id.value = btn.getAttribute('data-id') || '';
    if (nombre) nombre.textContent = btn.getAttribute('data-nombre') || '';
    if (monto) monto.value = btn.getAttribute('data-monto') || '';
    if (fecha) fecha.value = btn.getAttribute('data-fecha') || '';
    if (aviso) aviso.classList.toggle('d-none', btn.getAttribute('data-tipo') !== 'mercancia');
  });

  function tasaCambio() {
    var formCobro = document.getElementById('formCobranza');
    var tasaDom = formCobro ? parseFloat(formCobro.getAttribute('data-tasa') || '0') : 0;
    var tasaGlobal = window.TASA_BCV != null ? parseFloat(window.TASA_BCV) : 0;
    return tasaDom > 0 ? tasaDom : (tasaGlobal > 0 ? tasaGlobal : 0);
  }
  function numeroMonto(v) {
    var s = String(v).replace(/[\s$]|bs/gi, '');
    var coma = s.lastIndexOf(','), punto = s.lastIndexOf('.');
    if (coma !== -1 && punto !== -1) {
      s = coma > punto ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
    } else if (coma !== -1) {
      s = (s.match(/,/g) || []).length > 1 ? s.replace(/,/g, '') : s.replace(',', '.');
    } else if ((s.match(/\./g) || []).length > 1) {
      s = s.replace(/\./g, '');
    }
    var n = parseFloat(s);
    return isFinite(n) ? n : null;
  }
  var syncCobro = false;
  document.addEventListener('input', function (ev) {
    var t = ev.target;
    if (syncCobro || !t || (t.id !== 'cobMonto' && t.id !== 'cobMontoBs')) return;
    var tasa = tasaCambio();
    var usd = document.getElementById('cobMonto');
    var bs = document.getElementById('cobMontoBs');
    var moneda = document.getElementById('cobMoneda');
    if (tasa <= 0 || !usd || !bs) return;
    syncCobro = true;
    if (t.id === 'cobMonto') {
      var nUsd = numeroMonto(usd.value);
      bs.value = nUsd === null ? '' : (nUsd * tasa).toFixed(2);
    } else {
      var nBs = numeroMonto(bs.value);
      usd.value = nBs === null ? '' : (nBs / tasa).toFixed(2);
    }
    if (moneda) moneda.value = 'USD';
    syncCobro = false;
  });

  function filtrar(inputId, contadorId) {
    var input = document.getElementById(inputId);
    if (!input) return;
    var pane = input.closest('.tab-pane');
    if (!pane) return;
    var contador = document.getElementById(contadorId);
    var aplicar = function () {
      var q = input.value.toLowerCase().trim();
      var visibles = 0, total = 0;
      pane.querySelectorAll('tbody tr[data-emp]').forEach(function (tr) {
        total++;
        var ok = !q || tr.getAttribute('data-emp').indexOf(q) !== -1;
        tr.classList.toggle('d-none', !ok);
        if (ok) visibles++;
      });
      var tot = pane.querySelector('#filaTotales');
      if (tot) tot.classList.toggle('d-none', q !== '');
      if (contador) contador.textContent = q ? (visibles + ' de ' + total + ' empleados') : (total + ' empleados');
    };
    input.addEventListener('input', aplicar);
    aplicar();
  }
  filtrar('buscarNomina', 'contadorNomina');
  filtrar('buscarDirectorio', 'contadorDirectorio');

  document.querySelectorAll('[data-confirmar]').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      if (!window.confirm(btn.getAttribute('data-confirmar'))) ev.preventDefault();
    });
  });

  var modal = document.getElementById('modalEmpleado');
  var form = document.getElementById('formEmpleado');
  if (modal && form) {
    var campos = {
      empId: 'id', empCedula: 'cedula', empNombres: 'nombres', empApellidos: 'apellidos', empCargo: 'cargo',
      empProfesion: 'profesion', empTelefono: 'telefono', empDireccion: 'direccion', empDomicilio: 'direccion_domicilio',
      empIngreso: 'fecha_ingreso', empSalida: 'fecha_salida', empNacimiento: 'fecha_nacimiento'
    };
    function llenar(d, limpiarErrores) {
      var editar = d.modo === 'editar';
      form.action = modal.getAttribute(editar ? 'data-action-editar' : 'data-action-nuevo');
      document.getElementById('tituloEmpleado').textContent = editar ? 'Editar empleado' : 'Nuevo Empleado';
      Object.keys(campos).forEach(function (id) { document.getElementById(id).value = d[campos[id]] || ''; });
      document.getElementById('empSalario').value = d.salario_semanal_usd || d.salario_semanal || '0';
      document.getElementById('empLibres').value = d.dias_libres_mes !== undefined ? d.dias_libres_mes : 2;
      var propia = d.tipo_cuenta !== 'tercero';
      document.getElementById('empCuentaPropia').checked = propia;
      document.getElementById('empCuentaTercero').checked = !propia;
      document.getElementById('empBanco').value = d.banco || '';
      document.getElementById('empDocPago').value = d.titular_cedula || '';
      document.getElementById('empTelPago').value = d.titular_telefono || '';
      document.getElementById('empNumCuenta').value = d.numero_cuenta || '';
      document.getElementById('empPenal').checked = d.expediente_penal === true || d.expediente_penal === '1';
      document.getElementById('empEstado').value = d.activo === false || d.activo === '0' ? '0' : '1';
      var fotoPerfil = document.getElementById('empFotoPerfil');
      var fotoDoc = document.getElementById('empFotoDoc');
      if (fotoPerfil) fotoPerfil.value = '';
      if (fotoDoc) fotoDoc.value = '';
      if (limpiarErrores) {
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
      }
    }
    modal.addEventListener('show.bs.modal', function (ev) {
      var origen = ev.relatedTarget && ev.relatedTarget.closest('[data-emp]');
      if (!origen) return;
      llenar(JSON.parse(origen.getAttribute('data-emp') || '{"modo":"nuevo"}'), true);
    });
    var reabrir = modal.getAttribute('data-reabrir');
    if (reabrir) {
      llenar(JSON.parse(reabrir), false);
      document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(modal).show(); });
    }

    var btnNuevoCargo = document.getElementById('btnNuevoCargo');
    if (btnNuevoCargo) {
      btnNuevoCargo.addEventListener('click', function () {
        document.getElementById('cajaNuevoCargo').classList.toggle('d-none');
        document.getElementById('nuevoCargoNombre').focus();
      });
    }
    var confirmarCargo = document.getElementById('confirmarCargo');
    if (confirmarCargo) {
      confirmarCargo.addEventListener('click', function () {
        var caja = document.getElementById('cajaNuevoCargo');
        var input = document.getElementById('nuevoCargoNombre');
        var error = document.getElementById('errorNuevoCargo');
        var nombre = input.value.trim();
        error.textContent = '';
        if (!nombre) return;
        var fd = new FormData();
        fd.append('_csrf', csrfToken());
        fd.append('nombre', nombre);
        fetch(caja.getAttribute('data-url'), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.ok) {
              error.textContent = (data.errores && data.errores.nombre) || 'No se pudo crear el cargo.';
              return;
            }
            var sel = document.getElementById('empCargo');
            var existe = Array.prototype.some.call(sel.options, function (o) { return o.value === data.nombre; });
            if (!existe) {
              var opt = document.createElement('option');
              opt.value = data.nombre;
              opt.textContent = data.nombre;
              sel.appendChild(opt);
            }
            sel.value = data.nombre;
            input.value = '';
            caja.classList.add('d-none');
          })
          .catch(function () { error.textContent = 'No se pudo crear el cargo. Revisa la conexión.'; });
      });
    }
  }

  var formLibres = document.getElementById('formLibres');
  var vivas = document.getElementById('alertasVivas');
  var ciclo = { '': 'libre', 'libre': 'no_trabajado', 'no_trabajado': '' };
  function pintarAlertas(data) {
    if (!vivas) return;
    vivas.textContent = '';
    var mensajes = (data.alertas_cobertura || []).map(function (a) { return [a.nivel === 'critica' ? 'danger' : 'warning', a.mensaje]; });
    if (data.cupo && data.cupo.mensaje) mensajes.push(['info', data.cupo.mensaje]);
    mensajes.forEach(function (m) {
      var div = document.createElement('div');
      div.className = 'alert alert-' + m[0] + ' py-2 mb-2 small';
      div.textContent = m[1] + (m[0] === 'info' ? '' : '. Puedes guardar igual.');
      vivas.appendChild(div);
    });
  }
  function pintarCelda(btn, tipo) {
    var hidden = btn.nextElementSibling;
    btn.setAttribute('data-tipo', tipo);
    btn.textContent = tipo === 'libre' ? 'L' : (tipo === 'no_trabajado' ? 'F' : '');
    btn.classList.toggle('rh-dia-libre', tipo === 'libre');
    btn.classList.toggle('rh-dia-falta', tipo === 'no_trabajado');
    btn.classList.remove('rh-dia-exceso');
    hidden.value = tipo;
    if (tipo) hidden.setAttribute('name', hidden.getAttribute('data-name')); else hidden.removeAttribute('name');
  }
  if (formLibres) {
    formLibres.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.js-dia');
      if (!btn || btn.disabled) return;
      var tipo = ciclo[btn.getAttribute('data-tipo')];
      pintarCelda(btn, tipo);
      if (!tipo) { if (vivas) vivas.textContent = ''; return; }
      var fd = new FormData();
      fd.append('_csrf', formLibres.querySelector('input[name="_csrf"]').value);
      fd.append('empleado_id', btn.closest('tr').getAttribute('data-empleado'));
      fd.append('fecha', btn.getAttribute('data-fecha'));
      fd.append('tipo', tipo);
      fetch(formLibres.getAttribute('data-verificar'), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { if (data.ok) pintarAlertas(data); })
        .catch(function () {});
    });
  }

  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    bootstrap.Tooltip.getOrCreateInstance(el);
  });

  var formCobro = document.getElementById('formCobranza');
  if (formCobro) {
    var tasaDom = parseFloat(formCobro.getAttribute('data-tasa') || '0');
    var tasaGlobal = window.TASA_BCV != null ? parseFloat(window.TASA_BCV) : 0;
    var tasaCobro = tasaDom > 0 ? tasaDom : (tasaGlobal > 0 ? tasaGlobal : 0);
    var usdCobro = document.getElementById('cobMonto');
    var bsCobro = document.getElementById('cobMontoBs');
    var monedaCobro = document.getElementById('cobMoneda');
    if (bsCobro && tasaCobro > 0) bsCobro.disabled = false;
    var escribiendo = false;
    var numero = function (v) {
      var s = String(v).replace(/[\s$]|bs/gi, '');
      var coma = s.lastIndexOf(','), punto = s.lastIndexOf('.');
      if (coma !== -1 && punto !== -1) {
        s = coma > punto ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
      } else if (coma !== -1) {
        s = (s.match(/,/g) || []).length > 1 ? s.replace(/,/g, '') : s.replace(',', '.');
      } else if ((s.match(/\./g) || []).length > 1) {
        s = s.replace(/\./g, '');
      }
      var n = parseFloat(s);
      return isFinite(n) ? n : null;
    };
    if (usdCobro) {
      usdCobro.addEventListener('input', function () {
        if (escribiendo) return;
        if (monedaCobro) monedaCobro.value = 'USD';
        if (tasaCobro <= 0 || !bsCobro) return;
        escribiendo = true;
        var n = numero(usdCobro.value);
        bsCobro.value = n === null ? '' : (n * tasaCobro).toFixed(2);
        escribiendo = false;
      });
    }
    if (bsCobro) {
      bsCobro.addEventListener('input', function () {
        if (escribiendo || tasaCobro <= 0) return;
        escribiendo = true;
        var n = numero(bsCobro.value);
        if (usdCobro) usdCobro.value = n === null ? '' : (n / tasaCobro).toFixed(2);
        if (monedaCobro) monedaCobro.value = 'USD';
        escribiendo = false;
      });
    }
    formCobro.addEventListener('submit', function (ev) {
      if (monedaCobro) monedaCobro.value = 'USD';
      if (usdCobro && !usdCobro.value && bsCobro && tasaCobro > 0) {
        var n = numero(bsCobro.value);
        if (n !== null) usdCobro.value = (n / tasaCobro).toFixed(2);
      }
      if (usdCobro && !usdCobro.value) {
        ev.preventDefault();
        window.alert('Indica el monto en USD (o en Bs para convertirlo).');
      }
    });
  }

  function campoPrestamoId() {
    return document.getElementById('prestamo-empleado-id') || document.getElementById('prestamoEmpleado');
  }

  function idDesdeBoton(btn) {
    if (!btn || !btn.getAttribute) return '';
    return btn.getAttribute('data-empleado-id') || btn.getAttribute('data-empleado') || btn.getAttribute('data-id') || '';
  }

  function llenarPrestamo(origen) {
    var btn = origen;
    if (origen && origen.closest) {
      btn = origen.closest('.btn-prestamo') || origen.closest('[data-empleado-id]') || origen.closest('[data-id]');
    }
    if (!btn) return false;
    var id = idDesdeBoton(btn);
    console.log('[ADARA] préstamo modal', { id: id, dataId: btn.getAttribute('data-id') });
    var hidden = campoPrestamoId();
    if (hidden) hidden.value = id;
    var formPr = document.getElementById('formPrestamo') || (document.getElementById('modalPrestamo') && document.getElementById('modalPrestamo').querySelector('form'));
    if (formPr && id) {
      var url = new URL(formPr.getAttribute('action') || window.location.href, window.location.origin);
      url.searchParams.set('empleado_id', id);
      formPr.setAttribute('action', url.pathname + url.search);
    }
    var nombre = document.getElementById('prestamoNombre');
    var deuda = document.getElementById('prestamoDeuda');
    if (nombre) nombre.textContent = btn.getAttribute('data-nombre') || '';
    if (deuda) deuda.textContent = btn.getAttribute('data-deuda') || '$0,00';
    return id !== '';
  }

  var modalPrestamo = document.getElementById('modalPrestamo');
  if (modalPrestamo) {
    document.querySelectorAll('.btn-prestamo').forEach(function (btn) {
      btn.addEventListener('click', function () { llenarPrestamo(btn); });
    });
    modalPrestamo.addEventListener('show.bs.modal', function (ev) {
      llenarPrestamo(ev.relatedTarget);
    });
    modalPrestamo.addEventListener('shown.bs.modal', function (ev) {
      llenarPrestamo(ev.relatedTarget || document.querySelector('.btn-prestamo[aria-expanded="true"]'));
    });
    var formPr = document.getElementById('formPrestamo') || modalPrestamo.querySelector('form');
    if (formPr) {
      formPr.addEventListener('submit', function (ev) {
        var hidden = campoPrestamoId();
        if (!hidden || !hidden.value) {
          ev.preventDefault();
          window.alert('Selecciona el empleado desde el botón Préstamo del directorio.');
        }
      });
    }
  }

  document.querySelectorAll('#rhTabs button[data-bs-toggle="tab"]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', btn.getAttribute('data-bs-target').replace('#pane-', ''));
      history.replaceState(null, '', url);
    });
  });
})();
