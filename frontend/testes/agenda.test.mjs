/* =========================================================================
   Testes da agenda. Rode com: npm test

   O que importa aqui e provar a promessa do site: dois clientes NUNCA
   conseguem pegar o mesmo horario, e ninguem consegue marcar fora do
   expediente ou por um preco inventado.

   Sobe um servidor de blobs local (o mesmo que a Netlify usa em
   desenvolvimento), entao nao precisa de internet nem de conta em nada.
   ========================================================================= */

import { BlobsServer } from "@netlify/blobs/server";
import { rmSync, mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import CONFIG from "../assets/config.js";

const dir = mkdtempSync(join(tmpdir(), "blobs-"));
const token = "token-de-teste";
const server = new BlobsServer({ directory: dir, token, port: 0 });
const { port } = await server.start();

process.env.NETLIFY_BLOBS_CONTEXT = Buffer.from(JSON.stringify({
  edgeURL: `http://localhost:${port}`,
  uncachedEdgeURL: `http://localhost:${port}`,
  token,
  siteID: "site-teste",
  primaryRegion: "us-east-1"
})).toString("base64");

// O PIN e obrigatorio: o teste define o seu, nunca depende de um padrao.
const PIN = "pin-de-teste-9471";
process.env.PIN_PAINEL = PIN;

const { default: handler, _definirRelogioParaTestes } = await import("../netlify/functions/agenda.mjs");

// Relogio fixo: a suite da o mesmo resultado a qualquer hora do dia (antes
// dependia de ja ter passado das 08:00 em Sao Paulo e usava datas em UTC).
const AGORA = new Date("2026-09-24T09:00:00-03:00"); // quinta, 09:00 em SP
const fixarRelogio = (instante) => _definirRelogioParaTestes(() => new Date(instante));
fixarRelogio(AGORA);

// Dia (AAAA-MM-DD, em Sao Paulo) a n dias do instante fixo.
const diaSP = (n, base = AGORA) =>
  new Intl.DateTimeFormat("en-CA", { timeZone: "America/Sao_Paulo" })
    .format(new Date(base.getTime() + n * 86400000));

const base = "http://localhost/api/agenda";
let falhas = 0;

const ok = (cond, nome, extra) => {
  console.log((cond ? "  ok   " : "  FALHOU ") + nome + (cond ? "" : "\n           -> " + JSON.stringify(extra)));
  if (!cond) falhas++;
};

const chamar = async (metodo, query, corpo) => {
  const res = await handler(new Request(base + (query || ""), {
    method: metodo,
    headers: corpo ? { "content-type": "application/json" } : {},
    body: corpo ? JSON.stringify(corpo) : undefined
  }));
  return { status: res.status, corpo: await res.json() };
};

// Amanha (em relacao ao relogio fixo).
const DIA = diaSP(1);

const CLIENTE = { nome: "Joao da Silva", telefone: "(11) 98765-4321" };
const COMBO = { ...CLIENTE, dia: DIA, hora: "14:30", servicoId: "corte-barba", local: "barbearia" }; // 60 min
const CORTE = { ...CLIENTE, dia: DIA, hora: "10:00", servicoId: "corte", local: "barbearia" };      // 30 min

console.log("\n--- O dia comeca vazio ---");
let r = await chamar("GET", `?dia=${DIA}`);
ok(r.status === 200 && r.corpo.ocupados.length === 0, "nenhum horario ocupado", r);

console.log("\n--- Servico de 60 min ocupa os dois blocos de 30 ---");
r = await chamar("POST", "", COMBO);
ok(r.status === 201 && r.corpo.ok, "reserva criada", r);
const grupoCombo = r.corpo.grupo;

r = await chamar("GET", `?dia=${DIA}`);
ok(r.corpo.ocupados.join(",") === "14:30,15:00", "14:30 e 15:00 ficaram ocupados", r.corpo.ocupados);

// Regressao: no Windows o servidor de blobs local devolvia "14%3A30" no
// list(); o painel ficava vazio e o "liberar" dava 404.
const formatoHora = (lista) => Array.isArray(lista) && lista.every((h) => /^\d{2}:\d{2}$/.test(h));
ok(formatoHora(r.corpo.ocupados), "horarios ocupados vem no formato HH:MM", r.corpo.ocupados);

console.log("\n--- Nao pode bater com o horario de outro cliente ---");
r = await chamar("POST", "", { ...COMBO, nome: "Pedro Alves", telefone: "(11) 91111-2222" });
ok(r.status === 409 && r.corpo.erro === "OCUPADO", "mesmo horario e recusado", r);

r = await chamar("POST", "", { ...CORTE, hora: "15:00", nome: "Lucas Dias" });
ok(r.status === 409, "15:00 recusado: o combo das 14:30 ainda esta rolando", r);

r = await chamar("POST", "", { ...COMBO, hora: "14:00", nome: "Marcos Reis" });
ok(r.status === 409, "combo das 14:00 recusado: invadiria as 14:30", r);

r = await chamar("GET", `?dia=${DIA}`);
ok(!r.corpo.ocupados.includes("14:00"), "14:00 nao ficou preso (a reserva que falhou foi desfeita)", r.corpo.ocupados);

console.log("\n--- Horario livre do lado continua livre ---");
r = await chamar("POST", "", { ...CORTE, hora: "15:30", nome: "Ana Souza" });
ok(r.status === 201, "15:30 aceito", r);

console.log("\n--- Expediente ate as " + CONFIG.fechamento + " ---");
r = await chamar("POST", "", { ...COMBO, hora: "19:30", nome: "Rafael Lima" });
ok(r.status === 400 && /20:00/.test(r.corpo.erro), "combo de 60 min as 19:30 barrado (terminaria 20:30)", r);

r = await chamar("POST", "", { ...CORTE, hora: "19:30", nome: "Rafael Lima" });
ok(r.status === 201, "corte de 30 min as 19:30 aceito (termina exatamente as 20:00)", r);

r = await chamar("POST", "", { ...CORTE, hora: "07:30", nome: "Bruno Melo" });
ok(r.status === 400, "antes da abertura barrado", r);

r = await chamar("POST", "", { ...CORTE, hora: "10:45", nome: "Bruno Melo" });
ok(r.status === 400, "horario fora da grade de 30 min barrado", r);

console.log("\n--- Quem manda no preco e na duracao e o servidor ---");
r = await chamar("POST", "", { ...CORTE, hora: "11:00", preco: 1, total: 1, duracao: 5, servico: "De graca" });
ok(r.status === 201 && r.corpo.reserva.total === 40 && r.corpo.reserva.duracao === 30,
   "preco e duracao mandados pelo cliente sao ignorados", r.corpo.reserva);

r = await chamar("POST", "", { ...CORTE, hora: "11:30", servicoId: "servico-inventado" });
ok(r.status === 400, "servico que nao existe no config e recusado", r);

console.log("\n--- Atendimento a domicilio ---");
r = await chamar("POST", "", { ...CORTE, hora: "17:00", local: "domicilio", endereco: "", regiao: "centro" });
ok(r.status === 400 && /endereço/i.test(r.corpo.erro), "domicilio sem endereco barrado", r);

r = await chamar("POST", "", { ...CORTE, hora: "17:00", local: "domicilio", endereco: "Rua das Flores, 123 - Centro", regiao: "centro" });
ok(r.status === 201 && r.corpo.reserva.total === 40 + CONFIG.taxaDomicilio,
   "domicilio soma a taxa no total", r.corpo.reserva);

console.log("\n--- Deslocamento do domicilio sai da agenda ---");
{
  const DIA3 = diaSP(3);
  const zonaSul = CONFIG.regioes.find((r) => r.id === "zona-sul");   // 30 min
  const centro = CONFIG.regioes.find((r) => r.id === "centro");      // 15 min

  // Corte de 30 min as 14:00 na Zona Sul: 30 antes + 30 servico + 30 depois
  let r2 = await chamar("POST", "", {
    ...CORTE, dia: DIA3, hora: "14:00", local: "domicilio",
    endereco: "Rua Teste, 10", regiao: "zona-sul", nome: "Vitor Nunes"
  });
  ok(r2.status === 201 && r2.corpo.reserva.deslocamento === 30, "reserva em casa guarda o deslocamento", r2.corpo.reserva);

  r2 = await chamar("GET", `?dia=${DIA3}`);
  ok(r2.corpo.ocupados.join(",") === "13:30,14:00,14:30",
     "bloqueou 13:30 (ida), 14:00 (corte) e 14:30 (volta)", r2.corpo.ocupados);

  // Agora ninguem consegue marcar na barbearia colado nesse horario
  r2 = await chamar("POST", "", { ...CORTE, dia: DIA3, hora: "13:30", nome: "Tiago Alves" });
  ok(r2.status === 409, "13:30 na barbearia recusado: ele esta indo pra Zona Sul", r2);

  r2 = await chamar("POST", "", { ...CORTE, dia: DIA3, hora: "14:30", nome: "Tiago Alves" });
  ok(r2.status === 409, "14:30 na barbearia recusado: ele esta voltando", r2);

  r2 = await chamar("POST", "", { ...CORTE, dia: DIA3, hora: "15:00", nome: "Tiago Alves" });
  ok(r2.status === 201, "15:00 na barbearia liberado: ja deu tempo de voltar", r2);

  // Regiao mais perto reserva menos tempo
  const DIA4 = diaSP(4);
  r2 = await chamar("POST", "", {
    ...CORTE, dia: DIA4, hora: "14:00", local: "domicilio",
    endereco: "Rua Teste, 10", regiao: "centro", nome: "Vitor Nunes"
  });
  ok(r2.status === 201, "domicilio no Centro aceito", r2);

  r2 = await chamar("GET", `?dia=${DIA4}`);
  ok(r2.corpo.ocupados.join(",") === "13:30,14:00,14:30",
     `Centro (${centro.deslocamento} min) arredonda pra um bloco de cada lado`, r2.corpo.ocupados);

  void zonaSul;

  // O expediente tem que caber a janela inteira: ir, cortar e voltar
  r2 = await chamar("POST", "", {
    ...CORTE, dia: DIA3, hora: "08:00", local: "domicilio",
    endereco: "Rua Teste, 10", regiao: "zona-leste", nome: "Vitor Nunes"
  });
  ok(r2.status === 400 && /cedo demais/i.test(r2.corpo.erro),
     "08:00 em casa na Zona Leste barrado: teria que sair 07:15", r2);

  r2 = await chamar("POST", "", {
    ...CORTE, dia: DIA3, hora: "19:30", local: "domicilio",
    endereco: "Rua Teste, 10", regiao: "zona-leste", nome: "Vitor Nunes"
  });
  ok(r2.status === 400 && /20:00/.test(r2.corpo.erro),
     "19:30 em casa barrado: so chegaria em casa 20h45", r2);

  r2 = await chamar("POST", "", {
    ...CORTE, dia: DIA3, hora: "09:00", local: "domicilio",
    endereco: "Rua Teste, 10", regiao: "zona-leste", nome: "Vitor Nunes"
  });
  ok(r2.status === 201, "09:00 em casa na Zona Leste aceito (sai 08:15)", r2);

  // Sem regiao nao passa
  const DIA5 = diaSP(5);
  r2 = await chamar("POST", "", {
    ...CORTE, dia: DIA5, hora: "10:00", local: "domicilio",
    endereco: "Rua Teste, 10", nome: "Vitor Nunes"
  });
  ok(r2.status === 400 && /região/i.test(r2.corpo.erro), "domicilio sem regiao barrado", r2);

  r2 = await chamar("POST", "", {
    ...CORTE, dia: DIA5, hora: "10:00", local: "domicilio",
    endereco: "Rua Teste, 10", regiao: "marte", nome: "Vitor Nunes"
  });
  ok(r2.status === 400, "regiao inventada barrada", r2);
}

r = await chamar("POST", "", {
  ...CORTE, hora: "16:00", local: "domicilio",
  endereco: "Rua das Flores, 123 - Centro", regiao: "centro", nome: "Outro Cliente"
});
ok(r.status === 409, "16:00 em casa recusado: a ida esbarra no corte das 15:30", r);

console.log("\n--- Cadastro do cliente ---");
r = await chamar("POST", "", { ...CORTE, hora: "17:00", nome: "" });
ok(r.status === 400, "sem nome barrado", r);

r = await chamar("POST", "", { ...CORTE, hora: "17:00", telefone: "123" });
ok(r.status === 400, "telefone sem DDD barrado", r);

console.log("\n--- Datas ---");
r = await chamar("POST", "", { ...CORTE, dia: "2020-01-01" });
ok(r.status === 400, "dia que ja passou barrado", r);

r = await chamar("POST", "", { ...CORTE, dia: "2099-01-01" });
ok(r.status === 400, "dia longe demais barrado", r);

console.log("--- Horario que ja passou e antecedencia (relogio fixo: hoje 09:00 em SP) ---");
{
  const hoje = diaSP(0); // 2026-09-24
  let r2 = await chamar("POST", "", { ...CORTE, dia: hoje, hora: "08:00", nome: "Atrasado Silva" });
  ok(r2.status === 400 && /passou/i.test(r2.corpo.erro), "08:00 de hoje recusado porque ja passou", r2);

  // antecedenciaMinutos = 30: as 09:00 o primeiro horario possivel e 09:30.
  r2 = await chamar("POST", "", { ...CORTE, dia: hoje, hora: "09:00", nome: "Em Cima Silva" });
  ok(r2.status === 400 && /passou/i.test(r2.corpo.erro), `09:00 recusado: menos de ${CONFIG.antecedenciaMinutos} min de antecedencia`, r2);

  r2 = await chamar("POST", "", { ...CORTE, dia: hoje, hora: "09:30", nome: "Pontual Silva" });
  ok(r2.status === 201, "09:30 aceito: exatamente a antecedencia minima", r2);
}

console.log("--- Virada do dia no fuso de Sao Paulo ---");
{
  // 22:00 em SP = 01:00 do dia seguinte em UTC (o servidor da Netlify roda
  // em UTC). "Hoje" tem que continuar sendo 24/09.
  fixarRelogio("2026-09-24T22:00:00-03:00");
  const limite = diaSP(CONFIG.diasParaFrente, new Date("2026-09-24T12:00:00-03:00")); // 30 dias depois de 24/09
  const alemDoLimite = diaSP(CONFIG.diasParaFrente + 1, new Date("2026-09-24T12:00:00-03:00"));

  let r2 = await chamar("POST", "", { ...CORTE, dia: limite, hora: "10:00", nome: "Limite Silva" });
  ok(r2.status === 201, `as 22:00 de 24/09, ${limite} ainda esta dentro do horizonte`, r2);

  r2 = await chamar("POST", "", { ...CORTE, dia: alemDoLimite, hora: "10:00", nome: "Longe Silva" });
  ok(r2.status === 400 && /distante/i.test(r2.corpo.erro),
     `as 22:00 de 24/09, ${alemDoLimite} esta alem do horizonte (se usasse a data UTC, passaria)`, r2);

  // Meia-noite e um minuto de 10/10 (datas que nenhum outro caso usa):
  // 09/10 virou passado; 10/10 as 08:00 e futuro.
  fixarRelogio("2026-10-10T00:01:00-03:00");
  r2 = await chamar("POST", "", { ...CORTE, dia: "2026-10-09", hora: "19:30", nome: "Ontem Silva" });
  ok(r2.status === 400 && /já passou/i.test(r2.corpo.erro), "depois da meia-noite, 09/10 e dia que ja passou", r2);

  r2 = await chamar("POST", "", { ...CORTE, dia: "2026-10-10", hora: "08:00", nome: "Madrugador Silva" });
  ok(r2.status === 201, "depois da meia-noite, 10/10 08:00 e aceito", r2);

  fixarRelogio(AGORA);
}

console.log("\n--- Dados pessoais so aparecem com PIN ---");
r = await chamar("GET", `?dia=${DIA}`);
ok(!JSON.stringify(r.corpo).includes("Joao") && !JSON.stringify(r.corpo).includes("Flores"),
   "consulta publica devolve so os horarios", r.corpo);

r = await chamar("GET", `?dia=${DIA}&pin=errado`);
ok(r.status === 401, "PIN errado bloqueado", r);

r = await chamar("GET", `?dia=${DIA}&pin=1234`);
ok(r.status === 401, "o antigo PIN padrao 1234 nao abre o painel", r);

r = await chamar("GET", `?dia=${DIA}&pin=${PIN}`);
const horas = (r.corpo.agendamentos || []).map((a) => a.hora);
ok(r.status === 200 && horas.join(",") === "11:00,14:30,15:30,17:00,19:30",
   "painel lista um item por agendamento, em ordem", horas);
ok((r.corpo.agendamentos || []).some((a) => a.endereco === "Rua das Flores, 123 - Centro"),
   "painel mostra o endereco do domicilio", r.corpo.agendamentos);

console.log("\n--- Liberar horario ---");
r = await chamar("DELETE", `?dia=${DIA}&grupo=${grupoCombo}&pin=errado`);
ok(r.status === 401, "liberar sem o PIN certo bloqueado", r);

r = await chamar("DELETE", `?dia=${DIA}&grupo=${grupoCombo}`);
ok(r.status === 401, "liberar sem informar o PIN bloqueado", r);

r = await chamar("DELETE", `?dia=${DIA}&grupo=${grupoCombo}&pin=${PIN}`);
ok(r.status === 200 && r.corpo.liberados === 2, "liberou os 2 blocos do combo", r);

console.log("\n--- Sem PIN_PAINEL o painel recusa ---");
delete process.env.PIN_PAINEL;
r = await chamar("GET", `?dia=${DIA}&pin=1234`);
ok(r.status === 503 && r.corpo.erro === "Painel não configurado", "painel sem PIN configurado: 503", r);
r = await chamar("DELETE", `?dia=${DIA}&grupo=${grupoCombo}&pin=1234`);
ok(r.status === 503, "liberar sem PIN configurado: 503", r);
process.env.PIN_PAINEL = "";
r = await chamar("GET", `?dia=${DIA}&pin=`);
ok(r.status === 503, "PIN_PAINEL vazio tambem recusa (nao aceita PIN vazio)", r);
r = await chamar("GET", `?dia=${DIA}`);
ok(r.status === 200, "consulta publica de horarios segue funcionando sem PIN configurado", r);
process.env.PIN_PAINEL = PIN;

r = await chamar("GET", `?dia=${DIA}`);
// Sem conferir o formato, este teste passava por acaso quando a lista
// vinha como "14%3A30" (o includes nunca achava "14:30").
ok(formatoHora(r.corpo.ocupados) && r.corpo.ocupados.length > 0 &&
   !r.corpo.ocupados.includes("14:30") && !r.corpo.ocupados.includes("15:00"),
   "14:30 e 15:00 voltaram a ficar livres", r.corpo.ocupados);

r = await chamar("POST", "", { ...COMBO, nome: "Pedro Alves" });
ok(r.status === 201, "outro cliente conseguiu pegar o horario liberado", r);

console.log("\n--- Corrida: 12 clientes apertando confirmar no mesmo instante ---");
const DIA2 = diaSP(2);
const tentativas = await Promise.all(
  Array.from({ length: 12 }, (_, i) =>
    chamar("POST", "", { ...CORTE, dia: DIA2, hora: "09:00", nome: "Cliente " + i })
  )
);
const aceitos = tentativas.filter((t) => t.status === 201).length;
const recusados = tentativas.filter((t) => t.status === 409).length;
ok(aceitos === 1 && recusados === 11, `exatamente 1 passou e 11 levaram 409 (passaram: ${aceitos})`, tentativas.map((t) => t.status));

r = await chamar("GET", `?dia=${DIA2}`);
ok(r.corpo.ocupados.length === 1, "so um bloco ficou gravado no dia", r.corpo.ocupados);

await server.stop();
rmSync(dir, { recursive: true, force: true });

console.log(falhas ? `\n>>> ${falhas} TESTE(S) FALHARAM\n` : "\n>>> TUDO PASSOU\n");
process.exit(falhas ? 1 : 0);
