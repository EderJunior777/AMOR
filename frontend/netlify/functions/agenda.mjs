/* =========================================================================
   AGENDA - a "trava" de horarios mora aqui, no servidor.

   O navegador NAO decide nada: ele pergunta o que esta livre e manda o
   pedido. Preco, duracao e horario de funcionamento sao lidos do mesmo
   assets/config.js que o site usa, entao nao tem como um cliente esperto
   marcar fora do expediente ou por um preco que ele inventou.

   Cada bloco de 30 min vira uma chave propria ("2026-09-16__14:30"), e a
   trava tem duas camadas:

     1. a gravacao usa "onlyIfNew" - so cria se ninguem tiver criado antes.
        Na Netlify isso e atomico e ja resolve sozinho.
     2. depois de gravar TODOS os blocos, o servidor relê todos e confere
        se continuam sendo dele. Isso pega o caso em que o armazenamento
        nao respeita o onlyIfNew (o servidor de blobs local, por exemplo,
        grava sem trava de arquivo).

   Se dois clientes confirmarem no mesmo segundo, exatamente um passa; o
   outro leva 409 e a tela dele recarrega os horarios.

   Rotas (todas em /api/agenda):
     GET    ?dia=2026-09-16              -> horarios ocupados (sem dado pessoal)
     GET    ?dia=2026-09-16&pin=XXXX     -> agendamentos completos (painel)
     POST   { ... }                      -> reserva
     DELETE ?dia=...&grupo=...&pin=XXXX  -> libera um horario
   ========================================================================= */

import { createHash, timingSafeEqual } from "node:crypto";
import { getStore } from "@netlify/blobs";
import CONFIG from "../../assets/config.js";
import { registrarAtendimento, verificacaoLigada } from "./cliente.mjs";

// PIN do painel do barbeiro: variavel de ambiente PIN_PAINEL na Netlify
// (Site settings > Environment variables). OBRIGATORIA: sem ela o painel
// recusa tudo (503) em vez de cair num PIN conhecido. Lida a cada pedido.
const pinConfigurado = () => String(process.env.PIN_PAINEL || "");

// Compara os dois por hash (mesmo tamanho) em tempo constante.
const hash = (texto) => createHash("sha256").update(String(texto)).digest();
const pinConfere = (informado) => timingSafeEqual(hash(informado), hash(pinConfigurado()));

const painelNaoConfigurado = () => json({ erro: "Painel não configurado" }, 503);

const PASSO = Number(CONFIG.intervaloMinutos) || 30;
const MAX_DIAS = Number(CONFIG.diasParaFrente) || 30;
const FUSO = "America/Sao_Paulo";

const loja = () => getStore({ name: "agenda", consistency: "strong" });

const json = (dados, status = 200) =>
  new Response(JSON.stringify(dados), {
    status,
    headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" }
  });

/* ---------------------------------------------------------------- utils */

const ehDia = (d) => typeof d === "string" && /^\d{4}-\d{2}-\d{2}$/.test(d);
const ehHora = (h) => typeof h === "string" && /^([01]\d|2[0-3]):[0-5]\d$/.test(h);

const emMinutos = (hora) => {
  const [h, m] = hora.split(":").map(Number);
  return h * 60 + m;
};

const paraHora = (minutos) =>
  `${String(Math.floor(minutos / 60)).padStart(2, "0")}:${String(minutos % 60).padStart(2, "0")}`;

// Todos os blocos que um atendimento ocupa.
//
// 60 min as 14:30 ocupa 14:30 E 15:00. No domicilio entra tambem o
// deslocamento, reservado DOS DOIS LADOS: ele precisa de tempo pra chegar
// (senao um corte na barbearia as 14:00 tornaria o de 14:30 impossivel) e
// pra voltar (senao o corte seguinte na barbearia nao acontece).
const blocosDoServico = (horaInicial, duracao, deslocamento = 0) => {
  const folga = Math.ceil((deslocamento || 0) / PASSO) * PASSO;
  const inicio = Math.max(0, emMinutos(horaInicial) - folga);
  const fim = Math.min(24 * 60, emMinutos(horaInicial) + duracao + folga);

  const lista = [];
  for (let m = inicio; m < fim; m += PASSO) lista.push(paraHora(m));
  return lista;
};

const chave = (dia, hora) => `${dia}__${hora}`;

// O servidor de blobs local (@netlify/blobs/server) no Windows grava a
// chave como nome de arquivo codificado ("14:30" -> "14%3A30") e o list()
// devolve esse nome SEM decodificar; o get()/delete() seguinte codifica de
// novo ("%253A") e nao acha nada. Decodificar aqui mantem o formato da
// chave (compativel com o que ja estiver gravado) e nao muda nada quando a
// chave vem sem "%" - dia e hora nunca contem "%".
const chaveListada = (k) => {
  try { return decodeURIComponent(k); } catch { return k; }
};

const limpar = (texto, max) => String(texto ?? "").trim().slice(0, max);

// Relogio. Em producao e sempre o relogio real; os testes fixam um instante
// para verificar "ja passou", antecedencia e virada do dia sem depender da
// hora em que a suite roda (e sem mexer no relogio do sistema).
let relogio = () => new Date();
export const _definirRelogioParaTestes = (fn) => {
  relogio = typeof fn === "function" ? fn : () => new Date();
};

// O servidor da Netlify roda em UTC; sem ancorar no fuso de Sao Paulo ele
// acharia que ja e "amanha" depois das 21h e recusaria agendamentos de hoje.
const hojeISO = () => new Intl.DateTimeFormat("en-CA", { timeZone: FUSO }).format(relogio());

const agoraEmMinutos = () =>
  emMinutos(new Intl.DateTimeFormat("en-GB", {
    timeZone: FUSO, hour: "2-digit", minute: "2-digit", hour12: false
  }).format(relogio()));

const somarDias = (iso, dias) => {
  const d = new Date(`${iso}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + dias);
  return d.toISOString().slice(0, 10);
};

const diaDaSemana = (iso) => {
  const [a, m, d] = iso.split("-").map(Number);
  return new Date(Date.UTC(a, m - 1, d)).getUTCDay();
};

/* ------------------------------------------------------------ validacao */

function validarReserva(corpo) {
  const dia = limpar(corpo.dia, 10);
  const hora = limpar(corpo.hora, 5);

  if (!ehDia(dia)) return { erro: "Data inválida." };
  if (!ehHora(hora)) return { erro: "Horário inválido." };

  // O servico vem do config, nao do que o cliente mandou.
  const servico = (CONFIG.servicos || []).find((s) => s.id === limpar(corpo.servicoId, 40));
  if (!servico) return { erro: "Serviço não encontrado." };

  const duracao = Number(servico.duracao);
  const inicio = emMinutos(hora);
  const abre = emMinutos(CONFIG.abertura);
  const fecha = emMinutos(CONFIG.fechamento);

  if ((inicio - abre) % PASSO !== 0) return { erro: `Horário fora da grade de ${PASSO} minutos.` };

  const hoje = hojeISO();
  if (dia < hoje) return { erro: "Nao da pra marcar em um dia que ja passou." };

  // Uma pagina aberta de manha nao pode marcar as 09:00 quando ja sao 18:00.
  if (dia === hoje && inicio < agoraEmMinutos() + (Number(CONFIG.antecedenciaMinutos) || 0))
    return { erro: "Esse horário já passou. Escolha outro." };
  if (dia > somarDias(hoje, MAX_DIAS)) return { erro: "Data muito distante." };
  if ((CONFIG.diasFechados || []).includes(diaDaSemana(dia))) return { erro: "Fechado nesse dia." };

  const nome = limpar(corpo.nome, 80);
  if (nome.length < 2) return { erro: "Informe seu nome." };

  const telefone = limpar(corpo.telefone, 25);
  if (telefone.replace(/\D/g, "").length < 10) return { erro: "Informe um telefone válido com DDD." };

  const querDomicilio = corpo.local === "domicilio";
  if (querDomicilio && !CONFIG.domicilioAtivo) return { erro: "Atendimento a domicílio indisponível." };

  const local = querDomicilio ? "domicilio" : "barbearia";
  const endereco = limpar(corpo.endereco, 200);
  if (local === "domicilio" && endereco.length < 5)
    return { erro: "Para atendimento em casa, informe o endereco." };

  // A regiao decide quanto tempo de deslocamento sai da agenda, entao ela
  // e obrigatoria no domicilio e vem sempre do config - nunca do cliente.
  let regiao = null;
  if (local === "domicilio" && (CONFIG.regioes || []).length) {
    regiao = CONFIG.regioes.find((r) => r.id === limpar(corpo.regiao, 40));
    if (!regiao) return { erro: "Escolha a regiao do atendimento." };
  }

  const deslocamento = regiao ? Number(regiao.deslocamento) || 0 : 0;

  // A janela inteira do compromisso - ir, cortar e voltar - tem que caber
  // no expediente. Se ele atende ate as 20h, o site nao pode compromete-lo
  // a sair de casa as 07h15 nem a chegar em casa 20h45.
  if (inicio - deslocamento < abre)
    return deslocamento
      ? { erro: `Contando ${deslocamento} min de deslocamento, esse horário é cedo demais.` }
      : { erro: `O atendimento começa às ${CONFIG.abertura}.` };

  if (inicio + duracao + deslocamento > fecha)
    return { erro: deslocamento
      ? `Contando ${deslocamento} min de deslocamento, isso passaria das ${CONFIG.fechamento}.`
      : `Esse serviço leva ${duracao} min e não termina até as ${CONFIG.fechamento}.` };

  const taxa = local === "domicilio" ? Number(CONFIG.taxaDomicilio) || 0 : 0;

  return {
    reserva: {
      dia,
      hora,
      duracao,
      servico: servico.nome,
      servicoId: servico.id,
      preco: Number(servico.preco),
      taxa,
      total: Number(servico.preco) + taxa,
      local,
      endereco: local === "domicilio" ? endereco : "",
      regiao: regiao ? regiao.id : "",
      regiaoNome: regiao ? regiao.nome : "",
      deslocamento,
      nome,
      telefone,
      observacao: limpar(corpo.observacao, 300),
      criadoEm: relogio().toISOString()
    }
  };
}

/* --------------------------------------------------------------- acoes */

async function listarDia(dia, comDados) {
  const store = loja();
  const { blobs } = await store.list({ prefix: `${dia}__` });
  const chaves = blobs.map((b) => chaveListada(b.key));
  const horas = chaves.map((k) => k.split("__")[1]).filter(Boolean).sort();

  if (!comDados) return json({ dia, ocupados: horas, memoria: verificacaoLigada() });

  // Painel: um registro por agendamento, nao por bloco de 30 min.
  const registros = await Promise.all(
    chaves.map((k) => store.get(k, { type: "json" }).catch(() => null))
  );

  const agendamentos = registros
    .filter((r) => r && r.hora === r.inicio)
    .sort((a, b) => a.hora.localeCompare(b.hora));

  return json({ dia, ocupados: horas, agendamentos });
}

async function reservar(corpo) {
  const { erro, reserva } = validarReserva(corpo);
  if (erro) return json({ erro }, 400);

  const store = loja();
  const blocos = blocosDoServico(reserva.hora, reserva.duracao, reserva.deslocamento);
  const grupo = `${reserva.hora}-${Math.random().toString(36).slice(2, 10)}`;
  const gravadas = [];

  for (const bloco of blocos) {
    const k = chave(reserva.dia, bloco);

    // Checagem otimista: evita gravar metade dos blocos a toa.
    if ((await store.get(k)) !== null) {
      await desfazer(store, gravadas, grupo);
      return json({ erro: "OCUPADO", mensagem: "Esse horário acabou de ser preenchido." }, 409);
    }

    // A trava de verdade: so cria se a chave ainda nao existir.
    const resultado = await store.setJSON(
      k,
      { ...reserva, hora: bloco, inicio: reserva.hora, grupo },
      { onlyIfNew: true }
    );

    if (resultado && resultado.modified === false) {
      await desfazer(store, gravadas, grupo);
      return json({ erro: "OCUPADO", mensagem: "Esse horário acabou de ser preenchido." }, 409);
    }

    gravadas.push(k);
  }

  // Segunda camada: com TODOS os blocos gravados, relê cada um e confere
  // se continua sendo meu.
  //
  // Conferir logo depois de gravar cada bloco nao bastaria: um concorrente
  // pode sobrescrever o bloco das 14:30 enquanto eu ainda estou gravando o
  // das 15:00. So uma passada final, com tudo ja escrito, pega esse caso -
  // e e ela que garante que dois clientes nunca saem daqui com o mesmo
  // horario, mesmo se o armazenamento nao respeitar o onlyIfNew.
  for (const k of gravadas) {
    const confirmado = await store.get(k, { type: "json" }).catch(() => null);
    if (!confirmado || confirmado.grupo !== grupo) {
      await desfazer(store, gravadas, grupo);
      return json({ erro: "OCUPADO", mensagem: "Esse horário acabou de ser preenchido." }, 409);
    }
  }

  // Guarda no historico do cliente. Se falhar, a reserva continua valendo:
  // perder a memoria e chato, perder o horario e grave.
  try {
    await registrarAtendimento({ ...reserva, grupo });
  } catch (e) {
    console.error("nao consegui registrar o historico do cliente:", e && e.message);
  }

  return json({ ok: true, grupo, reserva: { ...reserva, grupo } }, 201);
}

// Reserva que falhou no meio nao pode deixar bloco preso na agenda - e nem
// apagar o bloco de quem ganhou a disputa. So apaga o que e do proprio grupo.
async function desfazer(store, chaves, grupo) {
  for (const k of chaves) {
    try {
      const registro = await store.get(k, { type: "json" }).catch(() => null);
      if (registro && registro.grupo === grupo) await store.delete(k);
    } catch {
      /* se falhar, o barbeiro libera pelo painel */
    }
  }
}

async function liberar(dia, grupo) {
  if (!ehDia(dia)) return json({ erro: "Data inválida." }, 400);
  if (!grupo) return json({ erro: "Agendamento não informado." }, 400);

  const store = loja();
  const { blobs } = await store.list({ prefix: `${dia}__` });
  let apagadas = 0;

  for (const b of blobs) {
    const k = chaveListada(b.key);
    const registro = await store.get(k, { type: "json" }).catch(() => null);
    if (registro && registro.grupo === grupo) {
      await store.delete(k);
      apagadas++;
    }
  }

  if (!apagadas) return json({ erro: "Agendamento não encontrado." }, 404);
  return json({ ok: true, liberados: apagadas });
}

/* ------------------------------------------------------------- handler */

export default async (req) => {
  const url = new URL(req.url);

  try {
    if (req.method === "GET") {
      const dia = url.searchParams.get("dia") || "";
      if (!ehDia(dia)) return json({ erro: "Informe o dia no formato AAAA-MM-DD." }, 400);

      const pin = url.searchParams.get("pin");
      if (pin !== null) {
        if (!pinConfigurado()) return painelNaoConfigurado();
        if (!pinConfere(pin)) return json({ erro: "PIN incorreto." }, 401);
      }

      return await listarDia(dia, pin !== null);
    }

    if (req.method === "POST") {
      const corpo = await req.json().catch(() => null);
      if (!corpo) return json({ erro: "Requisição inválida." }, 400);
      return await reservar(corpo);
    }

    if (req.method === "DELETE") {
      if (!pinConfigurado()) return painelNaoConfigurado();
      if (!pinConfere(url.searchParams.get("pin") ?? "")) return json({ erro: "PIN incorreto." }, 401);
      return await liberar(url.searchParams.get("dia") || "", url.searchParams.get("grupo") || "");
    }

    return json({ erro: "Método não suportado." }, 405);
  } catch (e) {
    return json({ erro: "Erro no servidor da agenda.", detalhe: String(e && e.message) }, 500);
  }
};

export const config = { path: "/api/agenda" };
