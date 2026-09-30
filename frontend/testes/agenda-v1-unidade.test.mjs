/* =========================================================================
   Cliente da agenda nova (assets/agenda-v1.js) sem servidor: fetch falso.
   Rode com: npm run test:agenda-v1

   Cobre o que a API real nao deixa provocar a vontade: 429/503 sem
   repeticao automatica, rede com UMA repeticao so (mesma chave), mensagens
   por status e a chave de idempotencia.
   ========================================================================= */

import { createRequire } from "node:module";
import { readFileSync } from "node:fs";

const require = createRequire(import.meta.url);
const AgendaV1 = require("../assets/agenda-v1.js");
const CONFIG = require("../assets/config.js");

let falhas = 0;
const ok = (cond, nome, extra) => {
  console.log((cond ? "  ok   " : "  FALHOU ") + nome + (cond ? "" : "\n           -> " + JSON.stringify(extra)));
  if (!cond) falhas++;
};
const secao = (titulo) => console.log("\n--- " + titulo + " ---");

function resposta(status, corpo) {
  return { ok: status >= 200 && status < 300, status, json: () => Promise.resolve(corpo) };
}

// fetch falso: devolve as respostas em ordem e guarda cada chamada.
function redeFalsa(respostas) {
  const chamadas = [];
  const fetchFalso = (url, init) => {
    chamadas.push({ url, init });
    const proxima = respostas[Math.min(chamadas.length, respostas.length) - 1];
    return proxima === "sem-rede" ? Promise.reject(new TypeError("Failed to fetch")) : Promise.resolve(proxima);
  };
  return { chamadas, api: AgendaV1.criar({ base: "/api/v1", fetch: fetchFalso, esperar: () => Promise.resolve() }) };
}

async function falhaDe(promessa) {
  try { await promessa; return null; } catch (e) { return e; }
}

const corpo = { servicos: [1], profissional_id: 1, data: "2026-10-07", hora: "10:00", modalidade: "barbearia",
  cliente: { nome: "Teste", telefone: "11900000001" } };

secao("Flag");
ok(CONFIG.agendaNova && CONFIG.agendaNova.ligada === false, "flag da agenda nova DESLIGADA por padrao");
ok(CONFIG.agendaNova.api === "/api/v1", "a API nova fica na mesma origem, em /api/v1 (sem CORS)");

secao("Mensagens por status");
const M = AgendaV1.MENSAGENS;
const casos = [
  [409, { codigo: "horario_indisponivel", mensagem: "Horario indisponivel." }, "conflito", M.conflito],
  [422, { codigo: "fora_do_expediente", mensagem: "Horario fora do expediente." }, "recusa", "Horario fora do expediente."],
  [422, {}, "recusa", M.recusa],
  [429, { codigo: "muitas_tentativas", mensagem: "Muitas tentativas." }, "espera", M.espera],
  [503, { codigo: "indisponivel", mensagem: "Servico temporariamente indisponivel." }, "espera", M.espera],
  [500, { codigo: "erro_interno" }, "erro", M.erro]
];
for (const [status, dados, tipo, mensagem] of casos) {
  const f = AgendaV1.interpretar(status, dados);
  ok(f.tipo === tipo && f.mensagem === mensagem && f.status === status, status + " -> " + tipo, f);
}
ok(/ocupado/.test(M.conflito), "409 diz que o horario acabou de ser ocupado");
ok(/instantes/.test(M.espera), "429/503 dizem para tentar de novo em instantes");

secao("Sem repeticao automatica em 429 e 503");
for (const status of [429, 503]) {
  const { chamadas, api } = redeFalsa([resposta(status, { codigo: "x" }), resposta(201, { codigo: "nunca" })]);
  const f = await falhaDe(api.reservar(corpo, AgendaV1.tentativaPara(null, corpo)));
  ok(f && f.tipo === "espera" && chamadas.length === 1, status + ": uma chamada so, sem loop", { f, chamadas: chamadas.length });
}
{
  const { chamadas, api } = redeFalsa([resposta(409, {}), resposta(201, {})]);
  await falhaDe(api.reservar(corpo, AgendaV1.tentativaPara(null, corpo)));
  ok(chamadas.length === 1, "409 tambem nao repete sozinho");
}

secao("Rede: uma repeticao, com a mesma chave");
{
  const { chamadas, api } = redeFalsa(["sem-rede", resposta(200, { codigo: "a" })]);
  const tentativa = AgendaV1.tentativaPara(null, corpo);
  const r = await api.reservar(corpo, tentativa);
  const chaves = chamadas.map((c) => c.init.headers["Idempotency-Key"]);
  ok(chamadas.length === 2 && chaves[0] === tentativa.chave && chaves[1] === tentativa.chave, "sem resposta: reenvia uma vez com a mesma chave", chaves);
  ok(r.repetida === true, "a resposta 200 do reenvio e a reserva ja existente");
}
{
  const { chamadas, api } = redeFalsa(["sem-rede", "sem-rede", "sem-rede"]);
  const f = await falhaDe(api.reservar(corpo, AgendaV1.tentativaPara(null, corpo)));
  ok(f && f.tipo === "rede" && chamadas.length === 2, "rede fora: no maximo 2 envios, depois avisa", { f, chamadas: chamadas.length });
  ok(f && f.mensagem === M.rede, "mensagem de rede");
}
{
  const { chamadas, api } = redeFalsa([resposta(201, { codigo: "a" })]);
  await api.reservar(corpo, AgendaV1.tentativaPara(null, corpo));
  const init = chamadas[0].init;
  ok(chamadas[0].url === "/api/v1/reservas" && init.method === "POST", "POST em /api/v1/reservas (mesma origem)");
  ok(init.headers["content-type"] === "application/json" && JSON.parse(init.body).hora === "10:00", "corpo em JSON");
}

secao("Quando a tentativa acaba (revisao de seguranca)");
// Resposta definitiva da API (antes de gravar): proxima tentativa, chave nova.
for (const status of [409, 422, 429, 503]) {
  ok(AgendaV1.respostaDefinitiva(AgendaV1.interpretar(status, {})) === true, status + ": resposta definitiva, encerra a tentativa");
}
// Resultado desconhecido (pode ter gravado): mantem a chave para o reenvio.
for (const status of [500, 502, 504]) {
  ok(AgendaV1.respostaDefinitiva(AgendaV1.interpretar(status, {})) === false, status + ": resultado incerto, mantem a mesma chave");
}
ok(AgendaV1.respostaDefinitiva({ tipo: "rede" }) === false, "sem resposta: mantem a mesma chave");
ok(AgendaV1.respostaDefinitiva(null) === false, "falha desconhecida: mantem a mesma chave");

secao("Tempo-limite: pedido pendurado vira falha de rede");
{
  let sinais = 0;
  const fetchPendurado = (url, init) => new Promise((_, rejeitar) => {
    if (init.signal) { sinais++; init.signal.addEventListener("abort", () => rejeitar(new Error("abortado"))); }
  });
  const api = AgendaV1.criar({ base: "/api/v1", fetch: fetchPendurado, esperar: () => Promise.resolve(), tempoLimiteMs: 30 });
  const f = await falhaDe(api.reservar(corpo, AgendaV1.tentativaPara(null, corpo)));
  ok(f && f.tipo === "rede" && sinais === 2, "abortado depois do tempo-limite, com uma repeticao (mesma chave)", { f, sinais });
}

secao("Chave de idempotencia por tentativa");
const t1 = AgendaV1.tentativaPara(null, corpo);
ok(/^[A-Za-z0-9_-]{16,100}$/.test(t1.chave), "formato aceito pela API ([A-Za-z0-9_-], 16 a 100)", t1.chave);
ok(AgendaV1.tentativaPara(t1, JSON.parse(JSON.stringify(corpo))) === t1, "mesmo corpo: mesma tentativa, mesma chave");
const t2 = AgendaV1.tentativaPara(t1, { ...corpo, hora: "10:30" });
ok(t2 !== t1 && t2.chave !== t1.chave, "corpo diferente: chave nova");
ok(AgendaV1.tentativaPara(null, corpo).chave !== t1.chave, "tentativa nova (depois de uma resposta): chave nova");
const semUuid = { getRandomValues: (b) => { for (let i = 0; i < b.length; i++) b[i] = i; return b; } };
ok(/^site-[0-9a-f]{32}$/.test(AgendaV1.novaChave(semUuid)), "sem randomUUID, usa getRandomValues");
ok((await falhaDe(Promise.resolve().then(() => AgendaV1.novaChave({})))) !== null, "sem gerador seguro, recusa (nao cai para Math.random)");
ok(!/Math\.random/.test(readFileSync(new URL("../assets/agenda-v1.js", import.meta.url), "utf8")), "o cliente nunca usa Math.random");

secao("Catalogo e horarios");
{
  const { chamadas, api } = redeFalsa([
    resposta(200, { servicos: [{ id: 7, codigo: "corte" }] }),
    resposta(200, { regioes: [{ id: 3, codigo: "centro" }] }),
    resposta(200, { profissionais: [{ id: 9, nome_exibicao: "Ze" }] }),
    resposta(200, { data: "2026-10-07", horarios: ["10:00"] })
  ]);
  const cat = await api.catalogo();
  ok(cat.servicos.corte.id === 7 && cat.regioes.centro.id === 3, "catalogo indexado pelo codigo (slug)");
  ok((await api.catalogo()) === cat && chamadas.length === 2, "catalogo carregado uma vez");
  const prof = await api.profissionalPara([7]);
  ok(prof === 9 && chamadas[2].url === "/api/v1/profissionais?servicos%5B%5D=7", "profissional pelos servicos", chamadas[2].url);
  const horas = await api.horarios({ data: "2026-10-07", servicoIds: [7], profissionalId: 9, modalidade: "barbearia", regiaoId: 3 });
  ok(horas[0] === "10:00", "horarios da disponibilidade");
  ok(chamadas[3].url === "/api/v1/disponibilidade?data=2026-10-07&servicos%5B%5D=7&profissional_id=9&modalidade=barbearia",
    "barbearia nao manda regiao", chamadas[3].url);
}
{
  const { api } = redeFalsa([resposta(200, { profissionais: [] })]);
  const f = await falhaDe(api.profissionalPara([7]));
  ok(f && f.tipo === "recusa" && f.mensagem === M.semProfissional, "servico sem profissional: recusa clara", f);
}

console.log(falhas ? "\n>>> " + falhas + " FALHA(S)" : "\n>>> TUDO PASSOU");
process.exit(falhas ? 1 : 0);
