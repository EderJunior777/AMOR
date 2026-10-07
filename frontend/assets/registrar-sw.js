/* Registra o service worker. Fica em arquivo proprio porque a politica de
   seguranca (CSP) do site nao aceita script dentro do HTML. */
if ("serviceWorker" in navigator) {
  window.addEventListener("load", function () {
    navigator.serviceWorker.register("sw.js").catch(function () {});
  });
}
