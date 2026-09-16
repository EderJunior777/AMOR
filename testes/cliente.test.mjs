/* =========================================================================
   Testes da memoria do cliente. Rode com: npm run test:cliente

   O que importa provar aqui e que o historico nao vaza: digitar o telefone
   de outra pessoa nao pode revelar o nome nem o endereco dela.

   O envio pelo WhatsApp e trocado por um dublê, entao o teste roda sem
   internet, sem conta na Meta e sem gastar mensagem.
   ========================================================================= */

import { BlobsServer } from "@netlify/blobs/server";
import { rmSync, mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const dir = mkdtempSync(join(tmpdir(), "blobs-cli-"));
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

// Credenciais de mentira: o suficiente pra ligar o recurso.
process.env.WHATSAPP_TOKEN = "token-falso";
process.env.WHATSAPP_PHONE_ID = "1234567890";
process.env.TELEFONE_SAL = "sal-de-teste";

// Dublê do WhatsApp: guarda o que "seria enviado" em vez de chamar a Meta.
const enviados = [];
const fetchOriginal = globalThis.fetch;
globalThis.fetch = async (url, opcoes) => {
  if (String(url).includes("graph.facebook.com")) {
    const corpo = JSON.parse(opcoes.body);
    enviados.push({
      para: corpo.to,
      codigo: corpo.template.components[0].parameters[0].text
    });
    return new Response(JSON.stringify({ messages: [{ id: "fake" }] }), { status: 200 });
  }
  return fetchOriginal(url, opcoes);
};

const { default: handler, registrarAtendimento } = await import("../netlify/functions/cliente.mjs");

let falhas = 0;
const ok = (cond, nome, extra) => {
  console.log((cond ? "  ok   " : "  FALHOU ") + nome + (cond ? "" : "\n           -> " + JSON.stringify(extra)));
  if (!cond) falhas++;
};

const chamar = async (metodo, query, corpo) => {
  const res = await handler(new Request("http://localhost/api/cliente" + (query || ""), {
    method: metodo,
    headers: corpo ? { "content-type": "application/json" } : {},
    body: corpo ? JSON.stringify(corpo) : undefined
  }));
  return { status: res.status, corpo: await res.json() };
};

const TEL_CLIENTE = "11987654321";
const TEL_ESTRANHO = "11911112222";

console.log("\n--- Um cliente que ja cortou aqui ---");
await registrarAtendimento({
  dia: "2026-09-01", hora: "14:00", servicoId: "degrade", servico: "Degrade",
  local: "domicilio", total: 70, grupo: "g1",
  nome: "Rafael Moreira", telefone: "(11) 98765-4321",
  endereco: "Av. Paulista, 900 - ap 71", regiao: "centro"
});
ok(true, "atendimento registrado");

console.log("\n--- Telefone sozinho nao abre nada ---");
let r = await chamar("GET", "?chave=" + "f".repeat(48));
ok(r.status === 401, "chave inventada e recusada", r);

r = await chamar("GET", "");
ok(r.status === 200 && r.corpo.ligado === true, "sem chave, so diz que o recurso existe", r);

console.log("\n--- Pedir codigo ---");
r = await chamar("POST", "", { acao: "pedir-codigo", telefone: TEL_CLIENTE });
ok(r.status === 200, "pedido aceito", r);
ok(enviados.length === 1 && enviados[0].para === TEL_CLIENTE, "codigo foi enviado pro numero certo", enviados);
const codigoBom = enviados[0].codigo;
ok(/^\d{6}$/.test(codigoBom), "codigo tem 6 digitos", codigoBom);

console.log("\n--- Numero que nunca cortou aqui ---");
const antes = enviados.length;
const r2 = await chamar("POST", "", { acao: "pedir-codigo", telefone: TEL_ESTRANHO });
ok(r2.status === 200, "responde 200 igualzinho", r2);
ok(enviados.length === antes, "mas nao envia mensagem nenhuma", enviados.length - antes);
ok(JSON.stringify(r2.corpo) === JSON.stringify({
  ok: true, mensagem: "Se esse numero ja cortou aqui, o codigo chegou no WhatsApp."
}), "resposta identica a de um cliente real (nao da pra descobrir quem e cliente)", r2.corpo);

console.log("\n--- Codigo errado ---");
r = await chamar("POST", "", { acao: "confirmar", telefone: TEL_CLIENTE, codigo: "000000" });
ok(r.status === 401 || r.status === 400, "codigo errado recusado", r);

r = await chamar("POST", "", { acao: "confirmar", telefone: TEL_CLIENTE, codigo: "12345" });
ok(r.status === 400, "codigo com menos de 6 digitos recusado", r);

console.log("\n--- Codigo certo ---");
r = await chamar("POST", "", { acao: "confirmar", telefone: TEL_CLIENTE, codigo: codigoBom });
ok(r.status === 200 && r.corpo.chave, "confirmou e devolveu a chave", r.status);
const chave = r.corpo.chave;
ok(r.corpo.cliente.nome === "Rafael Moreira", "veio o nome certo", r.corpo.cliente);
ok(r.corpo.cliente.atendimentos[0].servico === "Degrade", "veio o ultimo corte", r.corpo.cliente.atendimentos);

console.log("\n--- O que volta pro navegador nao pode ter segredo ---");
const texto = JSON.stringify(r.corpo.cliente);
ok(!texto.includes("chaves") && !texto.includes("hash"), "nenhum hash ou chave interna vaza", texto.slice(0, 120));

console.log("\n--- A chave abre o historico ---");
r = await chamar("GET", "?chave=" + chave);
ok(r.status === 200 && r.corpo.cliente.nome === "Rafael Moreira", "chave devolve o historico", r.status);
ok(r.corpo.cliente.ultimoEndereco === "Av. Paulista, 900 - ap 71", "e o ultimo endereco", r.corpo.cliente.ultimoEndereco);

console.log("\n--- O mesmo codigo nao serve duas vezes ---");
r = await chamar("POST", "", { acao: "confirmar", telefone: TEL_CLIENTE, codigo: codigoBom });
ok(r.status === 400, "codigo ja usado nao vale de novo", r);

console.log("\n--- Forca bruta no codigo ---");
await chamar("POST", "", { acao: "pedir-codigo", telefone: TEL_CLIENTE });
let bloqueou = false;
for (let i = 0; i < 6; i++) {
  const t = await chamar("POST", "", { acao: "confirmar", telefone: TEL_CLIENTE, codigo: "000001" });
  if (t.status === 429) { bloqueou = true; break; }
}
ok(bloqueou, "trava depois de algumas tentativas erradas");

console.log("\n--- Limite de pedidos por hora ---");
let limitou = false;
for (let i = 0; i < 6; i++) {
  const t = await chamar("POST", "", { acao: "pedir-codigo", telefone: TEL_CLIENTE });
  if (t.status === 429) { limitou = true; break; }
}
ok(limitou, "nao da pra usar o site pra floodar o WhatsApp de alguem");

console.log("\n--- Historico acumula e fica em ordem ---");
await registrarAtendimento({
  dia: "2026-09-10", hora: "10:00", servicoId: "corte", servico: "Corte",
  local: "barbearia", total: 40, grupo: "g2",
  nome: "Rafael Moreira", telefone: "11987654321", endereco: "", regiao: ""
});
r = await chamar("GET", "?chave=" + chave);
ok(r.corpo.cliente.atendimentos.length === 2, "dois atendimentos guardados", r.corpo.cliente.atendimentos.length);
ok(r.corpo.cliente.atendimentos[0].servico === "Corte", "o mais recente vem primeiro", r.corpo.cliente.atendimentos.map(a => a.servico));

console.log("\n--- Telefone com e sem mascara e a mesma pessoa ---");
ok(r.corpo.cliente.atendimentos.length === 2,
   "'(11) 98765-4321' e '11987654321' caem no mesmo historico");

await server.stop();
rmSync(dir, { recursive: true, force: true });
console.log(falhas ? `\n>>> ${falhas} TESTE(S) FALHARAM\n` : "\n>>> TUDO PASSOU\n");
process.exit(falhas ? 1 : 0);
