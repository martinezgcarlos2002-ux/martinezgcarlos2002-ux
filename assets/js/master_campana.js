(function () {
  var raiz = document.getElementById('campanaSoporte');
  if (!raiz) return;
  var url = raiz.getAttribute('data-url');
  var lista = document.getElementById('listaTicketsSoporte');
  var badge = document.getElementById('contadorTicketsSoporte');
  var menu = document.getElementById('contadorTicketsMenu');
  if (!url || !lista || !badge) return;

  function esc(texto) {
    return String(texto).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function pintar(data) {
    var total = Number(data.total) || 0;
    badge.textContent = total > 99 ? '99+' : String(total);
    badge.classList.toggle('d-none', total === 0);
    if (menu) menu.textContent = String(total);
    var tickets = Array.isArray(data.tickets) ? data.tickets : [];
    if (tickets.length === 0) {
      lista.innerHTML = '<li><span class="dropdown-item-text small text-body-secondary py-2">No hay reportes nuevos ni pendientes.</span></li>';
      return;
    }
    lista.innerHTML = tickets.map(function (t) {
      var href = esc(t.url || '#');
      return '<li><a class="dropdown-item d-flex flex-column py-2 border-bottom" href="' + href + '">'
        + '<span class="d-flex justify-content-between gap-2"><span class="fw-semibold small">' + esc(t.empresa) + '</span>'
        + '<span class="badge text-bg-light border">' + esc(t.tipo) + '</span></span>'
        + '<span class="small text-wrap">' + esc(t.asunto) + '</span>'
        + '<span class="small text-body-secondary">' + esc(t.cuando) + '</span></a></li>';
    }).join('');
  }

  function pedir() {
    if (document.hidden) return;
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) { if (data && data.ok) pintar(data); })
      .catch(function () {});
  }

  pedir();
  setInterval(pedir, 45000);
  document.addEventListener('visibilitychange', pedir);
})();
