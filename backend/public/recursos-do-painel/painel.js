/* Painel do barbeiro: JavaScript puro e minimo (sem biblioteca, sem script
   inline: a CSP so aceita este arquivo). Funciona no iPhone (Safari) e no
   Android, sem HTTPS: nada aqui exige contexto seguro.

   1) Atualizacao automatica dos pedidos: consulta o resumo a cada ~25 s, mostra
      "N pedidos novos" no topo, poe o contador no titulo da aba e toca um som
      curto (so depois do primeiro toque do usuario na pagina) UMA vez por
      pedido. A vibracao e um extra (Android; o iPhone nao tem) e nada depende
      dela. Retoma sozinha ao voltar para a aba. O botao "Atualizar agora"
      e um link comum e funciona sem JavaScript.
   2) Botao "Copiar" (data-copiar="#id-do-texto"): usa a area de transferencia
      quando existe (exige HTTPS), senao copia pelo metodo antigo, senao deixa
      o texto selecionado para copiar com o dedo (sem HTTPS tambem funciona). */
(function () {
  'use strict';

  /* ------------------------------------------------ atualizacao dos pedidos */

  var lista = document.getElementById('lista-de-pedidos');
  if (lista && lista.getAttribute('data-resumo')) {
    iniciarAvisoDePedidos(lista);
  }

  function iniciarAvisoDePedidos(lista) {
    var urlDoResumo = lista.getAttribute('data-resumo');
    var intervalo = (parseInt(lista.getAttribute('data-intervalo'), 10) || 25) * 1000;
    var faixa = document.getElementById('faixa-novos');
    var conexao = document.getElementById('estado-da-conexao');
    var tituloBase = document.title.replace(/^\(\d+\)\s*/, '');

    var naPagina = {};   // ids que ja estao na lista desenhada
    var avisados = {};   // ids que ja tocaram o som (nunca toca de novo no mesmo pedido)
    lerIds(lista.getAttribute('data-ids')).forEach(function (id) {
      naPagina[id] = true;
      avisados[id] = true;
    });

    var somArmado = false;
    var contexto = null;
    var timer = null;
    var consultando = false;
    var parou = false;

    // Navegadores so deixam tocar som depois de um toque do usuario.
    ['pointerdown', 'touchstart', 'keydown', 'click'].forEach(function (nome) {
      document.addEventListener(nome, armarSom, { passive: true });
    });

    function armarSom() {
      if (somArmado) { return; }
      try {
        var Contexto = window.AudioContext || window.webkitAudioContext;
        if (!Contexto) { return; }
        contexto = new Contexto();
        if (contexto.state === 'suspended' && contexto.resume) { contexto.resume(); }
        somArmado = true;
      } catch (e) { /* sem som: o resto funciona igual */ }
    }

    function tocar() {
      if (!somArmado || !contexto) { return; }
      try {
        var oscilador = contexto.createOscillator();
        var ganho = contexto.createGain();
        var agora = contexto.currentTime;
        oscilador.type = 'sine';
        oscilador.frequency.setValueAtTime(880, agora);
        oscilador.frequency.setValueAtTime(1175, agora + 0.14);
        ganho.gain.setValueAtTime(0.0001, agora);
        ganho.gain.exponentialRampToValueAtTime(0.3, agora + 0.02);
        ganho.gain.exponentialRampToValueAtTime(0.0001, agora + 0.32);
        oscilador.connect(ganho);
        ganho.connect(contexto.destination);
        oscilador.start(agora);
        oscilador.stop(agora + 0.34);
      } catch (e) { /* idem */ }
    }

    function vibrar() {
      // Extra opcional (Android). No iPhone nao existe e nao faz falta.
      try { if (navigator.vibrate) { navigator.vibrate([200, 100, 200]); } } catch (e) { /* idem */ }
    }

    function aplicar(resumo) {
      mostrarConexao('');
      var total = typeof resumo.total === 'number' ? resumo.total : 0;
      var ids = Array.isArray(resumo.ids) ? resumo.ids : [];

      document.title = (total > 0 ? '(' + total + ') ' : '') + tituloBase;

      var faltam = ids.filter(function (id) { return !naPagina[id]; }).length;
      if (faixa) {
        if (faltam > 0) {
          faixa.textContent = faltam === 1 ? '1 pedido novo' : faltam + ' pedidos novos';
          faixa.hidden = false;
        } else {
          faixa.hidden = true;
        }
      }

      var novosParaAvisar = ids.filter(function (id) { return !avisados[id]; });
      if (novosParaAvisar.length > 0) {
        novosParaAvisar.forEach(function (id) { avisados[id] = true; });
        tocar();
        vibrar();
      }
    }

    function mostrarConexao(texto, comLink) {
      if (!conexao) { return; }
      conexao.textContent = texto;
      if (comLink) {
        var a = document.createElement('a');
        a.href = '/painel/entrar';
        a.textContent = ' Entrar de novo';
        conexao.appendChild(a);
      }
      conexao.hidden = texto === '';
    }

    function consultar() {
      if (parou || consultando) { return; }
      consultando = true;
      clearTimeout(timer);

      fetch(urlDoResumo, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      }).then(function (resposta) {
        if (resposta.status === 401 || resposta.status === 419 || resposta.redirected) {
          parou = true;
          mostrarConexao('Sua sessão expirou.', true);
          throw new Error('sessao');
        }
        if (!resposta.ok) { throw new Error('http'); }
        return resposta.json();
      }).then(aplicar).catch(function (erro) {
        if (erro && erro.message === 'sessao') { return; }
        mostrarConexao('Sem conexão com o servidor. Tentando de novo…');
      }).then(function () {
        consultando = false;
        agendar();
      });
    }

    function agendar() {
      clearTimeout(timer);
      if (parou) { return; }
      timer = setTimeout(function () {
        // Aba escondida: nao gasta rede nem bateria; ao voltar, consulta na hora.
        if (document.hidden) { agendar(); } else { consultar(); }
      }, intervalo + Math.floor(Math.random() * 3000));
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { consultar(); }
    });
    window.addEventListener('online', consultar);
    window.addEventListener('pageshow', function (evento) {
      // Voltou pelo botao "voltar" (pagina vinda do cache do navegador): atualiza.
      if (evento.persisted) { consultar(); }
    });

    agendar();
  }

  function lerIds(texto) {
    try {
      var ids = JSON.parse(texto || '[]');
      return Array.isArray(ids) ? ids : [];
    } catch (e) {
      return [];
    }
  }

  /* ------------------------------------------------------------ botao copiar */

  document.addEventListener('click', function (evento) {
    var botao = evento.target.closest ? evento.target.closest('[data-copiar]') : null;
    if (!botao) { return; }
    var alvo = document.querySelector(botao.getAttribute('data-copiar'));
    var avisoSeletor = botao.getAttribute('data-copiar-aviso');
    var aviso = avisoSeletor ? document.querySelector(avisoSeletor) : null;
    if (!alvo) { return; }
    evento.preventDefault();

    function dizer(texto) { if (aviso) { aviso.textContent = texto; aviso.hidden = false; } }
    var texto = (alvo.value !== undefined ? alvo.value : alvo.textContent).trim();

    selecionar(alvo);

    // 1) Area de transferencia moderna (so em HTTPS ou localhost).
    if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(
        function () { dizer('Copiado.'); },
        function () { copiarAntigo(); }
      );
      return;
    }
    copiarAntigo();

    // 2) Metodo antigo (funciona em http); 3) texto ja selecionado: copiar com o dedo.
    function copiarAntigo() {
      var copiou = false;
      try { copiou = document.execCommand('copy'); } catch (e) { copiou = false; }
      dizer(copiou ? 'Copiado.' : 'Texto selecionado: toque e segure para copiar.');
    }
  });

  function selecionar(elemento) {
    try {
      if (typeof elemento.select === 'function') {
        elemento.focus();
        elemento.select();
        if (elemento.setSelectionRange) { elemento.setSelectionRange(0, elemento.value.length); }
        return;
      }
      var intervalo = document.createRange();
      intervalo.selectNodeContents(elemento);
      var selecao = window.getSelection();
      selecao.removeAllRanges();
      selecao.addRange(intervalo);
    } catch (e) { /* o texto continua selecionavel com o dedo (user-select: all) */ }
  }
})();
