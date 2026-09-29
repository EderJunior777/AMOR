# LGPD: anonimização de clientes (Fase 8)

> **Status: aprovado (D1 a D8, com os ajustes de 3.0) e implementado.**
> Migrations `2026_09_29_000300_anonimizacao_de_clientes` e
> `2026_09_29_000400_travar_cliente_anonimizado` (corrida com agendamento
> novo, achada na revisão final); comandos
> `cleison:anonimizar-cliente` e `cleison:anonimizar-inativos` (este inerte
> até D5); testes `AnonimizacaoTest`, `AnonimizacaoPeloDonoTest`,
> `AnonimizarClienteComandoTest` e `AnonimizarInativosTest`. Decisão ainda
> aberta: D5. Resolve a decisão
> pendente 16 (`DECISOES-PENDENTES.md`) e a linha "Anonimização/LGPD:
> decisão pendente" de `MODELO-DE-DADOS.md`. Prazos e bases legais precisam
> de validação jurídica; este documento cobre o lado técnico.

## 1. Objetivo

Atender a um pedido de eliminação do cliente (e a uma futura retenção
automática) **sem apagar o histórico da agenda**. Os agendamentos, os
serviços, os preços, os eventos e as ocupações continuam existindo e
contando, mas deixam de apontar para uma pessoa identificável.

Por que anonimizar e não apagar: `clientes`, `enderecos_cliente`,
`agendamentos` e `agendamento_eventos` estão ligados por FKs `RESTRICT`, e o
histórico de agendamento encerrado é imutável por decisão de projeto. Apagar
o cliente exigiria apagar o histórico, que é justamente o que precisa ficar.

## 2. Inventário de dados pessoais no backend

| Tabela.coluna | Conteúdo | Na anonimização |
|---|---|---|
| `clientes.nome` | nome | `Cliente anonimizado` |
| `clientes.telefone` | telefone E.164 (único) | `NULL` (libera o número para cadastro futuro) |
| `clientes.observacoes` | texto livre operacional | `NULL` |
| `enderecos_cliente.logradouro` | endereço | `[anonimizado]` (a coluna é `NOT NULL`) |
| `enderecos_cliente.complemento`, `referencia` | endereço | `NULL` |
| `agendamentos.endereco_texto` | snapshot do endereço | `[anonimizado]` (o CHECK de domicílio exige ≥ 5 caracteres) |
| `agendamentos.observacao_cliente` | texto livre do cliente | `NULL` |
| `agendamentos.motivo_cancelamento` | texto livre (pode citar a pessoa) | `NULL` |
| `agendamentos.chave_idempotencia` **e** `hash_requisicao` | chave e hash do pedido (o hash cobre o telefone) | **os dois `NULL` juntos**, no mesmo UPDATE, respeitando `agendamentos_idempotencia_coerente` (`(chave IS NULL) = (hash IS NULL)`) |
| `agendamento_eventos.dados` | jsonb; hoje só `motivo` é texto livre | fica **só a lista fechada de chaves sem texto livre** (seção 3.2); `motivo` e qualquer outra chave saem |

**Fica como está**, porque não identifica a pessoa sozinho: horários,
profissional, serviços, preço, taxa e minutos de deslocamento, estado,
origem, modalidade, `regiao_id`/`regiao_nome` (região de atendimento, não
endereço) e ator/usuário dos eventos (identificam o **operador**, não o
cliente). Itens (`agendamento_itens`) e ocupações (`ocupacoes_agenda`) não
têm dado pessoal e não são tocados.

Sobre o `hash_requisicao`: um SHA-256 do pedido não é anonimização. O espaço
de telefones é pequeno e dá para reverter o hash por força bruta. Por isso
ele sai, sempre junto com a chave. Proposta para a etapa 2: calcular como
HMAC com uma chave secreta e limpar a idempotência 7 dias depois da criação,
que é o bastante para repetir a requisição (D6).

## 3. A operação: `cleison_anonimizar_cliente`

### 3.0 Ajustes da aprovação

1. Nenhum nome de papel fixo: o `GRANT EXECUTE` usa o papel de
   `config('database.connections.pgsql.username')`, com `quote_ident`, como
   na migration `2026_09_29_000200`.
2. **Autorização no banco:** a função recebe o `usuario_id` de quem pede e
   só executa se o usuário existir, estiver ativo e tiver papel
   `proprietario`. A checagem fica dentro da função, não só no PHP.
   Consequência: a retenção automática também roda em nome de um
   proprietário, configurado em `CLEISON_RETENCAO_RESPONSAVEL_ID`.
   **Limite (revisão de segurança):** isso barra bug e engano, não o papel
   da aplicação comprometido, que tem DML em `users` e poderia promover um
   usuário antes de chamar a função. Fechar isso exige que papel e ativo só
   mudem por função do dono (trigger em `users`); fica para a etapa 3,
   porque hoje o `cleison:criar-proprietario` grava com o papel da aplicação.
3. Comando `cleison:anonimizar-cliente` com prévia das contagens,
   confirmação digitando o id do cliente, `--forcar` sem terminal
   interativo e `--simular` (seção 6).


Uma **função SQL** `SECURITY DEFINER`, do papel dono, com
`SET search_path = pg_catalog, public`. É o **único** caminho que altera
dado pessoal de histórico encerrado. A aplicação a chama por dentro de
`TransacaoAuditada::executar(...)`: ator operador para pedido do titular,
ator sistema para retenção automática.

```
cleison_anonimizar_cliente(
  p_cliente_id bigint,
  p_usuario_id bigint,    -- quem pede: proprietário ativo (conferido na função)
  p_origem     varchar,   -- 'pedido_titular' | 'retencao'
  p_protocolo  varchar    -- obrigatório em pedido_titular; formato fechado (3.3)
) RETURNS bigint          -- id em anonimizacoes; NULL se o cliente já estava anonimizado
```

Passos, numa só transação:

0. **Autorização:** se `p_usuario_id` não for de um usuário existente,
   ativo e com papel `proprietario`, recusa com
   `[anonimizacao_exige_proprietario]` (422 pelo `ErroDeBanco`). Vale para
   as duas origens.
1. `SELECT ... FROM clientes WHERE id = p_cliente_id FOR UPDATE`. Se o
   cliente não existir, erro. **Se já estiver anonimizado, retorna `NULL`
   sem alterar nada e sem gravar outra linha** (idempotente, seção 3.4).
2. **Recusa** se o cliente tiver agendamento ativo (`solicitado`,
   `confirmado`, `em_atendimento`), com o erro
   `[clientes_anonimizacao_com_ativo]`, que vira 422 pelo `ErroDeBanco`. O
   operador cancela ou conclui primeiro: anonimizar alguém com horário
   marcado apagaria o contato no meio do atendimento.
3. Atualiza `clientes` (e `anonimizado_em = now()`), `enderecos_cliente`
   (e `arquivado_em = now()`), `agendamentos` e `agendamento_eventos`
   conforme a seção 2, contando as linhas afetadas de cada tabela.
4. Grava uma linha em `anonimizacoes` (3.3) com as contagens e devolve o id.

### 3.1 Esquema novo em `clientes`

`clientes.anonimizado_em timestamptz` e o CHECK
`clientes_anonimizado_coerente`: se `anonimizado_em` não for nulo, então
`telefone` e `observacoes` são nulos e `nome = 'Cliente anonimizado'`.
Assim ninguém "reanima" um cliente anonimizado pela aplicação.

### 3.2 Texto livre dentro do jsonb dos eventos

Tirar só `dados->'motivo'` deixaria passar qualquer chave de texto livre que
alguém acrescente ao trigger no futuro. Então a regra é uma **lista fechada
do que fica**, não do que sai. Uma função `IMMUTABLE`
`cleison_evento_dados_anonimos(jsonb) RETURNS jsonb` mantém só:

- no nível de cima: `inicio_servico`, `fim_servico`, `inicio_ocupado`,
  `fim_ocupado`, `profissional_id` e `origem` (valores de domínio, não
  texto livre);
- dentro de `de` e `para` (evento `remarcado`): as mesmas chaves de
  horário e `profissional_id`.

Tudo o mais sai, inclusive `motivo`. Um teste amarra a lista ao trigger: se
`cleison_registrar_evento_agendamento` ganhar uma chave nova, o teste falha
até alguém decidir se ela é texto livre.

### 3.3 Tabela `anonimizacoes`, sem nenhum dado pessoal

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | bigserial | |
| `cliente_id` | bigint, FK `RESTRICT`, **`UNIQUE`** | aponta para o cliente já anonimizado |
| `ocorrido_em` | timestamptz | `now()` |
| `origem` | varchar | `'pedido_titular'` ou `'retencao'` |
| `usuario_id` | bigint, FK `users`, `NOT NULL` | o proprietário em nome de quem a anonimização rodou (em `retencao`, o responsável configurado) |
| `protocolo` | text | obrigatório em `pedido_titular`; CHECK `^[A-Za-z0-9._/-]{1,40}$`, para que não caiba nome, telefone nem frase |
| `enderecos_afetados`, `agendamentos_afetados`, `eventos_afetados` | integer ≥ 0 | contagens do passo 3 (o cliente é sempre 1) |

Não há coluna de texto livre, e nada do que foi apagado fica guardado. A
tabela é só inserção, com o mesmo padrão já usado em `agendamento_eventos`:
trigger recusa UPDATE e DELETE, a aplicação só lê, e só a função insere.

### 3.4 Idempotência

- A função trava o cliente (`FOR UPDATE`): duas chamadas simultâneas
  entram em fila, e a segunda encontra `anonimizado_em` preenchido e
  retorna `NULL`.
- `anonimizacoes.cliente_id` é `UNIQUE`: mesmo que a checagem falhe por
  algum motivo, o banco recusa a segunda linha.
- Os valores anonimizados são fixos: rodar de novo não produz diferença.

### 3.5 Exceções nos triggers de imutabilidade

Hoje os triggers barram toda alteração, inclusive a anonimização. Cada um
ganha uma exceção estreita, e as duas condições são obrigatórias:

1. **`current_user` é o dono da tabela**:
   `current_user::regrole = (SELECT relowner FROM pg_class WHERE oid = TG_RELID)`.
   Dentro da função `SECURITY DEFINER`, `current_user` é o dono; fora dela
   é o papel da aplicação. **Nunca `session_user`** (é sempre quem
   conectou, inclusive dentro da função) **nem variável de sessão** (o
   papel da aplicação pode rodar `set_config` à vontade).
2. **A diferença entre `OLD` e `NEW` é exatamente a anonimização:**
   - `agendamentos_encerrado_imutavel`: `NEW` tem que ser igual a `OLD`
     com as colunas da seção 2 substituídas pelos valores anonimizados.
     Qualquer outra coluna diferente continua recusada, mesmo para o dono.
   - `agendamento_eventos_somente_insercao`: UPDATE só se `NEW` for igual
     a `OLD` com `dados = cleison_evento_dados_anonimos(OLD.dados)`.
     **DELETE continua proibido sempre.**

### 3.6 Privilégios

- `REVOKE ALL ON FUNCTION cleison_anonimizar_cliente(...) FROM PUBLIC`.
- `GRANT EXECUTE` **só** para o papel da aplicação, lido de
  `config('database.connections.pgsql.username')` e escrito com
  `quote_ident` (nada de nome fixo; muda por ambiente).
- `anonimizacoes`: `SELECT` para `cleison_app`; `INSERT` só pelo dono (via
  função), como em `agendamento_eventos` e `ocupacoes_agenda`; sequência
  fechada da mesma forma que em `2026_09_29_000200_fechar_sequencias_da_agenda`.

### 3.7 Cliente anonimizado fica fechado

- Só a função marca `anonimizado_em` (mesma regra do `current_user` dono),
  e depois disso nenhuma coluna do cliente muda, nem para o dono.
- Cliente anonimizado não recebe endereço novo, não tem endereço alterado
  e não recebe agendamento (nem por troca de `cliente_id`): quem voltar a
  agendar vira um cliente novo.

Os triggers de evento (`agendamentos_registrar_evento`) não disparam
remarcação nem mudança de estado com esses UPDATEs: nenhuma coluna de
horário, profissional ou estado muda. O registro fica em `anonimizacoes`.

## 4. O que a anonimização NÃO alcança (e o que fazer)

| Onde | Situação | Proposta |
|---|---|---|
| **Site atual (Netlify Blobs, loja `agenda`)** | ⚠️ **Guarda `nome` e `telefone` de cada reserva, em produção hoje** (`netlify/functions/agenda.mjs:185-186`). | **Fora desta missão; precisa de decisão do responsável** (registrado em "Ações humanas pendentes" do `backend/README.md`). Enquanto isso, um pedido de titular também precisa ser atendido lá, manualmente. |
| **Backups** | Contêm o dado original até expirarem. | Retenção de backup (decisão 22) com prazo definido. Em qualquer **restauração**, reaplicar a função para todo `cliente_id` de `anonimizacoes` antes de reabrir o sistema (roteiro no runbook de restauração). |
| **Logs do Laravel e terminal** | Já sem dado pessoal de erro de banco (`ErroDeBanco`; commits `a51da59`, `e85e6d8`, `fd8c9ea`); rotação diária, 14 dias (`LOG_DAILY_DAYS`). | Manter. Não logar payload de requisição. |
| **Logs do servidor web / proxy** | IP e URL de cada acesso. | Retenção curta (sugestão: 14 dias) na configuração do servidor. |
| **Sessões** (`sessions`, driver database) | IP e user-agent de **operadores**, não de clientes. | Expiram pelo `SESSION_LIFETIME`; nada a fazer aqui. |
| **WhatsApp e conversas fora do sistema** | Fora do alcance técnico. | Orientação operacional. |

**Risco residual de reidentificação:** horário, profissional, região e
serviços de um agendamento, somados ao que alguém já sabe da pessoa, podem
apontar quem foi atendido numa agenda pequena. A proposta aceita esse risco
em troca de manter a agenda e os números íntegros. A alternativa (reduzir
a precisão do horário ou da região no histórico anonimizado) quebraria
relatórios e conferências de ocupação (D4).

## 5. Retenção automática (D5 em aberto)

Comando agendado `cleison:anonimizar-inativos`, diário e com ator sistema,
em nome do proprietário configurado em `CLEISON_RETENCAO_RESPONSAVEL_ID`
(a função exige um proprietário ativo, 3.0).
Anonimiza clientes sem agendamento há **N meses**, sem agendamento ativo e
ainda não anonimizados. Usa a mesma função, com origem `retencao`, em lotes
pequenos (por exemplo, 100 por transação) para não segurar lock.

**N é um parâmetro configurável sem valor padrão**:
`CLEISON_RETENCAO_CLIENTE_INATIVO_MESES` (em `config/cleison.php`). Sem
valor, ou com valor que não seja um inteiro positivo, o comando **não
anonimiza ninguém**: sai com erro e registra no log "retenção não
configurada". O mesmo vale sem `CLEISON_RETENCAO_RESPONSAVEL_ID`. Nenhum
prazo é presumido no código.
**Decisão do responsável + jurídico.**

## 6. Pedido do titular (fluxo operacional)

1. O operador recebe o pedido, confirma a identidade (telefone cadastrado)
   e anota um protocolo.
2. Resolve os agendamentos ativos do cliente (cancela ou conclui).
3. Executa a anonimização pelo painel (etapa 3) ou, até lá, pelo comando
   `cleison:anonimizar-cliente {cliente} --usuario=<id do proprietário>
   --protocolo=<protocolo>`:
   - antes de executar, mostra quantos endereços, agendamentos e eventos
     serão afetados (só contagens, nenhum dado pessoal) e pede
     confirmação digitando o id do cliente;
   - sem terminal interativo, exige `--forcar`;
   - com `--simular`, só mostra as contagens e não altera nada.
4. Faz o mesmo no site atual enquanto ele estiver em produção (seção 4).
5. Responde ao titular. O prazo segue a orientação jurídica.

Exportar os dados do titular (acesso e portabilidade) fica fora desta fase.
Registro como item para a etapa 3.

## 7. Testes previstos

**Imutabilidade (o app não fura a exceção):**

- O papel da aplicação não consegue alterar nenhuma coluna de agendamento
  encerrado, nem as de dado pessoal indo para os valores anonimizados,
  **nem depois de `set_config` com qualquer nome de variável**: a exceção
  só vale com `current_user` dono.
- O papel da aplicação não consegue alterar nem apagar
  `agendamento_eventos`, nem com `dados` já no formato anonimizado.
- Mesmo dentro da função (dono), um UPDATE que mude qualquer coluna fora da
  lista é recusado: um caso por coluna (horário, profissional, estado,
  preço de item, taxa, `cliente_id`).
- Um papel sem o `GRANT` (criado no teste) recebe "permission denied" ao
  chamar a função: o `EXECUTE` não vem de `PUBLIC`.
- **Autorização:** barbeiro, recepção, proprietário inativo e id
  inexistente são recusados pela função (chamada direta em SQL, sem passar
  pelo PHP), e nada muda.
- Cliente anonimizado: não aceita endereço novo nem agendamento novo, e o
  papel da aplicação não consegue marcar `anonimizado_em` por conta própria.
- `anonimizacoes` recusa UPDATE e DELETE; `protocolo` recusa texto com
  espaço, `@` ou mais de 40 caracteres.

**Varredura (nada sobra):**

- Cria um cliente com nome, telefone, observações, endereço, agendamentos
  concluído, cancelado com motivo, `nao_compareceu` e em domicílio, e
  eventos de criação, remarcação e cancelamento. Depois de anonimizar,
  percorre **todas as colunas `text`, `varchar`, `char` e `jsonb`** do
  schema `public` (lista tirada de `information_schema.columns`, para
  cobrir colunas futuras) nas linhas do cliente, dos endereços, dos
  agendamentos, dos itens, dos eventos e de `anonimizacoes`. Procura o
  nome, o telefone (E.164 e só dígitos, com e sem DDI), o endereço, as
  observações e o motivo originais: **nenhuma ocorrência**.
- Lista fechada do jsonb: o teste compara as chaves que
  `cleison_registrar_evento_agendamento` grava com as que
  `cleison_evento_dados_anonimos` mantém.

**Integridade da agenda:**

- `ocupacoes_agenda`, `agendamento_itens` e todas as colunas que não são
  dado pessoal de `agendamentos` e `agendamento_eventos` ficam idênticas
  (fotografia antes e depois, campo a campo).
- A constraint de exclusão continua valendo: uma reserva nova no horário de
  um agendamento ativo de outro cliente ainda dá 23P01.
- Número de eventos inalterado (a anonimização não gera evento de
  agendamento).

**Idempotência e recusa:**

- Anonimizar duas vezes: a segunda devolve `NULL`, não muda nada e não cria
  outra linha em `anonimizacoes`.
- Duas chamadas simultâneas (duas conexões): uma anonimiza e a outra
  devolve `NULL`; `anonimizacoes` fica com uma linha.
- Cliente com agendamento ativo: recusa com 422 e nada muda.
- O telefone liberado pode ser usado por um cliente novo.
- `cleison:anonimizar-inativos` sem o parâmetro configurado não anonimiza
  ninguém e sai com erro.
- Comando `cleison:anonimizar-cliente`: prévia sem dado pessoal,
  confirmação errada não altera nada, sem interação exige `--forcar`,
  `--simular` não altera nada, e `--usuario` e `--protocolo` são exigidos.

## 8. Decisões para aprovação

| # | Pergunta | Proposta |
|---|---|---|
| D1 | Anonimizar (mantendo o histórico) em vez de apagar? | Sim. |
| D2 | Recusar a anonimização se o cliente tiver agendamento ativo? | Sim; o operador cancela ou conclui antes. |
| D3 | O que entra no lugar do dado? | `Cliente anonimizado` no nome; `[anonimizado]` em logradouro e `endereco_texto` (colunas `NOT NULL` ou com CHECK de tamanho); `NULL` no resto. `chave_idempotencia` e `hash_requisicao` viram `NULL` **juntos**, respeitando `agendamentos_idempotencia_coerente`. Em `agendamento_eventos.dados` fica só a lista fechada de chaves sem texto livre (3.2): `motivo` e qualquer outra chave saem. |
| D4 | Aceitar o risco residual de reidentificação (seção 4) sem reduzir a precisão de horário e região no histórico? | Sim. |
| D5 | Prazo de retenção de cliente inativo (N meses). | **Em aberto: decisão do responsável + jurídico.** Parâmetro configurável `CLEISON_RETENCAO_CLIENTE_INATIVO_MESES`, **sem valor padrão**; sem ele, o comando de retenção não anonimiza ninguém. |
| D6 | Idempotência na etapa 2: HMAC com chave secreta no `hash_requisicao` e limpeza 7 dias após a criação? | Sim. |
| D7 | Site atual (Netlify), que guarda nome e telefone de cada reserva em produção hoje. | Fora desta missão, com atendimento manual de pedidos enquanto isso. **Decisão do responsável**, registrada em "Ações humanas pendentes" (`backend/README.md`). |
| D8 | Escopo e garantias da implementação. | Implementar nesta etapa: função, esquema, triggers, privilégios, comando `cleison:anonimizar-cliente` e os testes da seção 7. O comando de retenção entra pronto, mas inerte até D5. A exceção nos triggers vale só com `current_user` dono da tabela (nunca `session_user` nem variável de sessão) e só para a troca exata de dado pessoal. `EXECUTE` da função só para o papel da aplicação lido de `config('database.connections.pgsql.username')` com `quote_ident`, revogado de `PUBLIC`. A função só executa para `usuario_id` de proprietário ativo (checado no banco). `anonimizacoes` sem nenhum dado pessoal (`cliente_id` único, quando, `usuario_id`, origem, protocolo de formato fechado e contagens). Idempotente: anonimizar de novo não quebra, não muda nada e não duplica registro. |
