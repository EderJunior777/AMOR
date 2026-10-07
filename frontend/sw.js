/* =========================================================================
   Service worker do site da barbearia.

   Regra numero um: a AGENDA NUNCA E CACHEADA. Um horario guardado em cache
   faria o cliente ver como livre algo que ja foi preenchido - exatamente o
   problema que o site inteiro existe pra evitar. Tudo que passa por /api/
   vai direto pra rede, sempre.

   O resto (visual, fontes, textos) fica em cache pra abrir rapido e
   funcionar sem internet.
   ========================================================================= */

const VERSAO = "barbearia-v3";

const ESSENCIAIS = [
  "./",
  "./index.html",
  "./agenda.html",
  "./404.html",
  "./assets/styles.css",
  "./assets/fontes.css",
  "./assets/config.js",
  "./assets/agenda-v1.js",
  "./assets/app.js",
  "./assets/admin.js",
  "./assets/registrar-sw.js",
  "./assets/icone.svg",
  "./assets/fontes/inter.woff2",
  "./assets/fontes/instrument-serif.woff2",
  "./assets/fontes/instrument-serif-italico.woff2",
  "./manifest.webmanifest"
];

self.addEventListener("install", function (evento) {
  evento.waitUntil(
    caches.open(VERSAO)
      // addAll falha inteiro se um arquivo faltar; assim um 404 isolado
      // nao impede o service worker de instalar.
      .then(function (cache) {
        return Promise.all(ESSENCIAIS.map(function (caminho) {
          return cache.add(caminho).catch(function () { return null; });
        }));
      })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener("activate", function (evento) {
  evento.waitUntil(
    caches.keys()
      .then(function (chaves) {
        return Promise.all(chaves.map(function (chave) {
          return chave === VERSAO ? null : caches.delete(chave);
        }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener("fetch", function (evento) {
  const pedido = evento.request;
  const url = new URL(pedido.url);

  // So mexemos em GET do proprio site.
  if (pedido.method !== "GET" || url.origin !== self.location.origin) return;

  // A agenda (a de hoje e a nova, /api/v1) e os dados de cliente sempre vem
  // da rede. Sem excecao (testes/sw.test.mjs).
  if (url.pathname.indexOf("/api/") === 0) return;

  // Navegacao: tenta a rede (pra pegar o site atualizado) e, se estiver
  // sem internet, devolve a pagina guardada.
  if (pedido.mode === "navigate") {
    evento.respondWith(
      fetch(pedido)
        .then(function (resposta) {
          const copia = resposta.clone();
          caches.open(VERSAO).then(function (cache) { cache.put(pedido, copia); });
          return resposta;
        })
        .catch(function () {
          return caches.match(pedido).then(function (guardado) {
            return guardado || caches.match("./index.html");
          });
        })
    );
    return;
  }

  // Arquivos: responde do cache na hora e atualiza por baixo pra
  // proxima visita ja pegar a versao nova.
  evento.respondWith(
    caches.match(pedido).then(function (guardado) {
      const daRede = fetch(pedido)
        .then(function (resposta) {
          if (resposta && resposta.status === 200) {
            const copia = resposta.clone();
            caches.open(VERSAO).then(function (cache) { cache.put(pedido, copia); });
          }
          return resposta;
        })
        .catch(function () { return guardado; });

      return guardado || daRede;
    })
  );
});
