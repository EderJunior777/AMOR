/* =========================================================================
   Service worker (sw.js): /api/* NUNCA vai para o cache, nem da agenda de
   hoje (/api/agenda, /api/cliente) nem da nova (/api/v1/*), em nenhum
   metodo nem modo. Rode com: npm run test:sw

   Roda o sw.js de verdade num contexto isolado (node:vm), com self, caches
   e fetch falsos que registram tudo.
   ========================================================================= */

import { readFileSync } from "node:fs";
import vm from "node:vm";

let falhas = 0;
const ok = (cond, nome, extra) => {
  console.log((cond ? "  ok   " : "  FALHOU ") + nome + (cond ? "" : "\n           -> " + JSON.stringify(extra)));
  if (!cond) falhas++;
};
const secao = (titulo) => console.log("\n--- " + titulo + " ---");

const ORIGEM = "https://barbearia.example";
const guardados = [];      // tudo que foi para o cache (put/add)
const daRede = [];         // tudo que o proprio sw buscou na rede
const ouvintes = {};

const cache = {
  add: (pedido) => { guardados.push(String(pedido)); return Promise.resolve(); },
  put: (pedido) => { guardados.push(typeof pedido === "string" ? pedido : pedido.url); return Promise.resolve(); },
  match: () => Promise.resolve(undefined)
};

const contexto = {
  URL,
  Promise,
  console,
  caches: {
    open: () => Promise.resolve(cache),
    keys: () => Promise.resolve([]),
    delete: () => Promise.resolve(true),
    match: () => Promise.resolve(undefined)
  },
  fetch: (pedido) => {
    daRede.push(pedido.url);
    return Promise.resolve({ status: 200, clone() { return this; } });
  }
};
contexto.self = {
  location: { origin: ORIGEM },
  addEventListener: (tipo, fn) => { ouvintes[tipo] = fn; },
  skipWaiting: () => Promise.resolve(),
  clients: { claim: () => Promise.resolve() }
};

vm.runInNewContext(readFileSync(new URL("../sw.js", import.meta.url), "utf8"), contexto, { filename: "sw.js" });

async function disparar(caminho, { method = "GET", mode = "cors" } = {}) {
  let resposta = null;
  const evento = {
    request: { url: ORIGEM + caminho, method, mode },
    respondWith: (p) => { resposta = p; }
  };
  ouvintes.fetch(evento);
  if (resposta) await resposta;
  await new Promise((ok) => setTimeout(ok, 0)); // deixa os cache.put pendentes rodarem
  return resposta !== null;
}

secao("Instalacao");
let espera = null;
ouvintes.install({ waitUntil: (p) => { espera = p; } });
await espera;
ok(guardados.length > 0, "o sw guarda os arquivos essenciais", guardados);
ok(guardados.every((u) => !/\/api\//.test(u)), "nada de /api/ entre os essenciais", guardados);
ok(guardados.includes("./assets/agenda-v1.js"), "o cliente da agenda nova entra nos essenciais");

secao("/api/* passa direto, sem cache");
guardados.length = 0;
const rotasDeApi = [
  ["/api/v1/servicos"],
  ["/api/v1/regioes"],
  ["/api/v1/profissionais?servicos[]=1"],
  ["/api/v1/disponibilidade?data=2026-10-07&servicos[]=1"],
  ["/api/v1/reservas", { method: "POST" }],
  ["/api/v1/reservas/consultar", { method: "POST" }],
  ["/api/v1/reservas/cancelar", { method: "POST" }],
  ["/api/v1/reservas/remarcar", { method: "POST" }],
  ["/api/v1/servicos", { mode: "navigate" }],
  ["/api/agenda?dia=2026-10-07"],
  ["/api/agenda", { method: "POST" }],
  ["/api/cliente?chave=x"]
];
for (const [caminho, opcoes] of rotasDeApi) {
  const interceptou = await disparar(caminho, opcoes);
  ok(!interceptou, (opcoes && opcoes.method ? opcoes.method : "GET") + " " + caminho + (opcoes && opcoes.mode ? " (" + opcoes.mode + ")" : "") + ": sw nao responde");
}
ok(guardados.length === 0, "nenhuma resposta de /api/ foi para o cache", guardados);
ok(daRede.every((u) => !/\/api\//.test(u)), "o sw nem busca /api/ por conta propria", daRede);

secao("O resto continua em cache");
ok(await disparar("/assets/app.js"), "arquivo do site: sw responde");
ok(guardados.some((u) => u.endsWith("/assets/app.js")), "e atualiza o cache do arquivo");
ok(await disparar("/", { mode: "navigate" }), "navegacao do site: sw responde (funciona offline)");

console.log(falhas ? "\n>>> " + falhas + " FALHA(S)" : "\n>>> TUDO PASSOU");
process.exit(falhas ? 1 : 0);
