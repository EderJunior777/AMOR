/* =========================================================================
   Servidor de HOMOLOGACAO da agenda nova (flag ligada), para testar no
   navegador. Nao e o site publicado nem substitui o servidor-local.mjs.

       (backend) php artisan serve            -> http://127.0.0.1:8000
       (aqui)    node testes/servidor-homologacao.mjs
       abra http://localhost:8890

   - Serve os arquivos do site com CONFIG.agendaNova.ligada = true (so na
     resposta; o config.js no disco continua com a flag desligada).
   - /api/v1/* vai para o backend (API_V1_URL, padrao http://127.0.0.1:8000),
     na mesma origem, como o proxy da Netlify fara: sem CORS.
   - /api/agenda e /api/cliente (a agenda de hoje, nos Blobs) respondem 410:
     com a flag ligada o site NAO pode falar com os dois mundos. Toda chamada
     a eles aparece no console como ERRO.
   - O painel do barbeiro (agenda.html) NAO mostra estas reservas.
   ========================================================================= */

import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { join, extname, normalize } from "node:path";
import { fileURLToPath } from "node:url";

const RAIZ = fileURLToPath(new URL("..", import.meta.url));
const PORTA = Number(process.env.PORT) || 8890;
const API = String(process.env.API_V1_URL || "http://127.0.0.1:8000").replace(/\/+$/, "");

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

async function corpoDe(req) {
  const partes = [];
  for await (const parte of req) partes.push(parte);
  return partes.length ? Buffer.concat(partes) : undefined;
}

const servidor = createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORTA}`);

  if (url.pathname.startsWith("/api/v1/")) {
    const cabecalhos = {};
    for (const nome of ["accept", "content-type", "idempotency-key"]) {
      if (req.headers[nome]) cabecalhos[nome] = req.headers[nome];
    }
    try {
      const resposta = await fetch(API + url.pathname + url.search, {
        method: req.method,
        headers: cabecalhos,
        body: ["GET", "HEAD"].includes(req.method) ? undefined : await corpoDe(req)
      });
      const repassar = {};
      for (const nome of ["content-type", "retry-after"]) {
        const valor = resposta.headers.get(nome);
        if (valor) repassar[nome] = valor;
      }
      res.writeHead(resposta.status, repassar);
      res.end(Buffer.from(await resposta.arrayBuffer()));
    } catch {
      res.writeHead(502, { "content-type": "application/json" });
      res.end(JSON.stringify({ mensagem: "Backend fora do ar.", codigo: "backend_fora" }));
    }
    return;
  }

  if (url.pathname === "/api/agenda" || url.pathname === "/api/cliente") {
    console.error(`  ERRO: o site chamou ${req.method} ${url.pathname} com a agenda nova ligada (dois mundos).`);
    res.writeHead(410, { "content-type": "application/json" });
    res.end(JSON.stringify({ erro: "Agenda de hoje desligada nesta homologacao." }));
    return;
  }

  const caminho = url.pathname === "/" ? "/index.html" : url.pathname;
  const arquivo = join(RAIZ, normalize(caminho).replace(/^(\.\.[/\\])+/, ""));

  try {
    let conteudo = await readFile(arquivo);
    if (caminho === "/assets/config.js") {
      conteudo = Buffer.concat([conteudo, Buffer.from("\n/* homologacao */ CONFIG.agendaNova.ligada = true;\n")]);
    }
    res.writeHead(200, { "content-type": TIPOS[extname(arquivo)] || "application/octet-stream", "cache-control": "no-store" });
    res.end(conteudo);
  } catch {
    res.writeHead(404, { "content-type": "text/plain; charset=utf-8" });
    res.end("Nao encontrado");
  }
});

servidor.listen(PORTA, () => {
  console.log(`\n  HOMOLOGACAO da agenda nova: http://localhost:${PORTA}`);
  console.log(`  API v1 (proxy): ${API}/api/v1`);
  console.log("  O painel do barbeiro NAO mostra estas reservas.\n");
});
