/**
 * Modal «Generar Resumen» en Producción Diaria.
 */
(function () {
  'use strict';

  function init() {
    var btn = document.getElementById('btnGenerarResumen');
    var modalEl = document.getElementById('modalResumenRango');
    if (!btn || !modalEl) return;

    var dashTpl = btn.getAttribute('data-url-dashboard') || '';
    var pdfTpl = btn.getAttribute('data-url-pdf') || '';
    var desde = document.getElementById('resDesde');
    var hasta = document.getElementById('resHasta');

    function getModal() {
      if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
      return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    function armar(base, inicio, fin) {
      var sep = base.indexOf('?') >= 0 ? '&' : '?';
      return base + sep + 'inicio=' + encodeURIComponent(inicio) + '&fin=' + encodeURIComponent(fin);
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var m = getModal();
      if (m) m.show();
      else {
        modalEl.classList.add('show', 'd-block');
      }
    });

    var ver = document.getElementById('btnResumenDash');
    if (ver) {
      ver.addEventListener('click', function () {
        if (!desde.value || !hasta.value) return;
        window.location.href = armar(dashTpl, desde.value, hasta.value);
      });
    }
    var pdf = document.getElementById('btnResumenPdf');
    if (pdf) {
      pdf.addEventListener('click', function () {
        if (!desde.value || !hasta.value) return;
        window.open(armar(pdfTpl, desde.value, hasta.value), '_blank', 'noopener');
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
