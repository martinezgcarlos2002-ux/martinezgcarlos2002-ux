/**
 * ADARA ERP — Service Worker (modo offline: caché visual y navegabilidad).
 *
 * Ubicación: raíz del proyecto (/ADARA/sw.js) para que su scope cubra index.php?p=… y assets/.
 * Registro: layout_end() en layout.php (ver bloque "Service Worker + cola offline").
 *
 * Estrategias:
 *   - Navegaciones (HTML de index.php):  Network First con timeout → caché de páginas → offline.html.
 *   - Assets propios versionados (?v=…) y CDN con versión en la ruta: Cache First (son inmutables).
 *   - Assets propios sin versión: Network First → caché.
 *   - POST/PUT/DELETE y fetch JSON: nunca se interceptan; la cola de offlineSync.js se encarga.
 *
 * Integración PHP:
 *   - index.php envía "Cache-Control: no-store". El SW guarda igualmente la última copia de cada vista
 *     para poder mostrarla sin red; por eso se purga la caché de páginas en login/logout (ver onFetch).
 *   - Las rutas de descarga (PDF, impresión, exportaciones, respaldos) se excluyen en RUTAS_SIN_CACHE.
 *     Si agregas rutas nuevas de ese tipo en config/routes.php, añádelas aquí.
 *   - Al publicar cambios de layout incrementa CACHE_VERSION para invalidar todo lo anterior.
 */
'use strict';

const CACHE_VERSION = 'v4';
const CACHE_PREFIX = 'adara-';
const CACHE_ESTATICO = `${CACHE_PREFIX}estatico-${CACHE_VERSION}`;
const CACHE_PAGINAS = `${CACHE_PREFIX}paginas-${CACHE_VERSION}`;

const MAX_PAGINAS = 60;
const TIMEOUT_NAVEGACION_MS = 5000;

const BASE = new URL(self.registration.scope);
const OFFLINE_URL = new URL('offline.html', BASE).href;

const CDN_ORIGIN = 'https://cdn.jsdelivr.net';

/** Deben coincidir con BOOTSTRAP_CSS / BOOTSTRAP_ICONS_CSS / BOOTSTRAP_JS de layout.php. */
const PRECACHE_CDN = [
  `${CDN_ORIGIN}/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css`,
  `${CDN_ORIGIN}/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css`,
  `${CDN_ORIGIN}/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js`,
];

/** Esqueleto mínimo del layout. El resto de assets/ se cachea en tiempo de ejecución al visitarse. */
const PRECACHE_LOCAL = [
  'offline.html',
  'assets/css/adara.css',
  'assets/js/contacto.js',
  'assets/js/offlineSync.js',
  'assets/js/descargas_app.js',
  'assets/img/favicon.png',
  'assets/img/logo.jpg',
  'assets/img/ADARA%20LOGO.jpg',
].map((ruta) => new URL(ruta, BASE).href);

/** Valores de ?p= que nunca se guardan: sesión, binarios y descargas. */
const RUTAS_SIN_CACHE = /^(login|logout|master\w*)$|_pdf(_|$)|pdf_|imprimir|descargar|export|plantilla|respaldo/;

/* =====================================================================
 * Ciclo de vida
 * ===================================================================== */

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_ESTATICO);
    // offline.html es obligatorio; el resto es "best effort" para no abortar la instalación por un 404.
    await cache.add(new Request(OFFLINE_URL, { cache: 'reload' }));
    await Promise.allSettled([
      ...PRECACHE_LOCAL.filter((u) => u !== OFFLINE_URL).map((u) => cache.add(new Request(u, { cache: 'reload' }))),
      ...PRECACHE_CDN.map((u) => cache.add(new Request(u, { mode: 'cors', credentials: 'omit' }))),
    ]);
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const vigentes = new Set([CACHE_ESTATICO, CACHE_PAGINAS]);
    const nombres = await caches.keys();
    await Promise.all(nombres
      .filter((n) => n.startsWith(CACHE_PREFIX) && !vigentes.has(n))
      .map((n) => caches.delete(n)));
    if (self.registration.navigationPreload) {
      await self.registration.navigationPreload.enable();
    }
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  const tipo = event.data && event.data.tipo;
  if (tipo === 'PURGAR_PAGINAS') {
    event.waitUntil(caches.delete(CACHE_PAGINAS));
  } else if (tipo === 'SKIP_WAITING') {
    self.skipWaiting();
  } else if (tipo === 'PRECARGAR_PAGINAS' && Array.isArray(event.data.urls)) {
    event.waitUntil(precargarPaginas(event.data.urls));
  }
});

/** Guarda las vistas de los módulos mientras hay señal, para abrirlas después sin conexión. */
async function precargarPaginas(urls) {
  const limpias = urls
    .map((u) => { try { return new URL(u, BASE); } catch (_) { return null; } })
    .filter((u) => u && u.origin === BASE.origin && u.pathname.startsWith(BASE.pathname) && esVistaCacheable(u))
    .slice(0, 12);
  for (const url of limpias) {
    try {
      const res = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
      await guardarPagina(new Request(url.href), res);
    } catch (_) {
      // Sin señal o error del servidor: se intenta en la próxima visita al inicio.
    }
  }
}

/* =====================================================================
 * Enrutamiento de peticiones
 * ===================================================================== */

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  const mismoOrigen = url.origin === BASE.origin;

  if (req.method !== 'GET') {
    // Login/logout cambian de usuario: las vistas guardadas del anterior no deben quedar accesibles offline.
    if (mismoOrigen && req.mode === 'navigate' && /^(login|logout|master_salir|master_soporte|master_salir_soporte)$/.test(url.searchParams.get('p') || '')) {
      event.waitUntil(caches.delete(CACHE_PAGINAS));
    }
    return;
  }

  if (mismoOrigen && !url.pathname.startsWith(BASE.pathname)) return;

  if (req.mode === 'navigate') {
    if (mismoOrigen && esVistaCacheable(url)) {
      event.respondWith(navegacionNetworkFirst(event));
    } else if (mismoOrigen && (url.searchParams.get('p') || '') === 'login') {
      event.respondWith(navegacionLogin(event));
    }
    return;
  }

  if (url.origin === CDN_ORIGIN) {
    event.respondWith(cacheFirst(req));
    return;
  }

  if (mismoOrigen && url.pathname.startsWith(`${BASE.pathname}assets/`)) {
    event.respondWith(url.searchParams.has('v') ? cacheFirst(req) : networkFirstAsset(req));
  }
  // Resto (fetch JSON de los módulos, uploads, etc.): red directa, sin caché.
});

function esVistaCacheable(url) {
  const p = url.searchParams.get('p') || '';
  if (p === '') return true; // index.php sin ?p redirige; solo se usa el fallback.
  return !RUTAS_SIN_CACHE.test(p);
}

/* =====================================================================
 * Estrategias
 * ===================================================================== */

async function navegacionNetworkFirst(event) {
  const req = event.request;
  const red = (async () => {
    const preload = await event.preloadResponse;
    return preload || fetch(req);
  })();

  // Si la red tarda (conexión inestable) se sirve la copia local, pero la respuesta real sigue
  // actualizando la caché en segundo plano. El clone() debe ocurrir antes de que el navegador lea el body.
  event.waitUntil(red.then((res) => guardarPagina(req, res.clone())).catch(() => {}));

  try {
    return await conTimeout(red, TIMEOUT_NAVEGACION_MS);
  } catch (_) {
    const local = await buscarPagina(req.url);
    if (local) return local;
    try {
      return await red; // Sin copia local: esperar la red aunque sea lenta.
    } catch (__) {
      return (await caches.match(OFFLINE_URL)) || new Response('Sin conexión', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=UTF-8' },
      });
    }
  }
}

/**
 * La app Android abre en ?p=login. El login nunca se guarda, pero sin señal no puede quedar en blanco:
 * se muestra el inicio guardado (la caché de páginas se borra en cada login/logout, así que es del
 * mismo usuario) o, si no hay, offline.html.
 */
async function navegacionLogin(event) {
  try {
    const preload = await event.preloadResponse;
    return preload || await fetch(event.request);
  } catch (_) {
    const inicio = new URL(BASE.pathname + 'index.php', BASE.origin);
    inicio.searchParams.set('p', 'dashboard');
    return (await buscarPagina(inicio.href))
      || (await caches.match(OFFLINE_URL))
      || new Response('Sin conexión', { status: 503, headers: { 'Content-Type': 'text/plain; charset=UTF-8' } });
  }
}

async function guardarPagina(req, res) {
  // redirected: p.ej. sesión vencida → login; no se debe guardar el login bajo la URL de otra vista.
  if (!res || !res.ok || res.redirected || res.type !== 'basic') return;
  const tipo = res.headers.get('Content-Type') || '';
  const disposicion = res.headers.get('Content-Disposition') || '';
  if (!tipo.includes('text/html') || disposicion.includes('attachment')) return;

  const cache = await caches.open(CACHE_PAGINAS);
  await cache.put(req.url, res);
  await recortarCache(cache, MAX_PAGINAS);
}

/** Coincidencia exacta y, si no existe, la vista base del módulo (index.php?p=modulo). */
async function buscarPagina(urlTexto) {
  const cache = await caches.open(CACHE_PAGINAS);
  const exacta = await cache.match(urlTexto);
  if (exacta) return exacta;

  const url = new URL(urlTexto);
  const p = url.searchParams.get('p');
  if (!p) return undefined;
  const base = new URL(url.pathname, url.origin);
  base.searchParams.set('p', p);
  return cache.match(base.href);
}

async function cacheFirst(req) {
  const cache = await caches.open(CACHE_ESTATICO);
  const local = await cache.match(req);
  if (local) return local;
  try {
    const res = await fetch(req);
    if (res.ok) await cache.put(req, res.clone());
    return res;
  } catch (e) {
    // Mismo asset con otro ?v=: mejor una versión anterior que una página sin estilos.
    const anterior = await cache.match(req, { ignoreSearch: true });
    if (anterior) return anterior;
    throw e;
  }
}

async function networkFirstAsset(req) {
  const cache = await caches.open(CACHE_ESTATICO);
  try {
    const res = await fetch(req);
    if (res.ok) await cache.put(req, res.clone());
    return res;
  } catch (e) {
    const local = (await cache.match(req)) || (await cache.match(req, { ignoreSearch: true }));
    if (local) return local;
    throw e;
  }
}

/* =====================================================================
 * Utilidades
 * ===================================================================== */

function conTimeout(promesa, ms) {
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => reject(new Error('timeout')), ms);
    promesa.then(
      (v) => { clearTimeout(t); resolve(v); },
      (e) => { clearTimeout(t); reject(e); },
    );
  });
}

/** Cache.keys() devuelve en orden de inserción: se eliminan las vistas más antiguas. */
async function recortarCache(cache, max) {
  const claves = await cache.keys();
  const sobrantes = claves.length - max;
  for (let i = 0; i < sobrantes; i++) {
    await cache.delete(claves[i]);
  }
}
