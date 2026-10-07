/* =========================================================================
   MEMORIA DO CLIENTE

   Guarda o historico de quem ja cortou aqui e devolve pra pessoa certa.

   O ponto delicado: o telefone sozinho NAO e senha. Se bastasse digitar um
   numero pra ver nome e endereco, qualquer um descobriria onde mora quem
   marcou. Entao o fluxo e:

     1. o cliente pede o historico com o telefone dele
     2. o servidor manda um codigo de 6 digitos no WhatsApp desse numero
        (API oficial da Meta - veja README pra ligar)
     3. o cliente digita o codigo e recebe uma chave de acesso
     4. a chave fica no aparelho e serve pras proximas visitas

   Enquanto as credenciais da Meta nao estiverem configuradas, a
   verificacao fica DESLIGADA e o site simplesmente nao oferece o recurso -
   nada quebra, so nao aparece.

   Rotas (todas em /api/cliente):
     POST { acao: "pedir-codigo", telefone }
     POST { acao: "confirmar", telefone, codigo }   -> devolve chave
     GET  ?chave=...                                -> historico
   ========================================================================= */

import { getStore } from "@netlify/blobs";
import { createHash, randomBytes, timingSafeEqual } from "node:crypto";

const META_TOKEN = process.env.WHATSAPP_TOKEN || "";
const META_PHONE_ID = process.env.WHATSAPP_PHONE_ID || "";
const META_TEMPLATE = process.env.WHATSAPP_TEMPLATE || "codigo_acesso";
const META_IDIOMA = process.env.WHATSAPP_TEMPLATE_LANG || "pt_BR";

// Sal pra nao guardar telefone em texto puro. Defina TELEFONE_SAL na
// Netlify; sem ele os hashes ainda funcionam, so ficam menos protegidos
// caso alguem tenha acesso ao armazenamento.
const SAL = process.env.TELEFONE_SAL || "barbearia-sem-sal";

const CODIGO_VALIDADE = 10 * 60 * 1000;   // 10 min
const CODIGO_TENTATIVAS = 5;
const PEDIDOS_POR_HORA = 3;
const CHAVE_VALIDADE = 180 * 24 * 60 * 60 * 1000;  // 6 meses

export const verificacaoLigada = () => Boolean(META_TOKEN && META_PHONE_ID);

const clientes = () => getStore({ name: "clientes", consistency: "strong" });
const codigos = () => getStore({ name: "codigos", consistency: "strong" });

const json = (dados, status = 200) =>
  new Response(JSON.stringify(dados), {
    status,
    headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" }
  });

/* ---------------------------------------------------------------- utils */

// So os digitos: "(11) 98765-4321" e "11987654321" sao a mesma pessoa.
export const normalizarTelefone = (bruto) => String(bruto ?? "").replace(/\D/g, "");

export const chaveTelefone = (telefone) =>
  createHash("sha256").update(SAL + ":" + telefone).digest("hex").slice(0, 32);

const hashCodigo = (codigo, telefone) =>
  createHash("sha256").update(SAL + ":" + telefone + ":" + codigo).digest("hex");

const iguais = (a, b) => {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && timingSafeEqual(x, y);
};

const agora = () => Date.now();

/* --------------------------------------------------- historico (escrita) */

// Chamado pela agenda quando uma reserva e confirmada.
export async function registrarAtendimento(reserva) {
  const telefone = normalizarTelefone(reserva.telefone);
  if (telefone.length < 10) return;

  const store = clientes();
  const k = chaveTelefone(telefone);
  const atual = (await store.get(k, { type: "json" }).catch(() => null)) || {
    criadoEm: new Date().toISOString(),
    atendimentos: []
  };

  atual.nome = reserva.nome;
  atual.telefone = reserva.telefone;
  atual.ultimoEndereco = reserva.endereco || atual.ultimoEndereco || "";
  atual.ultimaRegiao = reserva.regiao || atual.ultimaRegiao || "";

  atual.atendimentos.unshift({
    dia: reserva.dia,
    hora: reserva.hora,
    servicoId: reserva.servicoId,
    servico: reserva.servico,
    local: reserva.local,
    total: reserva.total,
    grupo: reserva.grupo
  });

  // 20 ultimos bastam pra tudo que a tela mostra.
  atual.atendimentos = atual.atendimentos.slice(0, 20);
  atual.atualizadoEm = new Date().toISOString();

  await store.setJSON(k, atual);
}

/* -------------------------------------------------------------- WhatsApp */

async function enviarCodigo(telefone, codigo) {
  const resposta = await fetch(`https://graph.facebook.com/v21.0/${META_PHONE_ID}/messages`, {
    method: "POST",
    headers: {
      authorization: `Bearer ${META_TOKEN}`,
      "content-type": "application/json"
    },
    body: JSON.stringify({
      messaging_product: "whatsapp",
      to: telefone,
      type: "template",
      template: {
        name: META_TEMPLATE,
        language: { code: META_IDIOMA },
        components: [
          { type: "body", parameters: [{ type: "text", text: codigo }] },
          {
            type: "button",
            sub_type: "url",
            index: "0",
            parameters: [{ type: "text", text: codigo }]
          }
        ]
      }
    })
  });

  if (!resposta.ok) {
    const detalhe = await resposta.text().catch(() => "");
    throw new Error("Meta respondeu " + resposta.status + ": " + detalhe.slice(0, 300));
  }
}

/* ----------------------------------------------------------------- acoes */

async function pedirCodigo(telefone) {
  const store = codigos();
  const k = chaveTelefone(telefone);
  const registro = (await store.get(k, { type: "json" }).catch(() => null)) || {};

  // Limite de pedidos: sem isso, da pra usar o site pra floodar o
  // WhatsApp de alguem (e pra torrar a cota da Meta).
  const janela = (registro.pedidos || []).filter((t) => agora() - t < 60 * 60 * 1000);
  if (janela.length >= PEDIDOS_POR_HORA) {
    return json({ erro: "Você já pediu o código várias vezes. Tente daqui a pouco." }, 429);
  }

  const temHistorico = await clientes().get(chaveTelefone(telefone));

  // Responde igual tendo historico ou nao: senao o site vira uma
  // ferramenta pra descobrir quem e cliente da barbearia.
  if (temHistorico !== null) {
    const codigo = String(randomBytes(4).readUInt32BE(0) % 1000000).padStart(6, "0");

    try {
      await enviarCodigo(telefone, codigo);
    } catch (e) {
      // A resposta da Meta pode trazer dados da conta: so no log da funcao.
      console.error("[cliente] falha ao enviar o codigo:", e);
      return json({ erro: "Não consegui enviar o código agora." }, 502);
    }

    await store.setJSON(k, {
      hash: hashCodigo(codigo, telefone),
      expiraEm: agora() + CODIGO_VALIDADE,
      tentativas: 0,
      pedidos: janela.concat(agora())
    });
  } else {
    await store.setJSON(k, { pedidos: janela.concat(agora()) });
  }

  return json({ ok: true, mensagem: "Se esse número já cortou aqui, o código chegou no WhatsApp." });
}

async function confirmarCodigo(telefone, codigo) {
  const store = codigos();
  const k = chaveTelefone(telefone);
  const registro = await store.get(k, { type: "json" }).catch(() => null);

  if (!registro || !registro.hash) return json({ erro: "Peça um código novo." }, 400);
  if (agora() > registro.expiraEm) {
    await store.delete(k);
    return json({ erro: "Esse código expirou. Peça outro." }, 400);
  }

  if ((registro.tentativas || 0) >= CODIGO_TENTATIVAS) {
    await store.delete(k);
    return json({ erro: "Errou o código vezes demais. Peça um novo." }, 429);
  }

  if (!iguais(hashCodigo(codigo, telefone), registro.hash)) {
    registro.tentativas = (registro.tentativas || 0) + 1;
    await store.setJSON(k, registro);
    return json({ erro: "Código errado." }, 401);
  }

  await store.delete(k);

  // Chave de acesso pro aparelho. E ela, e nao o telefone, que abre o
  // historico daqui pra frente.
  const chave = randomBytes(24).toString("hex");
  const perfil = await clientes().get(chaveTelefone(telefone), { type: "json" }).catch(() => null);
  if (!perfil) return json({ erro: "Não achei seu histórico." }, 404);

  perfil.chaves = (perfil.chaves || [])
    .filter((c) => c.expiraEm > agora())
    .slice(-4);

  perfil.chaves.push({
    hash: createHash("sha256").update(chave).digest("hex"),
    expiraEm: agora() + CHAVE_VALIDADE
  });

  await clientes().setJSON(chaveTelefone(telefone), perfil);

  return json({ ok: true, chave, cliente: recortarPerfil(perfil) });
}

// O que a tela do cliente pode ver. Chaves e hashes nunca saem daqui.
function recortarPerfil(perfil) {
  return {
    nome: perfil.nome,
    telefone: perfil.telefone,
    ultimoEndereco: perfil.ultimoEndereco || "",
    ultimaRegiao: perfil.ultimaRegiao || "",
    atendimentos: (perfil.atendimentos || []).slice(0, 10).map((a) => ({
      dia: a.dia,
      hora: a.hora,
      servicoId: a.servicoId,
      servico: a.servico,
      local: a.local,
      total: a.total
    }))
  };
}

async function porChave(chave) {
  // Sem chave a rota so responde se o recurso esta ligado, pro site saber
  // se deve oferecer "recuperar meus dados".
  if (!chave) return json({ ok: true, ligado: true });
  if (chave.length < 32) return json({ erro: "Chave inválida." }, 401);

  const alvo = createHash("sha256").update(chave).digest("hex");
  const store = clientes();
  const { blobs } = await store.list();

  for (const b of blobs) {
    const perfil = await store.get(b.key, { type: "json" }).catch(() => null);
    if (!perfil || !perfil.chaves) continue;

    const valida = perfil.chaves.some((c) => c.hash === alvo && c.expiraEm > agora());
    if (valida) return json({ ok: true, cliente: recortarPerfil(perfil) });
  }

  return json({ erro: "Chave inválida ou expirada." }, 401);
}

/* --------------------------------------------------------------- handler */

export default async (req) => {
  const url = new URL(req.url);

  try {
    if (!verificacaoLigada()) {
      return json({ erro: "DESLIGADO", mensagem: "A memória do cliente ainda não foi configurada." }, 503);
    }

    if (req.method === "GET") {
      return await porChave(url.searchParams.get("chave") || "");
    }

    if (req.method === "POST") {
      const corpo = await req.json().catch(() => null);
      if (!corpo) return json({ erro: "Requisição inválida." }, 400);

      const telefone = normalizarTelefone(corpo.telefone);
      if (telefone.length < 10) return json({ erro: "Telefone inválido." }, 400);

      if (corpo.acao === "pedir-codigo") return await pedirCodigo(telefone);

      if (corpo.acao === "confirmar") {
        const codigo = String(corpo.codigo ?? "").replace(/\D/g, "");
        if (codigo.length !== 6) return json({ erro: "O código tem 6 dígitos." }, 400);
        return await confirmarCodigo(telefone, codigo);
      }

      return json({ erro: "Ação desconhecida." }, 400);
    }

    return json({ erro: "Método não suportado." }, 405);
  } catch (e) {
    console.error("[cliente] erro interno:", e);
    return json({ erro: "Erro no servidor." }, 500);
  }
};

export const config = { path: "/api/cliente" };
