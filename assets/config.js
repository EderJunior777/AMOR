/* =========================================================================
   ARQUIVO DE CONFIGURACAO - E O UNICO ARQUIVO QUE VOCE PRECISA EDITAR.
   Mude aqui o nome da barbearia, o numero do WhatsApp, os servicos,
   os precos, a taxa de domicilio e o horario de funcionamento.
   Depois de editar, salve e publique de novo.
   ========================================================================= */

const CONFIG = {
  // ---- Identidade -------------------------------------------------------
  barbearia: "Barbearia do Ze",
  barbeiro: "Ze",
  slogan: "Corte na regua, na barbearia ou na sua casa.",

  // ---- WhatsApp ---------------------------------------------------------
  // Formato: codigo do pais + DDD + numero, SO NUMEROS.
  // Exemplo Brasil, Sao Paulo: 5511987654321
  whatsapp: "5511987654321",

  // ---- Endereco da barbearia (aparece no rodape) ------------------------
  endereco: "Rua das Tesouras, 100 - Centro",
  instagram: "", // ex: "barbeariadoze" (deixe vazio para esconder)

  // ---- Funcionamento ----------------------------------------------------
  abertura: "08:00",      // primeiro horario do dia
  fechamento: "20:00",    // o atendimento TERMINA neste horario
  intervaloMinutos: 30,   // de quanto em quanto tempo os horarios aparecem
  diasParaFrente: 30,     // quantos dias para frente o cliente pode marcar
  diasFechados: [],       // 0=domingo, 1=segunda ... 6=sabado. Ex: [0] fecha domingo
  antecedenciaMinutos: 30, // nao deixa marcar em cima da hora

  // ---- Atendimento a domicilio -----------------------------------------
  domicilioAtivo: true,
  taxaDomicilio: 20,      // valor somado ao servico quando o corte e em casa

  // Regioes que o barbeiro atende em casa. O "deslocamento" e quanto tempo
  // ele leva pra chegar ali, em minutos: esse tempo e reservado na agenda
  // dos DOIS lados do corte (pra ir e pra voltar), senao ele acabaria com
  // um corte na barbearia colado num atendimento do outro lado da cidade.
  //
  // O valor e arredondado pra cima em blocos de 30 min (o tamanho da grade
  // de horarios), entao 15 e 30 reservam a mesma coisa. Quanto maior, mais
  // agenda some - vale conferir se compensa com a taxa que voce cobra.
  regioes: [
    { id: "centro",   nome: "Centro",             deslocamento: 15 },
    { id: "zona-sul", nome: "Zona Sul",           deslocamento: 30 },
    { id: "zona-norte", nome: "Zona Norte",       deslocamento: 30 },
    { id: "zona-leste", nome: "Zona Leste",       deslocamento: 45 },
    { id: "zona-oeste", nome: "Zona Oeste",       deslocamento: 45 }
  ],

  // ---- Servicos ---------------------------------------------------------
  // duracao SEMPRE em minutos e multipla do intervalo acima (30, 60, 90...)
  servicos: [
    { id: "corte",        nome: "Corte",           preco: 40, duracao: 30, descricao: "Maquina, tesoura e acabamento." },
    { id: "barba",        nome: "Barba",           preco: 30, duracao: 30, descricao: "Toalha quente, navalha e balm." },
    { id: "corte-barba",  nome: "Corte + Barba",   preco: 65, duracao: 60, descricao: "O combo completo." },
    { id: "degrade",      nome: "Degrade",         preco: 50, duracao: 60, descricao: "Fade caprichado, do zero ao topo." },
    { id: "pezinho",      nome: "Pezinho",         preco: 20, duracao: 30, descricao: "So o acabamento pra segurar a semana." },
    { id: "infantil",     nome: "Corte infantil",  preco: 35, duracao: 30, descricao: "Paciencia inclusa." }
  ]
};

/* Permite que a funcao da agenda (no servidor) leia exatamente este mesmo
   arquivo, para que horario, preco e duracao tenham UMA fonte da verdade.
   No navegador esta linha simplesmente nao acontece. */
if (typeof module !== "undefined" && module.exports) { module.exports = CONFIG; }
