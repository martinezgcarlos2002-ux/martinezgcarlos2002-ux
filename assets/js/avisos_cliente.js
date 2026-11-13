(function () {
  var raiz = document.getElementById('avisosSaas');
  var lista = document.getElementById('listaAvisosSaas');
  var badge = document.getElementById('contadorAlertas');
  var numero = document.getElementById('contadorAlertasNumero');
  var menu = document.getElementById('contadorAlertasMenu');
  var cabecera = document.getElementById('nAvisosSaas');
  if (!raiz || !lista || !badge || !numero) return;

  var base = parseInt(badge.getAttribute('data-base') || '0', 10) || 0;
  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? meta.getAttribute('content') || '' : '';

  function textoContador(total) {
    var n = base + total;
    badge.classList.toggle('d-none', n === 0);
    numero.textContent = n > 99 ? '99+' : String(n);
    if (menu) menu.textContent = String(n);
    if (cabecera) cabecera.textContent = String(total);
  }

  function pintar(avisos) {
    lista.textContent = '';
    if (!avisos.length) {
      var vacio = document.createElement('li');
      var nota = document.createElement('span');
      nota.className = 'dropdown-item-text small text-body-secondary py-2';
      nota.textContent = 'Sin avisos de suscripción.';
      vacio.appendChild(nota);
      lista.appendChild(vacio);
      return;
    }
    avisos.forEach(function (aviso) {
      var li = document.createElement('li');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'dropdown-item text-wrap py-2 border-bottom';
      var titulo = document.createElement('span');
      titulo.className = 'd-block fw-semibold small';
      titulo.textContent = aviso.mensaje || '';
      var fecha = document.createElement('span');
      fecha.className = 'd-block text-body-secondary small';
      fecha.textContent = aviso.fecha || '';
      btn.appendChild(titulo);
      btn.appendChild(fecha);
      btn.addEventListener('click', function () { marcar(aviso.id, li); });
      li.appendChild(btn);
      lista.appendChild(li);
    });
  }

  function cargar() {
    fetch(raiz.getAttribute('data-url'), {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (respuesta) {
      return respuesta.ok ? respuesta.json() : null;
    }).then(function (data) {
      if (!data || !data.ok) return;
      pintar(data.avisos || []);
      textoContador(data.total || 0);
    }).catch(function () {});
  }

  function marcar(id, li) {
    var cuerpo = new FormData();
    cuerpo.append('id', String(id));
    cuerpo.append('_csrf', token);
    fetch(raiz.getAttribute('data-leer'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': token },
      body: cuerpo
    }).then(function () {
      if (li && li.parentNode) li.parentNode.removeChild(li);
      cargar();
    }).catch(function () {});
  }

  lista.querySelectorAll('.js-aviso-saas').forEach(function (btn) {
    btn.addEventListener('click', function () {
      marcar(btn.getAttribute('data-id'), btn.parentNode);
    });
  });

  cargar();
  setInterval(cargar, 60000);
})();
