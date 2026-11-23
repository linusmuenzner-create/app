/* dirks3n – Service Worker
 * Speichert nur die App-Hülle (HTML/CSS/JS/Icons) für schnelles Starten.
 * Gesundheitsdaten (api.php) werden NIE zwischengespeichert.
 */
const CACHE = 'dirks3n-shell-v2';
const SHELL = [
  './',
  'index.html',
  'manifest.webmanifest',
  'assets/css/app.css?v=2',
  'assets/js/app.js?v=2',
  'assets/fonts/inter-latin.woff2',
  'assets/fonts/space-grotesk-latin.woff2',
  'assets/vendor/chart.umd.min.js?v=4.4.7',
  'assets/icons/icon-192.png',
  'assets/icons/apple-touch-icon.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  // PHP-Endpunkte immer direkt vom Server
  if (url.pathname.endsWith('.php')) return; // inkl. Google-Login
  // HTML: zuerst Netzwerk (damit Updates sofort ankommen), sonst Cache
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(() => caches.match('index.html')));
    return;
  }
  // Dateien: zuerst Cache, sonst Netzwerk
  e.respondWith(caches.match(e.request).then((hit) => hit || fetch(e.request)));
});
