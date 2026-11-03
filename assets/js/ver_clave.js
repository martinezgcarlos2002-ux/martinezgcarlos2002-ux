/**
 * Ojito para ver la contraseña. Se engancha a todo input type=password de la página.
 * El botón es type=button: no envía el formulario.
 */
(function () {
  'use strict';

  var ojo = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var oculto = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/><path d="M14.12 14.12a3 3 0 0 1-4.24-4.24"/></svg>';

  function montar(input) {
    if (input.closest('.adara-clave')) return;
    var caja = document.createElement('div');
    caja.className = 'adara-clave';
    input.parentNode.insertBefore(caja, input);
    caja.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'adara-clave-ojo';
    btn.setAttribute('aria-label', 'Mostrar contraseña');
    btn.innerHTML = ojo;
    caja.appendChild(btn);

    btn.addEventListener('click', function () {
      var visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      btn.innerHTML = visible ? oculto : ojo;
      btn.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
  }

  function iniciar() {
    document.querySelectorAll('input[type="password"]').forEach(montar);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
