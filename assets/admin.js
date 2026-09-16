/* =========================================================================
   Painel do barbeiro: os agendamentos do dia, com o resumo do caixa e o
   botao de liberar horario quando alguem desmarca.

   O PIN e conferido no servidor (variavel PIN_PAINEL na Netlify; enquanto
   voce nao trocar, o padrao e 1234).
   ========================================================================= */

(function () {
  "use strict";

  var API = "/api/agenda";
  var pin = "";
  var diaAtual = "";

  var el = function (id) { return document.getElementById(id); };

  function paraISO(data) {
    return data.getFullYear() + "-" +
      ("0" + (data.getMonth() + 1)).slice(-2) + "-" +
      ("0" + data.getDate()).slice(-2);
  }

  function deISO(iso) {
    var p = iso.split("-");
    return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
  }

  function dinheiro(valor) {
    var n = Number(valor);
    return "R$ " + (n % 1 === 0 ? n : n.toFixed(2).replace(".", ","));
  }

  function emMinutos(hora) {
    var p = hora.split(":");
    return Number(p[0]) * 60 + Number(p[1]);
  }

  function paraHora(min) {
    return ("0" + Math.floor(min / 60)).slice(-2) + ":" + ("0" + (min % 60)).slice(-2);
  }

  // O telefone pode chegar sem mascara (uma reserva feita fora do site,
  // por exemplo). Aqui ele sai sempre legivel.
  function telefoneBonito(bruto) {
    var n = String(bruto || "").replace(/\D/g, "");
    if (n.length === 11) return "(" + n.slice(0, 2) + ") " + n.slice(2, 7) + "-" + n.slice(7);
    if (n.length === 10) return "(" + n.slice(0, 2) + ") " + n.slice(2, 6) + "-" + n.slice(6);
    return String(bruto || "");
  }

  // O telefone do cliente e guardado so com DDD (10 ou 11 digitos, sem
  // codigo do pais - o formulario nem pergunta isso). O link wa.me precisa
  // do numero completo, senao abre o zap com o contato errado.
  function linkZap(telefoneBruto) {
    var n = String(telefoneBruto || "").replace(/\D/g, "");
    if (n.length <= 11) n = "55" + n;
    return "https://wa.me/" + n;
  }

  function escapar(texto) {
    return String(texto == null ? "" : texto).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function buscar(dia) {
    return fetch(API + "?dia=" + encodeURIComponent(dia) + "&pin=" + encodeURIComponent(pin))
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (corpo) {
          if (!r.ok) throw { status: r.status, corpo: corpo };
          return corpo;
        });
      });
  }

  function entrar(evento) {
    evento.preventDefault();
    pin = el("campo-pin").value.trim();
    el("erro-pin").hidden = true;

    var hoje = paraISO(new Date());

    buscar(hoje).then(function (dados) {
      el("form-entrar").hidden = true;
      el("area-agenda").hidden = false;
      diaAtual = hoje;
      el("campo-dia").value = hoje;
      desenhar(dados);
      try { sessionStorage.setItem("pin-painel", pin); } catch (e) { /* ok */ }
    }).catch(function (falha) {
      var caixa = el("erro-pin");
      caixa.textContent = falha && falha.status === 401
        ? "PIN incorreto."
        : "Nao consegui falar com a agenda. O site precisa estar publicado na Netlify.";
      caixa.hidden = false;
    });
  }

  function carregar(dia) {
    diaAtual = dia;
    el("campo-dia").value = dia;
    el("lista-agendamentos").innerHTML = '<div class="esqueleto" style="height:6rem"></div>';

    buscar(dia)
      .then(desenhar)
      .catch(function () {
        el("lista-agendamentos").innerHTML = '<div class="vazio">Nao consegui carregar esse dia.</div>';
      });
  }

  function desenhar(dados) {
    var caixa = el("lista-agendamentos");
    var lista = dados.agendamentos || [];

    el("conta-cortes").textContent = lista.length;
    el("conta-casa").textContent = lista.filter(function (a) { return a.local === "domicilio"; }).length;
    el("conta-total").textContent = dinheiro(lista.reduce(function (s, a) {
      return s + Number(a.total || a.preco || 0);
    }, 0));

    if (!lista.length) {
      caixa.innerHTML = '<div class="vazio"><strong>Dia livre.</strong>Ninguem marcou ainda.</div>';
      return;
    }

    caixa.innerHTML = "";

    lista.forEach(function (a) {
      var casa = a.local === "domicilio";
      var fim = paraHora(emMinutos(a.hora) + Number(a.duracao || 30));
      var zap = linkZap(a.telefone);

      // No domicilio o que ele precisa saber e a que horas SAIR, nao a que
      // horas o corte comeca.
      var desloc = Number(a.deslocamento) || 0;
      var saida = desloc ? paraHora(emMinutos(a.hora) - desloc) : null;
      var volta = desloc ? paraHora(emMinutos(fim) + desloc) : null;

      var item = document.createElement("article");
      item.className = "agendamento";
      item.innerHTML =
        "<div>" +
          '<div class="agendamento-hora">' + escapar(a.hora) + "</div>" +
          '<div class="agendamento-fim">ate ' + escapar(fim) + "</div>" +
        "</div>" +
        '<div class="agendamento-corpo">' +
          '<div class="agendamento-nome">' + escapar(a.nome) +
            '<span class="etiqueta ' + (casa ? "etiqueta-casa" : "") + '">' +
              (casa ? "Em casa" : "Barbearia") +
            "</span>" +
          "</div>" +
          '<p class="agendamento-dado">' + escapar(a.servico) + " - " +
            escapar(a.duracao) + " min - <b>" + escapar(dinheiro(a.total || a.preco || 0)) + "</b></p>" +
          '<p class="agendamento-dado">' + escapar(telefoneBonito(a.telefone)) + "</p>" +
          (casa
            ? '<p class="agendamento-dado">Endereco: ' + escapar(a.endereco) +
              (a.regiaoNome ? " (" + escapar(a.regiaoNome) + ")" : "") + "</p>"
            : "") +
          (saida
            ? '<p class="agendamento-dado agendamento-rota">Sai <b>' + escapar(saida) +
              "</b> - volta <b>" + escapar(volta) + "</b> (" + desloc + " min de cada lado)</p>"
            : "") +
          (a.observacao ? '<p class="agendamento-dado">Obs: ' + escapar(a.observacao) + "</p>" : "") +
          '<div class="acoes">' +
            '<a class="botao botao-zap" target="_blank" rel="noopener" href="' + escapar(zap) + '"><span>Chamar no zap</span></a>' +
            '<button class="botao" type="button"><span>Liberar horario</span></button>' +
          "</div>" +
        "</div>";

      item.querySelector(".acoes button").addEventListener("click", function () {
        if (!window.confirm("Liberar o horario de " + a.hora + " (" + a.nome + ")?")) return;
        liberar(a.grupo);
      });

      caixa.appendChild(item);
    });
  }

  function liberar(grupo) {
    fetch(API + "?dia=" + encodeURIComponent(diaAtual) +
              "&grupo=" + encodeURIComponent(grupo) +
              "&pin=" + encodeURIComponent(pin), { method: "DELETE" })
      .then(function (r) {
        if (!r.ok) throw new Error();
        carregar(diaAtual);
      })
      .catch(function () {
        var caixa = el("erro-agenda");
        caixa.textContent = "Nao consegui liberar esse horario. Tente de novo.";
        caixa.hidden = false;
      });
  }

  function pular(dias) {
    var d = deISO(diaAtual);
    d.setDate(d.getDate() + dias);
    carregar(paraISO(d));
  }

  document.addEventListener("DOMContentLoaded", function () {
    el("painel-titulo").textContent = CONFIG.barbearia || "Minha agenda";

    el("form-entrar").addEventListener("submit", entrar);
    el("campo-dia").addEventListener("change", function (e) {
      if (e.target.value) carregar(e.target.value);
    });
    el("botao-anterior").addEventListener("click", function () { pular(-1); });
    el("botao-proximo").addEventListener("click", function () { pular(1); });

    try {
      var salvo = sessionStorage.getItem("pin-painel");
      if (salvo) el("campo-pin").value = salvo;
    } catch (e) { /* ok */ }
  });
})();
