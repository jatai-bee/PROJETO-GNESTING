<?php
/**
 * Service worker da G-Nesting (loja e painel). Gerado por PwaController::serviceWorker().
 * @var string       $version  muda a cada pacote novo: o cache antigo é apagado na ativação
 * @var string       $base     subpasta da instalação ('' na raiz do domínio)
 * @var list<string> $precache offline + CSS/JS versionados
 */
$js = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
?>
/*
 * G-Nesting — service worker <?= $version ?>

 *
 * - arquivos de /assets com ?v= (nunca mudam sem mudar o endereço): cache primeiro
 * - fotos de /uploads: mostra a guardada e atualiza em segundo plano
 * - páginas: sempre da rede, nunca guardadas (trazem o nome do cliente, favoritos e tokens de formulário;
 *   num celular compartilhado, uma cópia mostraria os dados de uma pessoa para outra). Sem rede: página offline
 */
const VERSION = <?= $js($version) ?>;
const BASE = <?= $js($base) ?>;
const STATIC_CACHE = 'gn-static-' + VERSION;
const IMAGE_CACHE = 'gn-images-' + VERSION;
const OFFLINE_URL = <?= $js($precache[0]) ?>;
const PRECACHE = <?= $js($precache) ?>;

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(function (cache) { return Promise.allSettled(PRECACHE.map(function (url) { return cache.add(url); })); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(keys.filter(function (key) { return key.indexOf('gn-') === 0 && !key.endsWith(VERSION); })
          .map(function (key) { return caches.delete(key); }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  var url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.indexOf(BASE) !== 0) {
    return;
  }
  var path = url.pathname.slice(BASE.length) || '/';

  if (path.indexOf('/assets/') === 0) {
    if (url.search.indexOf('v=') !== -1) {
      event.respondWith(cacheFirst(request));
    }
    return;
  }
  if (path.indexOf('/uploads/') === 0) {
    event.respondWith(staleWhileRevalidate(request));
    return;
  }
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(function () { return caches.match(OFFLINE_URL); }));
  }
});

function cacheFirst(request) {
  return caches.match(request).then(function (cached) {
    return cached || fetch(request).then(function (response) {
      if (response.ok) {
        var copy = response.clone();
        caches.open(STATIC_CACHE).then(function (cache) { cache.put(request, copy); });
      }
      return response;
    });
  });
}

function staleWhileRevalidate(request) {
  return caches.open(IMAGE_CACHE).then(function (cache) {
    return cache.match(request).then(function (cached) {
      var network = fetch(request).then(function (response) {
        if (response.ok) {
          cache.put(request, response.clone());
        }
        return response;
      }).catch(function () { return cached; });
      return cached || network;
    });
  });
}
