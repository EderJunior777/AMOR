# Diagnóstico da base original

Reproduzido em 24/09/2026, Windows 11, Node 24.21.0, `@netlify/blobs` 11.1.0.

## 0. Onde está cada versão

- Não há `LEIA-PRIMEIRO.md` nem `site-original/`: **a raiz do repositório é o
  conteúdo do ZIP** e é tratada como referência original (não editada).
- O git mostra os 20 arquivos como modificados, mas quase tudo é fim de linha
  (CRLF/LF). Diferenças reais de conteúdo entre a raiz e o commit `0ddedb8`
  ("Corrige bugs de ambiente Windows...") existem em `netlify/functions/agenda.mjs`,
  `testes/servidor-local.mjs` e `assets/admin.js`. Ou seja: **o commit já
  tinha correções que o ZIP não tem.** O `0ddedb8` corrigia a agenda trocando
  o formato da chave (`14:30` → `14-30`); essa abordagem **deixaria invisíveis
  as reservas já gravadas em produção** com o formato antigo. A cópia de
  trabalho usa outra correção, compatível (abaixo).
- Cópia de trabalho: `frontend/`.

## 1. Baseline reproduzido

| Suíte | Resultado na raiz (original) |
|---|---|
| `node testes/agenda.test.mjs` | **7 falhas**. Horários voltam como `14%3A30`; painel vazio; "liberar" dá 404; o horário continua preso (409 ao reservar de novo). |
| `node testes/cliente.test.mjs` (separada) | Passa (23 ok). |

**Falso positivo encontrado:** o teste "14:30 e 15:00 voltaram a ficar
livres" **passava por acaso**. A lista vinha como `14%3A30`, então
`includes("14:30")` era sempre falso, mesmo com o horário ainda preso. Com o
teste endurecido para conferir o formato, o baseline sobe para 9 falhas.

## 2. Causa confirmada (no SDK, não na produção)

Em `node_modules/@netlify/blobs/dist/server.js` (`BlobsServer`, servidor
**local** de desenvolvimento):

- `getLocalPaths()` aplica `encodeName()` na chave. **Só no Windows**
  (`process.platform == "win32"`), isso vira `encodeURIComponent` para ter um
  nome de arquivo válido: `2026-09-25__14:30` → arquivo `2026-09-25__14%3A30`.
- `walk()`/`listBlobs()` devolvem o **nome do arquivo** como `key`, **sem**
  `decodeName()` (ao contrário de `listStores()`, que decodifica).
- `agenda.mjs` pega essa `key` e chama `store.get(key)` / `store.delete(key)`.
  O servidor codifica de novo (`%253A`) e não acha nada: `null` no painel e
  0 apagados no liberar (404).

Limite da evidência: no Linux, `encodeName` é identidade, e a Netlify real
não usa este servidor. **Não reproduzimos o comportamento da produção**
(não há acesso à conta nem aos dados). O comportamento esperado (listar
`HH:MM`, painel mostra, liberar apaga) vira critério da migração SQL, e o
backend novo já o garante no banco.

## 3. Correções mínimas em `frontend/` (com regressão)

| Arquivo | Problema | Correção |
|---|---|---|
| `netlify/functions/agenda.mjs` | Chaves listadas vinham codificadas no Windows local | `chaveListada()` decodifica a chave do `list()` antes de `get`/`delete`. **Não muda o formato gravado**; é no-op quando não há `%`. |
| `testes/agenda.test.mjs` | Falso positivo + nenhuma checagem de formato | Novo teste "horários ocupados vêm no formato HH:MM"; "voltaram a ficar livres" agora exige formato válido e lista não vazia. |
| `testes/servidor-local.mjs` | No Windows todo o site estático dava **404** (`URL.pathname` = `/C:/...`) | `fileURLToPath`. Verificado: `/`, `/agenda.html`, CSS, JS e `sw.js` → 200. |
| `assets/admin.js` | "Chamar no zap" usava o telefone sem `55` e abria o contato errado | `linkZap()` prefixa `55` quando há 10–11 dígitos. |
| `netlify/functions/agenda.mjs` + `testes/agenda.test.mjs` (revisão) | Caso "08:00 de hoje" só rodava depois das 08:00 em SP; datas dos testes em UTC (`toISOString`) | Relógio injetável (`_definirRelogioParaTestes`, só usado por testes; em produção é o relógio real). Suite com instante fixo; novos casos de antecedência, virada do dia e horizonte no fuso de SP. |

Resultado em `frontend/` (revisão): agenda **51 verificações ok, 0 falhas, 0
puladas**, a qualquer hora; clientes **23 ok**.

Reprodução independente (revisão): os testes endurecidos de `frontend/`
rodando com o `agenda.mjs` **original** dão 9 falhas; com o corrigido, 0.

## 4. Divergências registradas, NÃO corrigidas no legado

Viram requisitos do backend novo (etapa 2 ou 3):

| Arquivo | Divergência | Evidência |
|---|---|---|
| `agenda.mjs` | **Aceita datas inexistentes** (regex `AAAA-MM-DD` sem validar o calendário) | `2026-09-31` → 201; `2026-10-00` → 201 |
| `agenda.mjs` | **Sem idempotência**: retry idêntico após resposta perdida recebe 409 "ocupado" (o horário é da própria pessoa) | 1ª → 201, retry → 409 |
| `agenda.mjs` | Garantia depende de `onlyIfNew` atômico do provedor; a "segunda conferência" **não** impede dois vencedores se o armazenamento não respeitar `onlyIfNew` (check-then-write intercalado) | Análise do código; o teste de corrida passa no servidor local, mas não prova o caso geral |
| `agenda.mjs` | PIN padrão `1234` e **PIN na URL** (vai para logs e histórico) | Código |
| `agenda.mjs`, `cliente.mjs` | Erro 500 devolve `detalhe: e.message` ao público | Código |
| `cliente.mjs` | `TELEFONE_SAL` padrão fixo; histórico **limitado a 20**; gravação ler-modificar-gravar sem trava (perde atualização concorrente); busca por chave percorre **todos** os clientes | Código |
| `cliente.mjs` | Tokens de 6 meses guardados como hash **no perfil** | Não serão reaproveitados como sessão SQL |
| `sw.js` | Cacheia `agenda.html` (painel) e `admin.js` | A versão migrada deve excluir o admin do cache |
| `config.js` | "Barbearia do Ze", endereço, telefone, preços e regiões são **exemplo** | Só no seed de demonstração |

## 5. Dados reais nos Blobs

**Não verificado.** O ZIP contém código, não dados. Antes de qualquer
migração real (etapa 6) é preciso acessar a conta Netlify e exportar os
stores `agenda`, `clientes` e `codigos`, se existirem.
