/**
 * Pantalla pública: polling cada 30s y parche de DOM (sin location.reload).
 */
(function () {
  'use strict';

  var raw = document.getElementById('tvDatos');
  if (!raw) return;
  var estado = JSON.parse(raw.textContent);
  var grid = document.getElementById('tvGrid');
  var vacio = document.getElementById('tvVacio');
  var tasaEl = document.getElementById('tvTasa');

  function money(n) {
    return (Math.round(Number(n || 0) * 100) / 100).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function cardEl(id) {
    return grid.querySelector('[data-id="' + id + '"]');
  }

  function pintarCard(item) {
    var art = cardEl(item.id);
    if (!art) {
      art = document.createElement('article');
      art.className = 'col';
      art.dataset.id = String(item.id);
      art.innerHTML = '<div class="card bg-dark text-white border-0 menu-card">'
        + '<div class="menu-card-foto-vacia" data-rol="vacio"></div>'
        + '<img alt="" class="menu-card-foto d-none" data-rol="foto">'
        + '<div class="menu-card-gradiente"></div>'
        + '<div class="card-img-overlay menu-card-info">'
        + '<h2 data-rol="nombre"></h2>'
        + '<p class="menu-card-usd" data-rol="usd"></p>'
        + '<p class="menu-card-bs" data-rol="bs"></p>'
        + '</div></div>';
      grid.appendChild(art);
    }
    var foto = art.querySelector('[data-rol="foto"]');
    var hueco = art.querySelector('[data-rol="vacio"]');
    if (item.foto_url) {
      if (foto.getAttribute('src') !== item.foto_url) foto.setAttribute('src', item.foto_url);
      foto.classList.remove('d-none');
      hueco.classList.add('d-none');
    } else {
      foto.classList.add('d-none');
      foto.removeAttribute('src');
      hueco.classList.remove('d-none');
    }
    art.querySelector('[data-rol="nombre"]').textContent = item.nombre;
    art.querySelector('[data-rol="usd"]').textContent = '$' + money(item.precio_usd);
    art.querySelector('[data-rol="bs"]').textContent = item.precio_bs != null ? 'Bs. ' + money(item.precio_bs) : '';
  }

  function aplicar(data) {
    estado.revision = data.revision;
    estado.tasa = data.tasa;
    estado.items = data.items || [];
    if (tasaEl) {
      tasaEl.textContent = data.tasa != null ? '1 USD = Bs. ' + money(data.tasa) : 'Tasa no definida';
    }
    var ids = {};
    estado.items.forEach(function (it) {
      ids[String(it.id)] = true;
      pintarCard(it);
    });
    Array.prototype.slice.call(grid.querySelectorAll('[data-id]')).forEach(function (el) {
      if (!ids[el.dataset.id]) el.remove();
    });
    if (vacio) vacio.classList.toggle('d-none', estado.items.length > 0);
  }

  async function poll() {
    try {
      var res = await fetch(estado.poll, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      var json = JSON.parse(await res.text());
      if (!json || json.ok !== true) return;
      if (String(json.revision) === String(estado.revision)) return;
      aplicar(json);
    } catch (e) { /* silencio: la TV no debe parpadear */ }
  }

  aplicar(estado);
  setInterval(poll, 30000);
})();
