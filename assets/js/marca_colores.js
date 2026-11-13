(function () {
  var logo = document.getElementById('logo');
  var principal = document.getElementById('color_primario');
  var secundario = document.getElementById('color_secundario');
  if (!logo || !principal || !secundario) return;

  logo.addEventListener('change', function () {
    var archivo = logo.files && logo.files[0];
    if (!archivo || !/^image\/(png|jpeg|webp)$/.test(archivo.type)) return;
    var url = URL.createObjectURL(archivo);
    var img = new Image();
    img.onload = function () {
      URL.revokeObjectURL(url);
      var color = dominante(img);
      if (!color) return;
      principal.value = color;
      secundario.value = oscurecer(color, 0.28);
    };
    img.onerror = function () { URL.revokeObjectURL(url); };
    img.src = url;
  });

  function dominante(img) {
    var lado = 48;
    var canvas = document.createElement('canvas');
    canvas.width = lado;
    canvas.height = lado;
    var ctx = canvas.getContext('2d', { willReadFrequently: true });
    if (!ctx) return null;
    ctx.drawImage(img, 0, 0, lado, lado);
    var datos = ctx.getImageData(0, 0, lado, lado).data;
    var cubos = Object.create(null);
    var mejor = null;
    var mejorCuenta = 0;
    var i;
    for (i = 0; i < datos.length; i += 4) {
      var r = datos[i];
      var g = datos[i + 1];
      var b = datos[i + 2];
      var alfa = datos[i + 3];
      if (alfa < 128) continue;
      var max = Math.max(r, g, b);
      var min = Math.min(r, g, b);
      var lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
      if (lum > 0.92 || lum < 0.08 || (max - min) < 18) continue;
      var clave = ((r >> 4) << 8) | ((g >> 4) << 4) | (b >> 4);
      var cuenta = (cubos[clave] || 0) + 1;
      cubos[clave] = cuenta;
      if (cuenta > mejorCuenta) {
        mejorCuenta = cuenta;
        mejor = { r: (r >> 4) * 17, g: (g >> 4) * 17, b: (b >> 4) * 17 };
      }
    }
    return mejor ? hex(mejor.r, mejor.g, mejor.b) : null;
  }

  function oscurecer(color, factor) {
    var n = parseInt(color.slice(1), 16);
    return hex(
      Math.round(((n >> 16) & 255) * factor),
      Math.round(((n >> 8) & 255) * factor),
      Math.round((n & 255) * factor)
    );
  }

  function hex(r, g, b) {
    function par(v) {
      var s = Math.max(0, Math.min(255, v)).toString(16);
      return s.length === 1 ? '0' + s : s;
    }
    return '#' + par(r) + par(g) + par(b);
  }
})();
