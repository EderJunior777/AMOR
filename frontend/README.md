# Site da barbearia (cópia de trabalho)

Cópia de trabalho do site (HTML, CSS e JS estáticos, publicado na Netlify),
com as correções da etapa 1 e a integração com a agenda nova da etapa 2.
A raiz do repositório guarda o site original do ZIP e não é editada.

> ## ⚠️ Agenda nova: SÓ PARA HOMOLOGAÇÃO
>
> A flag `CONFIG.agendaNova.ligada` (em `assets/config.js`) vem **desligada**.
> Desligada, o site é **idêntico ao de hoje**: grava e lê a agenda nos
> Netlify Blobs (`/api/agenda`, `/api/cliente`) e os testes legados passam
> sem nenhuma alteração.
>
> **Ligada, é só para homologação.** O site passa a falar **apenas** com a
> API nova (`/api/v1`, backend Laravel), e:
>
> - **o painel do barbeiro (`agenda.html`) NÃO mostra essas reservas.** Ele
>   continua lendo os Blobs;
> - não há gravação dupla nem sincronização entre os dois mundos, de
>   propósito. Com a flag ligada, o site nunca chama `/api/agenda` nem
>   `/api/cliente`;
> - **não ligue no site publicado.** A troca definitiva de origem de
>   gravação (com importação dos Blobs) é da etapa 6, e o painel novo é da
>   etapa 3.

## Rodar

```bash
npm install
npm run dev            # site de hoje (flag desligada) + agenda nos Blobs: http://localhost:8888
```

### Homologar a agenda nova

```bash
# backend (pasta ../backend), com o catálogo de demonstração no banco que ele usa:
php artisan db:seed --class=DemonstracaoSeeder
php artisan serve                       # http://127.0.0.1:8000

# aqui:
npm run homologacao                     # http://localhost:8890
```

O `servidor-homologacao.mjs` serve o site com a flag **ligada só na
resposta** (o `config.js` no disco continua desligado) e faz o proxy de
`/api/v1/*` para o backend (`API_V1_URL`, padrão `http://127.0.0.1:8000`) na
mesma origem, como o proxy da Netlify fará. `/api/agenda` e `/api/cliente`
respondem 410 e aparecem como erro no console: se aparecerem, algo está
ligando os dois mundos.

## O que muda com a flag ligada

| Parte | Com a flag ligada |
|---|---|
| Catálogo | `GET /api/v1/servicos` e `/regioes`. O **slug** do `config.js` (`corte`, `zona-sul`...) casa com o `codigo` da API, e o **id vem da API**, nunca fixo no código. Preço, duração e taxa da região passam a ser os do servidor; serviço ou região sem par na API some. |
| Profissional | `GET /api/v1/profissionais?servicos[]=` (o primeiro que faz o serviço). |
| Horários | `GET /api/v1/disponibilidade` (a API confere expediente, antecedência, deslocamento e ocupação). |
| Reserva | `POST /api/v1/reservas` com `Idempotency-Key`, uma por **tentativa de envio**. Sem resposta (falha de rede ou tempo-limite de 20 s) ou com erro genérico (500, 502, 504: a reserva pode ter sido gravada), a tentativa continua: reenviar o **mesmo** pedido usa a **mesma** chave (a API devolve a reserva que já existe). Só a rede tem uma repetição automática; depois, o botão. Resposta definitiva (409, 422, 429, 503) ou pedido mudado: chave nova. |
| Erros | 409: "esse horário acabou de ser ocupado", e os horários são recarregados. 422: a mensagem do código que a API mandou. 429/503: "tente de novo em instantes", **sem** repetição automática. |
| Sucesso | Mostra o **código da reserva** (a credencial, junto com o telefone) e **não** pula sozinho para o WhatsApp, para o cliente ver o código. O botão do WhatsApp continua lá. O código não vai na mensagem do WhatsApp. |
| Minha reserva | Seção que só aparece com a flag: consultar, remarcar e cancelar por código + telefone (`POST /api/v1/reservas/consultar|remarcar|cancelar`). |
| Sem internet | Nada de "modo demonstração" (reserva só no aparelho): o botão fica desligado até a conexão voltar. |
| Memória do cliente | Desligada (ela mora nos Blobs, `/api/cliente`). |

Dados do cliente e respostas da API só entram na página por `textContent`.

## Service worker

`sw.js` **nunca** guarda `/api/*` em cache (agenda de hoje e nova, qualquer
método ou modo); só os arquivos do site. Coberto por `npm run test:sw`.

Os arquivos do site são servidos do cache primeiro e atualizados por baixo
(quem já visitou roda a versão antiga uma vez). Por isso **toda mudança em
JS/CSS publicada sobe `VERSAO` no `sw.js`**: a versão nova apaga o cache
antigo. Esta etapa subiu para `barbearia-v2`, o que invalida o cache dos
visitantes uma vez, mesmo com a flag desligada (é a única diferença visível
para quem já tinha o site em cache).

## Limites da API numa visita

Com a flag ligada, uma visita faz em geral 4 chamadas (catálogo, regiões,
profissional e os horários de hoje, compartilhados entre os dois cartões da
capa), e até 10 se os próximos dias estiverem lotados. O limite padrão por
IP é 60/min (`API_LIMITE_GERAL_POR_MINUTO`): dimensionar na homologação com
muitos clientes no mesmo Wi-Fi.

## Testes

| Comando | O que roda |
|---|---|
| `npm test` | Legado, **sem alteração**: agenda (Blobs) e cliente. |
| `npm run test:agenda-v1` | Cliente da agenda nova (`assets/agenda-v1.js`) com `fetch` falso: mensagens por status, 429/503 sem repetição, rede com uma repetição e a mesma chave, flag desligada por padrão. |
| `npm run test:sw` | Service worker: `/api/*` nunca em cache. |
| `npm run test:api-v1` | Agenda nova contra a **API real**: sobe `php artisan serve` no banco de **teste** do backend (`cleison_teste`, catálogo de demonstração) e cobre catálogo, horários, reservar, conflito 409, reenvio idempotente (inclusive resposta perdida na rede), consultar, remarcar e cancelar por código + telefone, e domicílio. Exige o PostgreSQL local de pé; **não rode junto com a suíte PHP** (mesmo banco). |

## Arquivos

| Arquivo | O que é |
|---|---|
| `assets/config.js` | Configuração do site, incluindo a flag `agendaNova` (desligada). |
| `assets/agenda-v1.js` | Cliente da API nova (só usado com a flag ligada). |
| `assets/app.js` | Fluxo de agendamento; os ramos da agenda nova só rodam com a flag. |
| `agenda.html`, `assets/admin.js` | Painel do barbeiro (Blobs e PIN), **não alterados** nesta etapa. |
| `netlify/functions/` | Agenda de hoje (Blobs), **não alterada**. |
| `testes/servidor-homologacao.mjs` | Servidor de homologação da agenda nova (só em 127.0.0.1, só os arquivos do site). |

## Checklist do primeiro deploy

Faça uma vez, no primeiro deploy desta versão (e sempre que trocar de site na
Netlify). Nada disso dá para provar localmente: só a Netlify aplica
`netlify.toml`.

1. **`PIN_PAINEL` definida** (Site settings → Environment variables), com um
   PIN que **não** seja o de desenvolvimento. Ela é **obrigatória**: sem ela o
   painel (`agenda.html`) responde 503 "Painel não configurado" e o barbeiro
   não entra. Não existe PIN padrão em produção (o `1234` só existe em
   `testes/servidor-local.mjs`, que não é uma função da Netlify).
   Confira: abrir `/agenda.html`, entrar com o PIN e ver o dia.
2. **Os 404** (`netlify.toml`): `publish = "."` publica a pasta inteira, e os
   redirects forçados escondem o que não é do site. Cada um deve dar **404**:
   - `/testes/servidor-local.mjs`
   - `/netlify/functions/agenda.mjs`
   - `/package.json` e `/package-lock.json`
   - `/README.md`

   E o site e as funções **devem continuar funcionando**: `/` abre,
   `/api/agenda?dia=AAAA-MM-DD` responde 200 e a reserva de teste fecha.
3. **Cabeçalhos** (`curl -I https://SEU-SITE/`): `Content-Security-Policy`,
   `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` e
   `Strict-Transport-Security`. Abrir o site e conferir que o console do
   navegador não mostra nenhum erro de CSP.
4. **Service worker**: abrir uma vez, recarregar, e conferir que o registro
   aparece (DevTools → Application) com a versão nova.

Se o item 2 falhar (algum arquivo abre), **não deixe no ar**: o painel e as
funções não devem ser lidos como arquivo estático.

## Pendente para publicar (etapa 6)

- Redirect `/api/v1/*` → backend no `netlify.toml` (proxy, status 200), com
  a URL do backend definida na implantação.
- Pré-requisito para ligar a flag em produção: verificação do telefone por
  código (WhatsApp) ou captcha, e a decisão sobre o painel (etapa 3) e a
  importação dos Blobs.
