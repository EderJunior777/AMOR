/* =========================================================================
   Servidor local para ver o site funcionando antes de publicar.
   Roda o site E a agenda, sem precisar instalar a CLI da Netlify.

       node testes/servidor-local.mjs
       abra http://localhost:8888

   Os agendamentos ficam numa pasta temporaria e somem quando voce fecha.
   ========================================================================= */

import { createServer } from "node:http";
import { readFile, mkdtemp } from "node:fs/promises";
import { join, extname, normalize } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import { BlobsServer } from "@netlify/blobs/server";

// fileURLToPath (e nao .pathname) para funcionar no Windows: .pathname
// devolve "/C:/pasta/..." (com a letra do disco) e o join depois monta um caminho invalido.
const RAIZ = fileURLToPath(new URL("..", import.meta.url));
const PORTA = Number(process.env.PORT) || 8888;

const TIPOS = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".svg": "image/svg+xml",
  ".png": "image/png",
  ".webmanifest": "application/manifest+json",
  ".woff2": "font/woff2"
};

const dir = await mkdtemp(join(tmpdir(), "agenda-local-"));
const token = "token-local";
const blobs = new BlobsServer({ directory: dir, token, port: 0 });
const { port } = await blobs.start();

process.env.NETLIFY_BLOBS_CONTEXT = Buffer.from(JSON.stringify({
  edgeURL: `http://localhost:${port}`,
  uncachedEdgeURL: `http://localhost:${port}`,
  token,
  siteID: "site-local",
  primaryRegion: "us-east-1"
})).toString("base64");

// SO DESENVOLVIMENTO (este arquivo nao e uma funcao da Netlify): sem
// PIN_PAINEL a funcao recusa o painel, entao o servidor local usa 1234.
// Em producao a variavel e obrigatoria e nao existe PIN padrao.
const PIN_DE_DESENVOLVIMENTO = "1234";
const usaPinPadrao = !process.env.PIN_PAINEL;
if (usaPinPadrao) process.env.PIN_PAINEL = PIN_DE_DESENVOLVIMENTO;

const { default: agenda } = await import("../netlify/functions/agenda.mjs");
const { default: cliente } = await import("../netlify/functions/cliente.mjs");

const ROTAS = { "/api/agenda": agenda, "/api/cliente": cliente };

const servidor = createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORTA}`);

  const rota = ROTAS[url.pathname];

  if (rota) {
    const corpo = ["POST", "PUT", "PATCH"].includes(req.method)
      ? await new Promise((ok) => {
          let dados = "";
          req.on("data", (p) => (dados += p));
          req.on("end", () => ok(dados));
        })
      : undefined;

    const resposta = await rota(new Request(url.href, {
      method: req.method,
      headers: req.headers,
      body: corpo || undefined
    }));

    res.writeHead(resposta.status, Object.fromEntries(resposta.headers));
    res.end(await resposta.text());
    return;
  }

  const caminho = url.pathname === "/" ? "/index.html" : url.pathname;
  const arquivo = join(RAIZ, normalize(caminho).replace(/^(\.\.[/\\])+/, ""));

  try {
    const conteudo = await readFile(arquivo);
    res.writeHead(200, { "content-type": TIPOS[extname(arquivo)] || "application/octet-stream" });
    res.end(conteudo);
  } catch {
    res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
    res.end("Nao encontrado");
  }
});

// So 127.0.0.1: o servidor de desenvolvimento nao fica visivel na rede.
servidor.listen(PORTA, "127.0.0.1", () => {
  console.log(`\n  Site:   http://localhost:${PORTA}`);
  console.log(`  Agenda: http://localhost:${PORTA}/agenda.html  (${usaPinPadrao
    ? "PIN de desenvolvimento " + PIN_DE_DESENVOLVIMENTO
    : "PIN vindo de PIN_PAINEL"})\n`);
});
