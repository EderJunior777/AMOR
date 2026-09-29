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
| PostgreSQL | **18** (testado 18.6) | Extensão `btree_gist` disponível (vem no pacote padrão; é *trusted*, o dono do banco cria). |
| Node | 18+ (testado 24) | Só para o site legado em `../frontend`. |

SQLite **não** serve: os testes recusam rodar fora do PostgreSQL.

## Dois papéis no banco (obrigatório)

| Papel | Usado por | Pode |
|---|---|---|
| `cleison` (dono) | **só** migrations: `composer migrar` | DDL (criar/alterar tabelas, triggers) |
| `cleison_app` | a aplicação e os testes | SELECT/INSERT/UPDATE/DELETE. **Não** pode desligar triggers, apagar constraints, `TRUNCATE` nem criar tabelas (`PrivilegiosTest`). |

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

Com um PostgreSQL 18, como superusuário:

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
