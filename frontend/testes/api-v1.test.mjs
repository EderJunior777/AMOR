/* =========================================================================
   Agenda NOVA (flag ligada) contra a API REAL: php artisan serve no banco
   de TESTE do backend (cleison_teste), com o catalogo de demonstracao (os
   mesmos slugs do assets/config.js). Rode com: npm run test:api-v1

   Exige o PostgreSQL local de pe (scripts/postgres-local.ps1 iniciar) e o
   backend instalado. Nao rode junto com a suite PHP: as duas usam o mesmo
   banco de teste.

   Cobre: catalogo (slug -> id vindo da API), horarios, reservar, conflito
   409, reenvio idempotente (mesma tentativa = mesma chave, inclusive com a
   resposta perdida na rede) e consultar/cancelar/remarcar por codigo +
   telefone.
   ========================================================================= */

import { spawn, spawnSync } from "node:child_process";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import { join } from "node:path";
import { createServer } from "node:net";

const require = createRequire(import.meta.url);
const CONFIG = require("../assets/config.js");
const AgendaV1 = require("../assets/agenda-v1.js");

const BACKEND = fileURLToPath(new URL("../../backend/", import.meta.url));
const PHP = process.env.PHP || "php";

let falhas = 0;
const ok = (cond, nome, extra) => {
  console.log((cond ? "  ok   " : "  FALHOU ") + nome + (cond ? "" : "\n           -> " + JSON.stringify(extra)));
  if (!cond) falhas++;
};
const secao = (titulo) => console.log("\n--- " + titulo + " ---");

// Ambiente do backend: SEMPRE o de teste; limites altos (aqui nao se testa 429).
const ambiente = {
  ...process.env,
  APP_ENV: "testing",
  API_LIMITE_GERAL_POR_MINUTO: "100000",
  API_LIMITE_CRIAR_POR_MINUTO_IP: "100000",
  API_LIMITE_CRIAR_POR_HORA_TELEFONE: "100000",
  API_LIMITE_RESERVA_POR_MINUTO_IP_CODIGO: "100000",
  API_LIMITE_RESERVA_POR_HORA_IP: "100000"
};

// 1. Banco de teste limpo, com o catalogo de demonstracao.
const preparo = spawnSync(PHP, [join("tests", "Suporte", "preparar_api_para_o_site.php")], {
  cwd: BACKEND, env: ambiente, encoding: "utf8"
});
if (preparo.status !== 0 || !/"ok":true/.test(preparo.stdout)) {
  console.error("Nao consegui preparar o banco de teste:\n" + preparo.stdout + preparo.stderr);
  process.exit(1);
}

// 2. artisan serve numa porta livre.
const porta = await new Promise((ok) => {
  const s = createServer().listen(0, "127.0.0.1", () => { const p = s.address().port; s.close(() => ok(p)); });
});
const servidor = spawn(PHP, ["artisan", "serve", "--no-reload", "--host=127.0.0.1", "--port=" + porta], {
  cwd: BACKEND, env: ambiente, stdio: "ignore"
});

function encerrar() {
  if (servidor.exitCode !== null) return;
  if (process.platform === "win32") spawnSync("taskkill", ["/PID", String(servidor.pid), "/T", "/F"], { stdio: "ignore" });
  else servidor.kill("SIGTERM");
}
process.on("exit", encerrar);

const ORIGEM = "http://127.0.0.1:" + porta;
for (let i = 0; ; i++) {
  try {
    const r = await fetch(ORIGEM + "/up");
    if (r.ok) break;
  } catch { /* ainda subindo */ }
  if (i > 150) { console.error("artisan serve nao subiu"); process.exit(1); }
  await new Promise((ok) => setTimeout(ok, 200));
}

const api = AgendaV1.criar({ base: ORIGEM + "/api/v1", fetch, esperar: () => Promise.resolve() });

// Dia de trabalho: daqui a 2 dias, no fuso de Sao Paulo (dentro do horizonte).
const DIA = new Intl.DateTimeFormat("en-CA", { timeZone: "America/Sao_Paulo" })
  .format(new Date(Date.now() + 2 * 86400000));
const DIA_SEGUINTE = new Intl.DateTimeFormat("en-CA", { timeZone: "America/Sao_Paulo" })
  .format(new Date(Date.now() + 3 * 86400000));

async function falhaDe(promessa) {
  try { await promessa; return null; } catch (e) { return e; }
}

try {
  secao("Flag");
  ok(CONFIG.agendaNova && CONFIG.agendaNova.ligada === false, "a flag da agenda nova vem DESLIGADA no config.js");

  secao("Catalogo: slug do config.js -> id da API");
  const cat = await api.catalogo();
  const semId = CONFIG.servicos.filter((s) => !cat.servicos[s.id]).map((s) => s.id);
  ok(semId.length === 0, "todo servico do config.js tem codigo igual na API", semId);
  ok(CONFIG.regioes.every((r) => cat.regioes[r.id] && Number.isInteger(cat.regioes[r.id].id)), "toda regiao do config.js tem id vindo da API");
  const corte = cat.servicos["corte"];
  ok(Number.isInteger(corte.id) && corte.preco_centavos === 4000 && corte.duracao_minutos === 30, "corte: id, preco e duracao vem da API", corte);

  const profissional = await api.profissionalPara([corte.id]);
  ok(Number.isInteger(profissional), "profissional vem da API (quem faz o servico)");

  secao("Horarios");
  const filtro = { data: DIA, servicoIds: [corte.id], profissionalId: profissional, modalidade: "barbearia" };
  const livres = await api.horarios(filtro);
  ok(livres.includes("10:00") && livres.includes("11:00"), "10:00 e 11:00 livres no dia de teste", livres.slice(0, 6));

  const pedido = (hora, telefone, extra = {}) => ({
    servicos: [corte.id], profissional_id: profissional, data: DIA, hora, modalidade: "barbearia",
    cliente: { nome: "Cliente de Teste", telefone }, observacao: null, ...extra
  });

  secao("Reservar");
  const corpoA = pedido("10:00", "(11) 90000-0001");
  const tentativaA = AgendaV1.tentativaPara(null, corpoA);
  const a = await api.reservar(corpoA, tentativaA);
  ok(a.repetida === false && a.reserva.estado === "solicitado", "reserva criada (201), estado solicitado", a);
  ok(/^[0-9a-f-]{36}$/.test(a.reserva.codigo), "a API devolve o codigo publico da reserva");
  ok(a.reserva.total_centavos === 4000 && a.reserva.hora === "10:00", "preco e horario da reserva vem do servidor", a.reserva);
  ok(!(await api.horarios(filtro)).includes("10:00"), "10:00 sumiu da disponibilidade");

  secao("Conflito 409");
  const corpoB = pedido("10:00", "(11) 90000-0002");
  const conflito = await falhaDe(api.reservar(corpoB, AgendaV1.tentativaPara(null, corpoB)));
  ok(conflito && conflito.tipo === "conflito" && conflito.status === 409, "outro cliente no mesmo horario leva 409", conflito);
  ok(conflito && conflito.mensagem === AgendaV1.MENSAGENS.conflito, "mensagem: o horario acabou de ser ocupado");

  secao("Reenvio idempotente");
  ok(AgendaV1.tentativaPara(tentativaA, pedido("10:00", "(11) 90000-0001")) === tentativaA,
    "mesma tentativa (mesmo corpo) reaproveita a mesma chave");
  const repetida = await api.reservar(corpoA, tentativaA);
  ok(repetida.repetida === true && repetida.reserva.codigo === a.reserva.codigo, "reenviar a mesma tentativa devolve a MESMA reserva (200)", repetida);

  // Resposta perdida: a API grava, mas a resposta nao chega. O cliente
  // reenvia sozinho UMA vez, com a mesma chave, e recebe a mesma reserva.
  let chamadas = 0;
  const chaves = [];
  const redeQuePerdeAPrimeiraResposta = async (url, init) => {
    chamadas++;
    if (init && init.headers && init.headers["Idempotency-Key"]) chaves.push(init.headers["Idempotency-Key"]);
    const resposta = await fetch(url, init);
    if (chamadas === 1) { await resposta.text(); throw new TypeError("Failed to fetch"); }
    return resposta;
  };
  const apiInstavel = AgendaV1.criar({ base: ORIGEM + "/api/v1", fetch: redeQuePerdeAPrimeiraResposta, esperar: () => Promise.resolve() });
  const corpoC = pedido("11:00", "(11) 90000-0003");
  const tentativaC = AgendaV1.tentativaPara(null, corpoC);
  const c = await apiInstavel.reservar(corpoC, tentativaC);
  ok(chamadas === 2 && chaves.length === 2 && chaves[0] === chaves[1] && chaves[0] === tentativaC.chave,
    "resposta perdida: reenvio automatico unico, com a mesma chave", { chamadas, chaves });
  ok(c.repetida === true, "o reenvio recebe a reserva que ja tinha sido gravada (200), nao uma segunda", c);
  const outraTentativa = AgendaV1.tentativaPara(tentativaC, pedido("14:00", "(11) 90000-0003"));
  ok(outraTentativa.chave !== tentativaC.chave, "mudou o pedido: tentativa nova, chave nova");

  secao("Consultar, cancelar e remarcar por codigo + telefone");
  const consulta = await api.consultar(a.reserva.codigo, "11900000001");
  ok(consulta.codigo === a.reserva.codigo && consulta.hora === "10:00", "consultar com o telefone em outra grafia", consulta);

  const errado = await falhaDe(api.consultar(a.reserva.codigo, "(11) 90000-0009"));
  ok(errado && errado.tipo === "recusa" && errado.codigo === "reserva_nao_encontrada", "telefone errado: reserva_nao_encontrada (422)", errado);
  ok(errado && typeof errado.mensagem === "string" && errado.mensagem.length > 0, "422 traz a mensagem do codigo");

  const remarcada = await api.remarcar(a.reserva.codigo, "(11) 90000-0001", DIA_SEGUINTE, "15:00");
  ok(remarcada.data === DIA_SEGUINTE && remarcada.hora === "15:00", "remarcar para outro dia e hora", remarcada);
  ok((await api.horarios(filtro)).includes("10:00"), "o horario antigo voltou a ficar livre");

  const ocupado = await falhaDe(api.remarcar(c.reserva.codigo, "(11) 90000-0003", DIA_SEGUINTE, "15:00"));
  ok(ocupado && ocupado.tipo === "conflito", "remarcar para horario ocupado: 409", ocupado);

  const cancelada = await api.cancelar(a.reserva.codigo, "(11) 90000-0001");
  ok(cancelada.estado === "cancelado", "cancelar pelo site", cancelada);
  const deNovo = await falhaDe(api.cancelar(a.reserva.codigo, "(11) 90000-0001"));
  ok(deNovo && deNovo.tipo === "recusa" && deNovo.codigo === "estado_nao_permite", "cancelar de novo: estado_nao_permite", deNovo);

  secao("Domicilio: regiao pelo id da API");
  const zonaSul = cat.regioes["zona-sul"];
  const filtroCasa = { data: DIA, servicoIds: [corte.id], profissionalId: profissional, modalidade: "domicilio", regiaoId: zonaSul.id };
  const livresCasa = await api.horarios(filtroCasa);
  ok(livresCasa.length > 0, "horarios a domicilio para a regiao", livresCasa.slice(0, 4));
  const corpoD = pedido(livresCasa[livresCasa.length - 1], "(11) 90000-0004", {
    modalidade: "domicilio", regiao_id: zonaSul.id, endereco: { logradouro: "Rua de Teste, 123" }
  });
  const d = await api.reservar(corpoD, AgendaV1.tentativaPara(null, corpoD));
  ok(d.reserva.modalidade === "domicilio" && d.reserva.taxa_deslocamento_centavos === zonaSul.taxa_centavos,
    "reserva a domicilio com a taxa da regiao vinda da API", d.reserva);
} catch (erro) {
  falhas++;
  console.log("  FALHOU com excecao: " + (erro && erro.stack ? erro.stack : JSON.stringify(erro)));
} finally {
  encerrar();
}

console.log(falhas ? "\n>>> " + falhas + " FALHA(S)" : "\n>>> TUDO PASSOU");
process.exit(falhas ? 1 : 0);
