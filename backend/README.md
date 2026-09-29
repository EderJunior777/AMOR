# CLEISON: backend (Laravel + PostgreSQL)

Etapa 1: fundação de dados. **Sem API de negócio, login ou painel ainda.**
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
| `DB_PASSWORD` (papel `cleison_app`, só DML) | Servidor web | A aplicação |
| `DB_MIGRACAO_USERNAME`/`DB_MIGRACAO_PASSWORD` (papel `cleison`, dono do schema) | **Só no pipeline de deploy** | `composer migrar` |

Ordem no pipeline:

```bash
composer install --no-dev --optimize-autoloader
# backup do banco ANTES de migrar (ver "Migração só para frente")
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
- `TRUSTED_PROXIES` com curinga.

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
`vendor/`, caches, `.ferramentas/`) e o conteúdo (`APP_KEY=base64:`, senhas
preenchidas, caminhos `C:/Users/...`). Se achar algo, ele recusa.

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
4. **Decidir o destino da raiz do repositório.** A cópia de trabalho da raiz
   é o ZIP original e diverge do commit `0ddedb8`. Esta missão não mexe
   nela. Decida se ela volta ao `HEAD` ou fica como referência do ZIP
   (`docs/ARQUITETURA.md` §1).

   | Arquivo | Divergência em relação ao `HEAD` |
   |---|---|
   | `assets/admin.js` | Remove `linkZap` (prefixo 55 no wa.me), que o `0ddedb8` adicionou |
   | `netlify/functions/agenda.mjs` | Volta a chave de blob com `:`, desfazendo a correção para Windows, e remove `horaDaChave` |
   | `testes/servidor-local.mjs` | Desfaz um trecho da correção de ambiente Windows |
   | Outros 17 da raiz (`index.html`, `README.md`, `sw.js`, `assets/*`, `package*.json`...) | Só fim de linha (LF/CRLF), sem mudança de conteúdo |

## Mapa

| Caminho | Conteúdo |
|---|---|
| `database/migrations/` | Esquema (SQL explícito, constraints nomeadas, triggers) |
| `app/Enums/` | Estado do agendamento (contrato), origem, modalidade, papel |
| `app/Models/` | Models do domínio usados pelo seed/testes |
| `app/Support/Telefone.php` | Normalização para E.164 |
| `app/Console/Commands/CriarProprietario.php` | Bootstrap do primeiro proprietário |
| `database/seeders/DemonstracaoSeeder.php` | Dados de exemplo do ZIP, identificados |
| `tests/Feature/Banco/` | Constraints, ocupação, estados, privilégios, concorrência, migrations |
| `tests/Suporte/` | Helpers, trait `BancoDeTeste`, processo filho da concorrência |
