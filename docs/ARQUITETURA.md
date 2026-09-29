# Arquitetura — CLEISON

> Estado: **etapa 1 (fundação PHP/SQL), revisada**. Não é um sistema
> concluído: não há API de negócio, login, painel nem integração com o site.
> O site em produção continua sendo o original (Netlify).

## 1. Estrutura do repositório

| Caminho | O que é | Pode editar? |
|---|---|---|
| raiz (`index.html`, `assets/`, `netlify/`, `testes/`, `sw.js`, `README.md`...) | **Referência original** (conteúdo do ZIP). É o que a Netlify publica hoje. | Não. Único arquivo alterado: `.gitignore` (+ `.ferramentas/`). |
| `frontend/` | **Cópia de trabalho** do site, com as correções da etapa 1. | Sim |
| `backend/` | Laravel 13 + PostgreSQL 18. | Sim |
| `docs/` | Esta documentação. | Sim |
| `scripts/postgres-local.ps1` | PostgreSQL local descartável (Windows, sem admin). | Sim |
| `.ferramentas/` | Binários do PostgreSQL, cluster local, credenciais geradas. **Ignorado pelo git e fora do pacote.** | — |
| `backend/tests/` + banco `cleison_teste` | Ambiente de testes: só roda num banco PostgreSQL **marcado como descartável** (`COMMENT` com `cleison:descartavel:<uuid>`), conferido nas duas conexões (aplicação e migrations). A isca `cleison_isca_teste` serve para testar a trava. | Sim |

## 2. Versões (verificadas em 24/09/2026)

| Componente | Versão | Onde está fixada |
|---|---|---|
| PHP | 8.4.25 testado; exigido `^8.4` | `composer.json` (`require.php`, `config.platform.php = 8.4.0`); o lock também exige ≥ 8.4 (Symfony 8). `check-platform-reqs --lock` passou. |
| Laravel / PHPUnit / Pint | 13.33.0 / 12.5.35 / 1.32.1 | `composer.lock` |
| PostgreSQL | 18.6 (EDB, SHA-256 fixado) | `scripts/postgres-local.ps1` |
| Node / `@netlify/blobs` | 24.21.0 / 11.1.0 | `frontend/package-lock.json` |

Ambiente testado: **somente Windows 11**. Linux, macOS e Docker não foram testados.

## 3. Decisões principais

1. **Mesma origem** na primeira implantação (etapas 2–3): Laravel servindo
   `frontend/`, `/api/*` e `/admin`. Até lá, **nenhum redirecionamento** do
   site original para o backend.
2. **Uma só fonte de gravação** na migração (Blobs → PostgreSQL), com
   congelamento da escrita antiga (etapa 6).
3. **Banco como fonte de verdade** de preço, duração, disponibilidade e
   reservas.
4. **Dinheiro em centavos (`integer`, `CHECK >= 0`)**; um teste falha se
   aparecer coluna `real`/`double`/`numeric`/`money`.
5. **Instantes em `timestamptz`**, sessão do banco e app em UTC; fuso do
   negócio em `estabelecimento.fuso_horario` (validado por trigger contra os
   fusos que o PostgreSQL conhece). Expediente em hora de parede (`time`).
6. **Um estabelecimento** (`CHECK (id = 1)`), **N profissionais**. As
   demais tabelas não referenciam o estabelecimento (não há multiempresa).
7. **Dois papéis no banco**: `cleison` (dono do schema, só migrations) e
   `cleison_app` (runtime, só DML). Detalhes na seção 6.
8. **Segredos fora do código**; nenhuma senha padrão; seed sem usuários.

## 4. Agenda: como o PostgreSQL impede sobreposição

```
agendamentos ──(trigger: estado ocupa agenda?)──► ocupacoes_agenda ◄──(trigger: cancelado_em nulo?)── bloqueios_agenda
                                                     │
                     EXCLUDE USING gist (profissional_id WITH =, periodo WITH &&)
```

Migration: `backend/database/migrations/2026_09_24_000300_criar_clientes_e_agenda.php`.

- **Tipo**: `tstzrange` com limites `[início, fim)` (fechado-aberto): 10:00–10:30
  e 10:30–11:00 encaixam. `btree_gist` permite combinar igualdade de
  `bigint` (profissional) com sobreposição de range no mesmo índice GiST.
- **Nulos não contornam**: `profissional_id` e `periodo` são `NOT NULL` na
  ocupação; os quatro instantes do agendamento e os dois do bloqueio também.
- **Vazios/ilimitados/infinitos recusados**: `ocupacoes_periodo_fechado_aberto`
  exige não vazio, `[)`, sem limite aberto **e** `isfinite()` nos dois
  limites (`upper_inf()` é falso para `'infinity'`, verificado). O agendamento
  e o bloqueio também têm `*_instantes_finitos`. Intervalo invertido é
  recusado pela própria coluna gerada `tstzrange()` (SQLSTATE 22000); fim =
  início, pelo CHECK de intervalo.
- **Estados**: ocupam agenda `solicitado`, `confirmado`, `em_atendimento`,
  `concluido`; liberam `cancelado`, `nao_compareceu`. Bloqueio ocupa enquanto
  `cancelado_em` é nulo. A linha de ocupação é criada/removida por trigger.
- **Deslocamento**: o agendamento guarda o serviço e o período ocupado;
  `agendamentos_deslocamento_reservado` exige que ida e volta declaradas
  caibam no período ocupado (pode sobrar, pelo arredondamento à grade) — isso
  também garante que o ocupado **contém** o serviço.
- **Remarcação atômica**: FK composta `(agendamento_id, profissional_id,
  periodo)` → `agendamentos (id, profissional_id, periodo_ocupado)` com
  `ON UPDATE CASCADE`. Remarcar é um `UPDATE`; se o destino estiver ocupado,
  tudo volta atrás (testado, inclusive troca de profissional).
- **Ocupação protegida**: não se apaga a de um compromisso ativo
  (`ocupacoes_protegidas`) nem se edita divergindo do pai (FK composta).
- **Fila por profissional**: trigger `BEFORE INSERT/UPDATE` em
  `ocupacoes_agenda` com `pg_advisory_xact_lock`. **Não é a garantia** — é o
  que troca deadlock por espera. Evidência (processos reais sincronizados por
  portão, seção 8):
  - *com a fila*: 10 processos no mesmo horário → 1 grava, 9 recebem `23P01`,
    0 repetições por deadlock;
  - *sem a fila* (desligada pelo papel dono só no teste): 8 processos → **1
    grava**; os 7 perdedores repetiram 32 vezes por `40P01` e terminaram em
    `40P01` — nenhum gravou nada. A exclusão sozinha já impede reserva dupla.
- **Papel da aplicação não consegue desligar nada disso** (seção 6).

### Contrato para a API da etapa 2 (planejado, não implementado)

- Transação por reserva: agendamento + itens; itens conferidos no COMMIT.
- `23P01` → HTTP 409 "horário ocupado". `40P01`/`40001` → repetir (até 3×).
- **Erro no COMMIT chega como `PDOException` crua** (verificado em teste).
- Definir o ator: `set_config('cleison.ator', ..., true)` e
  `set_config('cleison.usuario_id', ..., true)`.
- Idempotência por `chave_idempotencia` + `hash_requisicao`.
- Itens não são transferidos entre agendamentos (o banco recusa).
- Validar na aplicação: data real do calendário, passado, antecedência,
  horizonte, expediente/exceções **sobre o período ocupado já arredondado**,
  profissional ativo e habilitado para o serviço, serviço ativo e permitido na
  modalidade, região ativa.

## 5. Estados do atendimento

Estado **do atendimento**; não há estado financeiro nesta etapa (virá em
`vendas`/`pagamentos`, etapa 4, sem colunas antecipadas).

| Estado | Significado | Ocupa agenda |
|---|---|---|
| `solicitado` | Pedido pelo site, aguardando o barbeiro confirmar. Abrir o WhatsApp **não** muda isso. | Sim |
| `confirmado` | Barbeiro/recepção confirmou. | Sim |
| `em_atendimento` | Começou (ou cliente sem reserva sendo atendido). | Sim |
| `concluido` | Serviço realizado. Conta nos "realizados". | Sim (tempo usado) |
| `cancelado` | Não vai acontecer. Exige `cancelado_em`; motivo vai para o histórico. | Não |
| `nao_compareceu` | Cliente faltou. | Não |

| De \ Para | confirmado | em_atendimento | concluido | cancelado | nao_compareceu |
|---|---|---|---|---|---|
| solicitado | ✔ | — | — | ✔ | — |
| confirmado | | ✔ | ✔ | ✔ | ✔ |
| em_atendimento | — | | ✔ | ✔ | — |
| concluido / cancelado / nao_compareceu | encerrados | | | | |

- Nasce em `solicitado`, `confirmado` ou `em_atendimento`. Atendimento
  espontâneo já terminado: cria em `em_atendimento`, grava itens e conclui na
  mesma transação (testado).
- **Encerrado é imutável**: nenhuma coluna do agendamento muda (exceto
  `updated_at` do próprio trigger) e itens não são alterados, apagados nem
  acrescentados (`agendamentos_encerrado_imutavel`,
  `agendamento_itens_encerrado_imutavel`).
- Aplicado pelo banco (trigger + `cleison_transicao_permitida`) e espelhado
  em `App\Enums\EstadoAgendamento`; `ContratoDeEstadosTest` confronta os 30 pares.
- Solicitação não expira sozinha (não há tarefa agendada).

**Permissões por transição (etapa 3, a implementar no servidor):** cliente
cria `solicitado` e cancela/remarca os próprios com prova de posse e dentro
da antecedência (decisão pendente); barbeiro confirma, inicia, conclui,
marca falta, cancela e remarca os próprios; recepção conforme permissão;
proprietário tudo.

## 6. Papéis do banco e bootstrap

- `cleison` é dono do schema e só é usado por `composer migrar`
  (`php artisan migrate --database=pgsql_migracao`).
- `cleison_app` (conexão padrão `pgsql`) tem SELECT/INSERT/UPDATE/DELETE via
  `ALTER DEFAULT PRIVILEGES`. `PrivilegiosTest` prova que ele **não** consegue:
  `DISABLE TRIGGER`, `DROP CONSTRAINT`, `TRUNCATE`, `DROP TABLE`, trocar a
  função de transições, criar tabela (todos `42501`). `migrate:fresh` rodado
  por engano como aplicação é recusado (`must be owner`).
- `db:seed` → `DemonstracaoSeeder`: dados do ZIP **marcados**
  (`dados_demonstracao = true`, "(demonstracao)" nos nomes); recusa produção
  e estabelecimento real; não cria usuário; idempotente.
- `cleison:criar-proprietario`: só o primeiro proprietário; senha oculta
  (duas vezes, ≥ 12 com letras e números) ou `--gerar-senha` (24
  alfanuméricos, exibida uma vez, escapada no console); grava só o hash;
  lock consultivo contra bootstrap duplo; senha por argumento não é aceita.

## 7. Onde cada regra é garantida

| Regra | Garantia | Estado |
|---|---|---|
| Sem sobreposição por profissional (agendamento, deslocamento, bloqueio) | Constraint de exclusão + triggers | **Implementado e testado** |
| Remarcação atômica | FK composta com cascade | **Implementado e testado** |
| Contrato de estados, encerrado imutável, histórico só inserção | Triggers | **Implementado e testado** |
| Item não muda de agendamento; nenhuma alteração de itens deixa agendamento sem serviço ou com duração divergente | Trigger + conferência adiada (origem e destino) | **Implementado e testado** (revisão 2) |
| `migrate:fresh/refresh/reset/rollback` e `db:wipe` só em banco descartável marcado | `App\Console\Protegidos` + `App\Support\AlvoDescartavel` | **Implementado e testado** (revisão 2) |
| Snapshot de preço/duração/taxa/endereço | Colunas próprias + imutabilidade após encerrar | **Implementado e testado** |
| Dinheiro ≥ 0 em centavos, durações > 0, intervalos válidos e finitos | CHECK | **Implementado e testado** |
| Referências válidas, histórico não apagável | FK `RESTRICT` | **Implementado e testado** |
| Papel de runtime sem DDL | Privilégios do PostgreSQL | **Implementado e testado** (cluster local) |
| Data real, passado, antecedência, horizonte, expediente, grade | Aplicação (etapa 2) | **Planejado** |
| Profissional/serviço/região ativos e habilitados | Aplicação (etapa 2) | **Planejado** |
| Idempotência (mesma chave + mesmo corpo = mesma reserva) | Colunas + UNIQUE prontos; lógica na etapa 2 | **Parcial** |
| Login, MFA, autorização, CSRF, rate limit | Etapa 3 | **Planejado** |

## 8. Como a concorrência é testada

`backend/tests/Feature/Banco/ConcorrenciaTest.php`: processos PHP separados
(`tests/Suporte/reservar_concorrente.php`), cada um com a própria conexão,
como o papel da aplicação. Sincronização **sem relógio**: o teste segura
`pg_advisory_lock(portão)`; cada filho conecta e bloqueia em
`pg_advisory_lock_shared(portão)`; o teste confere em `pg_locks` que **todos**
estão esperando e só então solta. Ao final confere o que ficou **gravado**
(uma ocupação, nenhum item órfão, nenhuma sobreposição, todo ativo com
ocupação). Também testa duas conexões (a segunda espera a primeira e perde
com `23P01`), bloqueio × agendamentos concorrentes e horários encostados em
paralelo.

## 9. Segurança: o que existe e o que não existe

Existe: constraints, papéis separados, telefone E.164 único, histórico só de
inserção, nenhuma senha padrão, `.env` fora do git e do pacote, `APP_DEBUG`
falso por padrão no exemplo, cookie de sessão `Secure` por padrão em
produção, `HttpOnly`, sessão cifrada.

**Não existe ainda** (etapas 2–3): login, MFA, limites de tentativa,
autorização, CSRF em rotas próprias, prova de posse do telefone, cabeçalhos
de segurança, service worker da versão migrada. Telefone e endereço ficam em
**texto** no banco (necessários ao atendimento); a proteção é de acesso e de
backup (etapa 6). Hash de chave não "criptografa" nada.

## 10. Como rodar

[`backend/README.md`](../backend/README.md).
