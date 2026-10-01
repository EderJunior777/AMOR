#!/usr/bin/env node
/* =========================================================================
   Teste de NAVEGADOR do painel no celular (390 px): login -> pedido ->
   confirmar -> "Avisar cliente no WhatsApp", recusar, aviso automatico de
   pedido novo, agenda (iniciar e concluir), conta (sair) e, com o login do
   proprietario, o botao "Copiar" sem HTTPS.

   E um script MANUAL: nao faz parte do composer, do PHPUnit nem de nenhum CI,
   e o CLEISON nao instala nenhum pacote para ele.

   Dependencia: o pacote "playwright" (e os navegadores dele). Aponte para ele
   com a variavel PLAYWRIGHT_DIR (a pasta que tem node_modules/playwright, ou a
   propria pasta do pacote). Nao ha caminho fixo no codigo.
     - PLAYWRIGHT_DIR definida: testa em iPhone (WebKit, 390 x 844) E em Chrome
       (Chromium, 390 x 844), se o WebKit estiver instalado.
     - PLAYWRIGHT_DIR ausente: o script AVISA, tenta o "playwright" que o Node
       achar sozinho e testa SO no Chrome. Sem nenhum Playwright, para e explica.

   Precisa de um servidor do painel rodando e de DADOS DE TESTE (nunca de
   producao): o script cria os pedidos pela API publica, como um cliente.
   Variaveis:
     PAINEL_URL          padrao http://127.0.0.1:8000
     PAINEL_EMAIL        login de um barbeiro (ou recepcao) com senha definitiva
     PAINEL_SENHA        a senha dele (so aqui, nunca no codigo)
     PAINEL_DONO_EMAIL   (opcional) login do proprietario: liga o teste da Equipe
     PAINEL_DONO_SENHA
     PAINEL_CAPTURAS     pasta das capturas de tela (padrao: <temp>/painel-capturas)
     PAINEL_PULAR_ESPERA 1 = nao espera os ~30 s do relogio da atualizacao automatica
   Uso:  node tests/Navegador/painel-no-celular.mjs
   Saida: uma linha por verificacao (OK/FALHOU); codigo de saida 1 se algo falhar.
   ========================================================================= */

import { createRequire } from 'node:module';
import { existsSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';

const BASE = (process.env.PAINEL_URL || 'http://127.0.0.1:8000').replace(/\/+$/, '');
const EMAIL = process.env.PAINEL_EMAIL || '';
const SENHA = process.env.PAINEL_SENHA || '';
const DONO_EMAIL = process.env.PAINEL_DONO_EMAIL || '';
const DONO_SENHA = process.env.PAINEL_DONO_SENHA || '';
const PASTA = process.env.PAINEL_CAPTURAS || join(tmpdir(), 'painel-capturas');
const PULAR_ESPERA = process.env.PAINEL_PULAR_ESPERA === '1';
const VIEWPORT = { width: 390, height: 844 };

let falhas = 0;

function aviso(texto) { console.warn(`AVISO: ${texto}`); }
function sair(texto, codigo = 2) { console.error(`ERRO: ${texto}`); process.exit(codigo); }

/* ------------------------------------------------------------ playwright */

function carregarPlaywright() {
  const dir = process.env.PLAYWRIGHT_DIR || '';
  if (!dir) {
    aviso('PLAYWRIGHT_DIR não está definida: vou tentar o Playwright que o Node encontrar sozinho e testar SÓ no Chrome (o iPhone/WebKit não será testado).');
    try {
      return { modulo: createRequire(import.meta.url)('playwright'), comWebkit: false };
    } catch {
      sair('Não achei o Playwright. Defina PLAYWRIGHT_DIR com a pasta que tem node_modules/playwright (veja o cabeçalho deste arquivo).');
    }
  }
  const candidatos = [join(dir, 'node_modules', 'playwright'), join(dir, 'playwright'), dir];
  for (const caminho of candidatos) {
    if (existsSync(join(caminho, 'package.json'))) {
      try {
        return { modulo: createRequire(join(caminho, 'package.json'))(caminho), comWebkit: true };
      } catch { /* tenta o proximo */ }
    }
  }
  sair(`PLAYWRIGHT_DIR (${dir}) não tem um pacote "playwright" utilizável.`);
}

/* ----------------------------------------------------------- verificacoes */

async function verificar(descricao, funcao) {
  try {
    await funcao();
    console.log(`  OK      ${descricao}`);
  } catch (erro) {
    falhas += 1;
    console.log(`  FALHOU  ${descricao}\n          ${String(erro && erro.message ? erro.message : erro).split('\n')[0]}`);
  }
}

function afirmar(condicao, mensagem) { if (!condicao) { throw new Error(mensagem); } }

/* ------------------------------------------------------- pedidos pela API */

async function api(caminho, opcoes = {}) {
  const resposta = await fetch(`${BASE}/api/v1${caminho}`, {
    ...opcoes,
    headers: { accept: 'application/json', 'content-type': 'application/json', ...(opcoes.headers || {}) },
  });
  const corpo = await resposta.json().catch(() => ({}));
  return { status: resposta.status, corpo };
}

function telefoneNovo() {
  return `119${String(Math.floor(Math.random() * 1e8)).padStart(8, '0')}`;
}

/** Cria um pedido como o cliente do site (API publica) no primeiro horario livre dos proximos dias. */
async function criarPedido(nome) {
  const { corpo: catalogo } = await api('/servicos');
  const servico = (catalogo.servicos || []).find((s) => s.permite_barbearia !== false) || (catalogo.servicos || [])[0];
  afirmar(servico, 'a API não devolveu serviços (o banco de teste tem os dados de demonstração?)');
  const { corpo: profissionais } = await api(`/profissionais?servicos%5B%5D=${servico.id}`);
  const profissional = (profissionais.profissionais || [])[0];
  afirmar(profissional, 'nenhum profissional faz esse serviço');

  for (let dias = 2; dias <= 20; dias += 1) {
    const dia = new Date(Date.now() + dias * 86400000).toISOString().slice(0, 10);
    const { corpo } = await api(`/disponibilidade?data=${dia}&servicos%5B%5D=${servico.id}&profissional_id=${profissional.id}&modalidade=barbearia`);
    const hora = (corpo.horarios || [])[Math.floor(Math.random() * Math.min(5, (corpo.horarios || []).length))];
    if (!hora) { continue; }
    const horaTexto = typeof hora === 'string' ? hora : (hora.hora || hora.inicio);
    const resposta = await api('/reservas', {
      method: 'POST',
      headers: { 'Idempotency-Key': `nav-${randomUUID()}` },
      body: JSON.stringify({
        servicos: [servico.id], profissional_id: profissional.id, data: dia, hora: horaTexto, modalidade: 'barbearia',
        cliente: { nome, telefone: telefoneNovo() },
      }),
    });
    if (resposta.status === 201) { return { nome, dia, hora: horaTexto }; }
  }
  throw new Error('não consegui criar um pedido de teste pela API (sem horário livre ou limite atingido)');
}

/* ------------------------------------------------------- fluxo no navegador */

async function sobreNavegador(rotulo, motor, opcoesDoContexto, apelido) {
  console.log(`\n=== ${rotulo} (${VIEWPORT.width} px) ===`);
  let navegador;
  try {
    navegador = await motor.launch();
  } catch (erro) {
    aviso(`${rotulo}: não consegui abrir o navegador (${String(erro.message).split('\n')[0]}). Pulando.`);
    return;
  }

  const problemas = [];
  const contexto = await navegador.newContext({ ...opcoesDoContexto, viewport: VIEWPORT, baseURL: BASE, locale: 'pt-BR' });
  // CSP: qualquer violacao (estilo ou script inline, origem externa) e falha.
  await contexto.addInitScript(() => {
    window.__violacoesCsp = [];
    document.addEventListener('securitypolicyviolation', (e) => window.__violacoesCsp.push(`${e.violatedDirective} ${e.blockedURI}`));
  });
  const pagina = await contexto.newPage();
  pagina.on('pageerror', (e) => problemas.push(`erro de JavaScript: ${e.message}`));
  pagina.on('console', (m) => { if (m.type() === 'error' && !/favicon/i.test(m.text())) { problemas.push(`console: ${m.text()}`); } });

  mkdirSync(PASTA, { recursive: true });
  const captura = (nome) => pagina.screenshot({ path: join(PASTA, `${apelido}-${nome}.png`), fullPage: true });
  const semRolagemLateral = () => pagina.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);

  const cliente1 = await criarPedido(`Cliente Navegador A ${apelido}`);
  const cliente2 = await criarPedido(`Cliente Navegador B ${apelido}`);

  await verificar('a tela de entrada cabe em 390 px, com campos grandes e sem zoom no iPhone', async () => {
    await pagina.goto('/painel/entrar');
    afirmar(await semRolagemLateral(), 'a página tem rolagem lateral');
    const tamanhoDaFonte = await pagina.locator('input[name=email]').evaluate((e) => parseFloat(getComputedStyle(e).fontSize));
    afirmar(tamanhoDaFonte >= 16, `fonte do campo ${tamanhoDaFonte}px (<16px: o iPhone daria zoom)`);
    const altura = (await pagina.locator('button[type=submit]').boundingBox()).height;
    afirmar(altura >= 44, `botão Entrar com ${altura}px (<44px)`);
    await captura('01-entrar');
  });

  await verificar('login com senha errada mostra a mensagem igual e nao entra', async () => {
    await pagina.fill('input[name=email]', EMAIL);
    await pagina.fill('input[name=senha]', 'senha-errada-123');
    await Promise.all([pagina.waitForURL('**/painel/entrar'), pagina.click('button[type=submit]')]);
    afirmar(await pagina.getByText('E-mail ou senha incorretos.').isVisible(), 'sem a mensagem de erro');
  });

  await verificar('login certo leva aos Pedidos com o contador no titulo', async () => {
    await pagina.fill('input[name=email]', EMAIL);
    await pagina.fill('input[name=senha]', SENHA);
    await Promise.all([pagina.waitForURL(/\/painel\/?$/), pagina.click('button[type=submit]')]);
    afirmar(/^\(\d+\) Pedidos/.test(await pagina.title()), `título "${await pagina.title()}" sem o contador`);
    afirmar(await pagina.locator('.cartao', { hasText: cliente1.nome }).count() === 1, 'o pedido do cliente A não apareceu');
    afirmar(await semRolagemLateral(), 'a página tem rolagem lateral');
    await captura('02-pedidos');
  });

  await verificar('os botoes Confirmar e Recusar sao enormes (altura maior ou igual a 44 px) e nao dependem de hover', async () => {
    const cartao = pagina.locator('.cartao', { hasText: cliente1.nome });
    for (const nome of ['Confirmar', 'Recusar']) {
      const alvo = nome === 'Recusar' ? cartao.locator('summary') : cartao.getByRole('button', { name: nome, exact: true });
      const caixa = await alvo.boundingBox();
      afirmar(caixa && caixa.height >= 44, `${nome} com ${caixa && caixa.height}px`);
      afirmar(caixa.width >= 200, `${nome} com ${caixa.width}px de largura`);
    }
    afirmar(await pagina.locator('.cartao a[href^="tel:"]').first().isVisible(), 'sem o botão Ligar');
  });

  await verificar('confirmar mostra "Avisar cliente no WhatsApp" com o link pronto (nao envia sozinho)', async () => {
    const cartao = pagina.locator('.cartao', { hasText: cliente1.nome });
    await Promise.all([pagina.waitForURL(/\/painel\/?$/), cartao.getByRole('button', { name: 'Confirmar', exact: true }).click()]);
    afirmar(await pagina.getByText('Pedido confirmado').isVisible(), 'sem o aviso "Pedido confirmado"');
    const link = pagina.getByRole('link', { name: 'Avisar cliente no WhatsApp' });
    const destino = await link.getAttribute('href');
    afirmar(/^https:\/\/wa\.me\/55\d{10,11}\?text=/.test(destino), `link inesperado: ${destino}`);
    afirmar(decodeURIComponent(destino).includes('confirmado'), 'a mensagem não diz "confirmado"');
    afirmar(await pagina.locator('.cartao', { hasText: cliente1.nome }).count() === 0, 'o pedido confirmado continua na lista');
    await captura('03-confirmado');
  });

  await verificar('recusar pede o motivo, recusa e mostra "Avisar cliente no WhatsApp"', async () => {
    const cartao = pagina.locator('.cartao', { hasText: cliente2.nome });
    await cartao.locator('summary').click();
    await cartao.locator('textarea[name=motivo]').fill('Nesse horário não vou atender');
    await Promise.all([pagina.waitForURL(/\/painel\/?$/), cartao.getByRole('button', { name: 'Confirmar recusa' }).click()]);
    afirmar(await pagina.getByText('Pedido recusado').isVisible(), 'sem o aviso "Pedido recusado"');
    const destino = await pagina.getByRole('link', { name: 'Avisar cliente no WhatsApp' }).getAttribute('href');
    afirmar(!decodeURIComponent(destino).includes('não vou atender'), 'o motivo interno vazou para a mensagem do cliente');
  });

  await verificar('pedido novo aparece na faixa e no titulo ao voltar para a aba (visibilitychange)', async () => {
    await pagina.goto('/painel');
    await pagina.locator('body').click(); // o primeiro toque libera o som
    const novo = await criarPedido(`Cliente Navegador C ${apelido}`);
    await pagina.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await pagina.locator('#faixa-novos').waitFor({ state: 'visible', timeout: 10000 });
    afirmar(/pedido novo/.test(await pagina.locator('#faixa-novos').innerText()), 'texto da faixa errado');
    afirmar(/^\(\d+\) Pedidos/.test(await pagina.title()), 'o título perdeu o contador');
    await pagina.getByRole('link', { name: 'Atualizar agora' }).click();
    await pagina.waitForURL(/\/painel\/?$/);
    afirmar(await pagina.locator('.cartao', { hasText: novo.nome }).count() === 1, '"Atualizar agora" não trouxe o pedido novo');
    afirmar(await pagina.locator('#faixa-novos').isHidden(), 'a faixa não sumiu depois de atualizar');
    await captura('04-pedido-novo');
  });

  if (!PULAR_ESPERA) {
    await verificar('o relogio da atualizacao automatica (~25 s) tambem traz o pedido novo', async () => {
      await pagina.goto('/painel');
      await criarPedido(`Cliente Navegador D ${apelido}`);
      await pagina.locator('#faixa-novos').waitFor({ state: 'visible', timeout: 45000 });
    });
  }

  await verificar('agenda: iniciar atendimento e concluir', async () => {
    await pagina.goto('/painel/agenda');
    const cartao = pagina.locator('.cartao', { hasText: cliente1.nome });
    afirmar(await cartao.count() === 1, 'a reserva confirmada não está na agenda');
    await Promise.all([pagina.waitForURL(/\/painel\/agenda/), cartao.getByRole('button', { name: 'Iniciar atendimento' }).click()]);
    afirmar(await pagina.getByText('Atendimento iniciado.').isVisible(), 'sem "Atendimento iniciado."');
    await Promise.all([pagina.waitForURL(/\/painel\/agenda/), pagina.locator('.cartao', { hasText: cliente1.nome }).getByRole('button', { name: 'Concluir' }).click()]);
    afirmar(await pagina.getByText('Atendimento concluído.').isVisible(), 'sem "Atendimento concluído."');
    afirmar(await semRolagemLateral(), 'a agenda tem rolagem lateral');
    await captura('05-agenda');
  });

  await verificar('conta: sair volta para a entrada e fecha o painel', async () => {
    await pagina.goto('/painel/conta/senha');
    await Promise.all([pagina.waitForURL('**/painel/entrar'), pagina.getByRole('button', { name: 'Sair', exact: true }).click()]);
    await pagina.goto('/painel');
    afirmar(pagina.url().endsWith('/painel/entrar'), 'o painel abriu sem login');
  });

  if (DONO_EMAIL && DONO_SENHA) {
    await verificar('equipe: criar acesso mostra a senha uma vez e o Copiar funciona sem HTTPS', async () => {
      await pagina.addInitScript(() => { Object.defineProperty(window, 'isSecureContext', { value: false }); });
      await pagina.goto('/painel/entrar');
      await pagina.fill('input[name=email]', DONO_EMAIL);
      await pagina.fill('input[name=senha]', DONO_SENHA);
      await Promise.all([pagina.waitForURL(/\/painel\/?$/), pagina.click('button[type=submit]')]);
      await pagina.goto('/painel/equipe');
      await pagina.fill('input[name=nome]', `Recepcao Navegador ${apelido}`);
      await pagina.fill('input[name=email]', `recepcao.${Date.now()}.${apelido}@exemplo.com`);
      await pagina.selectOption('select[name=papel]', 'recepcao');
      await pagina.getByRole('button', { name: 'Criar acesso' }).click();
      await pagina.locator('#senha-temporaria').waitFor();
      const senha = (await pagina.locator('#senha-temporaria').innerText()).trim();
      afirmar(/^[A-Za-z0-9]{16}$/.test(senha), 'formato da senha temporária inesperado');
      await captura('06-senha-temporaria');
      await pagina.getByRole('button', { name: 'Copiar' }).click();
      await pagina.locator('#aviso-da-copia').waitFor({ state: 'visible' });
      const avisoDaCopia = await pagina.locator('#aviso-da-copia').innerText();
      afirmar(/Copiado|selecionado/.test(avisoDaCopia), `aviso inesperado: ${avisoDaCopia}`);
      const selecionado = await pagina.evaluate(() => String(window.getSelection()).trim());
      afirmar(selecionado === senha, 'o texto da senha ficou selecionado para copiar com o dedo');
      await pagina.goto('/painel/equipe');
      afirmar(!(await pagina.content()).includes(senha), 'a senha apareceu de novo na lista da equipe');
    });
  }

  await verificar('nenhuma violacao da CSP e nenhum erro de JavaScript', async () => {
    const violacoes = await pagina.evaluate(() => window.__violacoesCsp || []).catch(() => []);
    afirmar(violacoes.length === 0, `violações da CSP: ${violacoes.join(' | ')}`);
    afirmar(problemas.length === 0, problemas.join(' | '));
  });

  await navegador.close();
}

/* ---------------------------------------------------------------- principal */

if (!EMAIL || !SENHA) {
  sair('Defina PAINEL_EMAIL e PAINEL_SENHA (um barbeiro de TESTE com senha definitiva). Veja o cabeçalho deste arquivo.');
}
const { modulo, comWebkit } = carregarPlaywright();
console.log(`Painel em ${BASE}; capturas em ${PASTA}`);

if (comWebkit) {
  await sobreNavegador('iPhone (WebKit)', modulo.webkit, {
    ...modulo.devices['iPhone 13'], viewport: VIEWPORT,
  }, 'iphone-webkit');
} else {
  aviso('Pulando o teste em iPhone (WebKit): defina PLAYWRIGHT_DIR.');
}
await sobreNavegador('Chrome (Chromium)', modulo.chromium, { isMobile: true, hasTouch: true, deviceScaleFactor: 3 }, 'chrome');

console.log(falhas === 0 ? '\nTudo certo.' : `\n${falhas} verificação(ões) falharam.`);
process.exit(falhas === 0 ? 0 : 1);
