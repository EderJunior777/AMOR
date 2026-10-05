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

function resposta(status, corpo, cabecalhos) {
  return { ok: status >= 200 && status < 300, status, json: () => Promise.resolve(corpo), headers: new Headers(cabecalhos || {}) };
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

secao("Espera longa (Retry-After): limite por hora da API");
{
  const f = (status, cab) => AgendaV1.interpretar(status, { codigo: "muitas_tentativas" }, new Headers(cab || {}));
  const longa = f(429, { "retry-after": "982" });
  ok(longa.tipo === "espera" && longa.esperaSegundos === 982 && /17 minutos/.test(longa.mensagem),
    "429 com Retry-After de ~16 min diz \"cerca de 17 minutos\" (nao \"instantes\")", longa);
  ok(/2 minutos/.test(f(503, { "retry-after": "120" }).mensagem), "503 com 120 s: \"cerca de 2 minutos\"");
  ok(f(429, { "retry-after": "5" }).mensagem === M.espera && f(429, { "retry-after": "119" }).mensagem === M.espera,
    "espera curta (< 2 min) mantem \"tente de novo em instantes\"");
  ok(f(429).mensagem === M.espera && f(429).esperaSegundos === null, "sem Retry-After: texto de sempre");
  ok(f(429, { "retry-after": "abc" }).mensagem === M.espera && f(429, { "retry-after": "-3" }).mensagem === M.espera,
    "Retry-After invalido: texto de sempre");
  ok(AgendaV1.interpretar(429, {}).mensagem === M.espera, "chamada antiga sem cabecalhos continua valendo");
  ok(AgendaV1.respostaDefinitiva(longa), "espera segue sendo resposta definitiva (chave nova no proximo envio)");

  // ponta a ponta: o 429 vindo da rede chega com o tempo
  const { api } = redeFalsa([resposta(429, { codigo: "muitas_tentativas" }, { "retry-after": "982" })]);
  const erro = await falhaDe(api.consultar("COD", "11900000000"));
  ok(erro && erro.tipo === "espera" && /17 minutos/.test(erro.mensagem), "consultar com 429 de 982 s mostra o tempo de espera", erro);
}

secao("Estados da reserva");
const SEIS = ["solicitado", "confirmado", "em_atendimento", "concluido", "cancelado", "nao_compareceu"];
ok(JSON.stringify(Object.keys(AgendaV1.ESTADOS_DA_RESERVA).sort()) === JSON.stringify([...SEIS].sort()),
  "o mapa cobre exatamente os 6 estados do backend");
ok(SEIS.every((e) => AgendaV1.rotuloDoEstado(e) !== AgendaV1.ESTADO_DESCONHECIDO && AgendaV1.rotuloDoEstado(e) !== e),
  "cada estado conhecido tem um rotulo legivel (nunca o valor cru)");
ok(AgendaV1.rotuloDoEstado("solicitado") === "Aguardando confirmação do barbeiro", "solicitado: aguardando o barbeiro");
for (const estranho of ["expirado", "recusado", "EM_ANALISE", "", null, undefined, 42, {}, "constructor", "__proto__", "toString"]) {
  ok(AgendaV1.rotuloDoEstado(estranho) === "Em análise", "estado desconhecido vira \"Em análise\": " + JSON.stringify(estranho));
}
{
  // Se o backend ganhar ou perder um estado, este teste acusa (so roda com a pasta backend ao lado).
  const enumPhp = new URL("../../backend/app/Enums/EstadoAgendamento.php", import.meta.url);
  let fonte = null;
  try { fonte = readFileSync(enumPhp, "utf8"); } catch { /* deploy sem backend: pula */ }
  if (fonte) {
    const doBackend = [...fonte.matchAll(/case \w+ = '(\w+)';/g)].map((m) => m[1]).sort();
    ok(JSON.stringify(doBackend) === JSON.stringify([...SEIS].sort()), "mesmos estados do enum do backend", doBackend);
  } else {
    console.log("  (enum do backend ausente: comparacao pulada)");
  }
}
ok(["cancelado", "nao_compareceu"].every(AgendaV1.ofereceNovoHorario), "cancelado e nao compareceu: oferece \"Marcar novo horário\"");
ok(["solicitado", "confirmado", "em_atendimento", "concluido", "expirado", "recusado", null].every((e) => !AgendaV1.ofereceNovoHorario(e)),
  "os demais estados nao oferecem novo horario");
ok(AgendaV1.podeRemarcar("solicitado") && SEIS.filter((e) => e !== "solicitado").every((e) => !AgendaV1.podeRemarcar(e)),
  "so a reserva solicitada pode ser remarcada pelo site (a confirmada exige novo pedido)");

secao("Horarios da propria reserva (remarcar)");
{
  const catalogo = {
    servicos: { corte: { id: 7, nome: "Corte" }, barba: { id: 8, nome: "Barba" } },
    regioes: { centro: { id: 3, nome: "Centro" }, "zona-sul": { id: 4, nome: "Zona Sul" } }
  };
  const reserva = (extra) => ({
    estado: "solicitado", modalidade: "barbearia", regiao_nome: null,
    servicos: [{ nome: "Corte" }], profissional: { nome_exibicao: "Ze" }, ...extra
  });

  const a = AgendaV1.horariosDaReserva(reserva(), catalogo);
  ok(a && JSON.stringify(a.servicoIds) === "[7]" && a.modalidade === "barbearia" && a.regiaoId === null && a.profissionalNome === "Ze",
    "barbearia: servico da reserva, sem regiao", a);

  const b = AgendaV1.horariosDaReserva(reserva({ modalidade: "domicilio", regiao_nome: "Zona Sul" }), catalogo);
  ok(b && b.modalidade === "domicilio" && b.regiaoId === 4, "domicilio: a regiao DA RESERVA (nao a primeira)", b);

  const c = AgendaV1.horariosDaReserva(reserva({ servicos: [{ nome: "Corte" }, { nome: "Barba" }] }), catalogo);
  ok(c && JSON.stringify(c.servicoIds) === "[7,8]", "combo: todos os servicos, na ordem", c);

  ok(AgendaV1.horariosDaReserva(reserva({ servicos: [{ nome: "Servico que sumiu" }] }), catalogo) === null, "servico sem par no catalogo: null (nao chuta)");
  ok(AgendaV1.horariosDaReserva(reserva({ modalidade: "domicilio", regiao_nome: "Regiao que sumiu" }), catalogo) === null, "regiao sem par no catalogo: null");
  ok(AgendaV1.horariosDaReserva(reserva({ modalidade: "domicilio", regiao_nome: null }), catalogo) === null, "domicilio sem regiao: null");
  ok(AgendaV1.horariosDaReserva(reserva({ servicos: [] }), catalogo) === null, "reserva sem servicos: null");
  ok(AgendaV1.horariosDaReserva(null, catalogo) === null && AgendaV1.horariosDaReserva(reserva(), null) === null, "sem reserva ou sem catalogo: null");
}
{
  const { chamadas, api } = redeFalsa([
    resposta(200, { profissionais: [{ id: 5, nome_exibicao: "Outro" }, { id: 9, nome_exibicao: "Ze" }] }),
    resposta(200, { data: "2026-10-07", horarios: ["10:00", "10:30"] })
  ]);
  const id = await api.profissionalDaReserva([7], "Ze");
  ok(id === 9, "profissional da reserva escolhido pelo nome de exibicao (nao o primeiro)", id);
  ok((await api.profissionalDaReserva([7], "Ze")) === 9 && chamadas.length === 1, "mesmo profissional: uma consulta so");
  const horas = await api.horarios({ data: "2026-10-07", servicoIds: [7], profissionalId: id, modalidade: "domicilio", regiaoId: 4 });
  ok(horas.length === 2 && chamadas[1].url ===
    "/api/v1/disponibilidade?data=2026-10-07&servicos%5B%5D=7&profissional_id=9&modalidade=domicilio&regiao_id=4",
    "domicilio manda a regiao da reserva na disponibilidade", chamadas[1].url);
}
{
  const { api } = redeFalsa([resposta(200, { profissionais: [{ id: 5, nome_exibicao: "Outro" }] })]);
  ok((await api.profissionalDaReserva([7], "Nome que nao existe")) === 5, "sem par pelo nome, usa o primeiro profissional");
}
{
  const { api } = redeFalsa([resposta(200, { profissionais: [] })]);
  const f = await falhaDe(api.profissionalDaReserva([7], "Ze"));
  ok(f && f.tipo === "recusa" && f.mensagem === M.semProfissional, "reserva sem profissional disponivel: recusa clara", f);
}

console.log(falhas ? "\n>>> " + falhas + " FALHA(S)" : "\n>>> TUDO PASSOU");
process.exit(falhas ? 1 : 0);
