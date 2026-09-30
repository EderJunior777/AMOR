/* =========================================================================
   Cliente da agenda NOVA (API v1 do backend Laravel, /api/v1).

   SO HOMOLOGACAO. So e usado com CONFIG.agendaNova.ligada = true; com a
   flag desligada (padrao) o site segue na agenda de hoje (Netlify Blobs) e
   este arquivo nao faz nada. As reservas feitas aqui NAO aparecem no painel
   antigo do barbeiro (agenda.html), que le os Blobs.

   Regras:
   - o id de servico, regiao e profissional vem SEMPRE da API (slug do
     config.js -> codigo do catalogo); nada de id fixo no codigo;
   - cada tentativa de envio tem uma Idempotency-Key propria; reenviar a
     MESMA tentativa (falha de rede) usa a mesma chave;
   - 409 = horario ocupado; 422 = mensagem do codigo; 429/503 = tente de
     novo em instantes, sem repeticao automatica;
   - falha de rede: no maximo UMA repeticao automatica, com a mesma chave.

   Roda no navegador (window.AgendaV1) e no Node (testes, via require).
   ========================================================================= */

(function (raiz) {
  "use strict";

  var MENSAGENS = {
    conflito: "Esse horario acabou de ser ocupado. Escolha outro.",
    espera: "Muita gente agendando agora. Tente de novo em instantes.",
    rede: "Nao consegui falar com a agenda. Confira a internet e tente de novo.",
    erro: "Nao consegui concluir agora. Tente de novo em instantes.",
    recusa: "Confira os dados e tente de novo.",
    semProfissional: "Este servico nao esta disponivel agora."
  };

  var ESPERA_ANTES_DE_REENVIAR_MS = 800;

  /* Falha com tipo estavel: conflito (409), recusa (422), espera (429/503),
     rede (sem resposta) ou erro (o resto). A mensagem e sempre texto fixo ou
     a mensagem do codigo que a API mandou; quem mostra usa textContent. */
  function falha(tipo, status, codigo, mensagem) {
    return { falha: true, tipo: tipo, status: status, codigo: codigo || null, mensagem: mensagem };
  }

  function interpretar(status, corpo) {
    corpo = corpo || {};
    if (status === 409) return falha("conflito", status, corpo.codigo, MENSAGENS.conflito);
    if (status === 422) {
      var mensagem = typeof corpo.mensagem === "string" && corpo.mensagem ? corpo.mensagem : MENSAGENS.recusa;
      return falha("recusa", status, corpo.codigo, mensagem);
    }
    if (status === 429 || status === 503) return falha("espera", status, corpo.codigo, MENSAGENS.espera);
    return falha("erro", status, corpo.codigo, MENSAGENS.erro);
  }

  /* Chave de idempotencia: aleatoria de verdade (crypto), nunca o gerador comum.
     Formato aceito pela API: [A-Za-z0-9_-], 16 a 100 caracteres. */
  function novaChave(cripto) {
    cripto = cripto || (typeof crypto !== "undefined" ? crypto : null);
    if (cripto && typeof cripto.randomUUID === "function") return "site-" + cripto.randomUUID();
    if (cripto && typeof cripto.getRandomValues === "function") {
      var bytes = new Uint8Array(16);
      cripto.getRandomValues(bytes);
      var hex = "";
      for (var i = 0; i < bytes.length; i++) hex += ("0" + bytes[i].toString(16)).slice(-2);
      return "site-" + hex;
    }
    throw falha("erro", 0, null, MENSAGENS.erro);
  }

  /* Uma tentativa = um pedido de reserva com a sua chave. Mesmo corpo que a
     tentativa anterior (que nao teve resposta): e a MESMA tentativa, mesma
     chave. Corpo diferente: tentativa nova, chave nova. */
  function tentativaPara(anterior, corpo, cripto) {
    var assinatura = JSON.stringify(corpo);
    if (anterior && anterior.assinatura === assinatura) return anterior;
    return { chave: novaChave(cripto), assinatura: assinatura };
  }

  function criar(opcoes) {
    opcoes = opcoes || {};
    var base = String(opcoes.base || "/api/v1").replace(/\/+$/, "");
    var buscar = opcoes.fetch || (typeof fetch === "function" ? fetch.bind(raiz) : null);
    var esperar = opcoes.esperar || function (ms) {
      return new Promise(function (ok) { setTimeout(ok, ms); });
    };

    var catalogoEmAndamento = null;
    var profissionais = {};

    function pedir(metodo, caminho, corpo, cabecalhos) {
      var init = { method: metodo, headers: { accept: "application/json" } };
      if (corpo !== undefined) {
        init.headers["content-type"] = "application/json";
        init.body = JSON.stringify(corpo);
      }
      Object.keys(cabecalhos || {}).forEach(function (nome) { init.headers[nome] = cabecalhos[nome]; });

      return Promise.resolve()
        .then(function () { return buscar(base + caminho, init); })
        .then(null, function () { throw falha("rede", 0, null, MENSAGENS.rede); })
        .then(function (resposta) {
          return resposta.json().then(null, function () { return {}; }).then(function (dados) {
            if (!resposta.ok) throw interpretar(resposta.status, dados);
            return { status: resposta.status, dados: dados };
          });
        });
    }

    function consulta(parametros) {
      var partes = [];
      Object.keys(parametros).forEach(function (nome) {
        var valor = parametros[nome];
        if (valor === null || valor === undefined || valor === "") return;
        (Array.isArray(valor) ? valor : [valor]).forEach(function (v) {
          partes.push(encodeURIComponent(nome) + (Array.isArray(valor) ? "%5B%5D" : "") + "=" + encodeURIComponent(v));
        });
      });
      return partes.length ? "?" + partes.join("&") : "";
    }

    /* Catalogo da API indexado pelo codigo (o slug do config.js). */
    function catalogo() {
      if (!catalogoEmAndamento) {
        catalogoEmAndamento = Promise.all([pedir("GET", "/servicos"), pedir("GET", "/regioes")])
          .then(function (respostas) {
            var servicos = {}, regioes = {};
            (respostas[0].dados.servicos || []).forEach(function (s) { servicos[s.codigo] = s; });
            (respostas[1].dados.regioes || []).forEach(function (r) { regioes[r.codigo] = r; });
            return { servicos: servicos, regioes: regioes };
          })
          .then(null, function (erro) { catalogoEmAndamento = null; throw erro; });
      }
      return catalogoEmAndamento;
    }

    /* Primeiro profissional ativo que faz TODOS os servicos pedidos. */
    function profissionalPara(servicoIds) {
      var chave = servicoIds.slice().sort().join(",");
      if (!profissionais[chave]) {
        profissionais[chave] = pedir("GET", "/profissionais" + consulta({ servicos: servicoIds }))
          .then(function (r) {
            var lista = r.dados.profissionais || [];
            if (!lista.length) throw falha("recusa", 422, "profissional_indisponivel", MENSAGENS.semProfissional);
            return lista[0].id;
          })
          .then(null, function (erro) { delete profissionais[chave]; throw erro; });
      }
      return profissionais[chave];
    }

    function horarios(filtro) {
      return pedir("GET", "/disponibilidade" + consulta({
        data: filtro.data,
        servicos: filtro.servicoIds,
        profissional_id: filtro.profissionalId,
        modalidade: filtro.modalidade,
        regiao_id: filtro.modalidade === "domicilio" ? filtro.regiaoId : null
      })).then(function (r) { return r.dados.horarios || []; });
    }

    /* Envia a tentativa. Sem resposta (rede): repete UMA vez, com a MESMA
       chave. 429/503 nao repetem sozinhos. repetida = a API devolveu a
       reserva que ja existia para essa chave (200). */
    function reservar(corpo, tentativa) {
      function enviar() {
        return pedir("POST", "/reservas", corpo, { "Idempotency-Key": tentativa.chave });
      }
      return enviar()
        .then(null, function (erro) {
          if (!erro || erro.tipo !== "rede") throw erro;
          return esperar(ESPERA_ANTES_DE_REENVIAR_MS).then(enviar);
        })
        .then(function (r) { return { reserva: r.dados, repetida: r.status === 200 }; });
    }

    function consultar(codigo, telefone) {
      return pedir("POST", "/reservas/consultar", { codigo: codigo, telefone: telefone })
        .then(function (r) { return r.dados; });
    }

    function cancelar(codigo, telefone) {
      return pedir("POST", "/reservas/cancelar", { codigo: codigo, telefone: telefone })
        .then(function (r) { return r.dados; });
    }

    function remarcar(codigo, telefone, data, hora) {
      return pedir("POST", "/reservas/remarcar", { codigo: codigo, telefone: telefone, data: data, hora: hora })
        .then(function (r) { return r.dados; });
    }

    return {
      catalogo: catalogo,
      profissionalPara: profissionalPara,
      horarios: horarios,
      reservar: reservar,
      consultar: consultar,
      cancelar: cancelar,
      remarcar: remarcar
    };
  }

  var AgendaV1 = {
    criar: criar,
    interpretar: interpretar,
    novaChave: novaChave,
    tentativaPara: tentativaPara,
    MENSAGENS: MENSAGENS
  };

  raiz.AgendaV1 = AgendaV1;
  if (typeof module !== "undefined" && module.exports) { module.exports = AgendaV1; }
})(typeof window !== "undefined" ? window : globalThis);
