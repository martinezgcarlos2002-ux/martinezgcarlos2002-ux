(function () {
  function pintar(el) {
    var corte = el.getAttribute('data-corte');
    if (!corte) return;
    var partes = corte.split('-');
    if (partes.length !== 3) return;
    var fin = new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
    var hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    var dias = Math.round((fin.getTime() - hoy.getTime()) / 86400000);
    el.classList.remove('text-bg-success', 'text-bg-warning', 'text-bg-danger', 'text-bg-secondary');
    el.classList.add(dias <= 0 ? 'text-bg-danger' : (dias <= 7 ? 'text-bg-warning' : 'text-bg-success'));
    if (dias > 1) el.textContent = dias + ' días';
    else if (dias === 1) el.textContent = '1 día';
    else if (dias === 0) el.textContent = 'Vence hoy';
    else if (dias === -1) el.textContent = '1 día de atraso';
    else el.textContent = Math.abs(dias) + ' días de atraso';
  }
  function todos() {
    document.querySelectorAll('.js-cronometro').forEach(pintar);
  }
  todos();
  setInterval(todos, 60000);
})();
