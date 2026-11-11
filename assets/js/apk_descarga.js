/**
 * Muestra la descarga de la APK solo en un navegador normal.
 * Dentro de la app Capacitor el puente nativo inyecta window.Capacitor; la marca
 * "ADARA-App" del user agent (appendUserAgent en capacitor.config.ts) cubre el caso
 * en que el puente todavía no esté listo.
 */
(function () {
  'use strict';

  function enAppNativa() {
    var cap = window.Capacitor;
    if (cap) {
      if (typeof cap.isNativePlatform === 'function' && cap.isNativePlatform()) {
        return true;
      }
      if (typeof cap.getPlatform === 'function' && cap.getPlatform() !== 'web') {
        return true;
      }
    }
    return /\bADARA-App\b/.test(navigator.userAgent || '');
  }

  var seccion = document.getElementById('apk-download-section');
  if (!seccion) {
    return;
  }
  if (enAppNativa()) {
    seccion.remove();
    document.documentElement.classList.add('es-app-nativa');
    return;
  }
  seccion.hidden = false;
})();
