# Modelo de dados

Migrations: `backend/database/migrations/`. SQL explícito (`DB::unprepared`)
nas tabelas de domínio, para que cada constraint tenha nome estável e seja
testável. Todas as regras abaixo marcadas com 🧪 têm teste que exige a recusa
**pela constraint nomeada** (não basta "deu erro").

## Convenções

- Chaves `bigserial`; `estabelecimento.id` é `smallint` fixo em 1.
- Dinheiro: `*_centavos integer CHECK (>= 0)`. Instantes: `timestamptz`.
- Horários "de parede" (expediente): `time`, no fuso do estabelecimento.
- Nada que já foi usado é apagado: desativa-se (`ativo = false`) ou
  arquiva-se. FKs de histórico são `ON DELETE RESTRICT`.
- Texto livre tem limite de tamanho (`varchar(n)`).

## Diagrama (etapa 1)

```
users ─┐ 0..1                                         regioes_atendimento
       └── profissionais ──< profissional_servico >── servicos          │
               │  │  └──< expedientes_semanais                           │
               │  └─────< excecoes_expediente                            │
               │                                                          │
               ├──< bloqueios_agenda ──┐                                  │
               │                       ├──(0..1)── ocupacoes_agenda       │
               └──< agendamentos ──────┘            EXCLUDE (prof, periodo &&)
                      │   │   └──< agendamento_eventos (só inserção)      │
                      │   └──────< agendamento_itens >── servicos          │
                      └── clientes ──< enderecos_cliente ─────────────────┘
estabelecimento (1 linha: configurações)
```

## Tabelas

### `estabelecimento` (singleton)
Nome, `fuso_horario` (padrão `America/Sao_Paulo`), `whatsapp` (E.164),
endereço, `grade_minutos` (5/10/15/20/30/60), `antecedencia_minima_minutos`,
`horizonte_dias` (1–365), `domicilio_ativo`, `dados_demonstracao`.
🧪 `estabelecimento_unico` (id = 1), `estabelecimento_whatsapp`, `estabelecimento_grade`,
`estabelecimento_fuso_valido` (trigger: só fusos que o PostgreSQL conhece).
Nenhuma outra tabela referencia o estabelecimento: com uma linha só, uma FK
em todas as tabelas não acrescentaria garantia (ver ARQUITETURA, decisão 6).

### `users` — identidades administrativas
`name`, `email` (único sem diferenciar maiúsculas: índice em `lower(email)`),
`password` (hash), `papel` ∈ {proprietario, barbeiro, recepcao}, `ativo`.
Clientes **não** estão aqui. MFA/tentativas/auditoria entram na etapa 3.

### `profissionais`
`nome_exibicao`, `ativo`, `ordem`, `user_id` opcional e único (profissional
sem login; recepção sem ser profissional).

### `servicos`
`codigo` (slug único), `nome`, `descricao`, `preco_centavos`,
`duracao_minutos` (1–720), `conta_como_corte`, `permite_barbearia`,
`permite_domicilio`, `ativo`, `ordem`.
🧪 `servicos_preco`, `servicos_duracao`, `servicos_modalidade` (pelo menos
uma modalidade), `servicos_codigo`, `servicos_codigo_key`.
🧪 Serviço já usado não pode ser apagado (`agendamento_itens_servico_id_fkey`);
reajustar ou desativar não altera itens antigos (snapshot).

### `profissional_servico`
Quais serviços cada profissional executa. PK composta. 🧪 duplicata e
serviço inexistente recusados. Não entra como FK dos itens de agendamento,
porque desabilitar um serviço depois não pode quebrar o histórico. A checagem
"profissional executa este serviço" é da API (etapa 2), no momento da criação.

### `regioes_atendimento`
`codigo`, `nome`, `deslocamento_minutos` (0–240, tempo de ida, reservado
também na volta), `taxa_centavos` **por região** (o ZIP tinha taxa única de
R$ 20; o seed repete R$ 20 em todas). 🧪 `regioes_taxa`, `regioes_deslocamento`.

### `expedientes_semanais`
`profissional_id`, `dia_semana` (0 = domingo), `hora_inicio`, `hora_fim`.
Várias janelas por dia; o **intervalo** (almoço) é o buraco entre elas.
🧪 `expedientes_intervalo`, `expedientes_dia_semana`,
`expedientes_sem_sobreposicao` (janelas encostadas podem).

### `excecoes_expediente`
Numa `data`, as janelas desta tabela **substituem** as semanais (abrir num
domingo, fechar mais cedo na véspera de feriado).
🧪 `excecoes_sem_sobreposicao`, `excecoes_intervalo`.
**Folga, férias, feriado e compromisso não ficam aqui**: são
`bloqueios_agenda`, que disputam a agenda com os agendamentos e têm a mesma
garantia do banco.

### `clientes`
`nome`, `telefone` E.164 **único** (nulo permitido para atendimento de balcão
sem telefone), `observacoes` operacionais. Índice de busca por
`lower(nome)`. Normalização em `App\Support\Telefone`.
🧪 `clientes_telefone`, `clientes_telefone_unico`, `clientes_nome`; cliente
com histórico não é apagado.

### `enderecos_cliente`
`cliente_id`, `regiao_id` opcional, `logradouro`, `complemento`,
`referencia`, `arquivado_em`. `UNIQUE (id, cliente_id)` é alvo de FK composta.

### `agendamentos` — também o registro do atendimento
| Grupo | Colunas |
|---|---|
| Quem | `profissional_id`, `cliente_id`, `criado_por_user_id` |
| Classificação | `estado`, `origem` (site/whatsapp/presencial), `modalidade` (barbearia/domicilio) |
| Tempo | `inicio_servico`, `fim_servico`, `inicio_ocupado`, `fim_ocupado`, `periodo_ocupado` (gerada, `tstzrange [)`) |
| Domicílio (snapshot) | `endereco_cliente_id`, `endereco_texto`, `regiao_id`, `regiao_nome`, `deslocamento_minutos`, `taxa_deslocamento_centavos` |
| Outros | `observacao_cliente`, `chave_idempotencia`, `hash_requisicao`, `cancelado_em`, `motivo_cancelamento`, `codigo_publico` (uuid) |

🧪 Constraints: `agendamentos_servico_intervalo`,
`agendamentos_deslocamento_reservado` (ida e volta cabem no período ocupado;
implica que o ocupado contém o serviço — substituiu a antiga
`agendamentos_ocupado_contem_servico`, que ficara redundante),
`agendamentos_instantes_finitos`,
`agendamentos_minutos_cheios`, `agendamentos_domicilio_completo` (endereço +
região), `agendamentos_barbearia_sem_domicilio` (sem taxa/endereço),
`agendamentos_cancelamento_coerente`, `agendamentos_chave_idempotencia_unica`,
`agendamentos_idempotencia_coerente`, `agendamentos_endereco_do_cliente` (FK
composta: o endereço tem que ser do próprio cliente), domínios de
estado/origem/modalidade, `agendamentos_ocupado_max_24h`.
🧪 Triggers: `agendamentos_estado_inicial` (nasce em solicitado, confirmado
ou em_atendimento), `agendamentos_transicao_estado`,
`agendamentos_encerrado_imutavel` (nenhuma coluna muda após concluído,
cancelado ou falta), `agendamentos_com_servico` e
`agendamentos_duracao_dos_itens` (conferidos no COMMIT).

**Limpeza da idempotência.** Função `cleison_limpar_idempotencia` (migration
`2026_09_30_000400`, etapa 2, Fase 6) anula `chave_idempotencia` e
`hash_requisicao` dos agendamentos criados há mais de 7 dias (só as duas colunas, juntas;
exceção estreita na imutabilidade do encerrado). Executada diariamente às
03:45 São Paulo pelo agendador (`cleison:limpar-idempotencia`).

**Snapshot × referência atual**

| Dado | Como fica | Protegido depois de encerrar |
|---|---|---|
| Nome, preço, duração e "conta como corte" de cada serviço | Snapshot em `agendamento_itens` | Sim (`agendamento_itens_encerrado_imutavel`) |
| Taxa, deslocamento, nome da região, texto do endereço | Snapshot em `agendamentos` | Sim (`agendamentos_encerrado_imutavel`) |
| Horários do serviço e do período ocupado | Próprios do agendamento | Sim |
| `servico_id`, `regiao_id`, `endereco_cliente_id` | **Referência** ao cadastro atual (rastreabilidade; o cadastro pode mudar ou ser desativado) | FK `RESTRICT` impede apagar |
| `cliente_id`, `profissional_id` | **Referência** (nome do cliente/profissional é o atual) | Sim (não trocam após encerrar) |

Antes de encerrar, o operador ainda pode corrigir itens e dados (etapas 2–3
definem quem pode). Mudar o catálogo **nunca** altera itens já gravados:
não há cascata do catálogo para o snapshot.

**Por que o agendamento também é o atendimento.** Todo atendimento consome
tempo do profissional; se "atendimento" fosse outra tabela, cliente,
profissional, horário e serviços ficariam duplicados, e o atendimento
espontâneo não apareceria na agenda. Por isso quem chega sem reserva vira um
agendamento `origem = 'presencial'` criado em `em_atendimento` (se já terminou,
os itens são gravados e ele é concluído na mesma transação), e **também ocupa a agenda**. "Marcado" e "realizado" se
distinguem pelo estado: relatórios de realizados usam `concluido`. A venda
(etapa 4) terá `agendamento_id UNIQUE NOT NULL`: no máximo uma venda por
atendimento, o que impede venda duplicada pelo banco.

### `agendamento_itens` — snapshot
`servico_id` (histórico), `servico_nome`, `preco_centavos`,
`duracao_minutos`, `conta_como_corte`, `ordem`. A soma das durações tem que
bater com `fim_servico - inicio_servico`. Total do agendamento = soma dos
itens + `taxa_deslocamento_centavos` (derivado, não armazenado, para não
divergir). Combo "corte + barba" é **um** serviço (um item). Se for montado
como dois itens, conta corte pelo item marcado. 🧪 Itens de agendamento
encerrado não mudam, não somem e não ganham novos
(`agendamento_itens_encerrado_imutavel`).

**Um item nunca muda de agendamento** (`agendamento_itens_vinculo_imutavel`,
migration `2026_09_25_000100`). Remarcar mantém o agendamento e muda o
horário; trocar o serviço de um agendamento aberto é alterar, apagar ou
incluir itens **dele**. A conferência adiada (no COMMIT) revalida o
agendamento de origem e o de destino em qualquer UPDATE de item, como
segunda camada. Consequência para a API: não existe "transferir item"; para
mudar o serviço de um cliente para outro horário, remarca-se o agendamento.

### `agendamento_eventos` — trilha
`tipo` (criado/estado_alterado/remarcado), estados anterior/novo, `dados`
jsonb (de/para, motivo), `ator` (cliente/operador/sistema), `usuario_id`,
`ocorrido_em`. Gravado por trigger, **só inserção** (🧪
`agendamento_eventos_somente_insercao`). 🧪 Operador precisa de usuário.

### `bloqueios_agenda`
`profissional_id`, `tipo` (folga/ferias/feriado/compromisso/outro),
`inicio`, `fim` (até 90 dias, finitos: `bloqueios_instantes_finitos`), `periodo` (gerada), `motivo`,
`criado_por_user_id`, `cancelado_em`. Ativo = `cancelado_em IS NULL`.

### `ocupacoes_agenda` — a garantia
`profissional_id`, `periodo tstzrange`, `agendamento_id` **ou**
`bloqueio_id` (exatamente um). Linhas só existem para compromissos ativos e
são mantidas por trigger. 🧪 `ocupacoes_sem_sobreposicao` (exclusão, SQLSTATE
23P01), `ocupacoes_periodo_fechado_aberto` (`[)`, não vazio, sem limite aberto, limites finitos), `ocupacoes_uma_origem`,
`ocupacoes_do_agendamento`/`ocupacoes_do_bloqueio` (FK composta com cascade),
`ocupacoes_protegidas`. Fila por profissional: `ocupacoes_fila_por_profissional` (advisory lock; troca deadlock por espera, não é a garantia).

## Regras de exclusão (DELETE)

| Tabela | Pode apagar? |
|---|---|
| agendamentos, itens, eventos | **Não** (FK RESTRICT + eventos só inserção). Cancela-se. |
| clientes, enderecos com uso | Não (RESTRICT). Pedido de exclusão = anonimização (`cleison_anonimizar_cliente`, `docs/LGPD-ANONIMIZACAO.md`). |
| servicos, regioes, profissionais com uso | Não (RESTRICT). Desativa-se. |
| profissional_servico, expedientes, exceções | Sim (configuração, sem histórico). |
| bloqueios_agenda | Tecnicamente sim (a ocupação vai junto), mas o fluxo previsto é cancelar. |

## Módulos futuros (documentados, **não criados**)

| Etapa | Tabelas previstas | Notas |
|---|---|---|
| 2 | — | A API usa o esquema atual. Possível tabela de tokens de verificação do cliente (código por WhatsApp), só quando o fluxo existir. |
| 3 | `user_mfa` (segredo TOTP cifrado, códigos de recuperação com hash), `auditoria` (quem, o quê, antes/depois, IP), tentativas de login (ou cache/rate limiter) | Permissões finas, se o papel não bastar. |
| 4 | `vendas` (`agendamento_id UNIQUE`, subtotal, desconto com motivo e autor, total recalculado), `pagamentos` (valor aplicado, método, canal, recebido_em, operador, referência não sensível, `chave_idempotencia`), `devolucoes` (FK para o pagamento, `CHECK`/lock contra ultrapassar o saldo), troco registrado à parte do valor aplicado | Tudo em centavos. Sem dados de cartão, sem taxa inventada. |
| 5 | Nenhuma | Relatórios derivados dos registros; sem snapshot de fechamento. |
| 6 | `legado_importacoes` (id legado do Blob, hash, resultado) | Só se houver dados reais nos Blobs. |
