/**
 * Descargas en navegador y en la app Android (Capacitor con server.url).
 *
 * El WebView no maneja descargas ni <a download>, y el navegador externo (@capacitor/browser)
 * no lleva la cookie de sesión. En la app, el archivo se guarda en la caché con
 * @capacitor/filesystem y se entrega con @capacitor/share para abrirlo o guardarlo.
 *
 * API global (script clásico):
 *   window.AdaraDescargas.esNativo()                         → boolean
 *   window.AdaraDescargas.guardar({nombre, blob | base64, mime}) → Promise<void>
 *     Web: <a download> con URL.createObjectURL. App: Filesystem + Share.
 * Enlaces <a class="btn-descarga" href="..."> que devuelven un archivo del servidor:
 *   en la app se piden con fetch (misma sesión) y pasan por guardar(); en web no se tocan.
 *
 * Requiere en el proyecto Capacitor: @capacitor/filesystem y @capacitor/share.
 */
(function (global) {
  'use strict';

  var TIPOS = {
    'application/pdf': 'pdf',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
    'application/vnd.ms-excel': 'xls',
    'application/zip': 'zip',
    'text/csv': 'csv'
  };

  function esNativo() {
    var cap = global.Capacitor;
    return !!(cap && typeof cap.isNativePlatform === 'function' && cap.isNativePlatform());
  }

  function limpiarNombre(nombre) {
    return String(nombre || 'ADARA').replace(/[\\/:*?"<>|]+/g, '_');
  }

  function blobABase64(blob) {
    return new Promise(function (resolver, rechazar) {
      var lector = new FileReader();
      lector.onerror = function () { rechazar(lector.error); };
      lector.onload = function () {
        var texto = String(lector.result);
        resolver(texto.slice(texto.indexOf(',') + 1));
      };
      lector.readAsDataURL(blob);
    });
  }

  function base64ABlob(base64, mime) {
    var binario = global.atob(base64);
    var bytes = new Uint8Array(binario.length);
    for (var i = 0; i < binario.length; i++) {
      bytes[i] = binario.charCodeAt(i);
    }
    return new Blob([bytes], { type: mime || 'application/octet-stream' });
  }

  function descargaWeb(blob, nombre) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = nombre;
    a.rel = 'noopener';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
  }

  function esCancelacion(error) {
    return /cancel/i.test(String(error && (error.message || error)));
  }

  /**
   * @param {{nombre: string, blob?: Blob, base64?: string, mime?: string}} archivo
   * @returns {Promise<void>}
   */
  async function guardar(archivo) {
    var nombre = limpiarNombre(archivo.nombre);
    if (!archivo.blob && !archivo.base64) {
      throw new Error('No hay contenido para guardar.');
    }
    if (!esNativo()) {
      descargaWeb(archivo.blob || base64ABlob(archivo.base64, archivo.mime), nombre);
      return;
    }
    var plugins = global.Capacitor.Plugins || {};
    if (!plugins.Filesystem || !plugins.Share) {
      throw new Error('La app no tiene instalados los plugins de archivos y compartir. Actualiza la app.');
    }
    var datos = archivo.base64 || await blobABase64(archivo.blob);
    var guardado = await plugins.Filesystem.writeFile({ path: nombre, data: datos, directory: 'CACHE' });
    try {
      await plugins.Share.share({ title: nombre, dialogTitle: 'Abrir o guardar ' + nombre, files: [guardado.uri] });
    } catch (error) {
      if (!esCancelacion(error)) {
        throw error;
      }
    }
  }

  global.AdaraDescargas = { esNativo: esNativo, guardar: guardar };

  if (!esNativo()) {
    return;
  }

  function nombreDesdeRespuesta(respuesta, enlace, tipo) {
    var cd = respuesta.headers.get('Content-Disposition') || '';
    var utf = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(cd);
    if (utf) {
      try { return decodeURIComponent(utf[1].trim()); } catch (e) { /* sigue con el nombre simple */ }
    }
    var simple = /filename\s*=\s*"?([^";]+)"?/i.exec(cd);
    if (simple) {
      return simple[1].trim();
    }
    return enlace.getAttribute('download') || ('ADARA_' + Date.now() + '.' + (TIPOS[tipo] || 'bin'));
  }

  async function descargarEnlace(enlace) {
    if (enlace.dataset.descargando === '1') {
      return;
    }
    enlace.dataset.descargando = '1';
    enlace.setAttribute('aria-busy', 'true');
    try {
      var respuesta = await fetch(enlace.href, { credentials: 'same-origin' });
      var tipo = (respuesta.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
      if (!respuesta.ok) {
        global.alert('No se pudo generar el archivo (error ' + respuesta.status + ').');
        return;
      }
      // Si la sesión venció, el servidor responde con la página de login en HTML.
      if (tipo === 'text/html') {
        global.alert('Tu sesión terminó. Inicia sesión de nuevo y vuelve a descargar.');
        return;
      }
      await guardar({
        nombre: nombreDesdeRespuesta(respuesta, enlace, tipo),
        blob: await respuesta.blob(),
        mime: tipo
      });
    } catch (error) {
      console.error('ADARA: descarga fallida', error);
      global.alert(error && error.message ? error.message : 'No se pudo descargar el archivo.');
    } finally {
      delete enlace.dataset.descargando;
      enlace.removeAttribute('aria-busy');
    }
  }

  document.addEventListener('click', function (evento) {
    var enlace = evento.target.closest('a.btn-descarga[href]');
    if (!enlace || evento.defaultPrevented) {
      return;
    }
    evento.preventDefault();
    descargarEnlace(enlace);
  });
})(window);
