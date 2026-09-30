# Etapas

| # | Etapa | Estado |
|---|---|---|
| 1 | Fundação PHP/SQL | **Entregue e revisada** (24/09/2026; revisão 2 com correções pós-revisão externa), verificável localmente |
| 2 | Agenda integrada (API + integração mínima no site) | Não iniciada |
| 3 | Administração e operação (login, MFA, permissões, painel) | Não iniciada |
| 4 | Recebimentos | Não iniciada |
| 5 | Fechamento dos recebimentos registrados | Não iniciada |
| 6 | Homologação e implantação | Não iniciada |

## Etapa 1: o que existe

- Diagnóstico por arquivo e correções mínimas com regressão em `frontend/`
  ([DIAGNOSTICO-BASE.md](DIAGNOSTICO-BASE.md)). Raiz original intocada.
- Laravel 13.33 / PHP ^8.4 com lock e plataforma fixados; PostgreSQL 18.6
  local reproduzível (`scripts/postgres-local.ps1`, hash conferido).
- Migrations (5 arquivos): usuários administrativos, estabelecimento, profissionais,
  serviços, vínculos, regiões, expediente semanal, exceções, clientes,
  endereços, agendamentos, itens (snapshot), eventos (trilha), bloqueios e
  ocupações com exclusão por profissional, triggers de estado/ocupação/fila.
- Enums (estado, origem, modalidade, papel), models do domínio usados,
  normalizador de telefone E.164.
- Seed de demonstração identificado; comando `cleison:criar-proprietario`.
- Dois papéis no banco (dono para migrations, aplicação só com DML).
- `.env.example`, `backend/README.md`, documentação em `docs/`.

## Etapa 1: o que NÃO existe

API de negócio, disponibilidade, integração com o site, login/painel/MFA,
verificação por WhatsApp, recebimentos, relatórios, deploy, Docker, CI e
instruções verificadas para Linux/macOS.
O site publicado continua sendo o original, sem mudança de fluxo.

## Etapa 1: evidência de testes (revisão de 24/09/2026)

> Revisão 2 (correções pós-revisão externa): backend com **120 testes, 656
> asserções, 0 falhas**; detalhes na seção 11 de `REVISAO-ETAPA-1.md`. A tabela
> abaixo é da revisão 1.

Detalhes, comandos e limitações: [REVISAO-ETAPA-1.md](REVISAO-ETAPA-1.md).

| Suíte | Resultado |
|---|---|
| Backend PHPUnit, PostgreSQL 18.6 real, papel da aplicação | **94 testes, 468 asserções, 0 falhas, 0 pulados** |
| `ConcorrenciaTest` (7 testes, processos sincronizados por portão em `pg_locks`) | 3 de 3 execuções verdes |
| Migrations: reset + reaplicação (banco de teste) | Passa |
| Legado `frontend/testes/agenda.test.mjs` (relógio fixo) | **51 verificações ok, 0 falhas, 0 puladas** |
| Legado `frontend/testes/cliente.test.mjs` | 23 verificações ok |
| Legado na raiz original (referência, sem correção) | 36 ok, **7 falhas** (baseline preservado) |
| Instalação a partir do ZIP em pasta limpa + cluster novo | ver REVISAO-ETAPA-1.md |

## Etapa 2: agenda integrada (critérios)

- API: serviços ativos, regiões, disponibilidade por dia/profissional (sem
  dados pessoais de terceiros), criação idempotente, confirmação,
  cancelamento, remarcação.
- Validar no servidor: data real do calendário, passado, antecedência,
  horizonte, expediente/exceções **sobre o período ocupado já arredondado**,
  duração, modalidade do serviço, vínculo profissional × serviço.
- Tratamento de erro: `23P01` → 409; `40P01`/`40001` → repetir; erro no COMMIT
  é `PDOException`. Ator definido via `set_config` em toda transação.
- Integração visual mínima em `frontend/` (mesmos HTML/CSS), feature flag
  para voltar aos Blobs, service worker sem cache de API/admin.
- Testes: dois clientes disputando horário, profissionais diferentes,
  domicílio, limites de expediente, bloqueios, falha depois de gravar + retry
  (mesma reserva), reutilizar a chave com outro corpo (conflito), datas
  inexistentes.

## Etapa 3: administração
Login individual, sessão segura, revogação, limites de tentativa, MFA por
usuário (cada um cadastra o seu), recuperação segura, papéis no servidor
(policies), agenda diária/semanal, cadastros, atendimento espontâneo,
conclusão e falta, auditoria. Testes de acesso indevido trocando IDs,
exportação e profissional.

## Etapa 4: recebimentos
Vendas, pagamentos manuais (Pix/dinheiro/crédito/débito/outro), canal,
parciais e mistos, troco à parte, desconto com permissão e motivo,
devoluções limitadas ao saldo (com concorrência), idempotência, pendência de
devolução em cancelamento pago.

## Etapa 5: fechamento
Filtros, métricas com fórmula e denominador declarados, ranking por snapshot,
CSV protegido contra fórmula, cenário conhecido (R$ 40 = 20 Pix + 20 dinheiro;
R$ 65 pendente; cancelado fora), devolução em outro dia, preço alterado depois.

## Etapa 6: homologação e implantação
Navegador/mobile, perda de conexão, cache antigo, permissões,
backup/restauração ensaiados, verificação e importação dos Blobs (dry-run,
id legado, relatório), troca única da origem de gravação, HTTPS, health check,
monitoramento. Publicação e serviços externos só aqui, com escopo aprovado.
Proxy `/api/*` da Netlify: `API_ATRAS_DE_PROXY=true` (obrigatória em
produção) e `TRUSTED_PROXIES` com os IPs de saída do proxy; a trava de boot
recusa `true` com `TRUSTED_PROXIES` vazio (`backend/README.md`, "Atrás da
Netlify").
**Pré-requisito para ligar a flag do site em produção:** verificação do
telefone por código (WhatsApp) ou captcha no pedido de reserva
(`backend/README.md`, "Freios da reserva pelo site").
Cron do scheduler (`php artisan schedule:run` a cada minuto) **com alerta
se ele parar**: sem ele, reservas `solicitado` não expiram.
Atrás da Netlify, `TRUSTED_PROXIES` por IP é inviável (a Netlify não
publica lista fixa). Alternativa a avaliar: o redirect `/api/*` envia um
cabeçalho secreto (`headers` no `[[redirects]]` do `netlify.toml`) e o
backend só confia no `X-Forwarded-For` quando ele confere. Não implementado.
Limitador da API na tabela `cache` do banco: se o volume crescer, Redis.
