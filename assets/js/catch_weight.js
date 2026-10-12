/**
 * Catch weight: neto = bruto − (empaques × tara).
 * Tobos 1.0 kg, Potes 0.3/0.4/0.6, Piezas 0.
 */
(function (global) {
  'use strict';

  var TARA_FIJA = { Tobos: 1, Piezas: 0 };
  var TARA_VAR = { Potes: [0.3, 0.4, 0.6] };

  function parseNum(v) {
    if (v == null) return 0;
    return parseFloat(String(v).replace(',', '.')) || 0;
  }

  function modo(sec) {
    if (sec === 'Potes') return 'variable';
    if (sec === 'Tobos') return 'fija';
    return 'ninguna';
  }

  function unitaria(sec, elegida) {
    if (sec === 'Potes') {
      var n = Math.round(parseNum(elegida) * 1000);
      if (n === 300 || n === 400 || n === 600) return n / 1000;
      return 0.3;
    }
    if (Object.prototype.hasOwnProperty.call(TARA_FIJA, sec)) return TARA_FIJA[sec];
    return 0;
  }

  function neto(bruto, empaques, taraUnit) {
    var n = parseNum(bruto) - (parseInt(empaques, 10) || 0) * parseNum(taraUnit);
    return Math.round(n * 1000) / 1000;
  }

  function fmt(n, dec) {
    dec = dec == null ? 3 : dec;
    return (Math.round(n * Math.pow(10, dec)) / Math.pow(10, dec)).toLocaleString('es-VE', {
      minimumFractionDigits: 0,
      maximumFractionDigits: dec
    });
  }

  global.AdaraTara = {
    TARA_VAR: TARA_VAR,
    parse: parseNum,
    modo: modo,
    unitaria: unitaria,
    neto: neto,
    fmt: fmt
  };
})(window);
