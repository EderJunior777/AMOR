# Revisão técnica da etapa 1 — 24/09/2026

Escopo: revisar a fundação PHP/Laravel + PostgreSQL entregue na etapa 1,
corrigir defeitos comprovados dentro desse escopo e preparar um pacote
reproduzível. **Não é o sistema completo** e não está "pronto para produção":
não há API de negócio, login, painel, pagamentos nem implantação.

Ambiente de toda a verificação: Windows 11, PHP 8.4.25, Composer 2.10.3,
PostgreSQL 18.6 (binários EDB, cluster local em `127.0.0.1`), Node 24.21.0.

## 0. Como a revisão foi feita

- Leitura do código, migrations, configuração, testes e documentação.
- Três revisões independentes, **somente leitura**, cada uma com uma área
  (agentes ECC): modelagem PostgreSQL (`database-reviewer`), eficácia dos
  testes (`pr-test-analyzer`) e segurança da fundação (`security-reviewer`).
  Ninguém além de mim editou arquivos; consolidei, confirmei cada apontamento
  no código ou no banco e descartei o que não se sustentou (seção 5).
- Verificações executadas nesta rodada: seção 6.

Mapa das pastas: referência original = raiz do repositório (no ZIP,
`referencia-original/`); frontend de trabalho = `frontend/`; backend =
`backend/`; documentação = `docs/`; testes = `backend/tests/` +
`frontend/testes/`, banco `cleison_teste`.

## 1. Implementado e verificado (evidência desta rodada)

| Item | Evidência |
|---|---|
| Esquema: estabelecimento (singleton), usuários administrativos, profissionais, serviços, vínculo profissional×serviço, regiões, expediente semanal, exceções, clientes, endereços, agendamentos, itens (snapshot), histórico, bloqueios, ocupações | 5 migrations aplicadas em banco novo e reaplicadas (`MigracoesReversiveisTest`) |
| Sem sobreposição por profissional (mesmo horário, parcial, contido, deslocamento, bloqueio×reserva), adjacentes permitidos, profissionais diferentes permitidos | `OcupacaoAgendaTest`, `ConcorrenciaTest` (seção 3) |
| Intervalos inválidos, nulos, vazios e `infinity` recusados | `AgendamentoIntegridadeTest`, `OcupacaoAgendaTest` |
| Transação que falha não deixa ocupação órfã | `OcupacaoAgendaTest::test_transacao_que_falha...`, `ConcorrenciaTest::test_agendamento_sem_servico...` |
| Concorrência real entre processos | `ConcorrenciaTest` (7 testes, 3 execuções verdes) |
| Remarcação atômica (inclusive troca de profissional) | `OcupacaoAgendaTest` |
| Contrato de estados (30 pares banco × enum), estados iniciais | `ContratoDeEstadosTest`, `EstadoAgendamentoTest` |
| Agendamento encerrado e seus itens imutáveis; histórico só inserção | `AgendamentoIntegridadeTest` |
| Snapshot: reajuste/desativação do catálogo não altera itens | `CatalogoEExpedienteTest` |
| Dinheiro só em centavos inteiros; nenhum instante sem fuso | `EsquemaTest` (consulta o catálogo) |
| Papel da aplicação sem DDL (não desliga triggers, não trunca, não apaga constraints) | `PrivilegiosTest`; `migrate:fresh` como aplicação → `42501` |
| Fuso do estabelecimento validado | `CatalogoEExpedienteTest` |
| Seed só de demonstração, marcado, sem usuários, recusa produção e estabelecimento real | `DemonstracaoSeederTest` |
| Bootstrap do primeiro proprietário sem senha padrão | `CriarProprietarioTest` |
| Trava: testes só em PostgreSQL `*_teste` | Execução manual com `DB_DATABASE=cleison_guarda_inexistente` e com `DB_CONNECTION=sqlite`: ambos abortados antes de migrar |
| Correção da agenda legada (`frontend/`) e testes determinísticos | 51 verificações ok, 0 puladas; reprodução com o arquivo original: 9 falhas (seção 4) |
| Instalação a partir do pacote | ZIP extraído em pasta limpa + cluster novo criado pelo script → `composer install`, `composer migrar`, `db:seed`, 94/94, Pint, legado 51/23 |
| Dependências | `composer audit`: nenhum aviso; `npm audit` (frontend): 0 vulnerabilidades; `composer check-platform-reqs --lock`: ok |
| Saúde do cluster local após o incidente | seção 7 |

## 2. Implementado, mas não verificado

| Item | Motivo |
|---|---|
| Instruções para Linux/macOS (SQL dos papéis no `backend/README.md`) | Não há Linux/macOS nesta máquina. |
| Relações Eloquent de `Agendamento`/`AgendamentoItem`/`Cliente` | Nenhum código usa ainda; os testes vão direto ao SQL. Serão exercidas na etapa 2. |
| `servidor-local.mjs` (correção de caminho no Windows) | Verificado na rodada anterior (HTTP 200); o arquivo não mudou desde então; não repetido. |
| Comportamento na Netlify hospedada | Sem acesso à conta; só o servidor de blobs local foi exercitado. |
| Fórmula `SESSION_SECURE_COOKIE` em produção | Configuração lida por inspeção; não há rota com sessão para testar. |

## 3. Garantia contra sobreposição (PostgreSQL)

Migration `2026_09_24_000300_criar_clientes_e_agenda.php`:
`ocupacoes_agenda (profissional_id bigint NOT NULL, periodo tstzrange NOT NULL)`
com `EXCLUDE USING gist (profissional_id WITH =, periodo WITH &&)`.
`btree_gist` permite a igualdade de `bigint` no índice GiST junto com `&&`.

- Limites `[)`: 10:00–10:30 e 10:30–11:00 cabem (testado).
- `ocupacoes_periodo_fechado_aberto`: não vazio, `[)`, sem limite aberto,
  `isfinite()` nos limites. **Achado verificado no banco**: `upper_inf()` é
  falso para `'infinity'`; a checagem explícita foi acrescentada.
- Ativos: agendamento em solicitado/confirmado/em_atendimento/concluido;
  bloqueio com `cancelado_em` nulo. Triggers criam/removem a ocupação; FK
  composta com `ON UPDATE CASCADE` mantém período e profissional em sincronia;
  apagar/editar a ocupação "por fora" é recusado.
- **Cenários** (todos com a regra nomeada e o SQLSTATE conferidos):

| # | Cenário | Resultado |
|---|---|---|
| 1 | Mesmo profissional, mesmo horário | `23P01` |
| 2 | Sobreposição parcial | `23P01` |
| 3 | Intervalo dentro de outro | `23P01` |
| 4 | Profissionais diferentes, mesmo horário | aceito |
| 5 | Adjacentes (fim exclusivo) | aceito |
| 6 | Deslocamento sobre outro compromisso | `23P01` |
| 7 | Bloqueio sobre reserva e reserva sobre bloqueio | `23P01` (sequencial e com processos concorrentes) |
| 8 | Intervalo inválido | invertido: `22000` (coluna gerada `tstzrange`); igual: `23514`; `infinity`: `23514`; nulo: `23502` |
| 9 | Falha transacional | nenhuma ocupação, agendamento ou item fica gravado |
| 10 | Transações concorrentes | só uma persiste (abaixo) |

**Concorrência (processos PHP separados, uma conexão cada, papel da
aplicação).** Sincronização sem relógio: o teste segura
`pg_advisory_lock(portão)`, cada filho bloqueia em
`pg_advisory_lock_shared(portão)`, o teste confere em `pg_locks` que todos
estão esperando e só então solta. Ao final confere o que ficou **gravado**
(uma ocupação, nenhum item órfão, nenhuma sobreposição entre linhas, todo
ativo com ocupação, nenhum inativo com ocupação).

- Com a fila por profissional: 10 processos → 1 grava, 9 recebem `23P01`,
  0 repetições por deadlock.
- **Sem a fila** (desligada pelo papel dono só neste teste): 8 processos →
  **1 grava**; os 7 perdedores repetiram 32 vezes por `40P01` e terminaram em
  `40P01`, sem gravar nada. Prova que a constraint de exclusão sozinha já
  impede reserva dupla; a fila existe para trocar deadlock por espera curta.
- Duas conexões: a segunda espera a primeira (`55P03` com `lock_timeout`),
  e após o COMMIT recebe `23P01`. Se a primeira desiste, a segunda consegue.
- Bloqueio + 5 agendamentos concorrentes sobre o mesmo período: só 1 grava.
- Encostados e profissionais diferentes em paralelo: todos gravam.

**Limites:** a garantia vale para quem grava pelo banco com o papel da
aplicação; o papel dono (migrations) pode desligar triggers — por isso ele
não é usado em runtime. Validações de expediente, antecedência, horizonte e
grade são da aplicação (etapa 2) e **não** estão implementadas.

## 4. As sete falhas da agenda legada

1. **Componente**: `BlobsServer` do `@netlify/blobs` 11.1.0
   (`dist/server.js`), servidor **local** de desenvolvimento, **só no
   Windows**: `getLocalPaths()` aplica `encodeName()` → `encodeURIComponent`
   (`14:30` vira o arquivo `14%3A30`); `walk()`/`listBlobs()` devolvem o nome
   do arquivo como `key` sem `decodeName()`.
2. **Contrato esperado**: `list()` devolve as mesmas chaves passadas a
   `set()`; `get(key)`/`delete(key)` aceitam essas chaves.
3. **Onde**: no servidor local. O código da aplicação só "falha" porque
   confia no contrato. No Linux `encodeName` é identidade; a Netlify
   hospedada não usa esse servidor.
4. **Evidência**: leitura do SDK (funções citadas); reprodução: a listagem
   pública devolvia `14%3A30`; o `get` seguinte recodifica (`%253A`) e não
   acha nada → painel vazio e `liberar` com 404 → horário continua preso →
   409 ao reservar de novo. Nesta revisão: os testes endurecidos de
   `frontend/` rodando com o `agenda.mjs` original dão **9** falhas; com o
   corrigido, **0**.
5. **Arquivos alterados** (só em `frontend/`): `netlify/functions/agenda.mjs`
   (`chaveListada()` e, nesta revisão, relógio injetável),
   `testes/agenda.test.mjs` (regressões e relógio fixo),
   `testes/servidor-local.mjs`, `assets/admin.js`.
6. **Sem decodificação dupla**: a chave do `list()` é decodificada **uma**
   vez antes de `get`/`delete`; chaves válidas só têm dígitos, `-`, `_` e `:`
   (validados por `ehDia`/`ehHora`), nunca `%`, então no Linux/Netlify o
   `decodeURIComponent` não muda nada; sequência malformada devolve a chave
   crua. O **formato gravado não mudou** (a correção antiga do commit
   `0ddedb8`, que trocava `:` por `-`, deixaria invisíveis reservas já
   gravadas).
7. **Testes que reproduzem**: "horários ocupados vêm no formato HH:MM",
   "14:30 e 15:00 ficaram ocupados", "painel lista um item por agendamento",
   "painel mostra o endereço", "liberou os 2 blocos do combo", "14:30 e 15:00
   voltaram a ficar livres" (antes passava por acaso) e "outro cliente
   conseguiu pegar o horário liberado".
8. **Validado localmente**: reserva, listagem pública, listagem com PIN,
   liberação, nova reserva após liberar, dado pessoal ausente na listagem
   pública, corrida de 12 reservas. **Não validado na Netlify hospedada.**

**Testes determinísticos**: o caso "08:00 de hoje" só rodava depois das 08:00
em São Paulo e as datas usavam UTC. Agora a função tem um relógio injetável
(`_definirRelogioParaTestes`, usado só pelos testes; em produção é o relógio
real) e a suíte fixa `2026-09-24T09:00-03:00`. Novos casos: horário passado,
antecedência mínima (09:00 recusado, 09:30 aceito), às 22:00 de SP o
horizonte é contado do dia de SP (não do UTC), e depois da meia-noite o dia
anterior vira passado. Resultado: 51 ok, 0 falhas, **0 puladas**. A cópia
original na raiz continua com o caso dependente do horário (preservada).

## 5. Problemas encontrados e corrigidos nesta revisão

| # | Problema (origem) | Causa | Correção | Validação |
|---|---|---|---|---|
| 1 | Papel da aplicação era dono das tabelas: podia `DISABLE TRIGGER`, `TRUNCATE`, apagar constraints (segurança) | Um papel só no script e no `.env` | Papéis `cleison` (dono, só migrations via conexão `pgsql_migracao` / `composer migrar`) e `cleison_app` (DML via `ALTER DEFAULT PRIVILEGES`); testes migram como dono e rodam como aplicação | `PrivilegiosTest` (8 tentativas → `42501`); instalação do zero pelo script |
| 2 | Encerrado ainda aceitava mudar taxa, região, endereço, modalidade, origem... (banco) | Imutabilidade cobria só horário/profissional/cliente | Encerrado: nenhuma coluna muda (comparação da linha inteira) | `AgendamentoIntegridadeTest` |
| 3 | Itens de agendamento encerrado editáveis/apagáveis (banco) | Sem trigger nos itens | `agendamento_itens_encerrado_imutavel`; `concluido` deixou de ser estado inicial (espontâneo: nasce `em_atendimento`, conclui na mesma transação) | Testes de imutabilidade e do fluxo espontâneo; contrato 30 pares |
| 4 | `'infinity'` passava pela checagem da ocupação (banco, confirmado por mim) | `upper_inf()` é falso para `'infinity'` | `isfinite()` em agendamentos, bloqueios e ocupações | Testes com `infinity`/`-infinity` |
| 5 | Nada ligava `deslocamento_minutos` ao período ocupado (revisão própria) | CHECK ausente | `agendamentos_deslocamento_reservado` (substitui `ocupado_contem_servico`, que ficou redundante) | Teste com ida curta recusada e arredondamento aceito |
| 6 | Fuso do estabelecimento aceitava qualquer texto (revisão própria) | CHECK ausente | Trigger `estabelecimento_fuso_valido` | Teste com fuso inexistente |
| 7 | Senha do papel no argv do `psql` no script (segurança) | SQL com senha via `-c` | SQL pelo stdin (`-f -`) | `criar` executado num cluster descartável |
| 8 | Cookie de sessão sem `Secure` por padrão em produção (segurança) | `env()` sem padrão | Padrão `true` quando `APP_ENV=production`; `APP_DEBUG=false` no exemplo | Inspeção |
| 9 | SQLSTATE opcional nos testes negativos (testes) | Helper só conferia se passado | `assertBancoRecusa` sempre confere o SQLSTATE, deduzido de `pg_constraint` | Suíte |
| 10 | Concorrência sincronizada por relógio de parede (testes) | Barreira `microtime + 3s` | Portão por advisory lock conferido em `pg_locks` | 3 execuções |
| 11 | Exclusão não provada isoladamente da fila (testes) | Faltava cenário | Teste com a fila desligada pelo dono | Seção 3 |
| 12 | Faltavam: invertido, bloqueio×agendamento concorrente, falha transacional explícita, invariantes do gravado (testes) | Cobertura | Testes novos | Suíte |
| 13 | Teste legado dependente da hora, datas em UTC (pedido) | `Date.now()`/`toISOString` | Relógio injetável + instante fixo | 51/0/0 |
| 14 | Documentação chamava o incidente de "erro conhecido do Windows (ASLR)" e ensinava a apagar `postmaster.pid` | Conclusão sem prova | Relato separado em fatos/hipótese (seção 7); procedimento responsável no `backend/README.md` | Revisão do texto |

**Impacto das mudanças de migration**: as migrations só existiam em bancos
locais. O banco de teste é recriado pelos testes; o de desenvolvimento
(só dados de demonstração, conferido antes: 0 usuários, 0 clientes, 0
agendamentos) foi recriado com `migrate:fresh` como dono e semeado de novo.

**Apontamentos descartados ou não aplicados**: ordem de disparo dos
triggers `AFTER` quando estado e período mudam juntos (especulativo; a
análise não mostrou estado final errado e os testes de cancelamento/remarcação
passam); cache de `servicoPadrao` nos testes (o próprio revisor concluiu que
está correto); teste de sobreposição de 1 segundo (o banco só aceita minutos
cheios); cancelamento concorrente com reserva (não feito; fica para a etapa 2
junto com a API).

## 6. Testes e verificações executados nesta rodada

| Comando | Resultado |
|---|---|
| `php artisan test` (repositório) | **94 testes, 468 asserções, 0 falhas, 0 pulados** |
| `vendor/bin/phpunit --filter ConcorrenciaTest` × 3 | 7 testes / 108 asserções cada; 3 verdes (3,6 s a 42 s — o caso sem fila espera o `deadlock_timeout`) |
| Pacote: extração limpa → `postgres-local.ps1 criar` (cluster novo, porta 54339) → `composer install` → `composer migrar` → `db:seed` → `php artisan test` → `pint --test` | Tudo ok; **94/94, 468 asserções** |
| `node testes/agenda.test.mjs` (`frontend/`, 2× no repositório e 1× no pacote) | **51 verificações ok, 0 falhas, 0 puladas** |
| `node testes/cliente.test.mjs` (`frontend/` e pacote) | 23 verificações ok |
| `node testes/agenda.test.mjs` (referência original) | 36 ok, **7 falhas**, 1 caso pulado (antes das 08:00) — preservado |
| Reprodução: testes de `frontend/` + `agenda.mjs` original | 9 falhas (com o corrigido: 0) |
| `vendor/bin/pint --test` | ok |
| `composer validate`, `check-platform-reqs --lock`, `why-not php 8.3.99` | válido; plataforma ok; lock exige PHP ≥ 8.4, coerente com `^8.4` |
| `composer audit --locked` | "No security vulnerability advisories found" |
| `npm audit` (frontend) | 0 vulnerabilidades |
| Trava de banco de teste (banco errado / SQLite) | ambos abortados |
| `migrate:fresh` como aplicação (banco de teste) | `42501 must be owner`; 20 tabelas intactas |
| Saúde do cluster (seção 7) | ok |

Contagens: "testes" e "asserções" são do PHPUnit; "verificações" são as
linhas `ok` das suítes Node. Não somar as categorias.

**Não executado**: HawkScan (ferramenta e chave indisponíveis — nenhuma
varredura DAST foi feita); testes em Linux/macOS/Docker; qualquer coisa na
Netlify hospedada; testes de navegador (não há interface nova).

## 7. Incidente do PostgreSQL local (rodada anterior)

**Sintomas observados**: conexões novas (`psql`, `php artisan test`) ficavam
paradas; `pg_ctl stop -m fast` e `-m immediate` não concluíam.

**Mensagens registradas** (`.ferramentas/pgdata.log`):
- 03:49:57 servidor iniciado pelo `postgres-local.ps1 iniciar`, executado
  **dentro de um pipeline do PowerShell** (`| Select-Object`) que não
  retornava (o `postgres.exe` herdou o pipe) e que foi **encerrado à força**
  por mim logo depois (horário exato do encerramento não registrado).
- 03:51:58 `autovacuum worker (PID 38656) was terminated by exception
  0xC0000142` → `terminating any other active server processes` →
  `all server processes terminated; reinitializing`.
- 03:51:59 a 04:09:39: 30× `could not reserve shared memory region
  (addr=...) for child ...: error code 487`.
- Laravel registrou uma vez `SQLSTATE[08006] ... server closed the
  connection unexpectedly` (sem dados pessoais).

**Significado dos códigos**: `0xC0000142` = `STATUS_DLL_INIT_FAILED` (um
processo filho não conseguiu inicializar); erro Windows 487 =
`ERROR_INVALID_ADDRESS`. No Windows, cada processo filho do PostgreSQL
precisa reservar a memória compartilhada no mesmo endereço do postmaster;
após a reinicialização, essa reserva falhou em todos os filhos.

**Hipótese (não confirmada)**: o encerramento forçado do pipeline/console
que iniciou o servidor, cerca de dois minutos antes, deixou o postmaster num
contexto em que novos processos filhos não inicializam. A coincidência de
horário apoia, mas **não prova**. O relatório anterior chamou isso de "erro
conhecido do Windows (ASLR)" sem fundamento verificado; a afirmação foi
retirada. Não foi reproduzido de propósito (seria destrutivo).

**Procedimento executado na rodada anterior**: encerrei as tarefas
travadas; `pg_ctl stop -m fast` e `-m immediate` expiraram; identifiquei os
dois `postgres.exe` pelo comando (`-D ...\.ferramentas\pgdata`, porta 54329)
e os encerrei com `Stop-Process`; **apaguei o `postmaster.pid` sem verificar
se era necessário** (o PostgreSQL costuma reaproveitar arquivo residual);
iniciei de novo. Nenhum arquivo de WAL foi tocado; `pg_resetwal` não foi usado.

**Evidências de recuperação**: log 04:09:58–04:10:00: `database system was
not properly shut down; automatic recovery in progress`, `redo starts at
0/4C38A90`, `redo done at 0/4C38A90` (nada a refazer), `ready to accept
connections`; nenhum 487 depois disso. Nesta revisão, **sem reiniciar**:
`pg_is_in_recovery() = false`, `data_checksums = on`, `checksum_failures = 0`
nos dois bancos, `pg_amcheck --heapallindexed --parent-check` com saída 0 e
nenhum problema em `cleison_dev` e `cleison_teste` (extensão `amcheck`
instalada só para isso e removida). **Alcance**: heap e índices btree; não
afirmo cobertura dos índices GiST. O banco de desenvolvimento foi depois
recriado por causa das mudanças de migration.

**Prevenção**: o script avisa para não encadear a saída de `iniciar` em
pipe e para usar um terminal que continue aberto; o `backend/README.md` traz o
procedimento de recuperação sem apagar `postmaster.pid` às cegas.

## 8. Problemas restantes

| Problema | Impacto | Próxima ação |
|---|---|---|
| Validações de negócio (data real, passado, antecedência, horizonte, expediente, grade, serviço/profissional/região ativos) não existem no backend | Um cliente da futura API poderia gravar fora do expediente se a etapa 2 não as implementar | Etapa 2, com os testes listados em `ETAPAS.md` |
| Idempotência só tem colunas | Retry ainda não devolve a mesma reserva | Etapa 2 |
| Linux/macOS/Docker não verificados | Instalação fora do Windows sem prova | Rodar o `backend/README.md` num Linux antes da etapa 6 |
| Causa do incidente não confirmada | Pode repetir no ambiente local Windows | Seguir o procedimento; se repetir, coletar log antes de agir |
| `ConcorrenciaTest` pode levar ~45 s | Suíte lenta às vezes | Aceito: é o custo de provar a exclusão sem a fila |
| Divergências do legado não corrigidas (datas inexistentes, sem idempotência, PIN na URL, `detalhe` de erro público, histórico limitado a 20) | Continuam no site em produção | Resolvidas pela migração; ver `DIAGNOSTICO-BASE.md` |
| Dados reais nos Blobs desconhecidos | Migração pode precisar de importador | Etapa 6, com acesso à conta |
| Papéis em hospedagem real | Alguns PostgreSQL gerenciados limitam papéis | Verificar antes de contratar (decisão 29) |
| Resíduos do esqueleto Laravel (`composer setup`, Vite, `welcome.blade.php`) | Nenhum (não usados), mas confundem | Remover quando a etapa 2 definir como o Laravel serve o front |
| `.gitignore` da raiz alterado (+ `.ferramentas/`, `entregas/`) | Única mudança na referência original | Aceito e registrado |

## 9. Dependências do negócio

Ver `DECISOES-PENDENTES.md` (itens 1–29), em especial: marca e dados reais;
o que conta como corte; expiração de solicitações; cancelamento pelo cliente;
correção de atendimento encerrado; falta marcada por engano; folgas/férias
como bloqueio; arredondamento do deslocamento; canal de verificação do
cliente; hospedagem com dois papéis de banco.

## 10. Situação

Pronta para **revisão externa** da fundação, com as pendências acima
declaradas. A etapa 2 não foi iniciada.

---

## 11. Correções pós-revisão externa (revisão 2 — 24/09/2026)

A revisão externa do pacote `cleison-etapa1-revisao-2026-09-24.zip` apontou,
por inspeção estática, dois achados. Os dois foram **confirmados** por
reprodução em alvo descartável antes de qualquer correção.

### 11.1 Achado A — a trava não cobria o alvo das migrations

**Confirmado.**

- **Causa**: `tests/TestCase.php` checava só a conexão padrão, e pelo
  campo **bruto** `database`. As migrations dos testes rodam em
  `pgsql_migracao`, e o Laravel aplica `url` por cima dos campos separados
  (`ConfigurationUrlParser::parseConfiguration`, `array_merge`).
- **Reprodução** (alvo: isca `cleison_isca_teste`, criada para isso, sem
  marca e com uma tabela `sentinela`): `DB_MIGRACAO_URL` apontando para a
  isca + `vendor/bin/phpunit --filter EsquemaTest` → a trava aprovou, os 4
  testes passaram e o `migrate:fresh` do `RefreshDatabase` **criou as 20
  tabelas do projeto na isca**. A sentinela só sobreviveu porque o
  `FreshCommand` do Laravel executa o `db:wipe` apenas quando o alvo já tem a
  tabela `migrations` (conferido no código); numa segunda execução a isca
  teria sido apagada. A isca foi recriada. A senha não apareceu na saída.
- **Impacto**: com uma variável de ambiente ou URL errada, `migrate:fresh`,
  `migrate:reset` e os `TRUNCATE`/`ALTER TABLE` dos testes podiam atingir
  outro banco, inclusive um valioso.
- **Primeira tentativa de correção, insuficiente**: um listener de
  `CommandStarting`. O teste com a isca mostrou que ele **não dispara** nos
  testes: o `Kernel` do Laravel só liga os eventos de console quando
  `! runningUnitTests()`, isto é, nunca com `APP_ENV=testing` (conferido em
  `Illuminate/Foundation/Console/Kernel.php`). Esse teste também migrou a
  isca, que foi recriada. A abordagem foi descartada.
- **Solução adotada**:
  - `app/Support/AlvoDescartavel.php`: verifica cada conexão **efetiva**
    (driver resolvido após a `url`) por consulta **somente leitura** ao
    servidor: `current_database()` terminando em `_teste`; **marca** no
    `COMMENT` do banco (`cleison:descartavel:<32 hex>`, posta pelo script
    só no `cleison_teste`); mesma marca, OID, endereço e porta em todas as
    conexões verificadas. Falha de conexão ou configuração aborta, sem
    alternativa; as mensagens citam só o nome da conexão, o do banco e o
    SQLSTATE (nunca URL, usuário ou senha).
  - `tests/TestCase.php`: antes de qualquer teste, recusa configuração em
    cache, `APP_ENV` diferente de `testing`, e exige as **duas** conexões
    (`pgsql` e `pgsql_migracao`) no mesmo banco descartável, com usuários
    **diferentes**. `conexaoDono()` revalida antes de todo `TRUNCATE`/`ALTER`.
  - `app/Console/Protegidos/*` + `AppServiceProvider` (`$app->extend`):
    `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`
    e `db:wipe` passam a exigir o alvo descartável **dentro do próprio
    `handle()`**, em qualquer ambiente, sem depender de eventos.
  - `scripts/postgres-local.ps1 criar`: marca o `cleison_teste` e cria a isca.
- **Operações destrutivas usadas pelos testes e a conexão de cada uma**:

| Operação | Onde | Conexão | Proteção |
|---|---|---|---|
| `migrate:fresh` | `BancoDeTeste` (RefreshDatabase) | `pgsql_migracao` | `TestCase` + comando protegido |
| `migrate`, `migrate:reset` | `MigracoesReversiveisTest` | `pgsql_migracao` | `TestCase` + comando protegido (reset) |
| `migrate:rollback --step=1`, `migrate` | `MigracaoVinculoDosItensTest` | `pgsql_migracao` | `TestCase` + comando protegido |
| `TRUNCATE`, `ALTER TABLE ... DISABLE/ENABLE TRIGGER` | `ConcorrenciaTest`, `ItensDoAgendamentoTest`, `MigracaoVinculoDosItensTest` | `pgsql_migracao` | `conexaoDono()` revalida a cada uso |
| `CREATE/DROP TABLE sentinela_trava` | `TravaDeAlvoTest` | `pgsql_migracao` | `conexaoDono()` |
| Processos filhos da concorrência | `ConcorrenciaTest` | `pgsql` (aplicação) | mesma configuração validada no `TestCase` |

- **Regressão** (`tests/Feature/Banco/TravaDeAlvoTest.php`, 16 testes). A prova
  de "não executou" é a isca continuar com exatamente 1 tabela (`sentinela`,
  1 linha) e sem `migrations`, e a tabela `sentinela_trava` do banco de teste
  continuar existindo:
  - mesmo banco marcado, papéis diferentes → aceito;
  - migrations em outro banco; URL das migrations sobrescrevendo o banco
    declarado; URL da aplicação sobrescrevendo a configuração; driver
    `sqlite`; conexão desconhecida, lista vazia, usuário ausente; falha de
    conexão (porta 1); banco sem sufixo `_teste` → recusados;
  - os 5 comandos destrutivos chamados contra a isca → recusados, isca intacta;
  - processos isolados: `php artisan migrate:fresh` com `DB_MIGRACAO_URL`
    herdada para a isca; `phpunit` com `DB_DATABASE`, `DB_URL` ou
    `DB_MIGRACAO_URL` herdados para a isca; com o mesmo usuário nas duas
    conexões; com `APP_ENV=local`; com configuração em cache
    (`APP_CONFIG_CACHE`, arquivo temporário apagado ao final) → todos
    abortados, com a mensagem esperada e sem senha na saída.
- **Resultado observado**: 16/16. A reprodução original (`DB_MIGRACAO_URL` →
  isca) agora aborta com "Conexao pgsql_migracao: o banco cleison_isca_teste
  nao tem a marca de descartavel", e a isca fica intacta.

### 11.2 Achado B — transferência de item não revalidava a origem

**Confirmado.**

- **Causa**: `cleison_conferir_itens_agendamento()` escolhia um único
  agendamento a conferir: no UPDATE de item, só o de destino (`NEW`).
- **Reprodução** (COMMIT real, `cleison_teste`, papel da aplicação): A e B
  confirmados com 1 item cada; numa transação, B ajustado para 60 min e o
  item de A movido para B; COMMIT aceito. **Resultado persistido: A
  `confirmado`, 0 itens, ocupando a agenda**; B com 2 itens e 60 min.
- **Impacto**: um agendamento ativo sem serviço (sem preço, sem o que contar
  como corte) ocupando horário.
- **Decisão**: **opção A — o vínculo do item é imutável**. Remarcar não
  transfere itens (muda o horário do mesmo agendamento); não há necessidade
  concreta de transferência. Consequência para a API: não existe "mover
  item"; mudar o serviço de um agendamento aberto é alterar, apagar ou
  incluir itens dele.
- **Solução**: migration **incremental**
  `2026_09_25_000100_proteger_vinculo_dos_itens.php`:
  1. diagnóstico prévio: se já houver agendamento sem item ou com soma de
     durações divergente, **aborta sem alterar nada** e lista os ids (não
     apaga nem inventa serviço);
  2. a conferência adiada passa a revalidar **origem e destino** em todo
     UPDATE de item (segunda camada);
  3. trigger `agendamento_itens_vinculo_imutavel` (BEFORE UPDATE OF
     `agendamento_id`) recusa a transferência.
  - `down()` remove o trigger e restaura a função anterior (reabre a lacuna;
    só em banco descartável — o comando de rollback é protegido).
- **Regressão**:
  - `ItensDoAgendamentoTest` (9 testes, COMMIT real; após cada recusa, confere
    que agendamentos, itens e ocupações ficaram idênticos): mover o único item
    (B ajustado) e mover um entre vários → recusados no comando; **com a
    trava desligada pelo dono, a conferência adiada recusa no COMMIT
    apontando o agendamento de origem**; alterar item no mesmo agendamento →
    aceito; apagar o único item e duração inconsistente → recusados no
    COMMIT; encerrado protegido; criação na mesma transação e remarcação sem
    transferir → aceitas.
  - `MigracaoVinculoDosItensTest` (compatibilidade com banco que já tem
    dados): desfaz a migration, grava dados, reproduz a lacuna com a função
    antiga, e a migration **aborta** citando o id do agendamento
    inconsistente, sem registrar-se, sem mudar schema e sem apagar dados;
    após a correção manual (item devolvido), aplica e preserva as contagens
    de agendamentos, itens, eventos e ocupações; a transferência passa a ser
    recusada.
- **Resultado observado**: 10/10.

### 11.3 Migrations e bancos existentes

- Bancos com o schema aplicado: `cleison_dev` (só dados de demonstração) e
  `cleison_teste` (descartável). A correção é uma migration **nova**; nada
  foi editado nas migrations anteriores.
- `cleison_dev`: `composer migrar` aplicou só a migration nova, sem `fresh`.
  Antes/depois: 20 tabelas, 6 serviços, 5 regiões, 7 expedientes, 0
  agendamentos; `migrations` de 5 para 6; trigger novo presente.
- **Desvio de processo, registrado**: para demonstrar a trava, rodei uma vez
  o `migrate:fresh` (via `pgsql_migracao`) contra o `cleison_dev`. A trava
  recusou ("cleison_dev nao e de teste") e as contagens ficaram idênticas,
  mas executar um comando destrutivo como teste contra um alvo que não é
  comprovadamente descartável contraria a regra desta rodada. Não foi
  repetido; os testes automatizados usam só o banco de teste e a isca.
- Instalação do zero: ver seção 11.5.

### 11.4 Testes da revisão 2

| Comando | Resultado |
|---|---|
| `vendor/bin/phpunit --filter 'TravaDeAlvoTest\|ItensDoAgendamentoTest\|MigracaoVinculoDosItensTest'` | 26 testes; a 1ª rodada revelou o furo do listener (acima); versão final: 26/26 |
| `php artisan test` (repositório, suíte completa) | **120 testes, 656 asserções, 0 falhas, 0 pulados** |
| `vendor/bin/pint --test` | ok |

O frontend não foi alterado nesta rodada; seus testes não foram repetidos.
