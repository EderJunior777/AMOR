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

const { default: handler } = await import("../netlify/functions/agenda.mjs");

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

// Amanha, para nunca esbarrar na regra de "dia que ja passou".
const DIA = new Date(Date.now() + 86400000).toISOString().slice(0, 10);

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
ok(r.status === 400 && /endereco/i.test(r.corpo.erro), "domicilio sem endereco barrado", r);

r = await chamar("POST", "", { ...CORTE, hora: "17:00", local: "domicilio", endereco: "Rua das Flores, 123 - Centro", regiao: "centro" });
ok(r.status === 201 && r.corpo.reserva.total === 40 + CONFIG.taxaDomicilio,
   "domicilio soma a taxa no total", r.corpo.reserva);

console.log("\n--- Deslocamento do domicilio sai da agenda ---");
{
  const DIA3 = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);
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
  const DIA4 = new Date(Date.now() + 4 * 86400000).toISOString().slice(0, 10);
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
  const DIA5 = new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10);
  r2 = await chamar("POST", "", {
    ...CORTE, dia: DIA5, hora: "10:00", local: "domicilio",
    endereco: "Rua Teste, 10", nome: "Vitor Nunes"
  });
  ok(r2.status === 400 && /regiao/i.test(r2.corpo.erro), "domicilio sem regiao barrado", r2);

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

console.log("--- Horario que ja passou hoje ---");
{
  const hoje = new Intl.DateTimeFormat("en-CA", { timeZone: "America/Sao_Paulo" }).format(new Date());
  const agora = new Intl.DateTimeFormat("en-GB", {
    timeZone: "America/Sao_Paulo", hour: "2-digit", minute: "2-digit", hour12: false
  }).format(new Date());
  const [h] = agora.split(":").map(Number);

  if (h > 8) {
    const r2 = await chamar("POST", "", { ...CORTE, dia: hoje, hora: "08:00", nome: "Atrasado Silva" });
    ok(r2.status === 400 && /passou/i.test(r2.corpo.erro), "08:00 de hoje recusado porque ja passou", r2);
  } else {
    console.log("  (pulado: ainda nao passou das 08:00 em Sao Paulo)");
  }
}

console.log("\n--- Dados pessoais so aparecem com PIN ---");
r = await chamar("GET", `?dia=${DIA}`);
ok(!JSON.stringify(r.corpo).includes("Joao") && !JSON.stringify(r.corpo).includes("Flores"),
   "consulta publica devolve so os horarios", r.corpo);

r = await chamar("GET", `?dia=${DIA}&pin=errado`);
ok(r.status === 401, "PIN errado bloqueado", r);

r = await chamar("GET", `?dia=${DIA}&pin=1234`);
const horas = (r.corpo.agendamentos || []).map((a) => a.hora);
ok(r.status === 200 && horas.join(",") === "11:00,14:30,15:30,17:00,19:30",
   "painel lista um item por agendamento, em ordem", horas);
ok((r.corpo.agendamentos || []).some((a) => a.endereco === "Rua das Flores, 123 - Centro"),
   "painel mostra o endereco do domicilio", r.corpo.agendamentos);

console.log("\n--- Liberar horario ---");
r = await chamar("DELETE", `?dia=${DIA}&grupo=${grupoCombo}&pin=errado`);
ok(r.status === 401, "liberar sem o PIN certo bloqueado", r);

r = await chamar("DELETE", `?dia=${DIA}&grupo=${grupoCombo}&pin=1234`);
ok(r.status === 200 && r.corpo.liberados === 2, "liberou os 2 blocos do combo", r);

r = await chamar("GET", `?dia=${DIA}`);
ok(!r.corpo.ocupados.includes("14:30") && !r.corpo.ocupados.includes("15:00"),
   "14:30 e 15:00 voltaram a ficar livres", r.corpo.ocupados);

r = await chamar("POST", "", { ...COMBO, nome: "Pedro Alves" });
ok(r.status === 201, "outro cliente conseguiu pegar o horario liberado", r);

console.log("\n--- Corrida: 12 clientes apertando confirmar no mesmo instante ---");
const DIA2 = new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10);
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
