/**
 * Documento (V-/J-/E-/G-/P-) y teléfono (+58 + máscara XXX-XXXXXXX).
 * Concatena al enviar: V-280601335 y +58414-1234567.
 */
(function (global) {
  'use strict';

  var PREFIJOS = ['V', 'J', 'E', 'G', 'P'];
  var CODIGOS = ['+58', '+1', '+57', '+34', '+51', '+593', '+56', '+54', '+55', '+52', '+507'];

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function soloDigitos(s) { return String(s || '').replace(/\D/g, ''); }

  function maskLocal(raw) {
    var d = soloDigitos(raw);
    if (d.charAt(0) === '0' && d.length === 11) d = d.slice(1);
    d = d.slice(0, 10);
    return d.length <= 3 ? d : d.slice(0, 3) + '-' + d.slice(3);
  }

  function splitDocumento(raw) {
    var v = String(raw || '').toUpperCase().replace(/[\s.]/g, '');
    if (!v) return { prefijo: 'V', numero: '' };
    var m = v.match(/^([VEJGP])-?(\d{6,9})(?:-?(\d))?$/);
    if (m) return { prefijo: m[1], numero: m[2] + (m[3] ? '-' + m[3] : '') };
    var p = v.match(/^([VEJGP])-?(.*)$/);
    if (p) return { prefijo: p[1], numero: p[2].replace(/[^0-9-]/g, '') };
    return { prefijo: 'V', numero: v.replace(/[^0-9-]/g, '') };
  }

  function splitTelefono(raw) {
    var v = String(raw || '').replace(/\s+/g, '');
    if (!v) return { codigo: '+58', numero: '' };
    var orden = CODIGOS.slice().sort(function (a, b) { return b.length - a.length; });
    for (var i = 0; i < orden.length; i++) {
      if (v.indexOf(orden[i]) === 0) {
        return { codigo: orden[i], numero: maskLocal(v.slice(orden[i].length)) };
      }
    }
    var d = soloDigitos(v);
    if (d.indexOf('58') === 0 && d.length >= 12) return { codigo: '+58', numero: maskLocal(d.slice(2)) };
    if (d.charAt(0) === '0') return { codigo: '+58', numero: maskLocal(d) };
    return { codigo: '+58', numero: maskLocal(d) };
  }

  function valorDocumento(group) {
    var pref = (qs('.js-doc-prefijo', group) || {}).value || 'V-';
    var num = String((qs('.js-doc-numero', group) || {}).value || '').replace(/[\s.]/g, '');
    if (!num) return '';
    if (pref.slice(-1) !== '-') pref += '-';
    return pref + num;
  }

  function valorTelefono(group) {
    var cod = (qs('.js-tel-codigo', group) || {}).value || '+58';
    var num = String((qs('.js-tel-numero', group) || {}).value || '').trim();
    if (!num) return '';
    if (cod.charAt(0) !== '+') cod = '+' + cod;
    return cod + num;
  }

  function setDocumento(group, raw) {
    var parts = splitDocumento(raw);
    var pref = qs('.js-doc-prefijo', group);
    var num = qs('.js-doc-numero', group);
    var hid = qs('.js-doc-valor', group);
    if (pref) pref.value = PREFIJOS.indexOf(parts.prefijo) >= 0 ? parts.prefijo + '-' : 'V-';
    if (num) num.value = parts.numero;
    if (hid) hid.value = valorDocumento(group);
  }

  function setTelefono(group, raw) {
    var parts = splitTelefono(raw);
    var cod = qs('.js-tel-codigo', group);
    var num = qs('.js-tel-numero', group);
    var hid = qs('.js-tel-valor', group);
    if (cod) cod.value = CODIGOS.indexOf(parts.codigo) >= 0 ? parts.codigo : '+58';
    if (num) num.value = parts.numero;
    if (hid) hid.value = valorTelefono(group);
  }

  function groupOf(el) {
    if (!el) return null;
    if (typeof el === 'string') el = document.getElementById(el);
    if (!el) return null;
    return el.closest('.js-doc-group, .js-tel-group');
  }

  function syncGroup(group) {
    if (!group) return '';
    if (group.classList.contains('js-doc-group')) {
      var hidDoc = qs('.js-doc-valor', group);
      var valDoc = valorDocumento(group);
      if (hidDoc) hidDoc.value = valDoc;
      return valDoc;
    }
    var hidTel = qs('.js-tel-valor', group);
    var valTel = valorTelefono(group);
    if (hidTel) hidTel.value = valTel;
    return valTel;
  }

  function sync(root) {
    qsa('.js-doc-group, .js-tel-group', root || document).forEach(syncGroup);
  }

  function hydrate(root) {
    qsa('.js-doc-group', root || document).forEach(function (g) {
      var hid = qs('.js-doc-valor', g);
      if (hid && hid.value) setDocumento(g, hid.value);
    });
    qsa('.js-tel-group', root || document).forEach(function (g) {
      var hid = qs('.js-tel-valor', g);
      if (hid && hid.value) setTelefono(g, hid.value);
    });
  }

  function bindMask(input) {
    if (!input || input.getAttribute('data-mask-tel') === '1') return;
    input.setAttribute('data-mask-tel', '1');
    input.addEventListener('input', function () {
      var start = input.selectionStart;
      var before = input.value;
      input.value = maskLocal(input.value);
      if (start != null && document.activeElement === input) {
        var delta = input.value.length - before.length;
        var pos = Math.max(0, start + delta);
        try { input.setSelectionRange(pos, pos); } catch (e) {}
      }
    });
    input.addEventListener('blur', function () {
      if (input.value) input.value = maskLocal(input.value);
    });
  }

  function bindDocNumero(input) {
    if (!input || input.getAttribute('data-mask-doc') === '1') return;
    input.setAttribute('data-mask-doc', '1');
    input.addEventListener('input', function () {
      input.value = input.value.toUpperCase().replace(/[^0-9-]/g, '');
    });
  }

  function init(root) {
    qsa('.js-tel-numero', root || document).forEach(bindMask);
    qsa('.js-doc-numero', root || document).forEach(bindDocNumero);
    hydrate(root);
  }

  document.addEventListener('submit', function (ev) {
    if (ev.target && ev.target.tagName === 'FORM') sync(ev.target);
  }, true);

  document.addEventListener('show.bs.modal', function (ev) {
    var modal = ev.target;
    setTimeout(function () {
      init(modal);
      hydrate(modal);
    }, 0);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }

  global.AdaraContacto = {
    init: init,
    sync: sync,
    hydrate: hydrate,
    setDocumento: function (el, raw) { var g = groupOf(el); if (g) setDocumento(g, raw); },
    setTelefono: function (el, raw) { var g = groupOf(el); if (g) setTelefono(g, raw); },
    valor: function (el) { var g = groupOf(el); return g ? syncGroup(g) : ''; },
    maskLocal: maskLocal
  };
})(window);
