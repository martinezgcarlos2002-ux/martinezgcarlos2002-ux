/**
 * Facturas masivas de productores: impresión nativa + ZIP de PNG en cola.
 *
 * Config en #formLiquidaciones:
 *   data-html-url  POST JSON del HTML consolidado
 *   data-desde / data-hasta
 */
(function () {
  'use strict';

  var form = document.getElementById('formLiquidaciones');
  var grid = document.getElementById('fmGrid');
  var count = document.getElementById('fmCount');
  var btnZip = document.getElementById('fmZipImg');
  var stage = document.getElementById('fmZipStage');
  if (!form || !grid) return;

  form.addEventListener('submit', function (ev) {
    if (!grid.querySelector('input:checked')) {
      ev.preventDefault();
      window.alert('Selecciona al menos un productor.');
    }
  });

  function sync() {
    var n = 0;
    grid.querySelectorAll('.fm-card').forEach(function (card) {
      var on = card.querySelector('input').checked;
      card.classList.toggle('is-out', !on);
      if (on) n += 1;
    });
    if (count) count.textContent = String(n);
  }

  var btnIncluir = document.getElementById('fmIncluir');
  var btnExcluir = document.getElementById('fmExcluir');
  if (btnIncluir) {
    btnIncluir.addEventListener('click', function () {
      grid.querySelectorAll('input[name="ids[]"]').forEach(function (input) { input.checked = true; });
      sync();
    });
  }
  if (btnExcluir) {
    btnExcluir.addEventListener('click', function () {
      grid.querySelectorAll('input[name="ids[]"]').forEach(function (input) { input.checked = false; });
      sync();
    });
  }
  grid.addEventListener('change', sync);
  grid.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-excluir]');
    if (!btn) return;
    btn.closest('.fm-card').querySelector('input').checked = false;
    sync();
  });
  sync();

  if (!btnZip || !stage) return;

  var zipLabel = btnZip.getAttribute('data-label') || btnZip.textContent;
  var ocupado = false;

  function idsSeleccionados() {
    return Array.prototype.map.call(grid.querySelectorAll('input[name="ids[]"]:checked'), function (el) {
      return el.value;
    });
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function yieldUI() {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, 40);
    });
  }

  function esperarImagenes(el) {
    var imgs = Array.prototype.slice.call(el.querySelectorAll('img'));
    if (imgs.length === 0) return Promise.resolve();
    return Promise.all(imgs.map(function (img) {
      if (img.complete) return Promise.resolve();
      return new Promise(function (resolve) {
        img.onload = resolve;
        img.onerror = resolve;
      });
    }));
  }

  function progreso(hecho, total, extra) {
    var pct = total > 0 ? Math.round((hecho / total) * 100) : 0;
    var txt = extra || ('Generando imágenes... ' + pct + '%');
    btnZip.textContent = txt;
    var status = document.getElementById('fmZipStatus');
    var bar = document.getElementById('fmZipBar');
    if (status) status.textContent = txt;
    if (bar) bar.style.width = pct + '%';
  }

  function modalZip(mostrar) {
    var el = document.getElementById('fmZipModal');
    if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    var modal = bootstrap.Modal.getOrCreateInstance(el, { backdrop: 'static', keyboard: false });
    if (mostrar) modal.show();
    else modal.hide();
  }

  async function htmlConsolidado(ids) {
    var fd = new FormData();
    ids.forEach(function (id) { fd.append('ids[]', id); });
    fd.append('desde', form.getAttribute('data-desde') || '');
    fd.append('hasta', form.getAttribute('data-hasta') || '');

    var headers = new Headers();
    headers.set('Accept', 'application/json');
    var token = csrfToken();
    if (token) headers.set('X-CSRF-TOKEN', token);

    var res = await fetch(form.getAttribute('data-html-url'), {
      method: 'POST',
      headers: headers,
      body: fd,
      credentials: 'same-origin'
    });
    var json;
    try {
      json = JSON.parse(await res.text());
    } catch (e) {
      throw new Error('No se pudo leer la respuesta del servidor.');
    }
    if (!res.ok || !json || json.ok !== true || !json.html) {
      var err = json && json.errores && json.errores.general;
      throw new Error(err || 'No se pudo obtener el HTML de las liquidaciones.');
    }
    return json;
  }

  async function generarZip() {
    if (ocupado) return;
    var ids = idsSeleccionados();
    if (!ids.length) {
      window.alert('Selecciona al menos un productor.');
      return;
    }
    if (typeof html2canvas !== 'function' || typeof JSZip !== 'function' || typeof saveAs !== 'function') {
      window.alert('No se cargaron las librerías de exportación. Recarga la página.');
      return;
    }

    ocupado = true;
    btnZip.disabled = true;
    modalZip(true);
    progreso(0, ids.length, 'Generando imágenes... 0%');

    try {
      var data = await htmlConsolidado(ids);
      var hold = document.createElement('div');
      hold.innerHTML = data.html;
      var piezas = Array.prototype.slice.call(hold.querySelectorAll('.fm-doc-item'));
      if (piezas.length === 0) throw new Error('El servidor no devolvió liquidaciones.');

      var zip = new JSZip();
      var total = piezas.length;
      var i;
      for (i = 0; i < total; i++) {
        var pieza = piezas[i];
        var archivo = pieza.getAttribute('data-archivo') || ('Liquidacion_' + (i + 1) + '.png');
        var doc = pieza.querySelector('.doc') || pieza;
        stage.replaceChildren(pieza);
        await esperarImagenes(doc);
        await yieldUI();

        var canvas = await html2canvas(doc, {
          scale: 1.5,
          useCORS: true,
          logging: false,
          backgroundColor: '#ffffff',
          foreignObjectRendering: false
        });
        var raw = canvas.toDataURL('image/png');
        var coma = raw.indexOf(',');
        zip.file(archivo, coma >= 0 ? raw.slice(coma + 1) : raw, { base64: true });
        canvas.width = 1;
        canvas.height = 1;
        stage.replaceChildren();
        progreso(i + 1, total, 'Generando imágenes... ' + Math.round(((i + 1) / total) * 100) + '%');
        await yieldUI();
      }

      progreso(total, total, 'Empaquetando ZIP...');
      var blob = await zip.generateAsync({ type: 'blob', compression: 'DEFLATE' });
      saveAs(blob, 'Liquidaciones_Semana.zip');
      progreso(total, total, 'Listo');
    } catch (err) {
      window.alert(err && err.message ? err.message : 'No se pudo generar el ZIP.');
    } finally {
      stage.replaceChildren();
      ocupado = false;
      btnZip.disabled = false;
      btnZip.innerHTML = zipLabel;
      window.setTimeout(function () { modalZip(false); }, 400);
    }
  }

  btnZip.addEventListener('click', function () { generarZip(); });
})();
