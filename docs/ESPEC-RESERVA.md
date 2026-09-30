# Especificação: reserva de horário (etapa 2)

> **Status: especificação, sem código.** Entrada da etapa 2
> (`docs/ETAPAS.md`). Complementa `ARQUITETURA.md` e `MODELO-DE-DADOS.md`;
> onde houver conflito, eles mandam. Decisões em aberto na seção 10.

## 1. O problema

Brecha reproduzida na revisão externa: com o papel da aplicação, o banco
aceitou um agendamento **às 03:00**, com **serviço inativo**, profissional
**sem vínculo** com o serviço, **fora do expediente** e com preço de
**R$ 0,01**.

O banco não está errado em aceitar: ele garante a **coerência interna** da
agenda (sem sobreposição por profissional, itens que somam a duração,
estados e transições, domicílio completo, histórico imutável). As regras
**comerciais** dependem do catálogo vivo e do relógio:
- um serviço desativado amanhã não pode invalidar o histórico de ontem;
- o preço do snapshot é o do dia da reserva;
- a antecedência depende do "agora".

Essas regras não cabem num CHECK. Hoje nada impede um INSERT direto que as
ignore.

**Regra desta especificação:** toda reserva nova passa por **um único
serviço de domínio**, `App\Domain\Agenda\ReservarHorario`. Nenhum
controller, comando ou job grava em `agendamentos` ou `agendamento_itens`
por conta própria. A defesa em profundidade no banco fica como decisão E1.

## 2. O que entra e o que o servidor calcula

### 2.1 Entrada aceita do cliente (`PedidoDeReserva`)

| Campo | Regra de formato (`ReservarHorarioRequest`) |
|---|---|
| `servicos` | lista de ids, 1 a 3, sem repetição |
| `profissional_id` | id |
| `data` | `AAAA-MM-DD` |
| `hora` | `HH:MM`, horário de parede no fuso do estabelecimento |
| `modalidade` | `barbearia` \| `domicilio` |
| `regiao_id`, `endereco.logradouro`, `endereco.complemento`, `endereco.referencia` | só em domicílio |
| `cliente.nome`, `cliente.telefone` | nome 2 a 120 caracteres; telefone normalizado por `App\Support\Telefone::normalizar` (E.164) |
| `observacao` | até 300 caracteres, opcional |
| cabeçalho `Idempotency-Key` | obrigatório; 16 a 100 caracteres `[A-Za-z0-9_-]` |

### 2.2 Nunca aceito do cliente

`preco`, `taxa`, `duracao`, `inicio_ocupado`, `fim_ocupado`,
`deslocamento_minutos`, `estado`, `origem`, `cliente_id`,
`criado_por_user_id`, `codigo_publico`, `fuso` e qualquer campo não
listado em 2.1. O FormRequest usa `validated()`, e o DTO só tem os campos
de 2.1. **Um campo a mais não é erro, é ignorado.** Um teste manda
`preco_centavos: 1` e confere que o item grava o preço do catálogo.

### 2.3 Calculado no servidor (`CalculoDeReserva`, função pura)

| Valor | Origem |
|---|---|
| itens (`servico_nome`, `preco_centavos`, `duracao_minutos`, `conta_como_corte`) | snapshot de `servicos` no momento da reserva |
| duração total | soma das durações dos itens |
| `inicio_servico` | `data` + `hora` no `estabelecimento.fuso_horario`, convertido para `timestamptz` |
| `fim_servico` | `inicio_servico` + duração total |
| `deslocamento_minutos`, `taxa_deslocamento_centavos`, `regiao_nome` | `regioes_atendimento` (domicílio); 0/0/nulo na barbearia |
| `inicio_ocupado` | `inicio_servico` − deslocamento, arredondado **para baixo** na grade (`estabelecimento.grade_minutos`) |
| `fim_ocupado` | `fim_servico` + deslocamento, arredondado **para cima** na grade |
| `endereco_texto` | montado do endereço do cliente (domicílio) |
| `origem` | pelo canal: `site` na API pública; `whatsapp` e `presencial` pelo painel |
| `estado` inicial | `solicitado` (site) ou `confirmado` (operador) |

O arredondamento garante `agendamentos_deslocamento_reservado` e
`agendamentos_minutos_cheios`: o banco confere o que o domínio calculou.

## 3. Validações, em ordem

Todas antes de abrir a transação, exceto V9. Cada recusa vira uma
`ReservaRecusada` com um código estável, e a resposta é 422 com esse código.

| # | Regra | Código |
|---|---|---|
| V1 | `data` existe no calendário (`checkdate`); `hora` é múltiplo de `grade_minutos` | `data_invalida`, `fora_da_grade` |
| V2 | A hora existe no fuso: horário inexistente ou ambíguo numa troca de horário de verão é recusado, não ajustado | `hora_inexistente` |
| V3 | Antecedência: `inicio_servico` ≥ agora + `antecedencia_minima_minutos` (canal site; operador em E2) | `antecedencia` |
| V4 | Horizonte: `data` ≤ hoje (no fuso) + `horizonte_dias` | `alem_do_horizonte` |
| V5 | Todos os serviços existem, estão **ativos** e permitem a modalidade (`permite_barbearia` / `permite_domicilio`) | `servico_indisponivel` |
| V6 | Profissional existe e está **ativo**, e há `profissional_servico` para **cada** serviço | `profissional_indisponivel` |
| V7 | Domicílio: `estabelecimento.domicilio_ativo`, região existe e está **ativa**, logradouro com 5 caracteres ou mais | `domicilio_indisponivel` |
| V8 | Expediente: o período **ocupado** (já arredondado) cabe inteiro em **uma** janela do dia. As janelas são as de `excecoes_expediente` da data, se houver (elas substituem as semanais), ou as de `expedientes_semanais` do dia da semana no fuso. Não pode atravessar o intervalo de almoço nem a meia-noite | `fora_do_expediente` |
| V9 | Ocupação: **não** é conferida na aplicação. A verdade é a constraint de exclusão (`ocupacoes_sem_sobreposicao`, com fila por profissional em `cleison_fila_da_agenda`), e `23P01` vira 409 | `horario_indisponivel` |

A brecha da seção 1 cai em V1/V8 (03:00 fora da grade ou do expediente),
V5 (inativo), V6 (sem vínculo) e 2.2 (preço vem do catálogo).

## 4. Idempotência

- `chave_idempotencia` = o cabeçalho `Idempotency-Key`.
- `hash_requisicao` = **HMAC-SHA256** do JSON canônico do `PedidoDeReserva`
  normalizado (chaves ordenadas, telefone em E.164, sem espaços extras), com
  uma chave derivada do `APP_KEY` (`hash_hmac('sha256', $json,
  hash_hkdf('sha256', $appKey, 32, 'cleison.idempotencia'))`). Não é
  SHA-256 puro: o pedido contém telefone, e o espaço de telefones é pequeno
  (`LGPD-ANONIMIZACAO.md`, D6).
- **Fluxo:**
  1. Grava normalmente. A `UNIQUE (chave_idempotencia)` resolve a corrida:
     a segunda transação com a mesma chave espera a primeira e recebe
     `23505` (`agendamentos_chave_idempotencia_unica`).
  2. Em `23505` dessa constraint, fora da transação que abortou, lê o
     agendamento da chave. Se o hash for igual, é o **mesmo pedido**:
     devolve a reserva existente (200, mesmo corpo). Se for diferente, a
     chave foi reutilizada com outro conteúdo: 422
     `idempotencia_conflito`, sem revelar nada da reserva existente.
  3. "Falhou depois de gravar" (COMMIT feito, resposta perdida): o
     cliente reenvia com a mesma chave e cai em 2.
- **Limpeza:** uma rotina diária anula chave e hash (juntos) de
  agendamentos criados há mais de 7 dias. A anonimização também os anula.
  Implementada na Fase 6: `cleison_limpar_idempotencia()` (SECURITY DEFINER
  do dono, sem parâmetro: prazo e relógio do banco), chamada por
  `ReservarHorario::limparIdempotencia` e pelo comando
  `cleison:limpar-idempotencia` (todo dia às 03:45 de São Paulo). O trigger
  de imutabilidade do encerrado ganhou uma exceção estreita, no padrão da
  anonimização: só o dono, e só chave e hash indo a NULL juntos
  (migration `2026_09_30_000400`). Depois da limpeza, repetir o pedido com
  a mesma chave cria uma reserva nova (a janela de repetição é de 7 dias).

## 5. Transação e erros

```
TransacaoAuditada::executar(Ator::Cliente, null, fn () => ...)   // site
TransacaoAuditada::executar(Ator::Operador, $usuario, fn () => ...) // painel (etapa 3)
```

Dentro da transação, em ordem:
1. `SELECT ... FOR SHARE` nos serviços, no profissional, no vínculo e na
   região, e revalidação de V5 a V7. Isso impede que a desativação
   concorrente de um serviço passe entre a validação e o INSERT.
2. Cliente por telefone (`clientes_telefone_unico`): se não existe, cria.
   Se existe, **não altera** o nome cadastrado (E3). Cliente anonimizado
   não tem telefone, então quem volta vira um cliente novo.
3. Endereço (domicílio): novo `enderecos_cliente` ou reuso de um igual do
   mesmo cliente.
4. `agendamentos` + `agendamento_itens`. A conferência adiada dos itens
   roda no COMMIT.

| Situação | Tratamento |
|---|---|
| `23P01` (exclusão) | 409 `horario_indisponivel` (`ErroDeBanco`) |
| `40P01` / `40001` | repete a transação inteira até 3 vezes, com espera curta e aleatória; depois, 503 + `Retry-After` |
| `23505` da chave de idempotência | seção 4 |
| outro `23xxx` | 422 genérico e log de erro: é sinal de bug no cálculo, porque o domínio já validou |
| erro no COMMIT | chega como `PDOException` crua; o `ErroDeBanco` já trata as duas |

## 6. Interface

```php
namespace App\Domain\Agenda;

final class ReservarHorario
{
    public function executar(PedidoDeReserva $pedido, Canal $canal, ?User $operador = null): ResultadoDaReserva;
}

final readonly class PedidoDeReserva { /* só os campos de 2.1 + chave */ }
final readonly class ResultadoDaReserva { public Agendamento $agendamento; public bool $repetida; }
final class ReservaRecusada extends \DomainException { public string $codigo; }
enum Canal: string { case Site = 'site'; case Whatsapp = 'whatsapp'; case Presencial = 'presencial'; }
```

- O "agora" vem de `now()` / `CarbonImmutable::now()`; os testes usam
  `Carbon::setTestNow` (sem pacote novo).
- `CalculoDeReserva` e `JanelasDeExpediente` são puros (sem banco), com
  testes unitários de grade, fuso, arredondamento e janelas.

**Saída (`ReservaResource`):** `codigo_publico` (uuid), estado, início e
fim no fuso do estabelecimento, serviços (nome e preço), taxa, total e nome
de exibição do profissional. **Nunca** ids internos nem dados de outro
cliente. 201 na criação e 200 na repetição idempotente.

## 7. Estrutura de pastas proposta

```
app/
  Domain/Agenda/
    ReservarHorario.php        serviço de domínio (único ponto de escrita)
    PedidoDeReserva.php        DTO de entrada
    ResultadoDaReserva.php
    ReservaRecusada.php
    Canal.php
    CalculoDeReserva.php       puro: preço, duração, período, grade
    JanelasDeExpediente.php    puro: janelas do dia (semanal x exceção)
    HashDaRequisicao.php       HMAC do pedido canônico
  Http/
    Controllers/Api/ReservaController.php        fino: request -> domínio -> resource
    Controllers/Api/DisponibilidadeController.php
    Requests/ReservarHorarioRequest.php          só formato (seção 2.1)
    Resources/ReservaResource.php
    Resources/DisponibilidadeResource.php        horários livres, sem dado de terceiros
  Policies/
    AgendamentoPolicy.php      etapa 3 (painel); o site usa codigo_publico + prova de posse
    BloqueioAgendaPolicy.php
routes/api.php                 prefixo api/v1, throttle por IP e por telefone
```

`routes/api.php` entra por `withRouting(api: ...)` no `bootstrap/app.php`.
Endpoints públicos não precisam de Sanctum nem de pacote novo.

**Models que faltam:**

| Model | Tabela | Observação |
|---|---|---|
| `BloqueioAgenda` | `bloqueios_agenda` | `$fillable` mínimo; cancelar por método de domínio (`cancelado_em`) |
| `AgendamentoEvento` | `agendamento_eventos` | **somente leitura**: `save()`/`delete()` lançam exceção (o banco já nega; isto evita o erro em runtime) |
| `EnderecoCliente` | `enderecos_cliente` | `belongsTo(Cliente)`; `Cliente::enderecos()` |
| `ExcecaoExpediente` | `excecoes_expediente` | `belongsTo(Profissional)` |

Também faltam as relações `Profissional::servicos()` e
`Servico::profissionais()` (`belongsToMany` via `profissional_servico`).

## 8. Disponibilidade (leitura)

`GET /api/v1/disponibilidade?data=&servicos[]=&profissional_id=&modalidade=&regiao_id=`
usa o mesmo `CalculoDeReserva` e `JanelasDeExpediente`, e subtrai
`ocupacoes_agenda`. Devolve só horários de início livres, sem nomes, ids de
agendamento ou motivo de bloqueio. É apenas uma **sugestão**: a garantia
continua no INSERT (V9).

## 9. Testes da etapa 2

- **Regressão da brecha:** 03:00, serviço inativo, profissional sem
  vínculo, fora do expediente e `preco_centavos: 1` no corpo. As quatro
  primeiras são recusadas com o código certo, e o preço gravado é o do
  catálogo.
- V1 a V8, um caso por regra, incluindo 29/02 em ano não bissexto, hora
  fora da grade, a exceção que substitui a janela semanal, a borda da
  janela (termina exatamente no fim, começa no almoço) e o domicílio com
  deslocamento empurrando o ocupado para fora da janela.
- Dois clientes disputando o mesmo horário: um 201, um 409 (processos
  separados, como no `ConcorrenciaTest`). Profissionais diferentes no mesmo
  horário: dois 201.
- Idempotência: repetição com o mesmo corpo devolve a mesma reserva (200,
  sem novo agendamento); mesma chave com outro corpo dá 422; falha depois
  do COMMIT seguida de retry devolve a mesma reserva.
- Deadlock/serialização simulados: repete e depois responde 503.
- Operador (ator `operador`) grava `usuario_id` no histórico; site grava
  ator `cliente`.
- Nenhuma resposta ou log com dado de outro cliente (varredura como a do
  `AnonimizacaoTest`).

## 10. Decisões para a etapa 2

| # | Pergunta | Recomendação |
|---|---|---|
| E1 | Defesa em profundidade no banco para o canal site (trigger no INSERT de `agendamento_itens` exigindo serviço ativo e vínculo `profissional_servico` quando `origem = 'site'`)? | Sim, só para o que não depende do relógio (ativo e vínculo). Expediente, antecedência e horizonte ficam no domínio. |
| E2 | O operador pode encaixar fora do expediente ou da antecedência? | Sim, com ator operador e motivo obrigatório; nunca preço manual nesta etapa. |
| E3 | O site pode alterar o nome de um cliente já cadastrado pelo telefone? | Não (evita que alguém troque o nome de outra pessoa sabendo o telefone). |
| E4 | Quantos serviços por reserva no site? | Até 3 (o site atual envia 1). |
| E5 | Estado inicial pelo site | `solicitado`, com confirmação pelo operador; revisar depois do piloto. |
