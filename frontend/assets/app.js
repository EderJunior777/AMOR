/* =========================================================================
   Fluxo de agendamento do cliente.

   Quem decide o que esta livre e o servidor (/api/agenda); esta tela so
   pergunta, desenha e confirma. Se o servidor nao responder (site aberto
   direto do arquivo, por exemplo), cai num modo demonstracao com aviso.
   ========================================================================= */

(function () {
  "use strict";

  var API = "/api/agenda";
  var CHAVE_LOCAL = "agenda-demo";

  // Agenda NOVA (API v1 do backend, assets/agenda-v1.js): SO HOMOLOGACAO.
  // Desligada (padrao), nada que depende de "nova" roda e o site e o de
  // hoje (Blobs). Ligada, o site fala SO com a API nova: nunca com
  // /api/agenda nem /api/cliente (sem gravacao dupla), e o painel antigo do
  // barbeiro nao ve estas reservas.
  var nova = !!(typeof CONFIG !== "undefined" && CONFIG.agendaNova && CONFIG.agendaNova.ligada &&
    typeof window.AgendaV1 !== "undefined");
  var agendaNova = nova ? window.AgendaV1.criar({ base: CONFIG.agendaNova.api || "/api/v1" }) : null;

  var estado = {
    servico: null,
    local: "barbearia",
    regiao: null,
    cliente: null,
    dia: null,
    hora: null,
    ocupados: [],
    modoLocal: false,
    enviando: false,
    // So agenda nova:
    livresApi: [],      // horarios livres que a API devolveu para o dia
    pedidoHoras: 0,     // descarta resposta atrasada de um dia ja trocado
    tentativa: null,    // tentativa de envio em aberto (Idempotency-Key)
    semAgenda: false    // catalogo da API nao carregou
  };

  var el = function (id) { return document.getElementById(id); };

  /* ------------------------------------------------------------ ajudas */

  function dinheiro(valor) {
    var n = Number(valor);
    return "R$ " + (n % 1 === 0 ? n : n.toFixed(2).replace(".", ","));
  }

  function emMinutos(hora) {
    var p = hora.split(":");
    return Number(p[0]) * 60 + Number(p[1]);
  }

  function paraHora(min) {
    var h = Math.floor(min / 60), m = min % 60;
    return ("0" + h).slice(-2) + ":" + ("0" + m).slice(-2);
  }

  // Data como AAAA-MM-DD no fuso do proprio aparelho.
  // (new Date("2026-09-16") seria lido como UTC e podia voltar um dia.)
  function paraISO(data) {
    return data.getFullYear() + "-" +
      ("0" + (data.getMonth() + 1)).slice(-2) + "-" +
      ("0" + data.getDate()).slice(-2);
  }

  function deISO(iso) {
    var p = iso.split("-");
    return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
  }

  var SEMANA = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sab"];
  var MESES = ["jan", "fev", "mar", "abr", "mai", "jun", "jul", "ago", "set", "out", "nov", "dez"];

  function dataPorExtenso(iso) {
    var d = deISO(iso);
    return SEMANA[d.getDay()] + ", " + ("0" + d.getDate()).slice(-2) + "/" + ("0" + (d.getMonth() + 1)).slice(-2);
  }

  function escapar(texto) {
    return String(texto == null ? "" : texto).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function taxaAtual() {
    if (nova && estado.regiao && estado.regiao.taxa != null) return Number(estado.regiao.taxa);
    return Number(CONFIG.taxaDomicilio || 0);
  }

  function totalDoAgendamento() {
    if (!estado.servico) return 0;
    var taxa = estado.local === "domicilio" ? taxaAtual() : 0;
    return Number(estado.servico.preco) + taxa;
  }

  /* -------------------------------------------- modo demonstracao (local) */

  function lerLocal() {
    try { return JSON.parse(localStorage.getItem(CHAVE_LOCAL) || "{}"); }
    catch (e) { return {}; }
  }

  function gravarLocal(dia, horas) {
    try {
      var tudo = lerLocal();
      tudo[dia] = (tudo[dia] || []).concat(horas);
      localStorage.setItem(CHAVE_LOCAL, JSON.stringify(tudo));
    } catch (e) { /* navegador sem storage: segue sem gravar */ }
  }

  function ativarModoLocal() {
    if (nova) {
      // Agenda nova: nada de modo demonstracao (reserva so no aparelho).
      estado.offline = navigator.onLine === false;
      if (estado.offline) {
        el("aviso-local").textContent = "Voce esta sem internet. Da pra ver os servicos e os precos, " +
          "mas pra reservar um horario de verdade precisa de conexao.";
        el("aviso-local").hidden = false;
      }
      atualizarBotaoConfirmar();
      return;
    }
    if (estado.modoLocal) return;
    estado.modoLocal = true;

    // Sem internet e servidor fora do ar sao coisas diferentes. Offline,
    // deixar a pessoa "reservar" no localStorage seria mentir pra ela: o
    // horario nao ficou guardado com ninguem.
    estado.offline = navigator.onLine === false;

    var aviso = el("aviso-local");
    if (!aviso) return;

    if (estado.offline) {
      aviso.innerHTML = "<strong>Voce esta sem internet.</strong> Da pra ver os servicos e os " +
        "precos, mas pra reservar um horario de verdade precisa de conexao.";
    }

    aviso.hidden = false;
    atualizarBotaoConfirmar();
  }

  function atualizarBotaoConfirmar() {
    var botao = el("botao-confirmar");
    if (!botao) return;

    botao.disabled = !!estado.offline || !!estado.semAgenda;
    botao.querySelector("span").textContent = estado.offline
      ? "Sem internet - nao da pra reservar"
      : estado.semAgenda ? "Agenda fora do ar - tente mais tarde"
      : "Confirmar e ir para o WhatsApp";
  }

  // Voltou a internet: recarrega a agenda e devolve o botao.
  function ligarAvisoDeConexao() {
    window.addEventListener("online", function () {
      estado.offline = false;
      estado.modoLocal = false;
      el("aviso-local").hidden = true;
      atualizarBotaoConfirmar();
      if (estado.dia) carregarHorarios();
    });

    window.addEventListener("offline", function () {
      estado.modoLocal = false;
      ativarModoLocal();
    });
  }

  /* ----------------------------------------------------------- servidor */

  function buscarOcupados(dia) {
    if (estado.modoLocal) return Promise.resolve(lerLocal()[dia] || []);

    return fetch(API + "?dia=" + encodeURIComponent(dia), { headers: { accept: "application/json" } })
      .then(function (r) {
        if (!r.ok) throw new Error("resposta " + r.status);
        return r.json();
      })
      .then(function (dados) { return dados.ocupados || []; })
      .catch(function () {
        ativarModoLocal();
        return lerLocal()[dia] || [];
      });
  }

  function reservar(dados) {
    if (estado.modoLocal) {
      gravarLocal(dados.dia, blocosDoServico(dados.hora, estado.servico.duracao, deslocamentoAtual()));
      return Promise.resolve({ ok: true });
    }

    return fetch(API, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(dados)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (corpo) {
        if (!r.ok) throw { status: r.status, corpo: corpo };
        return corpo;
      });
    });
  }

  /* ----------------------------------------------------- horarios livres */

  // Mesma conta do servidor: no domicilio o deslocamento sai da agenda dos
  // dois lados, pra ele ter tempo de chegar e de voltar.
  function blocosDoServico(hora, duracao, deslocamento) {
    var passo = Number(CONFIG.intervaloMinutos) || 30;
    var folga = Math.ceil((Number(deslocamento) || 0) / passo) * passo;
    var inicio = Math.max(0, emMinutos(hora) - folga);
    var fim = Math.min(24 * 60, emMinutos(hora) + duracao + folga);

    var lista = [];
    for (var m = inicio; m < fim; m += passo) lista.push(paraHora(m));
    return lista;
  }

  // Quanto tempo de deslocamento a escolha atual reserva.
  function deslocamentoAtual() {
    if (estado.local !== "domicilio" || !estado.regiao) return 0;
    return Number(estado.regiao.deslocamento) || 0;
  }

  // Um horario so aparece se TODOS os blocos que o servico ocupa estiverem
  // livres e se o servico terminar ate o fechamento.
  function horariosLivres(duracao, dia, ocupados, deslocamento) {
    var passo = Number(CONFIG.intervaloMinutos) || 30;
    var abre = emMinutos(CONFIG.abertura);
    var fecha = emMinutos(CONFIG.fechamento);
    var livres = [];

    var agora = new Date();
    var ehHoje = dia === paraISO(agora);
    var minimoHoje = agora.getHours() * 60 + agora.getMinutes() + (Number(CONFIG.antecedenciaMinutos) || 0);

    // A janela inteira (ida + corte + volta) tem que caber no expediente,
    // igual o servidor valida.
    var folgaReal = Number(deslocamento) || 0;
    var primeiro = abre + Math.ceil(folgaReal / passo) * passo;

    for (var inicio = primeiro; inicio + duracao + folgaReal <= fecha; inicio += passo) {
      if (ehHoje && inicio < minimoHoje) continue;

      var hora = paraHora(inicio);
      var blocos = blocosDoServico(hora, duracao, deslocamento);
      var cabe = true;

      for (var i = 0; i < blocos.length; i++) {
        if (ocupados.indexOf(blocos[i]) !== -1) { cabe = false; break; }
      }
      if (cabe) livres.push(hora);
    }
    return livres;
  }

  /* ------------------------------------------------------------ desenho */

  function estadoDoPasso(id, liberado) {
    var passo = el(id);
    if (passo) passo.classList.toggle("bloqueado", !liberado);
  }

  function marcarFeito(id, feito) {
    var passo = el(id);
    if (passo) passo.classList.toggle("feito", !!feito);
  }

  function ativar(id) {
    ["passo-servico", "passo-local", "passo-dia", "passo-hora", "passo-dados"].forEach(function (p) {
      var no = el(p);
      if (no) no.classList.toggle("ativo", p === id);
    });
  }

  function irPara(id) {
    var alvo = el(id);
    if (!alvo) return;
    setTimeout(function () {
      var topo = alvo.getBoundingClientRect().top + window.pageYOffset - 90;
      window.scrollTo({ top: topo, behavior: "smooth" });
    }, 90);
  }

  function selecionarUm(caixa, escolhido) {
    Array.prototype.forEach.call(caixa.children, function (b) {
      if (b.setAttribute) b.setAttribute("aria-pressed", String(b === escolhido));
    });
  }

  function desenharServicos() {
    var caixa = el("lista-servicos");
    caixa.innerHTML = "";

    CONFIG.servicos.forEach(function (servico, i) {
      var botao = document.createElement("button");
      botao.type = "button";
      botao.className = "item";
      botao.setAttribute("aria-pressed", "false");
      botao.innerHTML =
        '<span class="item-marca" aria-hidden="true"></span>' +
        '<span class="item-num">' + ("0" + (i + 1)).slice(-2) + "</span>" +
        '<span class="item-nome">' + escapar(servico.nome) + "</span>" +
        '<span class="item-preco">' + escapar(dinheiro(servico.preco)) + "</span>" +
        '<span class="item-info">' + escapar(servico.duracao + " min" +
          (servico.descricao ? " - " + servico.descricao : "")) + "</span>";

      botao.addEventListener("click", function () {
        estado.servico = servico;
        estado.hora = null;
        selecionarUm(caixa, botao);
        marcarFeito("passo-servico", true);
        estadoDoPasso("passo-local", true);
        estadoDoPasso("passo-dia", true);
        estadoDoPasso("passo-dados", false);
        ativar("passo-local");
        atualizarResumo();
        atualizarBarra();
        if (estado.dia) carregarHorarios();
        irPara("passo-local");
      });

      caixa.appendChild(botao);
    });

    el("dica-servico").textContent = CONFIG.servicos.length + " servicos";
  }

  function desenharLocal() {
    var caixa = el("lista-local");
    caixa.innerHTML = "";

    var opcoes = [{
      id: "barbearia",
      nome: "Na barbearia",
      info: CONFIG.endereco || "Voce vem ate o barbeiro",
      extra: 0
    }];

    if (CONFIG.domicilioAtivo) {
      opcoes.push({
        id: "domicilio",
        nome: "Em casa",
        info: "O barbeiro vai ate voce",
        extra: Number(CONFIG.taxaDomicilio || 0)
      });
    }

    opcoes.forEach(function (opcao) {
      var botao = document.createElement("button");
      botao.type = "button";
      botao.className = "local";
      botao.setAttribute("aria-pressed", String(opcao.id === estado.local));
      botao.innerHTML =
        '<span class="local-topo">' +
          '<span class="local-nome">' + escapar(opcao.nome) + "</span>" +
          (opcao.extra ? '<span class="local-extra">+ ' + escapar(dinheiro(opcao.extra)) + "</span>" : "") +
          '<span class="local-check" aria-hidden="true">' +
            '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17.5 19.5 7"/></svg>' +
          "</span>" +
        "</span>" +
        '<span class="local-info">' + escapar(opcao.info) + "</span>";

      botao.addEventListener("click", function () {
        var casa = opcao.id === "domicilio";
        var temRegioes = casa && (CONFIG.regioes || []).length > 0;

        estado.local = opcao.id;
        if (!casa) estado.regiao = null;
        estado.hora = null;

        selecionarUm(caixa, botao);
        el("campo-endereco-area").hidden = !casa;
        el("campo-regiao-area").hidden = !temRegioes;
        atualizarNotaRegiao();

        // Sem a regiao escolhida nao da pra saber quais horarios cabem,
        // entao o passo do dia so abre depois dela.
        var pronto = !temRegioes || !!estado.regiao;
        marcarFeito("passo-local", pronto);
        estadoDoPasso("passo-dia", pronto);
        estadoDoPasso("passo-dados", false);

        atualizarResumo();
        atualizarBarra();

        if (estado.dia && pronto) carregarHorarios();
        if (pronto) { ativar("passo-dia"); irPara("passo-dia"); }
        else { ativar("passo-local"); el("campo-regiao").focus(); }
      });

      caixa.appendChild(botao);
    });
  }

  // O select de regiao mora no passo 2, nao no 5: trocar de regiao muda
  // quantos minutos saem da agenda e, portanto, quais horarios cabem.
  function desenharRegioes() {
    var caixa = el("campo-regiao");
    var regioes = CONFIG.regioes || [];
    if (!caixa) return;

    caixa.innerHTML = "";

    var vazio = document.createElement("option");
    vazio.value = "";
    vazio.textContent = "Escolha a regiao...";
    caixa.appendChild(vazio);

    regioes.forEach(function (regiao) {
      var opcao = document.createElement("option");
      opcao.value = regiao.id;
      opcao.textContent = regiao.nome;
      caixa.appendChild(opcao);
    });

    caixa.addEventListener("change", function () {
      estado.regiao = regioes.filter(function (r) { return r.id === caixa.value; })[0] || null;
      estado.hora = null;
      atualizarNotaRegiao();
      marcarFeito("passo-local", !!estado.regiao);
      estadoDoPasso("passo-dia", !!estado.regiao);
      estadoDoPasso("passo-dados", false);
      atualizarResumo();
      atualizarBarra();

      if (estado.dia) carregarHorarios();
      if (estado.regiao) irPara("passo-dia");
    });
  }

  function atualizarNotaRegiao() {
    var nota = el("nota-regiao");
    if (!nota) return;

    if (!estado.regiao) {
      nota.textContent = "O tempo de deslocamento muda os horarios disponiveis.";
      return;
    }

    nota.textContent = "Reservamos " + estado.regiao.deslocamento +
      " min de deslocamento antes e depois, pro barbeiro conseguir chegar e voltar.";
  }

  function desenharDias() {
    var caixa = el("lista-dias");
    caixa.innerHTML = "";

    var fechados = CONFIG.diasFechados || [];
    var hoje = new Date();
    hoje.setHours(0, 0, 0, 0);

    for (var i = 0; i < Number(CONFIG.diasParaFrente || 30); i++) {
      var data = new Date(hoje.getTime());
      data.setDate(data.getDate() + i);
      if (fechados.indexOf(data.getDay()) !== -1) continue;

      (function (data, i) {
        var iso = paraISO(data);
        var botao = document.createElement("button");
        botao.type = "button";
        botao.className = "dia";
        botao.dataset.dia = iso;
        botao.setAttribute("aria-pressed", "false");
        botao.innerHTML =
          '<span class="dia-semana">' + (i === 0 ? "Hoje" : SEMANA[data.getDay()]) + "</span>" +
          '<span class="dia-numero">' + data.getDate() + "</span>" +
          '<span class="dia-mes">' + MESES[data.getMonth()] + "</span>";

        botao.addEventListener("click", function () {
          estado.dia = iso;
          estado.hora = null;
          selecionarUm(caixa, botao);
          marcarFeito("passo-dia", true);
          estadoDoPasso("passo-hora", true);
          estadoDoPasso("passo-dados", false);
          ativar("passo-hora");
          atualizarResumo();
          atualizarBarra();
          carregarHorarios();
          irPara("passo-hora");
        });

        caixa.appendChild(botao);
      })(data, i);
    }
  }

  /* ------------------------------------------------ agenda nova (API v1) */

  // Servico com a menor duracao (para os numeros da capa).
  function servicoMaisCurto() {
    return CONFIG.servicos.reduce(function (menor, s) {
      return !menor || Number(s.duracao) < Number(menor.duracao) ? s : menor;
    }, null);
  }

  // Horarios livres de um dia, perguntados a API (ela confere expediente,
  // antecedencia, deslocamento e ocupacao). O id vem do catalogo da API.
  function horariosDaApi(iso, servico, local, regiao) {
    if (!servico || !servico.apiId) {
      return Promise.reject({ mensagem: "A agenda esta fora do ar. Recarregue a pagina em instantes." });
    }
    return agendaNova.profissionalPara([servico.apiId]).then(function (profissional) {
      return agendaNova.horarios({
        data: iso,
        servicoIds: [servico.apiId],
        profissionalId: profissional,
        modalidade: local,
        regiaoId: regiao ? regiao.apiId : null
      });
    });
  }

  function carregarHorariosDaApi() {
    if (estado.local === "domicilio" && !estado.regiao) return;

    var caixa = el("lista-horas");
    var esqueleto = '<div class="horas">';
    for (var i = 0; i < 8; i++) esqueleto += '<div class="esqueleto"></div>';
    caixa.innerHTML = esqueleto + "</div>";

    var pedido = ++estado.pedidoHoras;
    horariosDaApi(estado.dia, estado.servico, estado.local, estado.regiao).then(function (livres) {
      if (pedido !== estado.pedidoHoras) return;
      estado.livresApi = livres;
      desenharHorarios();
    }, function (falha) {
      if (pedido !== estado.pedidoHoras) return;
      caixa.textContent = "";
      var aviso = document.createElement("div");
      aviso.className = "vazio";
      aviso.textContent = (falha && falha.mensagem) || window.AgendaV1.MENSAGENS.erro;
      caixa.appendChild(aviso);
      estadoDoPasso("passo-dados", false);
    });
  }

  function carregarHorarios() {
    if (!estado.servico || !estado.dia) return;
    if (nova) return carregarHorariosDaApi();

    var caixa = el("lista-horas");
    var esqueleto = '<div class="horas">';
    for (var i = 0; i < 8; i++) esqueleto += '<div class="esqueleto"></div>';
    caixa.innerHTML = esqueleto + "</div>";

    buscarOcupados(estado.dia).then(function (ocupados) {
      estado.ocupados = ocupados;
      desenharHorarios();
    });
  }

  // Manha, tarde e noite separados: 24 botoes numa grade unica cansam a vista.
  function desenharHorarios() {
    var caixa = el("lista-horas");
    var livres = nova ? estado.livresApi : horariosLivres(
      Number(estado.servico.duracao), estado.dia, estado.ocupados, deslocamentoAtual()
    );

    el("ajuda-hora").textContent = estado.servico.duracao + " min - ate as " + CONFIG.fechamento;
    caixa.innerHTML = "";

    if (!livres.length) {
      caixa.innerHTML =
        '<div class="vazio"><strong>Esse dia lotou.</strong>' +
        "Nao sobrou horario para " + escapar(estado.servico.nome) + ". Tente o dia seguinte.</div>";
      estadoDoPasso("passo-dados", false);
      return;
    }

    var faixas = [
      { nome: "Manha", ate: 12 * 60 },
      { nome: "Tarde", ate: 18 * 60 },
      { nome: "Noite", ate: 24 * 60 }
    ];

    var indice = 0;

    faixas.forEach(function (faixa, f) {
      var doPeriodo = livres.filter(function (h) {
        var m = emMinutos(h);
        return m < faixa.ate && (f === 0 || m >= faixas[f - 1].ate);
      });

      if (!doPeriodo.length) return;

      var titulo = document.createElement("p");
      titulo.className = "periodo";
      titulo.textContent = faixa.nome + " (" + doPeriodo.length + ")";
      caixa.appendChild(titulo);

      var grade = document.createElement("div");
      grade.className = "horas";

      doPeriodo.forEach(function (hora) {
        var botao = document.createElement("button");
        botao.type = "button";
        botao.className = "hora";
        botao.innerHTML = "<span>" + hora + "</span>";
        botao.setAttribute("aria-pressed", String(hora === estado.hora));
        botao.style.setProperty("--atraso", (indice++ * 14) + "ms");

        botao.addEventListener("click", function () {
          estado.hora = hora;
          Array.prototype.forEach.call(caixa.querySelectorAll(".hora"), function (b) {
            b.setAttribute("aria-pressed", String(b === botao));
          });
          marcarFeito("passo-hora", true);
          estadoDoPasso("passo-dados", true);
          ativar("passo-dados");
          atualizarResumo();
          atualizarBarra();
          irPara("passo-dados");
        });

        grade.appendChild(botao);
      });

      caixa.appendChild(grade);
    });
  }

  function linhaComanda(rotulo, valor) {
    return '<div class="comanda-linha"><span>' + escapar(rotulo) +
           "</span><span>" + escapar(valor) + "</span></div>";
  }

  function atualizarResumo() {
    var caixa = el("resumo");
    if (!estado.servico) { caixa.innerHTML = ""; return; }

    var fim = estado.hora
      ? paraHora(emMinutos(estado.hora) + Number(estado.servico.duracao))
      : null;

    var html = '<p class="comanda-titulo">Sua comanda</p>';
    html += linhaComanda("Servico", estado.servico.nome + " (" + estado.servico.duracao + " min)");
    html += linhaComanda("Local", estado.local === "domicilio"
      ? "Na sua casa" + (estado.regiao ? " - " + estado.regiao.nome : "")
      : "Na barbearia");
    html += linhaComanda("Quando", estado.dia
      ? dataPorExtenso(estado.dia) + (estado.hora ? ", " + estado.hora + " as " + fim : "")
      : "A escolher");
    html += linhaComanda("Valor do servico", dinheiro(estado.servico.preco));

    if (estado.local === "domicilio" && taxaAtual()) {
      html += linhaComanda("Taxa de domicilio", dinheiro(taxaAtual()));
    }

    html += '<div class="comanda-picote"></div>';
    html += '<div class="comanda-total"><span>Total</span><span>' +
            escapar(dinheiro(totalDoAgendamento())) + "</span></div>";

    caixa.innerHTML = html;
  }

  /* --------------------------------------------------------- barra fixa */

  function atualizarBarra() {
    var barra = el("barra-resumo");
    if (!estado.servico) { barra.classList.remove("visivel"); return; }

    var partes = [estado.local === "domicilio" ? "em casa" : "na barbearia"];
    if (estado.dia) partes.push(dataPorExtenso(estado.dia));
    if (estado.hora) partes.push(estado.hora);

    el("barra-nome").textContent = estado.servico.nome + " - " + dinheiro(totalDoAgendamento());
    el("barra-sub").textContent = partes.join(" - ");

    var proximo = !estado.dia ? "passo-dia" : !estado.hora ? "passo-hora" : "passo-dados";
    el("barra-acao").textContent = proximo === "passo-dados" ? "Finalizar" : "Continuar";
    el("barra-botao").onclick = function () { irPara(proximo); };

    barra.classList.add("visivel");
  }

  function esconderBarra() {
    el("barra-resumo").classList.remove("visivel");
  }

  // Quando o cliente ja esta no passo final, a barra so atrapalha - ela
  // ficaria em cima do proprio botao de confirmar.
  function ligarSumicoDaBarra() {
    if (!("IntersectionObserver" in window)) return;

    var observador = new IntersectionObserver(function (entradas) {
      entradas.forEach(function (e) {
        estado.noFinal = e.isIntersecting;
        el("barra-resumo").classList.toggle("escondida", e.isIntersecting);
      });
    }, { threshold: .18 });

    observador.observe(el("passo-dados"));
  }

  /* ---------------------------------------------------------- WhatsApp */

  function montarMensagem(dados) {
    var duracao = dados.duracao || (estado.servico && estado.servico.duracao) || 0;

    var linhas = [
      "Ola, " + CONFIG.barbeiro + "! Acabei de agendar pelo site:",
      "",
      "Servico: " + dados.servico + " (" + duracao + " min)",
      "Dia: " + dataPorExtenso(dados.dia) + " as " + dados.hora,
      "Local: " + (dados.local === "domicilio"
        ? "na minha casa - " + dados.endereco +
          (dados.regiaoNome ? " (" + dados.regiaoNome + ")" : "")
        : "na barbearia"),
      "Nome: " + dados.nome,
      "Telefone: " + dados.telefone
    ];

    if (dados.observacao) linhas.push("Obs: " + dados.observacao);

    linhas.push("");
    linhas.push("Total: " + dinheiro(dados.total) +
      (dados.local === "domicilio" ? " (ja com a taxa de domicilio)" : ""));
    linhas.push("Pode confirmar?");

    return "https://wa.me/" + String(CONFIG.whatsapp).replace(/\D/g, "") +
      "?text=" + encodeURIComponent(linhas.join("\n"));
  }

  /* ------------------------------------------------------------- envio */

  function mascaraTelefone(valor) {
    var n = valor.replace(/\D/g, "").slice(0, 11);
    if (n.length <= 2) return n;
    if (n.length <= 6) return "(" + n.slice(0, 2) + ") " + n.slice(2);
    if (n.length <= 10) return "(" + n.slice(0, 2) + ") " + n.slice(2, 6) + "-" + n.slice(6);
    return "(" + n.slice(0, 2) + ") " + n.slice(2, 7) + "-" + n.slice(7);
  }

  function mostrarErro(mensagem) {
    var caixa = el("erro-form");
    caixa.textContent = mensagem;
    caixa.hidden = !mensagem;
  }

  function enviar(evento) {
    evento.preventDefault();
    if (estado.enviando) return;
    if (estado.offline) return mostrarErro("Sem internet nao da pra garantir o horario. Tente de novo quando voltar.");

    var nome = el("campo-nome").value.trim();
    var telefone = el("campo-telefone").value.trim();
    var endereco = el("campo-endereco").value.trim();
    var observacao = el("campo-obs").value.trim();

    if (!estado.servico || !estado.dia || !estado.hora) return mostrarErro("Escolha o servico, o dia e o horario.");
    if (nome.length < 2) return mostrarErro("Escreva seu nome.");
    if (telefone.replace(/\D/g, "").length < 10) return mostrarErro("Escreva seu telefone com DDD.");
    if (estado.local === "domicilio" && endereco.length < 5) return mostrarErro("Escreva o endereco do atendimento.");
    if (estado.local === "domicilio" && (CONFIG.regioes || []).length && !estado.regiao)
      return mostrarErro("Escolha a regiao do atendimento la no passo 2.");

    mostrarErro("");

    if (nova) return enviarParaAgendaNova({ nome: nome, telefone: telefone, endereco: endereco, observacao: observacao });

    var dados = {
      dia: estado.dia,
      hora: estado.hora,
      servicoId: estado.servico.id,
      servico: estado.servico.nome,
      duracao: Number(estado.servico.duracao),
      preco: Number(estado.servico.preco),
      total: totalDoAgendamento(),
      local: estado.local,
      endereco: endereco,
      regiao: estado.regiao ? estado.regiao.id : "",
      nome: nome,
      telefone: telefone,
      observacao: observacao
    };

    estado.enviando = true;
    var botao = el("botao-confirmar");
    botao.disabled = true;
    botao.querySelector("span").textContent = "Reservando seu horario...";

    reservar(dados)
      .then(function (resposta) {
        // Preco e duracao valem os do servidor, nao os desta tela.
        mostrarSucesso((resposta && resposta.reserva) || dados);
      })
      .catch(function (falha) {
        var corpo = (falha && falha.corpo) || {};

        if (falha && falha.status === 409) {
          mostrarErro("Poxa, esse horario acabou de ser preenchido por outro cliente. Escolha outro.");
          estado.hora = null;
          estadoDoPasso("passo-dados", false);
          ativar("passo-hora");
          carregarHorarios();
          atualizarBarra();
          irPara("passo-hora");
        } else {
          mostrarErro(corpo.erro || "Nao consegui reservar agora. Tente de novo em instantes.");
        }
      })
      .then(function () {
        estado.enviando = false;
        atualizarBotaoConfirmar();
      });
  }

  // Uma tentativa de envio = uma Idempotency-Key. Sem resposta (rede), a
  // tentativa fica em aberto e reenviar o MESMO pedido usa a mesma chave;
  // qualquer resposta da API encerra a tentativa.
  function enviarParaAgendaNova(form) {
    estado.enviando = true;
    var botao = el("botao-confirmar");
    botao.disabled = true;
    botao.querySelector("span").textContent = "Reservando seu horario...";

    var servico = estado.servico;
    agendaNova.profissionalPara([servico.apiId])
      .then(function (profissional) {
        var corpo = {
          servicos: [servico.apiId],
          profissional_id: profissional,
          data: estado.dia,
          hora: estado.hora,
          modalidade: estado.local,
          cliente: { nome: form.nome, telefone: form.telefone },
          observacao: form.observacao || null
        };
        if (estado.local === "domicilio") {
          corpo.regiao_id = estado.regiao ? estado.regiao.apiId : null;
          corpo.endereco = { logradouro: form.endereco };
        }
        estado.tentativa = window.AgendaV1.tentativaPara(estado.tentativa, corpo);
        return agendaNova.reservar(corpo, estado.tentativa);
      })
      .then(function (resultado) {
        estado.tentativa = null;
        mostrarSucessoDaAgendaNova(resultado.reserva, form);
      }, function (falha) {
        falha = falha || {};
        if (falha.tipo !== "rede") estado.tentativa = null;

        if (falha.tipo === "conflito") {
          mostrarErro(falha.mensagem);
          estado.hora = null;
          estadoDoPasso("passo-dados", false);
          ativar("passo-hora");
          carregarHorarios();
          atualizarBarra();
          irPara("passo-hora");
        } else {
          mostrarErro(falha.mensagem || window.AgendaV1.MENSAGENS.erro);
        }
      })
      .then(function () {
        estado.enviando = false;
        atualizarBotaoConfirmar();
      });
  }

  // Linha "rotulo / valor" do recibo, so com textContent.
  function linhaDeRecibo(rotulo, valor) {
    var linha = document.createElement("div");
    linha.className = "recibo-linha";
    var a = document.createElement("span");
    a.textContent = rotulo;
    var b = document.createElement("span");
    b.textContent = valor;
    linha.appendChild(a);
    linha.appendChild(b);
    return linha;
  }

  var ESTADOS_DA_RESERVA = {
    solicitado: "Aguardando confirmacao do barbeiro",
    confirmado: "Confirmada",
    em_atendimento: "Em atendimento",
    concluido: "Concluida",
    cancelado: "Cancelada",
    nao_compareceu: "Nao compareceu"
  };

  function linhasDaReserva(caixa, reserva) {
    var nomes = (reserva.servicos || []).map(function (s) { return s.nome; }).join(" + ");
    var duracao = (reserva.servicos || []).reduce(function (t, s) { return t + Number(s.duracao_minutos); }, 0);
    var fim = paraHora(emMinutos(reserva.hora) + duracao);

    caixa.appendChild(linhaDeRecibo("Servico", nomes));
    caixa.appendChild(linhaDeRecibo("Quando", dataPorExtenso(reserva.data) + ", " + reserva.hora + " as " + fim));
    caixa.appendChild(linhaDeRecibo("Local", reserva.modalidade === "domicilio"
      ? "Na sua casa" + (reserva.regiao_nome ? " - " + reserva.regiao_nome : "")
      : "Na barbearia"));
    caixa.appendChild(linhaDeRecibo("Total", dinheiro(Number(reserva.total_centavos) / 100)));
    caixa.appendChild(linhaDeRecibo("Situacao", ESTADOS_DA_RESERVA[reserva.estado] || reserva.estado));
    caixa.appendChild(linhaDeRecibo("Codigo da reserva", reserva.codigo));
    return { nomes: nomes, duracao: duracao };
  }

  // Sucesso na agenda nova: mostra o codigo (credencial para consultar,
  // cancelar e remarcar) e NAO pula sozinho para o WhatsApp, senao o
  // cliente nao ve o codigo.
  function mostrarSucessoDaAgendaNova(reserva, form) {
    var caixa = el("sucesso-detalhe");
    caixa.textContent = "";
    var resumo = linhasDaReserva(caixa, reserva);

    var tela = el("tela-sucesso");
    tela.querySelector("h2").textContent = "Pedido de horario enviado";
    tela.querySelector(".recibo-sub").textContent = "O horario fica guardado no seu nome enquanto o barbeiro confirma.";
    tela.querySelector(".recibo-nota").textContent = "Guarde o codigo da reserva: com ele e o mesmo telefone da pra " +
      "consultar, cancelar ou remarcar em \"Minha reserva\", mais abaixo na pagina.";

    el("link-zap").href = montarMensagem({
      servico: resumo.nomes,
      duracao: resumo.duracao,
      dia: reserva.data,
      hora: reserva.hora,
      local: reserva.modalidade,
      endereco: form.endereco,
      regiaoNome: reserva.regiao_nome,
      nome: form.nome,
      telefone: form.telefone,
      observacao: form.observacao,
      total: Number(reserva.total_centavos) / 100
    });
    tela.hidden = false;
    esconderBarra();

    if (el("minha-codigo")) el("minha-codigo").value = reserva.codigo;
    if (el("minha-telefone")) el("minha-telefone").value = form.telefone;
  }

  // Consultar, cancelar e remarcar por codigo + telefone (so agenda nova).
  function ligarMinhaReserva() {
    var secao = el("minha-reserva");
    if (!secao) return;
    secao.hidden = false;

    el("minha-telefone").addEventListener("input", function (e) {
      e.target.value = mascaraTelefone(e.target.value);
    });

    function avisar(erro, aviso) {
      el("minha-erro").textContent = erro || "";
      el("minha-erro").hidden = !erro;
      el("minha-aviso").textContent = aviso || "";
    }

    function acao(id, executar) {
      var botao = el(id);
      botao.addEventListener("click", function () {
        var codigo = el("minha-codigo").value.trim();
        var telefone = el("minha-telefone").value.trim();
        if (!codigo || telefone.replace(/\D/g, "").length < 10) {
          return avisar("Informe o codigo da reserva e o telefone com DDD.");
        }
        avisar("");
        botao.disabled = true;
        Promise.resolve()
          .then(function () { return executar(codigo, telefone); })
          .then(function (resultado) {
            if (!resultado) return;
            var caixa = el("minha-detalhe");
            caixa.textContent = "";
            linhasDaReserva(caixa, resultado.reserva);
            avisar("", resultado.aviso);
          }, function (falha) {
            avisar((falha && falha.mensagem) || window.AgendaV1.MENSAGENS.erro);
          })
          .then(function () { botao.disabled = false; });
      });
    }

    acao("btn-minha-consultar", function (codigo, telefone) {
      return agendaNova.consultar(codigo, telefone).then(function (r) { return { reserva: r }; });
    });

    acao("btn-minha-remarcar", function (codigo, telefone) {
      var dia = el("minha-data").value;
      var hora = el("minha-hora").value;
      if (!dia || !hora) throw { mensagem: "Escolha o novo dia e o novo horario." };
      return agendaNova.remarcar(codigo, telefone, dia, hora).then(function (r) {
        return { reserva: r, aviso: "Reserva remarcada." };
      });
    });

    acao("btn-minha-cancelar", function (codigo, telefone) {
      if (!window.confirm("Cancelar esta reserva?")) return null;
      return agendaNova.cancelar(codigo, telefone).then(function (r) {
        return { reserva: r, aviso: "Reserva cancelada." };
      });
    });
  }

  function mostrarSucesso(dados) {
    var link = montarMensagem(dados);
    var duracao = dados.duracao || estado.servico.duracao;
    var fim = paraHora(emMinutos(dados.hora) + Number(duracao));

    var html = "";
    html += '<div class="recibo-linha"><span>Servico</span><span>' + escapar(dados.servico) + "</span></div>";
    html += '<div class="recibo-linha"><span>Quando</span><span>' +
            escapar(dataPorExtenso(dados.dia) + ", " + dados.hora + " as " + fim) + "</span></div>";
    html += '<div class="recibo-linha"><span>Local</span><span>' +
            escapar(dados.local === "domicilio" ? "Na sua casa" : "Na barbearia") + "</span></div>";
    html += '<div class="recibo-linha"><span>Total</span><span>' + escapar(dinheiro(dados.total)) + "</span></div>";

    el("sucesso-detalhe").innerHTML = html;
    el("link-zap").href = link;
    el("tela-sucesso").hidden = false;
    esconderBarra();

    // Leva o cliente direto pro WhatsApp; o botao fica de reserva
    // caso o navegador bloqueie o redirecionamento.
    setTimeout(function () { window.location.href = link; }, 800);
  }

  /* --------------------------------------------------- memoria do cliente */

  var API_CLIENTE = "/api/cliente";
  var CHAVE_CLIENTE = "barbearia-chave";

  function lerChave() {
    try { return localStorage.getItem(CHAVE_CLIENTE) || ""; }
    catch (e) { return ""; }
  }

  function gravarChave(chave) {
    try { localStorage.setItem(CHAVE_CLIENTE, chave); } catch (e) { /* ok */ }
  }

  function apagarChave() {
    try { localStorage.removeItem(CHAVE_CLIENTE); } catch (e) { /* ok */ }
  }

  function diasDesde(iso) {
    var alvo = deISO(iso);
    var hoje = new Date();
    hoje.setHours(0, 0, 0, 0);
    return Math.round((hoje - alvo) / 86400000);
  }

  // Mostra o cartao "bom te ver de novo" e pre-enche o que da.
  function aplicarCliente(cliente) {
    if (!cliente) return;
    estado.cliente = cliente;

    if (cliente.nome && !el("campo-nome").value) el("campo-nome").value = cliente.nome;
    if (cliente.telefone && !el("campo-telefone").value) {
      el("campo-telefone").value = mascaraTelefone(cliente.telefone);
    }
    if (cliente.ultimoEndereco && !el("campo-endereco").value) {
      el("campo-endereco").value = cliente.ultimoEndereco;
    }

    var ultimo = (cliente.atendimentos || [])[0];
    if (!ultimo) return;

    var dias = diasDesde(ultimo.dia);
    var quando = dias <= 0 ? "hoje"
      : dias === 1 ? "ontem"
      : dias < 30 ? "faz " + dias + " dias"
      : "faz " + Math.round(dias / 30) + " meses";

    el("memoria-texto").innerHTML =
      escapar(cliente.nome ? cliente.nome.split(" ")[0] : "Voce") +
      ", seu ultimo corte foi <b>" + escapar(ultimo.servico) + "</b>, " + escapar(quando) + ".";

    var servico = CONFIG.servicos.filter(function (s) { return s.id === ultimo.servicoId; })[0];
    el("btn-repetir-corte").hidden = !servico;
    el("cartao-memoria").hidden = false;
    el("recuperar-caixa").hidden = true;

    el("btn-repetir-corte").onclick = function () {
      if (!servico) return;
      var itens = el("lista-servicos").children;
      var indice = CONFIG.servicos.indexOf(servico);
      if (itens[indice]) itens[indice].click();
    };
  }

  function buscarClientePelaChave() {
    var chave = lerChave();
    if (!chave) return Promise.resolve(null);

    return fetch(API_CLIENTE + "?chave=" + encodeURIComponent(chave))
      .then(function (r) {
        if (r.status === 401) { apagarChave(); return null; }
        if (!r.ok) return null;
        return r.json();
      })
      .then(function (dados) {
        if (dados && dados.cliente) aplicarCliente(dados.cliente);
        return dados;
      })
      .catch(function () { return null; });
  }

  function mostrarErroCodigo(mensagem) {
    var caixa = el("erro-codigo");
    caixa.textContent = mensagem;
    caixa.hidden = !mensagem;
  }

  function lerCodigoDigitado() {
    return Array.prototype.map.call(
      el("codigo-campos").children,
      function (i) { return i.value.replace(/\D/g, ""); }
    ).join("");
  }

  function ligarCamposDeCodigo() {
    var campos = el("codigo-campos").children;

    Array.prototype.forEach.call(campos, function (campo, i) {
      campo.addEventListener("input", function () {
        campo.value = campo.value.replace(/\D/g, "").slice(0, 1);
        if (campo.value && campos[i + 1]) campos[i + 1].focus();
      });

      campo.addEventListener("keydown", function (e) {
        if (e.key === "Backspace" && !campo.value && campos[i - 1]) campos[i - 1].focus();
      });

      // Colar o codigo inteiro num campo so espalha nos seis.
      campo.addEventListener("paste", function (e) {
        var texto = (e.clipboardData || window.clipboardData).getData("text").replace(/\D/g, "");
        if (!texto) return;
        e.preventDefault();
        Array.prototype.forEach.call(campos, function (c, j) { c.value = texto[j] || ""; });
        (campos[Math.min(texto.length, 5)] || campos[5]).focus();
      });
    });
  }

  function pedirCodigo() {
    var telefone = el("campo-telefone").value.replace(/\D/g, "");
    if (telefone.length < 10) return mostrarErroCodigo("Escreva seu telefone com DDD primeiro.");

    mostrarErroCodigo("");
    var botao = el("btn-pedir-codigo");
    botao.disabled = true;
    botao.querySelector("span").textContent = "Enviando...";

    fetch(API_CLIENTE, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ acao: "pedir-codigo", telefone: telefone })
    })
      .then(function (r) { return r.json().then(function (c) { return { ok: r.ok, corpo: c }; }); })
      .then(function (res) {
        if (!res.ok) return mostrarErroCodigo(res.corpo.erro || "Nao consegui enviar agora.");
        el("codigo-passo").hidden = false;
        el("codigo-aviso").textContent = res.corpo.mensagem || "Codigo enviado.";
        el("codigo-campos").children[0].focus();
      })
      .catch(function () { mostrarErroCodigo("Nao consegui falar com o servidor."); })
      .then(function () {
        botao.disabled = false;
        botao.querySelector("span").textContent = "Enviar de novo";
      });
  }

  function confirmarCodigo() {
    var codigo = lerCodigoDigitado();
    if (codigo.length !== 6) return mostrarErroCodigo("Digite os seis numeros.");

    mostrarErroCodigo("");
    var botao = el("btn-confirmar-codigo");
    botao.disabled = true;

    fetch(API_CLIENTE, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        acao: "confirmar",
        telefone: el("campo-telefone").value.replace(/\D/g, ""),
        codigo: codigo
      })
    })
      .then(function (r) { return r.json().then(function (c) { return { ok: r.ok, corpo: c }; }); })
      .then(function (res) {
        if (!res.ok) return mostrarErroCodigo(res.corpo.erro || "Codigo errado.");
        gravarChave(res.corpo.chave);
        el("codigo-passo").hidden = true;
        aplicarCliente(res.corpo.cliente);
      })
      .catch(function () { mostrarErroCodigo("Nao consegui falar com o servidor."); })
      .then(function () { botao.disabled = false; });
  }

  function ligarMemoria(ligada) {
    if (!ligada) return;

    ligarCamposDeCodigo();
    el("btn-pedir-codigo").addEventListener("click", pedirCodigo);
    el("btn-confirmar-codigo").addEventListener("click", confirmarCodigo);

    el("btn-esquecer").addEventListener("click", function () {
      apagarChave();
      estado.cliente = null;
      el("cartao-memoria").hidden = true;
      el("campo-nome").value = "";
      el("campo-telefone").value = "";
      el("campo-endereco").value = "";
      el("recuperar-caixa").hidden = false;
    });

    buscarClientePelaChave().then(function (dados) {
      // Sem chave no aparelho, oferece a recuperacao por codigo.
      if (!dados || !dados.cliente) el("recuperar-caixa").hidden = false;
    });
  }

  /* ------------------------------------------------------ enfeites */

  // Revela os blocos conforme eles entram na tela.
  function ligarRevelacoes() {
    var alvos = document.querySelectorAll(".revela");

    if (!("IntersectionObserver" in window)) {
      Array.prototype.forEach.call(alvos, function (a) { a.classList.add("visivel"); });
      return;
    }

    var observador = new IntersectionObserver(function (entradas) {
      entradas.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add("visivel");
          observador.unobserve(e.target);
        }
      });
    }, { threshold: .12, rootMargin: "0px 0px -8% 0px" });

    Array.prototype.forEach.call(alvos, function (a) { observador.observe(a); });
  }

  // Luz da capa acompanhando o dedo ou o mouse.
  function ligarLuz() {
    var capa = document.querySelector(".capa");
    var luz = el("capa-luz");
    if (!capa || !luz || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

    capa.addEventListener("pointermove", function (e) {
      var caixa = capa.getBoundingClientRect();
      luz.style.setProperty("--x", ((e.clientX - caixa.left) / caixa.width * 100) + "%");
      luz.style.setProperty("--y", ((e.clientY - caixa.top) / caixa.height * 100) + "%");
    });
  }

  // "Aberto agora" / "Fechado" calculado na hora, e quantos horarios
  // ainda sobram hoje - o numero vem da agenda de verdade.
  function atualizarStatus() {
    var agora = new Date();
    var minutos = agora.getHours() * 60 + agora.getMinutes();
    var abre = emMinutos(CONFIG.abertura);
    var fecha = emMinutos(CONFIG.fechamento);
    var fechados = CONFIG.diasFechados || [];
    var aberto = minutos >= abre && minutos < fecha && fechados.indexOf(agora.getDay()) === -1;

    el("status-ponto").classList.toggle("fechado", !aberto);
    el("status-aberto").innerHTML = aberto
      ? "<b>Aberto agora</b> &middot; fecha as " + CONFIG.fechamento
      : "<b>Fechado</b> &middot; abre as " + CONFIG.abertura;
  }

  // Quantos horarios ainda cabem hoje, de verdade, vindo da agenda.
  // Se hoje ja acabou, mostra o de amanha - "0 livres hoje" as 22h nao
  // informa nada, so desanima quem chegou no site.
  function contarLivresHoje() {
    if (nova) return contarLivresHojeNaApi();
    var menor = CONFIG.servicos.reduce(function (m, s) {
      return Math.min(m, Number(s.duracao));
    }, Infinity);

    var hoje = paraISO(new Date());
    var amanha = new Date();
    amanha.setDate(amanha.getDate() + 1);

    buscarOcupados(hoje).then(function (ocupados) {
      var livres = horariosLivres(menor, hoje, ocupados).length;
      if (livres) {
        el("dado-livres").textContent = livres;
        return;
      }

      var iso = paraISO(amanha);
      buscarOcupados(iso).then(function (ocupadosAmanha) {
        el("dado-livres").textContent = horariosLivres(menor, iso, ocupadosAmanha).length;
        el("dado-livres-rotulo").textContent = "Livres amanha";
      });
    });
  }

  function contarLivresHojeNaApi() {
    var servico = servicoMaisCurto();
    var nenhum = function () { return []; };
    var amanha = new Date();
    amanha.setDate(amanha.getDate() + 1);

    horariosDaApi(paraISO(new Date()), servico, "barbearia", null).then(null, nenhum).then(function (livres) {
      if (livres.length) {
        el("dado-livres").textContent = livres.length;
        return;
      }
      return horariosDaApi(paraISO(amanha), servico, "barbearia", null).then(null, nenhum).then(function (deAmanha) {
        el("dado-livres").textContent = deAmanha.length;
        el("dado-livres-rotulo").textContent = "Livres amanha";
      });
    });
  }

  // Cartao da capa: os proximos horarios livres de verdade, varrendo os
  // dias para frente ate achar alguns. Clicar ja escolhe aquele dia.
  function mostrarProximos() {
    var menor = CONFIG.servicos.reduce(function (m, s) {
      return Math.min(m, Number(s.duracao));
    }, Infinity);

    var achados = [];
    var fechados = CONFIG.diasFechados || [];

    function varrer(passo) {
      if (achados.length >= 4 || passo > 6) return Promise.resolve();

      var data = new Date();
      data.setDate(data.getDate() + passo);
      if (fechados.indexOf(data.getDay()) !== -1) return varrer(passo + 1);

      var iso = paraISO(data);
      var livresDoDia = nova
        ? horariosDaApi(iso, servicoMaisCurto(), "barbearia", null).then(null, function () { return []; })
        : buscarOcupados(iso).then(function (ocupados) { return horariosLivres(menor, iso, ocupados); });
      return livresDoDia.then(function (livres) {
        livres.slice(0, 4 - achados.length)
          .forEach(function (hora) {
            achados.push({ dia: iso, hora: hora, hoje: passo === 0 });
          });
        return varrer(passo + 1);
      });
    }

    varrer(0).then(function () {
      if (!achados.length) return;

      var lista = el("proximos-lista");
      lista.innerHTML = "";

      // Agrupado por dia: repetir "Qua, 16/09" quatro vezes seguidas e
      // ruido, o cliente so quer bater o olho nas horas.
      var dias = [];
      achados.forEach(function (vaga) {
        var grupo = dias[dias.length - 1];
        if (!grupo || grupo.dia !== vaga.dia) {
          grupo = { dia: vaga.dia, hoje: vaga.hoje, horas: [] };
          dias.push(grupo);
        }
        grupo.horas.push(vaga.hora);
      });

      dias.forEach(function (grupo) {
        var bloco = document.createElement("div");
        bloco.className = "proximo-grupo";

        var titulo = document.createElement("p");
        titulo.className = "proximo-dia";
        titulo.textContent = grupo.hoje ? "Ainda hoje" : dataPorExtenso(grupo.dia);
        bloco.appendChild(titulo);

        var fichas = document.createElement("div");
        fichas.className = "proximo-fichas";

        grupo.horas.forEach(function (hora) {
          var botao = document.createElement("button");
          botao.type = "button";
          botao.className = "proximo";
          botao.innerHTML = '<span class="proximo-hora">' + hora + "</span>";

          botao.addEventListener("click", function () {
            escolherDia(grupo.dia);
            irPara(estado.servico ? "passo-hora" : "passo-servico");
          });

          fichas.appendChild(botao);
        });

        bloco.appendChild(fichas);
        lista.appendChild(bloco);
      });

      el("cartao-proximos").hidden = false;
    });
  }

  // Marca um dia como se o cliente tivesse tocado nele la no passo 3.
  function escolherDia(iso) {
    var alvo = el("lista-dias").querySelector('[data-dia="' + iso + '"]');
    if (alvo) alvo.click();
  }

  /* -------------------------------------------------------------- pagina */

  function preencherTextos() {
    var nome = CONFIG.barbearia || "Barbearia";
    document.title = "Agende seu corte - " + nome;

    // Ultima palavra em italico dourado: o nome ganha um ar de marca.
    // Ligacoes curtas ("do", "da", "dos") vao junto com ela, senao a capa
    // quebra feio em "Barbearia do" / "Ze".
    var partes = nome.trim().split(/\s+/);
    var ultima = partes.length > 1 ? partes.pop() : "";
    var ligacoes = ["do", "da", "de", "dos", "das", "e", "o", "a"];

    while (ultima && partes.length > 1 &&
           ligacoes.indexOf(partes[partes.length - 1].toLowerCase()) !== -1) {
      ultima = partes.pop() + " " + ultima;
    }
    // O espaco antes do <em> importa: sem ele o texto puro da pagina
    // (leitor de tela, copiar e colar, busca) vira "Barbeariado Ze".
    el("capa-titulo").innerHTML = escapar(partes.join(" ")) +
      (ultima ? " <em>" + escapar(ultima) + "</em>" : "");

    el("capa-slogan").textContent = CONFIG.slogan || "";
    el("rodape-nome").textContent = nome;
    el("rodape-slogan").textContent = CONFIG.slogan || "";
    el("rodape-endereco").textContent = CONFIG.endereco || "";

    var zap = "https://wa.me/" + String(CONFIG.whatsapp).replace(/\D/g, "");
    el("rodape-zap").href = zap;
    el("capa-zap").href = zap;

    el("dado-horario").innerHTML =
      CONFIG.abertura.replace(":00", "h") + " &ndash; " + CONFIG.fechamento.replace(":00", "h");

    el("rodape-horario").textContent =
      ((CONFIG.diasFechados || []).length ? "Consulte os dias" : "Todos os dias") +
      ", das " + CONFIG.abertura + " as " + CONFIG.fechamento;

    if (CONFIG.instagram) {
      var insta = el("rodape-insta");
      insta.hidden = false;
      insta.href = "https://instagram.com/" + CONFIG.instagram;
      insta.textContent = "@" + CONFIG.instagram;
    }

    if (CONFIG.domicilioAtivo) {
      el("valor-domicilio").textContent = "+ " + dinheiro(CONFIG.taxaDomicilio);
      el("destaque-domicilio").textContent =
        "Atendimento a domicilio por " + dinheiro(CONFIG.taxaDomicilio) + " a mais. Ele leva tudo.";
    } else {
      el("dado-domicilio").textContent = "Nao";
      el("domicilio-secao").hidden = true;
      el("destaque-domicilio").textContent = "Atendimento na barbearia.";
    }
  }

  // Catalogo da API: o slug do config.js vira o id da API; preco e duracao
  // passam a ser os do servidor; servico ou regiao sem par na API somem.
  function prepararAgendaNova() {
    return agendaNova.catalogo().then(function (cat) {
      CONFIG.servicos = CONFIG.servicos.filter(function (s) { return cat.servicos[s.id]; }).map(function (s) {
        var daApi = cat.servicos[s.id];
        s.apiId = daApi.id;
        s.preco = Number(daApi.preco_centavos) / 100;
        s.duracao = Number(daApi.duracao_minutos);
        return s;
      });
      CONFIG.regioes = (CONFIG.regioes || []).filter(function (r) { return cat.regioes[r.id]; }).map(function (r) {
        var daApi = cat.regioes[r.id];
        r.apiId = daApi.id;
        r.taxa = Number(daApi.taxa_centavos) / 100;
        return r;
      });
      if (!CONFIG.regioes.length) CONFIG.domicilioAtivo = false;
    });
  }

  function iniciarAgendaNova() {
    prepararAgendaNova().then(function () {
      preencherTextos();
      montarTela();
      ligarMinhaReserva();
      contarLivresHoje();
      mostrarProximos();
    }, function () {
      estado.semAgenda = true;
      preencherTextos();
      montarTela();
      ligarMinhaReserva();
      el("aviso-local").textContent = "A agenda nao respondeu agora. Tente de novo em instantes.";
      el("aviso-local").hidden = false;
      atualizarBotaoConfirmar();
    });
  }

  function montarTela() {
    desenharServicos();
    desenharLocal();
    desenharRegioes();
    desenharDias();
    atualizarStatus();
    ligarRevelacoes();
    ligarLuz();
    ligarSumicoDaBarra();
    ligarAvisoDeConexao();

    setInterval(atualizarStatus, 60000);

    el("campo-telefone").addEventListener("input", function (e) {
      e.target.value = mascaraTelefone(e.target.value);
    });

    el("form-dados").addEventListener("submit", enviar);
    el("botao-novo").addEventListener("click", function () { window.location.reload(); });
  }

  function iniciar() {
    if (nova) return iniciarAgendaNova();

    preencherTextos();
    montarTela();

    // Bate na agenda uma vez pra saber se o servidor esta de pe e
    // ja aproveita pra contar quantos horarios sobraram hoje.
    fetch(API + "?dia=" + paraISO(new Date()))
      .then(function (r) {
        if (!r.ok) throw new Error();
        return r.json();
      })
      .then(function (dados) { ligarMemoria(dados && dados.memoria); })
      .catch(ativarModoLocal)
      .then(function () {
        contarLivresHoje();
        mostrarProximos();
      });
  }

  document.addEventListener("DOMContentLoaded", iniciar);
})();
