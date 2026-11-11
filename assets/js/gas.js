(function () {
  'use strict';

  var cfgEl = document.getElementById('gasConfig');
  if (!cfgEl) return;
  var cfg = JSON.parse(cfgEl.textContent);
  var catalogo = null;
  var detalleId = 0;
  var detalle = null;
  var proveedores = [];
  var cilindros = [];
  var despachos = [];
  var disponibles = [];

  var FISICO = { optimo: 'Óptimas', regular: 'Regular', mal_estado: 'Mal estado' };
  var DEUDA = { pendiente: 'Pendiente', saldo_restante: 'Saldo restante', cancelado: 'Cancelado' };
  var PROV = { activo: 'Activo', inactivo: 'Inactivo', eliminado: 'Archivado' };
  var METODO = { efectivo: 'Efectivo', pago_movil: 'Pago móvil', transferencia: 'Transferencia', otro: 'Otro' };

  function usd(n) {
    return Number(n || 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fecha(iso) {
    if (!iso) return '—';
    var p = String(iso).slice(0, 10).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function swal(opts) {
    if (!window.Swal) return Promise.resolve({ isConfirmed: false });
    return Swal.fire(Object.assign({ heightAuto: false, scrollbarPadding: false }, opts));
  }
  function aviso(icon, title, text) {
    if (window.Swal) return swal({ icon: icon, title: title, text: text || '', confirmButtonText: 'Entendido' });
    window.alert((title ? title + '\n' : '') + (text || ''));
    return Promise.resolve();
  }
  function erroresDe(err) {
    var map = (err && err.errores) || {};
    return Object.keys(map).map(function (k) { return map[k]; }).filter(Boolean).join(' ') || 'No se pudo completar la operación.';
  }

  /** Gas necesita respuesta del servidor (ids y saldos): sin señal se avisa y no se guarda. */
  async function pedir(url, opts) {
    try {
      return await fetch(url, opts);
    } catch (e) {
      var err = new Error(navigator.onLine === false
        ? 'Sin conexión: este registro de gas no se guardó. Inténtalo cuando vuelva la señal.'
        : 'No se pudo contactar al servidor. Revisa la conexión e inténtalo de nuevo.');
      err.errores = { general: err.message };
      throw err;
    }
  }

  async function apiForm(url, fd) {
    var res = await pedir(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
      },
      body: fd
    });
    var json;
    try { json = await res.json(); } catch (e) { json = { ok: false, errores: { general: 'Respuesta inválida del servidor.' } }; }
    if (!res.ok || !json.ok) {
      var err = new Error(erroresDe(json));
      err.errores = json.errores || { general: err.message };
      throw err;
    }
    return json;
  }

  async function api(url, body) {
    var headers = { Accept: 'application/json' };
    var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: headers };
    if (body) {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-TOKEN'] = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
      opts.body = JSON.stringify(body);
    }
    var res = await pedir(url, opts);
    var json;
    try { json = await res.json(); } catch (e) { json = { ok: false, errores: { general: 'Respuesta inválida del servidor.' } }; }
    if (!res.ok || !json.ok) {
      var err = new Error(erroresDe(json));
      err.errores = json.errores || { general: err.message };
      throw err;
    }
    return json;
  }

  function filas(tbody, html) {
    tbody.innerHTML = html || '<tr><td colspan="6" class="text-muted px-3 py-3">Sin registros.</td></tr>';
  }

  function pintarResumen(data) {
    document.getElementById('gasSemanaLabel').textContent = 'Semana ' + fecha(data.semana_inicio) + ' al ' + fecha(data.semana_fin)
      + (data.cargos_pendientes ? ' · ' + data.cargos_pendientes + ' llenados de terceros por cobrar' : '');
    var s = data.semana || {};
    document.getElementById('kpiInvertido').textContent = '$ ' + usd(s.invertido_usd);
    document.getElementById('kpiPagado').textContent = '$ ' + usd(s.pagado_usd);
    document.getElementById('kpiSaldo').textContent = '$ ' + usd(s.saldo_usd);
    var inventario = data.inventario || {};
    var totalBombonas = 0;
    filas(document.querySelector('#tablaInventario tbody'), [10, 15, 27, 43].map(function (kg) {
      var fila = inventario[kg] || inventario[String(kg)] || {};
      totalBombonas += Number(fila.total || 0);
      return '<tr><td>' + kg + ' kg</td><td class="text-end">' + (fila.optimo || 0) + '</td><td class="text-end">'
        + (fila.regular || 0) + '</td><td class="text-end">' + (fila.mal_estado || 0) + '</td><td class="text-end"><strong>'
        + (fila.total || 0) + '</strong></td></tr>';
    }).join(''));
    document.getElementById('kpiTotal').textContent = String(totalBombonas);
    var body = document.querySelector('#tablaSemanas tbody');
    filas(body, (data.semanas || []).map(function (r) {
      return '<tr><td>' + fecha(r.semana_inicio) + '</td><td>' + esc(r.proveedor) + '</td><td class="text-end">'
        + esc(r.cilindros) + '</td><td class="text-end">$ ' + usd(r.invertido_usd) + '</td><td class="text-end">$ '
        + usd(r.saldo_usd) + '</td></tr>';
    }).join(''));
  }

  function badgeDeuda(estado) {
    var cls = estado === 'cancelado' ? 'text-bg-success' : (estado === 'saldo_restante' ? 'text-bg-warning' : 'text-bg-secondary');
    return '<span class="badge ' + cls + '">' + esc(DEUDA[estado] || estado) + '</span>';
  }

  function pintarProveedores() {
    var q = (document.getElementById('qProveedores').value || '').toLowerCase();
    var rows = proveedores.filter(function (p) {
      return !q || (p.nombre + ' ' + (p.documento || '') + ' ' + (p.telefono || '')).toLowerCase().indexOf(q) >= 0;
    });
    filas(document.querySelector('#tablaProveedores tbody'), rows.map(function (p) {
      var btn = cfg.editar ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-prov="' + p.id + '">Editar</button>' : '';
      return '<tr><td>' + esc(p.nombre) + '</td><td>' + esc(p.documento || '—') + '</td><td>' + esc(p.telefono || '—')
        + '</td><td>' + esc(PROV[p.estado] || p.estado) + '</td><td class="text-end">' + btn + '</td></tr>';
    }).join(''));
  }

  function pintarCilindros() {
    var q = (document.getElementById('qCilindros').value || '').toLowerCase();
    var rows = cilindros.filter(function (c) {
      return !q || (c.serial + ' ' + (c.dueno || '')).toLowerCase().indexOf(q) >= 0;
    });
    filas(document.querySelector('#tablaCilindros tbody'), rows.map(function (c) {
      var cls = c.estado_fisico === 'mal_estado' ? ' class="table-danger"' : '';
      var btn = cfg.editar ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-cil="' + c.id + '">Editar</button>' : '';
      if (cfg.anular) btn += ' <button type="button" class="btn btn-sm btn-outline-danger" data-eliminar="' + c.id + '">Eliminar</button>';
      var foto = c.foto_url
        ? '<img src="' + esc(c.foto_url) + '" alt="Foto de ' + esc(c.serial) + '" width="40" height="40" style="object-fit:cover;border-radius:6px">'
        : '—';
      return '<tr' + cls + '><td>' + foto + '</td><td>' + esc(c.serial) + '</td><td>' + esc(c.capacidad_kg) + ' kg</td><td>' + esc(FISICO[c.estado_fisico] || c.estado_fisico)
        + '</td><td>' + esc(c.dueno || '—') + '</td><td>' + (c.estado_registro === 'archivado' ? 'Archivado' : 'Activo')
        + '</td><td class="text-end">' + btn + '</td></tr>';
    }).join(''));
  }

  function pintarDespachos() {
    var q = (document.getElementById('qDespachos').value || '').toLowerCase();
    var rows = despachos.filter(function (d) {
      return !q || (d.proveedor + ' ' + (d.codigo_despacho || d.id)).toLowerCase().indexOf(q) >= 0;
    });
    filas(document.querySelector('#tablaDespachos tbody'), rows.map(function (d) {
      return '<tr data-des="' + d.id + '" style="cursor:pointer"><td>' + fecha(d.fecha_salida) + '</td><td>' + esc(d.proveedor)
        + '</td><td class="text-end">$ ' + usd(d.saldo_usd) + '</td><td>' + badgeDeuda(d.estado_deuda) + '</td></tr>';
    }).join(''));
  }

  function pintarDetalle(data) {
    detalle = data;
    var d = data.despacho;
    detalleId = Number(d.id);
    document.getElementById('detalleVacio').hidden = true;
    document.getElementById('detalleCuerpo').hidden = false;
    document.getElementById('detTitulo').textContent = (d.codigo_despacho || ('Despacho #' + d.id)) + ' · ' + d.proveedor;
    document.getElementById('detMeta').textContent = 'Salida ' + fecha(d.fecha_salida)
      + (d.fecha_entrega ? ' · Entrega ' + fecha(d.fecha_entrega) : ' · En tránsito')
      + ' · 10 kg $ ' + usd(d.precio_10_usd)
      + ' · 15 kg $ ' + usd(d.precio_15_usd)
      + ' · 27 kg $ ' + usd(d.precio_27_usd)
      + ' · 43 kg $ ' + usd(d.precio_43_usd);
    document.getElementById('detCosto').textContent = '$ ' + usd(d.costo_total_usd);
    document.getElementById('detPagado').textContent = '$ ' + usd(d.pagado_usd);
    document.getElementById('detSaldo').textContent = '$ ' + usd(d.saldo_usd);
    var abierto = !d.fecha_entrega && Number(d.pagado_usd) === 0;
    var vacio = Number(d.cantidad) === 0 && Number(d.pagado_usd) === 0;
    var btnCargar = document.getElementById('btnCargarCilindros');
    var btnEntregar = document.getElementById('btnEntregar');
    var btnQuitarViaje = document.getElementById('btnQuitarViaje');
    if (btnCargar) btnCargar.hidden = !abierto;
    if (btnEntregar) btnEntregar.hidden = !!d.fecha_entrega || vacio;
    if (btnQuitarViaje) btnQuitarViaje.hidden = !vacio;
    filas(document.querySelector('#tablaLineas tbody'), (data.lineas || []).map(function (l) {
      var quitar = abierto && cfg.editar
        ? '<button type="button" class="btn btn-sm btn-outline-danger" data-quitar="' + l.cilindro_id + '">Quitar</button>' : '';
      var cls = l.estado_fisico === 'mal_estado' ? ' class="table-danger"' : '';
      return '<tr' + cls + '><td>' + esc(l.serial) + '</td><td>' + esc(l.capacidad_kg) + ' kg</td><td>' + esc(l.propietario_nombre || (l.pertenencia === 'propio' ? 'Empresa' : '—'))
        + '</td><td>' + esc(FISICO[l.estado_fisico] || '') + '</td><td class="text-end">$ ' + usd(l.subtotal_usd)
        + '</td><td class="text-end">' + quitar + '</td></tr>';
    }).join(''));
    filas(document.querySelector('#tablaPagos tbody'), (data.pagos || []).map(function (p) {
      var anulado = p.anulado_en ? ' <span class="badge text-bg-secondary">Anulado</span>' : '';
      var btn = (!p.anulado_en && cfg.anular)
        ? '<button type="button" class="btn btn-sm btn-outline-danger" data-anular="' + p.id + '">Anular</button>' : '';
      return '<tr><td>' + fecha(p.fecha) + anulado + '</td><td>' + esc(METODO[p.metodo] || p.metodo)
        + '</td><td>' + esc(p.referencia || '—') + '</td><td class="text-end">$ ' + usd(p.monto_usd)
        + '</td><td class="text-end">' + btn + '</td></tr>';
    }).join(''));
  }

  async function cargarDetalle(id) {
    var data = await api(cfg.url.despacho + '&id=' + id);
    pintarDetalle(data);
  }

  function campoDueno(tipo, actual) {
    var slot = document.getElementById('cilDuenoCampo');
    actual = actual || {};
    if (!catalogo || tipo === 'propio') { slot.innerHTML = ''; return; }
    if (tipo === 'otro') {
      slot.innerHTML = '<label class="form-label" for="cilNombre">Nombre del propietario</label>'
        + '<input class="form-control form-control-sm" id="cilNombre" name="propietario_nombre" maxlength="120" required value="'
        + esc(actual.propietario_nombre || '') + '">';
      return;
    }
    var lista = tipo === 'empleado' ? catalogo.empleados : (tipo === 'transportista' ? catalogo.transportistas : catalogo.productores);
    var name = tipo === 'empleado' ? 'empleado_id' : (tipo === 'transportista' ? 'transportista_id' : 'productor_id');
    var selected = String(actual[name] || '');
    var opts = (lista || []).map(function (item) {
      var label = tipo === 'empleado' ? (item.apellidos + ', ' + item.nombres) : item.nombre;
      return '<option value="' + item.id + '"' + (String(item.id) === selected ? ' selected' : '') + '>' + esc(label) + '</option>';
    }).join('');
    slot.innerHTML = '<label class="form-label" for="cilRelacion">Dueño</label><select class="form-select form-select-sm" id="cilRelacion" name="'
      + name + '" required><option value="">Selecciona…</option>' + opts + '</select>';
  }

  async function asegurarCatalogo() {
    if (!catalogo) catalogo = await api(cfg.url.catalogo);
    return catalogo;
  }

  function llenarProveedoresSelect() {
    var sel = document.getElementById('desProveedor');
    var opts = (catalogo.proveedores || []).map(function (p) {
      return '<option value="' + p.id + '">' + esc(p.nombre) + '</option>';
    }).join('');
    sel.innerHTML = opts || '<option value="">No hay proveedores activos</option>';
  }

  function modal(id) {
    return bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
  }

  function formData(form) {
    var data = {};
    new FormData(form).forEach(function (value, key) { data[key] = value; });
    return data;
  }

  async function cargarResumen() {
    pintarResumen(await api(cfg.url.resumen));
  }
  async function cargarProveedores() {
    proveedores = (await api(cfg.url.proveedores)).proveedores || [];
    pintarProveedores();
  }
  async function cargarCilindros() {
    cilindros = (await api(cfg.url.cilindros)).cilindros || [];
    pintarCilindros();
  }
  async function cargarDespachos() {
    await asegurarCatalogo();
    llenarProveedoresSelect();
    despachos = (await api(cfg.url.despachos)).despachos || [];
    pintarDespachos();
  }

  document.getElementById('qProveedores').addEventListener('input', pintarProveedores);
  document.getElementById('qCilindros').addEventListener('input', pintarCilindros);
  document.getElementById('qDespachos').addEventListener('input', pintarDespachos);

  var btnProv = document.getElementById('btnProvNuevo');
  if (btnProv) btnProv.addEventListener('click', function () {
    document.getElementById('formProveedor').reset();
    document.getElementById('provId').value = '';
    document.getElementById('tituloProveedor').textContent = 'Nuevo proveedor';
    document.getElementById('provEstadoWrap').hidden = true;
    modal('modalProveedor').show();
  });

  document.querySelector('#tablaProveedores tbody').addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-prov]');
    if (!btn) return;
    var p = proveedores.find(function (row) { return String(row.id) === btn.getAttribute('data-prov'); });
    if (!p) return;
    document.getElementById('provId').value = p.id;
    document.getElementById('provNombre').value = p.nombre || '';
    document.getElementById('provDoc').value = p.documento || '';
    document.getElementById('provContacto').value = p.contacto || '';
    document.getElementById('provTel').value = p.telefono || '';
    document.getElementById('provCorreo').value = p.correo || '';
    document.getElementById('provDir').value = p.direccion || '';
    document.getElementById('provNotas').value = p.notas || '';
    document.getElementById('provEstado').value = p.estado;
    document.getElementById('tituloProveedor').textContent = 'Editar proveedor';
    document.getElementById('provEstadoWrap').hidden = false;
    modal('modalProveedor').show();
  });

  document.getElementById('formProveedor').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var data = formData(ev.target);
    var url = data.id ? cfg.url.provEditar : cfg.url.provCrear;
    try {
      var res = await api(url, data);
      proveedores = res.proveedores || proveedores;
      pintarProveedores();
      catalogo = null;
      modal('modalProveedor').hide();
      aviso('success', 'Listo', res.mensaje);
    } catch (err) { aviso('error', 'No se guardó', err.message); }
  });

  var btnCil = document.getElementById('btnCilNuevo');
  if (btnCil) btnCil.addEventListener('click', async function () {
    try { await asegurarCatalogo(); } catch (err) { aviso('error', 'Catálogo', err.message); return; }
    document.getElementById('formCilindro').reset();
    document.getElementById('cilCapacidad').value = '';
    document.getElementById('cilId').value = '';
    document.getElementById('tituloCilindro').textContent = 'Nueva bombona';
    document.getElementById('cilArchivoWrap').hidden = true;
    var previaNueva = document.getElementById('cilFotoPrev');
    previaNueva.removeAttribute('src');
    previaNueva.classList.add('d-none');
    campoDueno('propio');
    modal('modalCilindro').show();
  });

  document.getElementById('cilDueno').addEventListener('change', function () { campoDueno(this.value); });

  document.querySelector('#tablaCilindros tbody').addEventListener('click', async function (ev) {
    var borrar = ev.target.closest('[data-eliminar]');
    if (borrar) {
      var idBorrar = borrar.getAttribute('data-eliminar');
      var bombona = cilindros.find(function (row) { return String(row.id) === idBorrar; });
      var ok = await swal({
        icon: 'warning',
        title: 'Eliminar ' + (bombona ? bombona.serial : 'esta bombona'),
        text: 'Solo se borra si nunca salió en un viaje ni fue a reparación. Si ya tiene historial, hay que archivarla.',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar'
      });
      if (!ok.isConfirmed) return;
      try {
        var res = await api(cfg.url.cilEliminar, { id: idBorrar });
        cilindros = res.cilindros || [];
        pintarCilindros();
        aviso('success', 'Listo', res.mensaje);
      } catch (err) { aviso('error', 'No se eliminó', err.message); }
      return;
    }
    var btn = ev.target.closest('[data-cil]');
    if (!btn) return;
    var c = cilindros.find(function (row) { return String(row.id) === btn.getAttribute('data-cil'); });
    if (!c) return;
    try { await asegurarCatalogo(); } catch (err) { aviso('error', 'Catálogo', err.message); return; }
    document.getElementById('cilId').value = c.id;
    document.getElementById('tituloCilindro').textContent = 'Editar ' + c.serial;
    document.getElementById('cilCapacidad').value = String(c.capacidad_kg || '');
    document.getElementById('cilFisico').value = c.estado_fisico;
    document.getElementById('cilObs').value = c.observacion_fisica || '';
    var tipo = c.pertenencia === 'propio' ? 'propio' : (c.tipo_tercero || 'otro');
    document.getElementById('cilDueno').value = tipo;
    document.getElementById('cilArchivo').value = c.estado_registro;
    document.getElementById('cilArchivoWrap').hidden = false;
    document.getElementById('tituloCilindro').textContent = 'Editar bombona';
    var previa = document.getElementById('cilFotoPrev');
    if (c.foto_url) {
      previa.src = c.foto_url;
      previa.classList.remove('d-none');
    } else {
      previa.removeAttribute('src');
      previa.classList.add('d-none');
    }
    campoDueno(tipo, c);
    modal('modalCilindro').show();
  });

  document.getElementById('formCilindro').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var fd = new FormData(ev.target);
    fd.delete('serial');
    var url = fd.get('id') ? cfg.url.cilEditar : cfg.url.cilCrear;
    try {
      var res = await apiForm(url, fd);
      cilindros = res.cilindros || cilindros;
      pintarCilindros();
      modal('modalCilindro').hide();
      aviso('success', 'Listo', res.mensaje);
      cargarResumen().catch(function () {});
    } catch (err) { aviso('error', 'No se guardó', err.message); }
  });

  document.getElementById('formDespacho').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (!cfg.crear) return;
    try {
      var res = await api(cfg.url.desCrear, formData(ev.target));
      ev.target.reset();
      document.getElementById('desSalida').value = cfg.hoy;
      ['desP10', 'desP15', 'desP27', 'desP43'].forEach(function (id) { document.getElementById(id).value = ''; });
      await cargarDespachos();
      pintarDetalle(res);
      aviso('success', res.despacho.codigo_despacho || 'Despacho abierto', 'El código quedó generado. Ahora carga las bombonas del viaje.');
    } catch (err) { aviso('error', 'No se abrió el despacho', err.message); }
  });

  document.querySelector('#tablaDespachos tbody').addEventListener('click', function (ev) {
    var tr = ev.target.closest('[data-des]');
    if (!tr) return;
    cargarDetalle(tr.getAttribute('data-des')).catch(function (err) { aviso('error', 'Despacho', err.message); });
  });

  var btnCargar = document.getElementById('btnCargarCilindros');
  if (btnCargar) btnCargar.addEventListener('click', async function () {
    try {
      disponibles = (await api(cfg.url.disponibles)).cilindros || [];
      pintarDisponibles();
      modal('modalCargar').show();
    } catch (err) { aviso('error', 'Bombonas', err.message); }
  });

  function pintarDisponibles() {
    var q = (document.getElementById('qDisponibles').value || '').toLowerCase();
    var box = document.getElementById('listaDisponibles');
    var rows = disponibles.filter(function (c) {
      return !q || (c.serial + ' ' + (c.dueno || '')).toLowerCase().indexOf(q) >= 0;
    });
    box.innerHTML = rows.length ? rows.map(function (c) {
      var mal = c.estado_fisico === 'mal_estado' ? ' text-danger' : '';
      return '<label class="form-check' + mal + '"><input class="form-check-input js-disp" type="checkbox" value="' + c.id + '"> '
        + esc(c.serial) + ' <span class="text-muted">· ' + esc(c.dueno || '') + ' · ' + esc(FISICO[c.estado_fisico] || '') + '</span></label>';
    }).join('') : '<div class="p-3 text-muted">No hay bombonas libres.</div>';
    document.getElementById('chkTodas').checked = false;
  }
  document.getElementById('qDisponibles').addEventListener('input', pintarDisponibles);
  document.getElementById('chkTodas').addEventListener('change', function () {
    document.querySelectorAll('#listaDisponibles .js-disp').forEach(function (el) { el.checked = document.getElementById('chkTodas').checked; });
  });
  document.getElementById('formCargar').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var ids = Array.prototype.map.call(document.querySelectorAll('#listaDisponibles .js-disp:checked'), function (el) { return Number(el.value); });
    if (!ids.length) { aviso('warning', 'Selecciona bombonas', 'Marca al menos una.'); return; }
    try {
      var res = await api(cfg.url.desCilindros, { despacho_id: detalleId, cilindros: ids });
      modal('modalCargar').hide();
      pintarDetalle(res);
      await cargarDespachos();
      aviso('success', 'Cargadas', res.mensaje);
    } catch (err) { aviso('error', 'No se cargaron', err.message); }
  });

  document.querySelector('#tablaLineas tbody').addEventListener('click', async function (ev) {
    var btn = ev.target.closest('[data-quitar]');
    if (!btn) return;
    try {
      var res = await api(cfg.url.desQuitar, { despacho_id: detalleId, cilindro_id: Number(btn.getAttribute('data-quitar')) });
      pintarDetalle(res);
      await cargarDespachos();
    } catch (err) { aviso('error', 'No se quitó', err.message); }
  });

  var btnQuitarViaje = document.getElementById('btnQuitarViaje');
  if (btnQuitarViaje) btnQuitarViaje.addEventListener('click', async function () {
    var pregunta = window.Swal
      ? await swal({ title: 'Quitar viaje', text: 'Este viaje no tiene bombonas ni abonos. Se eliminará de la lista.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Quitar' })
      : { isConfirmed: window.confirm('¿Quitar este viaje vacío?') };
    if (!pregunta.isConfirmed) return;
    try {
      await api(cfg.url.desEliminar, { id: detalleId });
      detalle = null;
      detalleId = 0;
      document.getElementById('detalleCuerpo').hidden = true;
      document.getElementById('detalleVacio').hidden = false;
      await cargarDespachos();
      aviso('success', 'Viaje eliminado', 'El despacho vacío ya no está en la lista.');
    } catch (err) { aviso('error', 'No se quitó', err.message); }
  });

  var btnEntregar = document.getElementById('btnEntregar');
  if (btnEntregar) btnEntregar.addEventListener('click', async function () {
    var pregunta = window.Swal
      ? await swal({ title: 'Marcar entregada', text: 'Fecha real en que el viaje regresó a la quesera.', input: 'date', inputValue: cfg.hoy, showCancelButton: true, confirmButtonText: 'Entregar' })
      : { isConfirmed: true, value: cfg.hoy };
    if (!pregunta.isConfirmed || !pregunta.value) return;
    try {
      var res = await api(cfg.url.desEditar, { id: detalleId, fecha_entrega: pregunta.value });
      pintarDetalle(res);
      await cargarDespachos();
      aviso('success', 'Entregado', 'Las bombonas quedaron libres para otro viaje.');
    } catch (err) { aviso('error', 'No se entregó', err.message); }
  });

  var btnAbonar = document.getElementById('btnAbonar');
  if (btnAbonar) btnAbonar.addEventListener('click', function () {
    if (!detalle) return;
    document.getElementById('pagoSaldo').textContent = 'Saldo pendiente: $ ' + usd(detalle.despacho.saldo_usd);
    document.getElementById('pagoFecha').value = cfg.hoy;
    document.getElementById('pagoMonto').value = '';
    document.getElementById('pagoRef').value = '';
    if (cfg.tasa) document.getElementById('pagoTasa').value = Number(cfg.tasa).toFixed(4);
    syncPago();
    modal('modalPago').show();
  });

  function syncPago() {
    var metodo = document.getElementById('pagoMetodo').value;
    document.getElementById('pagoCuentaWrap').hidden = metodo !== 'otro';
    document.getElementById('pagoRef').required = metodo === 'pago_movil' || metodo === 'transferencia';
  }
  document.getElementById('pagoMetodo').addEventListener('change', syncPago);

  document.getElementById('formPago').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var data = formData(ev.target);
    data.despacho_id = detalleId;
    try {
      var res = await api(cfg.url.pago, data);
      modal('modalPago').hide();
      pintarDetalle(res);
      await cargarDespachos();
      await cargarResumen();
      aviso('success', 'Abono registrado', res.mensaje);
    } catch (err) { aviso('error', 'No se registró el abono', err.message); }
  });

  document.querySelector('#tablaPagos tbody').addEventListener('click', async function (ev) {
    var btn = ev.target.closest('[data-anular]');
    if (!btn) return;
    var pregunta = window.Swal
      ? await swal({
          title: 'Anular abono',
          input: 'text',
          inputLabel: 'Motivo',
          inputPlaceholder: 'Mínimo 3 caracteres',
          showCancelButton: true,
          confirmButtonText: 'Anular y revertir caja'
        })
      : { isConfirmed: false };
    if (!pregunta.isConfirmed) return;
    try {
      var res = await api(cfg.url.pagoAnular, { id: Number(btn.getAttribute('data-anular')), motivo: pregunta.value || '' });
      pintarDetalle(res);
      await cargarDespachos();
      await cargarResumen();
      aviso('success', 'Anulado', res.mensaje);
    } catch (err) { aviso('error', 'No se anuló', err.message); }
  });

  var paraReparar = [];
  function pintarReparaciones(data) {
    var rows = data.reparaciones || [];
    paraReparar = data.disponibles || paraReparar;
    filas(document.querySelector('#tablaReparaciones tbody'), rows.map(function (r) {
      var btn = (r.estado === 'en_reparacion' && cfg.editar)
        ? '<button type="button" class="btn btn-sm btn-outline-success" data-reparada="' + r.id + '">Marcar reparado</button>' : '';
      return '<tr><td>' + esc(r.serial) + '</td><td>' + esc(r.capacidad_kg) + ' kg</td><td>' + esc(r.responsable)
        + '</td><td>' + fecha(r.fecha_salida) + '</td><td>' + (r.estado === 'reparado' ? 'Reparado' : 'En reparación')
        + '</td><td class="text-end">' + (r.costo_usd ? '$ ' + usd(r.costo_usd) : '—')
        + '</td><td class="text-end">' + btn + '</td></tr>';
    }).join(''));
  }
  function pintarListaReparar() {
    var q = (document.getElementById('qReparar').value || '').toLowerCase();
    var rows = paraReparar.filter(function (c) { return !q || String(c.serial).toLowerCase().indexOf(q) >= 0; });
    document.getElementById('listaReparar').innerHTML = rows.length ? rows.map(function (c) {
      return '<label class="form-check"><input class="form-check-input js-rep" type="checkbox" value="' + c.id + '"> '
        + esc(c.serial) + ' <span class="text-muted">· ' + esc(c.capacidad_kg) + ' kg · ' + esc(FISICO[c.estado_fisico] || '') + '</span></label>';
    }).join('') : '<div class="p-3 text-muted">No hay bombonas en estado regular o mal estado disponibles.</div>';
  }
  async function cargarReparaciones() {
    pintarReparaciones(await api(cfg.url.reparaciones));
  }
  var btnReparar = document.getElementById('btnReparar');
  if (btnReparar) btnReparar.addEventListener('click', async function () {
    try {
      await cargarReparaciones();
      document.getElementById('formReparar').reset();
      document.getElementById('repSalida').value = cfg.hoy;
      pintarListaReparar();
      modal('modalReparar').show();
    } catch (err) { aviso('error', 'Reparaciones', err.message); }
  });
  document.getElementById('qReparar').addEventListener('input', pintarListaReparar);
  document.getElementById('formReparar').addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var ids = Array.prototype.map.call(document.querySelectorAll('#listaReparar .js-rep:checked'), function (el) { return Number(el.value); });
    if (!ids.length) { aviso('warning', 'Selecciona bombonas', 'Solo aparecen las que están regular o en mal estado.'); return; }
    var data = formData(ev.target);
    data.cilindros = ids;
    try {
      var res = await api(cfg.url.repEnviar, data);
      modal('modalReparar').hide();
      pintarReparaciones(res);
      aviso('success', 'Enviadas', res.mensaje);
    } catch (err) { aviso('error', 'No se envió', err.message); }
  });
  document.querySelector('#tablaReparaciones tbody').addEventListener('click', async function (ev) {
    var btn = ev.target.closest('[data-reparada]');
    if (!btn) return;
    var pregunta = window.Swal
      ? await swal({ title: 'Marcar reparado', input: 'text', inputLabel: 'Costo de la reparación (USD)', inputPlaceholder: '0,00', showCancelButton: true, confirmButtonText: 'Registrar egreso' })
      : { isConfirmed: false };
    if (!pregunta.isConfirmed) return;
    try {
      var res = await api(cfg.url.repCerrar, { id: Number(btn.getAttribute('data-reparada')), costo_usd: pregunta.value || '' });
      pintarReparaciones(res);
      aviso('success', 'Reparado', res.mensaje);
      cargarResumen().catch(function () {});
      cargarCilindros().catch(function () {});
    } catch (err) { aviso('error', 'No se cerró', err.message); }
  });

  document.getElementById('gasTabs').addEventListener('shown.bs.tab', function (ev) {
    var target = ev.target.getAttribute('data-bs-target');
    var job = target === '#gasProveedores' ? cargarProveedores
      : target === '#gasCilindros' ? cargarCilindros
      : target === '#gasDespachos' ? cargarDespachos
      : target === '#gasReparaciones' ? cargarReparaciones
      : cargarResumen;
    job().catch(function (err) { aviso('error', 'No se pudo cargar', err.message); });
  });

  cargarResumen().catch(function (err) { aviso('error', 'Resumen', err.message); });
})();
