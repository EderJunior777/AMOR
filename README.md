# Agenda da Barbearia

Site para o cliente marcar o corte sozinho e cair no WhatsApp com a mensagem
pronta, sem horário batido e sem aquela conversa de ida e volta pra descobrir
o que está livre.

**Como funciona pro cliente:** escolhe o serviço → barbearia ou em casa →
escolhe o dia → vê só os horários realmente livres → põe nome e telefone →
confirma → o WhatsApp abre com tudo escrito pro barbeiro confirmar.

Dá para instalar na tela inicial do celular como um app, e ele abre mesmo
sem internet (aí só não dá para reservar — e a tela avisa isso).

**Como funciona pro barbeiro:** em `agenda.html` ele vê quem marcou, com
telefone e endereço, o total do dia, chama a pessoa no zap com um toque e
libera o horário quando alguém desmarca.

---

## Publicar na Netlify (uma vez só, uns 3 minutos)

A agenda compartilhada precisa da Netlify — é ela que guarda os horários e
faz todo mundo ver a mesma coisa, de graça e sem você criar conta em banco
de dados nenhum.

1. Entre em [netlify.com](https://netlify.com) e crie a conta (dá pra entrar
   com o GitHub).
2. **Add new site → Import an existing project** → escolha este repositório.
3. Não mexa em nada nas opções e clique em **Deploy**.

Pronto, o site sai no ar. Não precisa configurar variável nem chave nenhuma.

### Ligar a memória do cliente (opcional)

O site pode lembrar quem já cortou aqui: a pessoa digita o telefone, recebe
um código de 6 dígitos no WhatsApp, confirma, e daí em diante o site já
preenche o nome e o endereço dela e oferece **"repetir o último corte"**.

Isso exige a API oficial da Meta, porque o site precisa *enviar* uma
mensagem — e o link `wa.me`, que o resto do site usa, só *abre* o WhatsApp,
não envia nada sozinho.

**Enquanto você não configurar, o recurso simplesmente não aparece.** Nada
quebra, o agendamento funciona igual.

Para ligar:

1. Crie uma conta no [Meta for Developers](https://developers.facebook.com),
   um app do tipo **Business** e adicione o produto **WhatsApp**.
2. Cadastre um número de telefone dedicado. **Não pode ser o mesmo número
   que o barbeiro usa no WhatsApp pessoal** — ao cadastrar na API, o número
   deixa de funcionar no app comum.
3. Crie um modelo de mensagem (*template*) de categoria **Autenticação**,
   com um parâmetro para o código. Anote o nome dele.
4. Na Netlify, em **Site settings → Environment variables**, crie:

   | Variável | O que é |
   |---|---|
   | `WHATSAPP_TOKEN` | O token de acesso permanente do app |
   | `WHATSAPP_PHONE_ID` | O *Phone number ID* do número cadastrado |
   | `WHATSAPP_TEMPLATE` | O nome do template (padrão: `codigo_acesso`) |
   | `WHATSAPP_TEMPLATE_LANG` | O idioma do template (padrão: `pt_BR`) |
   | `TELEFONE_SAL` | Qualquer texto aleatório e secreto — ele embaralha os telefones no armazenamento |

5. **Deploys → Trigger deploy.**

O que o site faz para proteger os dados:

- O telefone nunca é guardado em texto puro, e sim embaralhado com o
  `TELEFONE_SAL`.
- Pedir o código para um número que **não** é cliente devolve exatamente a
  mesma resposta de um número que é — senão o site viraria uma forma de
  descobrir quem corta o cabelo ali.
- No máximo 3 pedidos de código por hora e 5 tentativas por código, para
  ninguém usar o site para encher o WhatsApp de outra pessoa.
- O código vale 10 minutos e só pode ser usado uma vez.

### Trocar o PIN do painel

O PIN do `agenda.html` começa como **1234**. Para trocar, na Netlify vá em
**Site settings → Environment variables**, crie `PIN_PAINEL` com o valor que
você quiser e clique em **Deploys → Trigger deploy**.

---

## Mudar serviços, preços e horários

Tudo isso mora num arquivo só: **`assets/config.js`**. Abra, edite, salve e
publique de novo.

| O que mudar | Onde |
|---|---|
| Nome da barbearia e do barbeiro | `barbearia`, `barbeiro` |
| Número do WhatsApp | `whatsapp` (código do país + DDD + número, só dígitos) |
| Serviços, preços e duração | `servicos` |
| Valor da taxa de domicílio | `taxaDomicilio` |
| Regiões que ele atende em casa | `regioes` |
| Horário de funcionamento | `abertura`, `fechamento` |
| Dias de folga | `diasFechados` (`0` = domingo, `6` = sábado) |

A duração de cada serviço tem que ser múltipla de `intervaloMinutos` (30, 60,
90...). O servidor lê esse mesmo arquivo, então preço e horário valem para
todo mundo — não adianta o cliente tentar mudar pelo navegador.

---

## Como o site impede horário batido

Essa é a parte que importa, então vale explicar:

- Cada meia hora da agenda é uma chave própria no armazenamento da Netlify.
  Um corte + barba de 60 min às 14:30 ocupa **duas**: 14:30 e 15:00.
- A gravação usa "só crie se ainda não existir". Se dois clientes apertarem
  confirmar no mesmo segundo, **um único** passa; o outro recebe o aviso de
  que o horário acabou de ser preenchido e a lista dele recarrega sozinha.
- Logo depois de gravar, o servidor relê o horário pra conferir que ele ficou
  mesmo com aquele cliente. É a segunda tranca.
- O horário só aparece se o serviço **inteiro** couber antes do fechamento:
  um corte de 30 min aparece até 19:30, um combo de 60 min só até 19:00.
- Horário que já passou não aparece — e o servidor recusa mesmo que alguém
  tente por uma página velha aberta.
- **No atendimento em casa, o deslocamento também sai da agenda.** Um corte
  de 30 min na Zona Leste (45 min de distância) ocupa das 13:15 às 15:45:
  o tempo de ir, o corte e o tempo de voltar. Sem isso ele marcaria um
  corte na barbearia logo em seguida e não daria tempo de chegar. O tempo
  de cada região fica em `regioes`, no `config.js`.

O horário fica preso assim que o cliente confirma, antes mesmo de ele mandar
a mensagem. Se a pessoa some e nunca manda o zap, o barbeiro libera o horário
pelo painel.

---

## Mexer no código

```bash
npm install                      # uma vez
node testes/servidor-local.mjs   # abre em http://localhost:8888
npm test                         # roda os testes da agenda
```

O `servidor-local.mjs` roda o site e a agenda juntos, sem precisar da CLI da
Netlify e sem internet. Os agendamentos de teste somem quando você fecha.

Se você abrir o `index.html` direto (dois cliques no arquivo), o site aparece
em **modo demonstração**: dá pra navegar e ver o visual, mas os horários ficam
só naquele aparelho e não bloqueiam ninguém. Um aviso amarelo avisa isso na
tela.

### Os arquivos

| Arquivo | O que faz |
|---|---|
| `assets/config.js` | **O único que você precisa editar.** Serviços, preços, horários, zap. |
| `index.html`, `assets/app.js` | A tela do cliente e o fluxo de agendamento. |
| `assets/styles.css` | Todo o visual. Sem framework, sem build. |
| `assets/fontes/` | As fontes ficam no próprio site: carrega mais rápido, funciona offline e não entrega o IP de quem visita pro Google. |
| `agenda.html`, `assets/admin.js` | O painel do barbeiro. |
| `netlify/functions/agenda.mjs` | A agenda de verdade: é quem trava o horário. |
| `netlify/functions/cliente.mjs` | A memória do cliente e a verificação por código. |
| `sw.js`, `manifest.webmanifest` | O que faz o site virar app instalável e abrir sem internet. |
| `testes/agenda.test.mjs` | Prova que dois clientes não pegam o mesmo horário. |
| `testes/cliente.test.mjs` | Prova que o histórico de um cliente não vaza para outro. |
| `testes/servidor-local.mjs` | Roda o site inteiro na sua máquina pra você ver antes de publicar. |
