# CLEISON: backend (Laravel + PostgreSQL)

Etapa 3 (administração) **em andamento** na branch `etapa-3-painel`, sobre a
etapa 2 (API pública v1 em `/api/v1/*`, domínio `ReservarHorario`). Prontos:
painel em `/painel` com login, equipe, Pedidos, Agenda e Conta (Fases 1 a 3).
Falta o teste num iPhone de verdade e a **Fase 4, MFA**: até lá, o painel só
pode ser usado em `localhost` ou Wi-Fi de confiança, com dados de
demonstração (veja "Ações humanas pendentes", mais abaixo).
Arquitetura e decisões: [`../docs/ARQUITETURA.md`](../docs/ARQUITETURA.md).
Relatório da revisão: [`../docs/REVISAO-ETAPA-1.md`](../docs/REVISAO-ETAPA-1.md).

## Ambiente efetivamente testado

Windows 11, PHP 8.4.25 (ZTS, x64), Composer 2.10.3, PostgreSQL 18.6
(binários EDB), Node 24.21.0. **Linux, macOS e Docker não foram testados**
(não estavam disponíveis). O SQL e o Laravel não dependem de Windows, mas
só o caminho abaixo foi executado.

## Requisitos

| Item | Versão | Observação |
|---|---|---|
| PHP | **8.4.x** | `composer.json` exige `^8.4`; o lock (Symfony 8) também exige ≥ 8.4. Extensões: `pdo_pgsql`, `mbstring`, `openssl` e as do Laravel (`composer check-platform-reqs --lock`). |
| Composer | 2.x | `composer install` usa o `composer.lock` (Laravel 13.33.0, PHPUnit 12.5.35). |
| PostgreSQL | **≥ 17** (testado 18.6) | Extensão `btree_gist` disponível (vem no pacote padrão; é *trusted*, o dono do banco cria). O PG 16 não serve: instantes infinitos falham com 22008 antes da constraint `agendamentos_instantes_finitos`. A migration `2026_09_29_000100` recusa servidores mais antigos. |
| Node | 18+ (testado 24) | Só para o site legado em `../frontend`. |

SQLite **não** serve: os testes recusam rodar fora do PostgreSQL.

## Dois papéis no banco (obrigatório)

| Papel | Usado por | Pode |
|---|---|---|
| `cleison` (dono) | **só** migrations: `composer migrar` | DDL (criar/alterar tabelas, triggers) |
| `cleison_app` | a aplicação e os testes | SELECT/INSERT/UPDATE/DELETE, **exceto** em `ocupacoes_agenda` e `agendamento_eventos`, onde só lê: essas duas tabelas são escritas apenas pelos triggers (`SECURITY DEFINER`, papel dono). **Não** pode desligar triggers, apagar constraints, `TRUNCATE` nem criar tabelas (`PrivilegiosTest`). |

A garantia contra horário batido mora em constraints e triggers; o papel
de runtime não consegue desligá-la.

## Instalação (Windows, sem Docker nem admin)

Na raiz do repositório, num terminal que **continue aberto** (não encadeie a
saída do script em pipe):

```powershell
# 1. PostgreSQL local descartavel: baixa os binarios EDB 18.6 (hash
#    conferido), cria o cluster em .ferramentas\, os papeis cleison e
#    cleison_app, os bancos cleison_dev e cleison_teste (este MARCADO como
#    descartavel) e a isca cleison_isca_teste
powershell -ExecutionPolicy Bypass -File scripts\postgres-local.ps1 criar
#    proximas vezes: ... postgres-local.ps1 iniciar

# 2. Dependencias travadas
cd backend
composer install

# 3. Configuracao
copy .env.example .env
#    preencha DB_PASSWORD e DB_MIGRACAO_PASSWORD com os valores de
#    ..\.ferramentas\pg-credenciais.txt (DB_PORT ja e 54329)
php artisan key:generate
copy .env .env.testing
#    no .env.testing: APP_ENV=testing e DB_DATABASE=cleison_teste

# 4. Banco
composer migrar                # = php artisan migrate --database=pgsql_migracao
php artisan db:seed            # opcional: dados de DEMONSTRACAO (marcados), sem usuarios
```

**Cluster criado antes da revisão 2** (sem marca nem isca): aplique, como
superusuário, só o trecho "marca + isca" do SQL da seção Linux/macOS abaixo
(o `COMMENT` apenas no `cleison_teste`). Sem a marca, os testes se recusam a
rodar. Depois, `composer migrar` aplica a migration nova sem apagar dados.

`php artisan migrate` **sem** `--database=pgsql_migracao` falha com
`42501 must be owner` (o papel da aplicação não tem DDL). É esperado.
Não use `composer setup` (script do esqueleto Laravel, que roda migrate como
aplicação e um build de front-end que este backend não usa).

### Linux/macOS (não testado)

Com um PostgreSQL 17 ou mais novo, como superusuário:

```sql
CREATE ROLE cleison LOGIN PASSWORD '<senha gerada>';
CREATE ROLE cleison_app LOGIN PASSWORD '<outra senha gerada>';
CREATE DATABASE cleison_dev OWNER cleison;
CREATE DATABASE cleison_teste OWNER cleison;
-- em CADA um dos dois bancos:
REVOKE ALL ON DATABASE cleison_dev FROM PUBLIC;          -- (use o nome do banco)
GRANT CONNECT, TEMPORARY ON DATABASE cleison_dev TO cleison, cleison_app;
GRANT USAGE ON SCHEMA public TO cleison_app;
ALTER DEFAULT PRIVILEGES FOR ROLE cleison IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO cleison_app;
ALTER DEFAULT PRIVILEGES FOR ROLE cleison IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO cleison_app;
-- so no banco de TESTE (32 caracteres hexadecimais aleatorios):
COMMENT ON DATABASE cleison_teste IS 'cleison:descartavel:<32 hex>';
-- isca para os testes da trava (sem marca):
CREATE DATABASE cleison_isca_teste OWNER cleison;
-- dentro dela, como cleison:
--   CREATE TABLE sentinela (id int PRIMARY KEY, nota text NOT NULL);
--   INSERT INTO sentinela VALUES (1, 'isca');
```

## Primeiro acesso administrativo

Não existe usuário nem senha padrão; o seed não cria usuários.

```bash
php artisan cleison:criar-proprietario voce@exemplo.com --nome="Seu Nome"
# sem terminal interativo (mostra a senha gerada UMA vez):
php artisan cleison:criar-proprietario voce@exemplo.com --nome="Seu Nome" --gerar-senha
```

Só cria o **primeiro** proprietário. O login em si é da etapa 3.

## Testes

```bash
php artisan test                               # suite completa, PostgreSQL real
vendor/bin/phpunit --filter ConcorrenciaTest   # processos concorrentes
vendor/bin/pint --test                         # estilo
```

- **Trava de alvo** (`App\Support\AlvoDescartavel`, chamada em
  `tests/TestCase.php` antes de qualquer migration e em cada uso do papel
  dono): as **duas** conexões dos testes (`pgsql`, aplicação, e
  `pgsql_migracao`, dono) são verificadas **na conexão efetiva** (com `url`
  já aplicada), por consulta somente leitura ao servidor. Exige: driver
  `pgsql`; banco terminando em `_teste`; **marca de descartável** no próprio
  banco (`COMMENT ON DATABASE ... IS 'cleison:descartavel:<32 hex>'`, posta
  pelo `postgres-local.ps1 criar`); a mesma marca, OID, endereço e porta nas
  duas conexões; usuários diferentes; `APP_ENV=testing`; nenhuma
  configuração em cache. Qualquer falha aborta antes de tocar no banco, sem
  alternativa e sem imprimir URL ou senha.
- **Comandos destrutivos protegidos em qualquer ambiente**:
  `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` e
  `db:wipe` são substituídos por versões (`app/Console/Protegidos`) que
  exigem o alvo descartável dentro do próprio comando. Por isso
  `migrate:fresh` no banco de desenvolvimento é **recusado**; o schema de
  desenvolvimento só evolui com `composer migrar`. (Os eventos de console
  do Laravel não servem para isso: ficam desligados com `APP_ENV=testing`.)
- Os testes da trava usam só o banco de teste e a **isca**
  `cleison_isca_teste` (sem marca, com uma tabela `sentinela`).
- As migrations dos testes rodam como dono; os testes rodam como aplicação.
- `MigracoesReversiveisTest` desfaz e reaplica todas as migrations (só no banco de teste).
- `ConcorrenciaTest` leva de ~4 s a ~45 s: um dos casos desliga a fila por
  profissional de propósito e espera o PostgreSQL detectar deadlocks
  (`deadlock_timeout` = 1 s). Para ver o resumo desse caso:
  `CLEISON_RELATORIO_CONCORRENCIA=1 vendor/bin/phpunit --filter ConcorrenciaTest`.
- Etapa 2: domínio da reserva em `tests/Feature/Agenda/` (validações, idempotência,
  consulta/cancelamento/remarcação, expiração de `solicitado`, máximo em aberto,
  concorrência com COMMIT real) e API HTTP em `tests/Feature/Api/` (contrato, erros,
  limites por IP/telefone/código/rota, teto diário).

## PostgreSQL local: travamento e recuperação responsável

Aconteceu uma vez nesta máquina (relato completo em
`../docs/REVISAO-ETAPA-1.md`, seção "Incidente"). Se conexões novas ficarem
paradas:

1. **Não reinicie às cegas.** Veja o final de `.ferramentas\pgdata.log` e
   `...\pgsql\bin\pg_ctl -D .ferramentas\pgdata status`.
2. Confirme **qual** processo é deste cluster: o PID está na 1ª linha de
   `.ferramentas\pgdata\postmaster.pid`; confira com
   `Get-CimInstance Win32_Process -Filter "ProcessId=<pid>"` que é
   `postgres.exe` **e** que a linha de comando contém o caminho deste
   `.ferramentas\pgdata`. Nunca encerre `postgres.exe` de outro projeto.
3. Tente desligar corretamente: `postgres-local.ps1 parar` (modo *fast*).
   Se não responder, `pg_ctl -D .ferramentas\pgdata -m immediate -w stop`.
4. Só se nada responder: encerre **apenas** os PIDs confirmados no passo 2
   (`Stop-Process -Id <pid>`).
5. **Não apague `postmaster.pid`.** Ao iniciar, o PostgreSQL confere se o
   PID gravado ainda existe e reaproveita o arquivo residual (comportamento do
   PostgreSQL; **não observado nesta máquina**, porque no incidente o arquivo
   foi apagado antes de tentar, sem confirmar se era necessário). Só avalie removê-lo se
   `iniciar` falhar citando o *lock file* **e** o `pg_ctl status` disser que
   não há servidor **e** o PID do arquivo não existir (ou não for o
   postgres deste cluster).
6. **Nunca** apague nada em `pg_wal` nem use `pg_resetwal`.
7. `postgres-local.ps1 iniciar` e confira no log: `automatic recovery`,
   `redo done`, `database system is ready to accept connections`.
8. Verificações não destrutivas: `SELECT pg_is_in_recovery()` (deve ser
   `false`), `checksum_failures` em `pg_stat_database` (deve ser 0) e, se
   quiser, `pg_amcheck --install-missing --heapallindexed` (lê heap e índices
   btree; instala a extensão `amcheck`, remova depois). Conexão bem-sucedida
   **não** é auditoria de integridade.

## Deploy (produção)

Base: [`.env.production.example`](.env.production.example). Há **dois
segredos de banco, em lugares diferentes**:

| Segredo | Onde mora | Quem usa |
|---|---|---|
| `DB_USERNAME` e `DB_PASSWORD` (papel `cleison_app`, só DML) | Servidor web | A aplicação em tempo de execução |
| `DB_MIGRACAO_USERNAME` e `DB_MIGRACAO_PASSWORD` (papel `cleison`, dono do schema) | **Só no pipeline de deploy** | `composer migrar` (migrations) |

O servidor web **nunca** tem acesso ao papel dono. As senhas são
configuradas uma única vez (primeira implantação); depois, **apenas**
o pipeline de deploy tem acesso a `DB_MIGRACAO_*`. Se a senha da aplicação
precisar rotacionar (decisão "Ações humanas pendentes", item 2), use
`ALTER ROLE cleison_app PASSWORD '...'` como superusuário e depois
atualize `DB_PASSWORD` em cada ambiente.

Ordem no pipeline:

```bash
composer install --no-dev --optimize-autoloader
# backup do banco ANTES de migrar (ver "Runbook: Migração só para frente")
DB_MIGRACAO_USERNAME=... DB_MIGRACAO_PASSWORD=... composer migrar
php artisan config:cache     # SEM DB_MIGRACAO_* no ambiente deste passo
```

Se `config:cache` rodar com a senha do dono no ambiente, ela vai parar no
cache e a trava de boot recusa subir a aplicação.

### Trava de boot

`App\Support\TravaDeProducao`, chamada em `AppServiceProvider::boot()`.
Com `APP_ENV=production`, a aplicação **não sobe** (lança
`ConfiguracaoInsegura`, cuja mensagem cita só os nomes das variáveis) se
houver:

- `APP_DEBUG` ligado ou `APP_KEY` vazia;
- cookie de sessão sem `Secure` (`SESSION_SECURE_COOKIE` falso);
- conexão da aplicação fora do `pgsql` ou com `sslmode` diferente de
  `require`/`verify-ca`/`verify-full` (a `DB_URL` também é lida);
- senha do papel dono presente, no config (inclusive via `DB_MIGRACAO_URL`)
  ou no ambiente do processo;
- `TRUSTED_PROXIES` com curinga ou faixa ampla demais (`/0`, ou IPv4 mais largo que `/8`);
- `API_ATRAS_DE_PROXY` ausente ou diferente de `true`/`false` (é obrigatória,
  sem padrão), ou `true` com `TRUSTED_PROXIES` vazio.

**Onde a trava vale:** em processos que **atendem HTTP**, ou seja,
requisições web e os comandos `artisan serve`/`octane:*`. Os demais
comandos de console **não** são travados, porque o pipeline precisa deles:
`package:discover` (no `composer install`), `composer migrar` (que tem a
senha do dono) e `config:cache`. Os workers de fila também ficam de fora.
A distinção é feita por `runningInConsole()` e pelo nome do comando
(`TravaDeProducao::COMANDOS_HTTP`).

### Proxies confiáveis

`TRUSTED_PROXIES` recebe IPs ou CIDR separados por vírgula (ex.:
`10.0.0.0/8,192.168.1.10`). Só desses endereços se aceitam
`X-Forwarded-For` e `X-Forwarded-Proto`. Vazio significa nenhum proxy
confiável: o IP e o esquema vêm da própria conexão. Curinga (`*`) é
recusado em produção.

#### Atrás da Netlify (site chamando `/api/*` na mesma origem)

Com o proxy `/api/*` da Netlify, **toda** requisição chega ao backend com o
IP da Netlify. Sem `TRUSTED_PROXIES`, os limites da API por IP
(`API_LIMITE_GERAL_POR_MINUTO`, `API_LIMITE_CRIAR_POR_MINUTO_IP`,
`API_LIMITE_RESERVA_POR_*`) valeriam para todos os clientes juntos: um único
abusador bloquearia o site inteiro.

Por isso **`API_ATRAS_DE_PROXY` é obrigatória em produção** (`true` ou
`false`, sem padrão), e a trava de boot recusa subir sem ela ou com
`API_ATRAS_DE_PROXY=true` e `TRUSTED_PROXIES` vazio:

- **Atrás de um proxy com IPs de saída conhecidos:** `API_ATRAS_DE_PROXY=true`
  e `TRUSTED_PROXIES` só com esses endereços (nunca `*` nem faixa ampla). O
  IP do cliente vem do `X-Forwarded-For` e cada cliente tem o próprio limite
  (`LimitesPorIpAtrasDeProxyTest`).
- **Cliente chegando direto:** `API_ATRAS_DE_PROXY=false`.

**Atrás da Netlify, hoje, não há configuração correta: publicar a API atrás
dela está BLOQUEADO até a etapa 6.** A Netlify **não publica uma lista fixa**
de IPs de saída, então `TRUSTED_PROXIES` por IP é inviável, e a trava de
boot recusa `API_ATRAS_DE_PROXY=true` sem ela. Declarar `false` atrás do
proxy passaria na trava (ela confere a declaração, não a rede), mas seria
falso: o limite por IP viraria um limite global calado. Alternativa a
avaliar na etapa 6 (não implementada): o redirect `/api/*` da Netlify envia
um **cabeçalho secreto** (`headers` no `[[redirects]]` do `netlify.toml`) e
o backend só confia no `X-Forwarded-For` quando esse cabeçalho confere.
Para a homologação local, use `frontend/testes/servidor-homologacao.mjs`.

`API_LIMITE_POR_IP=false` continua existindo como interruptor para quando o
IP não for confiável por outro motivo. Desligado, ficam o limite **por
telefone** (criação), o **por código** (consultar/cancelar/remarcar) e, em
toda rota, um **limite global alto por rota**
(`API_LIMITE_GLOBAL_POR_MINUTO_POR_ROTA`, padrão 600/min, todos os clientes
juntos), para que catálogo e disponibilidade nunca fiquem sem freio
(`LimitesSemIpApiTest`). Configurar e conferir tudo isso é item da etapa 6
(implantação).

O limite **por telefone** da criação só conta reserva **criada**: fica no
`ReservaController`, depois da validação. Recusa de formato ou de regra
(422), horário ocupado (409) e repetição idempotente não consomem o limite
de ninguém; quem sabe o telefone de alguém não esgota o limite dessa pessoa
(`LimitesApiTest`). Por IP, toda tentativa conta, inclusive a inválida.

## Runbook: migração só para frente

Em produção o esquema só avança. `migrate:rollback`, `migrate:reset`,
`migrate:refresh`, `migrate:fresh` e `db:wipe` são **bloqueados de
propósito** fora de um banco descartável comprovado
(`app/Console/Protegidos/*`, `app/Support/AlvoDescartavel.php`). As
migrations têm `down()` funcional, mas ele só roda em banco de teste. Uma
migration errada se corrige com **outra migration, para frente**.

O `down()` da `2026_09_29_000100` é aproximado: devolve INSERT/UPDATE/DELETE
em `ocupacoes_agenda` e `agendamento_eventos` a **todo** papel que tenha
SELECT nelas, não só ao da aplicação. Em banco descartável isso não importa;
é mais um motivo para nunca desfazer em produção.

### Antes de cada `composer migrar`

1. **Backup**, com o papel dono, no formato custom:
   ```bash
   pg_dump -h <host> -p <porta> -U <papel dono> -d <banco> \
     --format=custom --file=cleison-AAAA-MM-DD-HHMM.backup
   ```
   Guarde fora do servidor do banco. Ensaie a restauração num banco
   separado de tempos em tempos (a política de backup é a decisão 22 de
   `docs/DECISOES-PENDENTES.md`).
2. Exporte a lista de anonimizações (ver "Restauração de backup x LGPD").
3. Rode `composer migrar` no pipeline, com os segredos do dono.

### Se a migration falhar

No PostgreSQL, cada migration roda na própria transação: a que falhou é
desfeita inteira, e as anteriores do mesmo `migrate` ficam aplicadas. Leia
a mensagem: os `RAISE` das migrations dizem o que corrigir (ex.:
`[migracao_ocupacoes_fantasma]`, PostgreSQL < 17). Na conexão do dono o
console mostra a mensagem completa. Corrija o dado ou escreva uma migration
nova e rode `composer migrar` de novo. **Nunca** tente desfazer em produção.

A `2026_09_29_000300` (anonimização) tem um motivo a mais: o `down()` dela
**recusa rodar** se houver anonimizações registradas, porque o registro é
o que permite reaplicá-las depois de restaurar um backup. A tabela
`anonimizacoes` é só inserção, até para o dono.

### Restaurar um backup

```bash
createdb -h <host> -U <papel dono> cleison_restaurado
pg_restore -h <host> -p <porta> -U <papel dono> -d cleison_restaurado \
  --no-owner --role=<papel dono> cleison-AAAA-MM-DD-HHMM.backup
```

Depois: confira os privilégios do papel da aplicação (seção "Dois papéis
no banco"), **reaplique as anonimizações** (seção seguinte) e só então
aponte a aplicação para o banco restaurado.

## Restauração de backup x LGPD

Um backup anterior a uma anonimização traz de volta o dado pessoal daquele
cliente. Por isso:

1. **Sempre que possível, antes de restaurar** (e também periodicamente,
   e antes de cada `composer migrar`), exporte do banco atual:
   ```sql
   SELECT cliente_id, protocolo FROM anonimizacoes ORDER BY cliente_id;
   ```
   Guarde a lista **fora do banco**: se o banco atual se perder, só ela
   diz quem precisa ser anonimizado de novo. A lista não tem dado pessoal.
2. Restaure o backup.
3. **Antes de reabrir o sistema**, reaplique para cada linha da lista, com
   o id de um proprietário ativo:
   ```bash
   while IFS=, read -r cliente protocolo; do
     php artisan cleison:anonimizar-cliente "$cliente" \
       --usuario=<id do proprietario> --protocolo="${protocolo:-RESTAURACAO}" \
       --forcar --no-interaction
   done < anonimizacoes.csv
   ```
   O comando é idempotente: cliente que já está anonimizado no backup só
   gera "Nada a fazer".

## Operação LGPD

Antes da etapa 3 (painel), o atendimento a pedidos de eliminação do cliente
(anonimização) é pelo comando de console. Documentação completa:
[`../docs/LGPD-ANONIMIZACAO.md`](../docs/LGPD-ANONIMIZACAO.md).

### Pedido do titular

1. Operador recebe o pedido, confirma a identidade (telefone cadastrado) e anota
   um protocolo.
2. Cancela ou conclui qualquer agendamento ativo do cliente (a anonimização
   recusa se houver).
3. Executa:
   ```bash
   php artisan cleison:anonimizar-cliente <id> \
     --usuario=<proprietario_id> \
     --protocolo=<protocolo>
   ```
   O comando mostra contagens (só números, nunca nome/telefone/endereço),
   pede confirmação digitando o id do cliente e executa a função do banco
   que de fato confere o proprietário e recusa regras. Opções:
   - `--simular`: só mostra as contagens, não altera nada.
   - `--forcar`: dispensa a confirmação interativa (para scripts/CI).

### Retenção automática (LGPD, decisão D5)

O comando `cleison:anonimizar-inativos` roda diariamente às 03:30 (fuso São Paulo)
e anonimiza clientes sem agendamento há N meses, se configurado.

**Está inerte por enquanto:** sem `CLEISON_RETENCAO_CLIENTE_INATIVO_MESES`, ele
nem entra na agenda (vazia, zero ou texto também não contam). Rodado à
mão sem configuração, sai com erro, registra "Retencao nao configurada" no
log e não anonimiza ninguém.

Quando o prazo for decidido (responsável + jurídico):
1. Configure `CLEISON_RETENCAO_CLIENTE_INATIVO_MESES` (inteiro > 0, em meses).
2. Configure `CLEISON_RETENCAO_RESPONSAVEL_ID` (id de um proprietário ativo, em
   nome de quem a rotina roda).
3. Assegure que `php artisan schedule:run` roda em cron a cada minuto.

Ver `config/cleison.php` e `routes/console.php`.

## Freios da reserva pelo site

Respostas ao achado #1 da revisão de segurança da Fase 5 (reservas
`solicitado` segurando horário sem prazo). Valores em `config/cleison.php`
(`reservas`); inteiro inválido falha fechado.

| Freio | Variável (padrão) | Comportamento |
|---|---|---|
| Expiração | `CLEISON_SOLICITADO_EXPIRA_HORAS` (12) | `solicitado` não confirmado vira `cancelado` depois de N horas da criação **ou** quando o início chega, o que vier primeiro. Ator `sistema`, motivo `expirado` no evento. Comando `cleison:expirar-solicitados`, agendado a cada 5 minutos, pelo domínio (`ReservarHorario::expirarSolicitados`), com a linha travada. |
| Reservas em aberto por telefone | `CLEISON_MAXIMO_RESERVAS_EM_ABERTO_POR_TELEFONE` (2) | O site recusa (422 `limite_de_reservas_em_aberto`) quem já tem N reservas `solicitado`/`confirmado` com início no futuro, de qualquer canal. Contagem dentro da transação, com o cliente travado (`FOR UPDATE`): pedidos simultâneos do mesmo telefone entram em fila. O operador não tem esse limite. |
| Teto diário do site | `CLEISON_TETO_DIARIO_RESERVAS_SITE` (500) | Freio de emergência: reservas **criadas** pelo site no dia (fuso do estabelecimento), somando todos os telefones. Acima disso, 503 genérico (`indisponivel`), sem dizer que é um teto, e um aviso no log só com o teto. Conta sem trava: sob disputa pode passar por algumas unidades. |

**A expiração depende do scheduler:** `php artisan schedule:run` no cron a
cada minuto. Se ele parar, nenhuma reserva `solicitado` expira e os
horários ficam presos até alguém confirmar ou cancelar. A etapa 6 precisa
de um **alerta** para quando o `schedule:run` parar (ex.: o comando grava
um "último sinal" e o monitoramento avisa se ele envelhecer).

**Pré-requisito para ligar a flag do site em produção (etapa 6):**
verificação de posse do telefone por código (WhatsApp) **ou** captcha no
pedido de reserva. Os freios acima limitam o estrago, mas não impedem que
alguém reserve com telefones que não são seus. Nenhum serviço externo entra
nesta etapa.

## Achados baixos da revisão de segurança (Fase 5), sem ação

Registrados para não se perderem; nenhum exige mudança agora.

| # | Achado | Por que fica como está |
|---|---|---|
| 5 | Oráculo da chave de idempotência: uma chave usada com outro corpo responde `idempotencia_conflito`, o que confirma que a chave existe. | A chave tem 16+ caracteres aleatórios gerados no cliente: não é enumerável. Quem tem chave **e** corpo idênticos recebe a própria reserva. Opcional no futuro: escopar a chave por hash do telefone. |
| 7 | Disponibilidade sem cache: cada chamada faz ~6 consultas (um dia, até 3 serviços). | Custo limitado por chamada e limitado por IP ou pelo global por rota. Opcional: cache curto (5 a 15 s) por chave da consulta. |
| 8 | O limitador escreve na tabela `cache` do banco a cada requisição. | Com o volume atual, é aceitável. **Nota para a etapa 6:** se o volume crescer, mover o cache (e o limitador) para Redis. |
| 9 | Diferença de tempo em `localizar`: uuid inválido não consulta o banco; uuid válido consulta. | Só distingue "não é uuid" de "é uuid"; o uuid v4 tem 122 bits aleatórios, então isso não ajuda ninguém a achar reserva. |

Da revisão da Fase 7 e da revisão final, **riscos aceitos** (sem ação agora):

| Risco | Por que fica |
|---|---|
| `limite_de_reservas_em_aberto` (422) revela a quem informa um telefone alheio que esse telefone já tem reservas em aberto, e esse alguém pode ocupar as vagas da vítima até a expiração (12 h). | Os limites por IP e por telefone freiam; a solução de fundo é a verificação do telefone (pré-requisito da flag em produção). |
| O teto diário conta reservas criadas em qualquer estado; bots com muitos IPs podem esgotá-lo e o site fica em 503 genérico até o fim do dia. | É o freio de emergência por desenho. Monitorar o aviso `Teto diario de reservas do site atingido` no log. |
| O limite por telefone é "confere e depois conta" (não atômico): rajadas simultâneas podem passar por algumas unidades. | É freio, não cota exata; o máximo de reservas em aberto (com trava) segura o que importa. |

## Saúde e rotas

`GET /up` — confere que o banco está disponível. Resposta:
- 200 `{"status":"up"}` se o banco respondeu.
- 503 `{"status":"down","mensagem":"Banco de dados indisponível"}` sem detalhe
  de conexão (não expõe host, porta, usuário nem mensagem do erro).

Implementação: [`app/Http/Controllers/SaudeController.php`](app/Http/Controllers/SaudeController.php).
Use para o health check do balanceador.

`GET /` — responde 204 (sem corpo) quando a aplicação está pronta. Rotas públicas
de negócio em `/api/v1` (etapa 2): serviços, regiões, disponibilidade, reserva,
consulta, cancelamento, remarcação. O painel administrativo é da etapa 3.

## Empacotar para revisão ou distribuição

**Nunca zipe a pasta.** Um pacote anterior feito assim levou o `.env` (com
`APP_KEY` e as senhas dos dois papéis do banco) e um log com caminhos locais.
Use o script, que parte do git (`git archive`), ou seja, só do que está
commitado:

```bash
scripts/empacotar.sh            # HEAD (recusa se backend/, docs/ ou scripts/ tiverem mudança não commitada)
scripts/empacotar.sh <commit>   # outro commit ou tag
```

Saída em `entregas/` (ignorada pelo git): `cleison-<commit>-<data>.zip` e o
`.sha256`. Antes de gerar, o script confere os nomes (`.env*`, logs,
`vendor/`, caches, `.ferramentas/`) e o conteúdo (chave do app preenchida, senhas
preenchidas, credencial dentro de `DB_URL`, caminhos da pasta de usuário do
Windows). Se achar algo, ele recusa.

## Ações humanas pendentes

Coisas que o código não resolve e que dependem do responsável pelo projeto:

1. **Rotacionar o `APP_KEY`.** Ele vazou num pacote anterior. Gere outro com
   `php artisan key:generate` em cada ambiente. As sessões cifradas atuais
   caem. Se já existir dado cifrado com a chave antiga, ponha a antiga em
   `APP_PREVIOUS_KEYS` durante a transição.
2. **Trocar a senha do papel `cleison_app`**, que também vazou:
   `ALTER ROLE cleison_app PASSWORD '<nova>'` como superusuário. Depois,
   atualize `DB_PASSWORD`.
3. **Trocar a senha do papel `cleison`** (dono do schema), que também vazou:
   `ALTER ROLE cleison PASSWORD '<nova>'`. Depois, atualize o segredo de
   migração. No cluster local descartável, basta recriar com
   `postgres-local.ps1 criar`.

   > **Feito em 2026-09-30, só no ambiente local de desenvolvimento** (itens 1
   > a 3): `APP_KEY`, a senha de `cleison_app` e a senha de `cleison` foram
   > rotacionadas no PostgreSQL local (127.0.0.1) e em `backend/.env`,
   > `backend/.env.testing` e `.ferramentas/pg-credenciais.txt`, todos fora do
   > git. Nenhum valor foi registrado. **Em produção**, gere os segredos no
   > gerenciador de segredos do pipeline, nunca em `.env` versionado.
4. **Configurar os dois segredos no pipeline de deploy:**
   - `DB_MIGRACAO_USERNAME` e `DB_MIGRACAO_PASSWORD` (papel `cleison`, dono)
     no cofre de secrets do CI/CD, passados **apenas** ao passo de
     `composer migrar`.
   - `DB_PASSWORD` (papel `cleison_app`, aplicação) no cofre do servidor web.
   - Validar que o passo de `config:cache` **não** tem acesso a
     `DB_MIGRACAO_*`.
5. **LGPD D5: Prazo de retenção de cliente inativo.**
   - Decisão conjunta (responsável + jurídico, com validação de base legal).
   - Parâmetro configurável sem valor padrão:
     `CLEISON_RETENCAO_CLIENTE_INATIVO_MESES` (inteiro > 0, em meses).
   - Responsável da retenção: `CLEISON_RETENCAO_RESPONSAVEL_ID` (id de um
     proprietário ativo, em nome de quem a rotina `cleison:anonimizar-inativos`
     roda diariamente às 03:30 de São Paulo).
   - Até definir: o comando não anonimiza ninguém (status: inerte).
   - **Retenção do registro de acessos (`auditoria_acessos`)**: decidir junto
     com o D5 (responsável + jurídico). Sem valor padrão: enquanto não houver
     decisão, nada é apagado. O registro guarda só `usuario_id` (ou o e-mail
     normalizado, em falha), o resultado e a data e hora, sem IP.
   - **Sem MFA, o painel só pode ser usado em `localhost` ou em Wi-Fi de
     confiança, com dados de demonstração.** Não exponha o painel à internet
     nem use dados reais de clientes até haver segundo fator (decisão
     pendente do responsável).
6. **Aprovar mover `laravel/tinker` para `require-dev`** (proposta):
   - Tinker é uma shell REPL para debugging e não precisa em produção.
   - Altera `composer.lock` (aprove antes de executar).
   - Comando: `composer remove laravel/tinker && composer require --dev laravel/tinker`.
   - Ou deixar em `require` se a organização preferir ter acesso em produção
     para debugging direto (trade-off de segurança/conveniência).
7. **Decidir o destino da raiz do repositório.** A cópia de trabalho da raiz
   é o ZIP original e diverge do commit `0ddedb8`. Esta missão não mexe
   nela. Decida se ela volta ao `HEAD` ou fica como referência do ZIP
   (`docs/ARQUITETURA.md` §1).

   | Arquivo | Divergência em relação ao `HEAD` |
   |---|---|
   | `assets/admin.js` | Remove `linkZap` (prefixo 55 no wa.me), que o `0ddedb8` adicionou |
   | `netlify/functions/agenda.mjs` | Volta a chave de blob com `:`, desfazendo a correção para Windows, e remove `horaDaChave` |
   | `testes/servidor-local.mjs` | Desfaz um trecho da correção de ambiente Windows |
   | Outros 17 da raiz (`index.html`, `README.md`, `sw.js`, `assets/*`, `package*.json`...) | Só fim de linha (LF/CRLF), sem mudança de conteúdo |

8. ⚠️ **Dados pessoais no site atual (Netlify), em produção hoje.** A
   função `netlify/functions/agenda.mjs` grava **nome e telefone de cada
   reserva** no Netlify Blobs (loja `agenda`, linhas 185-186). A
   anonimização da Fase 8 (`docs/LGPD-ANONIMIZACAO.md`) só alcança o
   backend novo. **Precisa de decisão fora desta missão:** por quanto tempo
   esses registros ficam lá, quem atende pedido de titular sobre eles
   (hoje, só manualmente) e, na migração para o backend, se são importados,
   anonimizados ou descartados.
9. ~~Decisões da etapa 2 (E1 a E8)~~ **Decididas e implementadas** (E1 catálogo
   válido no banco para o site; E2 encaixe do operador com motivo; E3 site não
   altera nome; E4 até 3 serviços; E5 site nasce `solicitado`; E6 código +
   telefone; E7 confirmação por comando; E8 flag desligada).
   [`docs/ESPEC-RESERVA.md`](../docs/ESPEC-RESERVA.md), seção 10.
10. **Proxy na frente da API (etapa 6).** Definir `API_ATRAS_DE_PROXY`
    (obrigatória em produção; a trava recusa `true` com `TRUSTED_PROXIES`
    vazio). Atrás da Netlify não há configuração correta hoje (sem lista
    fixa de IPs): publicar a API atrás dela fica bloqueado até decidir o
    item 13. Detalhes em "Proxies confiáveis > Atrás da Netlify".
11. **Antes de ligar a flag do site em produção (etapa 6):** verificação do
    telefone por código (WhatsApp) ou captcha no pedido de reserva
    (pré-requisito, ver "Freios da reserva pelo site").
12. **Cron do scheduler e alerta (etapa 6):** `php artisan schedule:run` a
    cada minuto no servidor, com alerta se ele parar. Sem ele, reservas
    `solicitado` não expiram.
13. **Confiança no `X-Forwarded-For` atrás da Netlify (etapa 6):** lista
    fixa de IPs é inviável; avaliar o cabeçalho secreto no redirect
    `/api/*` (ver "Atrás da Netlify").

## Mapa

| Caminho | Conteúdo |
|---|---|
| `database/migrations/` | Esquema (SQL explícito, constraints nomeadas, triggers) |
| `database/migrations/2026_09_29_000100_fechar_escrita_direta_na_agenda.php` | Exigência PostgreSQL >= 17; fecha INSERT/UPDATE/DELETE em `ocupacoes_agenda` e `agendamento_eventos` |
| `database/migrations/2026_09_29_000200_fechar_sequencias_da_agenda.php` | Fecha sequências de ocupações e histórico |
| `database/migrations/2026_09_29_000300_anonimizacao_de_clientes.php` | Tabela `anonimizacoes`, triggers de exceção à imutabilidade, função `cleison_anonimizar_cliente` |
| `database/migrations/2026_09_30_000400_limpeza_da_idempotencia.php` | Função `cleison_limpar_idempotencia` e a exceção estreita da limpeza no encerrado |
| `app/Enums/` | Estado do agendamento (contrato), origem, modalidade, papel, origem de anonimização |
| `app/Models/` | Models do domínio usados pelo seed/testes |
| `app/Support/Telefone.php` | Normalização para E.164 |
| `app/Support/TravaDeProducao.php` | Validação de segurança em `APP_ENV=production` e em processos HTTP |
| `app/Support/ErroDeBanco.php` | Classificação de erros PostgreSQL por SQLSTATE, respostas HTTP, log sem PII |
| `app/Support/TransacaoAuditada.php` | Ponto único de entrada para escrever em agendamentos/bloqueios; define ator e usuário no contexto |
| `app/Support/Anonimizacao.php` | Lado PHP da anonimização LGPD (previa, chamada da função do banco em transação auditada) |
| `app/Console/Kernel.php` | Tratamento de erro de banco em console (mensagem traduzida, id de correlação) |
| `app/Console/Commands/CriarProprietario.php` | Bootstrap do primeiro proprietário |
| `app/Console/Commands/AnonimizarCliente.php` | Comando `cleison:anonimizar-cliente`: pedido do titular (LGPD) com confirmação interativa |
| `app/Console/Commands/AnonimizarInativos.php` | Comando `cleison:anonimizar-inativos`: retenção automática agendada (inerte até D5) |
| `app/Console/Commands/ExpirarSolicitados.php` | Comando `cleison:expirar-solicitados`: expira reservas `solicitado` vencidas (a cada 5 minutos) |
| `app/Console/Commands/LimparIdempotencia.php` | Comando `cleison:limpar-idempotencia`: anula chave e hash com mais de 7 dias (todo dia às 03:45) |
| `app/Support/ChaveDeLimite.php` | Chave HMAC dos limites da API (nunca guarda telefone, código nem IP crus) |
| `app/Domain/Agenda/AgendaSobrecarregada.php` | Teto diário de reservas do site atingido (503 genérico) |
| `app/Http/Controllers/SaudeController.php` | `GET /up`: confere banco sem expor detalhe de conexão (503 se falhar) |
| `routes/api.php` | Rotas da API v1 (`/api/v1/*`): serviços, regiões, disponibilidade, reserva, consulta, cancelamento, remarcação |
| `app/Http/Controllers/Api/` | `CatalogoController`, `DisponibilidadeController` e `ReservaController` (criação idempotente com o limite por telefone, consulta, cancelamento, remarcação) |
| `app/Http/Requests/Api/*.php` | Validação só de formato (`ReservarRequest`, `DisponibilidadeRequest`, `ProfissionaisRequest`, `ReservaExistenteRequest`, `RemarcarRequest`); a regra comercial é do domínio |
| `app/Http/Resources/` | Respostas JSON estruturadas (agendamento, disponibilidade, serviços) |
| `app/Domain/Agenda/ReservarHorario.php` | Lógica de negócio: validações V1–V8, cálculo de períodos, idempotência, freios |
| `app/Domain/Agenda/` | Suporte: `CalculoDeReserva`, `ConsultarDisponibilidade`, `CatalogoDaReserva`, `PedidoDeReserva`, `ResultadoDaReserva`, `RepetirEmConflito`, `JanelasDeExpediente`, `SnapshotServico`, `SnapshotRegiao` |
| `app/Domain/Agenda/ReservaRecusada.php` | Exceção de recusa de regra, com código estável e mensagem fixa (422 na API) |
| `config/cleison.php` | Retenção LGPD (prazo e responsável, sem padrão), freios da reserva, limites da API e `API_ATRAS_DE_PROXY` |
| `routes/console.php` | Agenda: `cleison:expirar-solicitados` a cada 5 minutos; `cleison:anonimizar-inativos` às 03:30 e `cleison:limpar-idempotencia` às 03:45 de São Paulo |
| `database/seeders/DemonstracaoSeeder.php` | Dados de exemplo do ZIP, identificados |
| `tests/Feature/Banco/` | Constraints, ocupação, estados, privilégios, concorrência, migrations, anonimização |
| `tests/Feature/Agenda/` | Domínio da reserva: V1 a V8, idempotência, consulta/cancelamento/remarcação, expiração, máximo em aberto, concorrência com COMMIT real |
| `tests/Feature/Api/` | API HTTP v1: contrato, varredura sem dado de terceiros, erros, limites (IP, telefone, código, global por rota), teto diário |
| `tests/Suporte/` | Helpers, trait `BancoDeTeste`, processo filho da concorrência |
| `docs/LGPD-ANONIMIZACAO.md` | Especificação completa da anonimização (D1–D8, decisões, funções SQL) |
| `docs/ESPEC-RESERVA.md` | Especificação da reserva de horário (etapa 2, E1–E5, decisões) |
