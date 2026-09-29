<#
  PostgreSQL local e descartavel para desenvolvimento/testes (Windows, sem admin).

  Usa os binarios oficiais da EDB (zip, sem instalador e sem servico do
  Windows), numa pasta ignorada pelo git: .ferramentas/pgsql e .ferramentas/pgdata.
  Escuta so em 127.0.0.1. As senhas sao geradas aleatoriamente na criacao e
  gravadas em .ferramentas/pg-credenciais.txt (fora do git). Nada aqui serve
  para producao.

  Bancos: cleison_dev (desenvolvimento), cleison_teste (marcado como
  descartavel) e cleison_isca_teste (isca sem marca, usada pelos testes da
  trava de alvo).

  Papeis criados:
    cleison      dono do schema; usado SO para migrations (composer migrar)
    cleison_app  aplicacao; so SELECT/INSERT/UPDATE/DELETE (sem DDL, TRUNCATE
                 ou desligar triggers, que protegem a agenda)

  Uso (PowerShell, na raiz do projeto, num terminal que continue aberto):
    powershell -ExecutionPolicy Bypass -File scripts\postgres-local.ps1 criar
    powershell -ExecutionPolicy Bypass -File scripts\postgres-local.ps1 iniciar
    powershell -ExecutionPolicy Bypass -File scripts\postgres-local.ps1 parar
    powershell -ExecutionPolicy Bypass -File scripts\postgres-local.ps1 status

  Nao encadeie a saida de "iniciar" em pipe (| Select-Object ...): o
  postgres.exe herda o pipe e o comando nao retorna.

  Linux/macOS: nao ha script; veja backend/README.md (SQL equivalente).
#>
param(
  [ValidateSet('criar', 'iniciar', 'parar', 'status')]
  [string]$Acao = 'iniciar',
  [int]$Porta = 54329,
  # Pasta das ferramentas (padrao: <raiz>\.ferramentas). Permite um cluster
  # separado, por exemplo para testar este script sem tocar no principal.
  [string]$Ferramentas = ''
)

$ErrorActionPreference = 'Stop'

$Raiz      = Split-Path -Parent $PSScriptRoot
$Ferr      = if ($Ferramentas) { $Ferramentas } else { Join-Path $Raiz '.ferramentas' }
$PgRaiz    = Join-Path $Ferr 'pgsql'
$Bin       = Join-Path $PgRaiz 'bin'
$Dados     = Join-Path $Ferr 'pgdata'
$Log       = Join-Path $Ferr 'pgdata.log'
$Credenc   = Join-Path $Ferr 'pg-credenciais.txt'

# Versao fixada e conferida por hash. Para atualizar, troque os tres juntos.
$PgVersao  = '18.6'
$PgUrl     = 'https://sbp.enterprisedb.com/getfile.jsp?fileid=1260566'
$PgSha256  = '1df55002afe95b945d934c078b13e82c1603fa546731e511d068aa983b4ead28'

function Nova-Senha {
  $bytes = New-Object byte[] 24
  [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
  # Sem simbolos que atrapalhem em URL/.env/SQL
  return ([Convert]::ToBase64String($bytes)).Replace('+', 'A').Replace('/', 'B').Replace('=', '')
}

function Garantir-Binarios {
  if (Test-Path (Join-Path $Bin 'postgres.exe')) { return }
  New-Item -ItemType Directory -Force $Ferr | Out-Null
  $zip = Join-Path $Ferr "postgresql-$PgVersao.zip"
  Write-Host "Baixando PostgreSQL $PgVersao (binarios EDB)..."
  [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
  Invoke-WebRequest -Uri $PgUrl -OutFile $zip -UseBasicParsing
  $hash = (Get-FileHash $zip -Algorithm SHA256).Hash.ToLower()
  if ($hash -ne $PgSha256) { throw "Hash do zip nao confere ($hash). Abortando." }
  Write-Host 'Extraindo...'
  Expand-Archive -Path $zip -DestinationPath $Ferr -Force
  foreach ($extra in @('pgAdmin 4', 'StackBuilder', 'doc')) {
    $p = Join-Path $PgRaiz $extra
    if (Test-Path $p) { Remove-Item -Recurse -Force $p }
  }
  Remove-Item -Force $zip
}

# O SQL (que pode conter senha) vai pelo stdin do psql, nunca pela linha de
# comando (visivel na lista de processos). A senha do superusuario vai por
# variavel de ambiente do processo filho.
function Psql([string]$Senha, [string]$Banco, [string]$Sql) {
  $env:PGPASSWORD = $Senha
  try {
    $Sql | & (Join-Path $Bin 'psql.exe') -h 127.0.0.1 -p $Porta -U postgres -d $Banco -X -q -v ON_ERROR_STOP=1 -f -
    if ($LASTEXITCODE -ne 0) { throw "psql falhou no banco $Banco" }
  } finally {
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
  }
}

function Iniciar {
  & (Join-Path $Bin 'pg_ctl.exe') -D $Dados status *> $null
  if ($LASTEXITCODE -eq 0) { Write-Host "PostgreSQL ja esta rodando ($Dados)."; return }
  & (Join-Path $Bin 'pg_ctl.exe') -D $Dados -l $Log -w -o "-p $Porta -h 127.0.0.1" start
  if ($LASTEXITCODE -ne 0) { throw "Nao consegui iniciar. Veja $Log" }
}

switch ($Acao) {
  'criar' {
    Garantir-Binarios
    if (Test-Path $Dados) { throw "Ja existe um cluster em $Dados. Use 'iniciar'." }

    $senhaSuper = Nova-Senha
    $senhaDono  = Nova-Senha
    $senhaApp   = Nova-Senha
    $pw = Join-Path $Ferr 'pwfile.tmp'
    [IO.File]::WriteAllText($pw, $senhaSuper)
    try {
      & (Join-Path $Bin 'initdb.exe') -D $Dados -U postgres --pwfile=$pw --auth=scram-sha-256 -E UTF8 --locale=C
      if ($LASTEXITCODE -ne 0) { throw 'initdb falhou' }
    } finally { Remove-Item -Force $pw }

    Iniciar

    # Nenhum dos dois e superusuario. btree_gist e "trusted" desde o PG 13:
    # o dono do banco cria a extensao na migration.
    Psql $senhaSuper 'postgres' @"
CREATE ROLE cleison LOGIN PASSWORD '$senhaDono';
CREATE ROLE cleison_app LOGIN PASSWORD '$senhaApp';
CREATE DATABASE cleison_dev OWNER cleison;
CREATE DATABASE cleison_teste OWNER cleison;
"@

    # Marca de banco DESCARTAVEL (App\Support\AlvoDescartavel): so o banco de
    # testes recebe. Testes e comandos destrutivos (migrate:fresh, reset,
    # rollback, db:wipe) exigem essa marca no alvo efetivo; o de
    # desenvolvimento fica sem ela de proposito.
    $marca = 'cleison:descartavel:' + [guid]::NewGuid().ToString('N')
    Psql $senhaSuper 'postgres' @"
COMMENT ON DATABASE cleison_teste IS '$marca';
CREATE DATABASE cleison_isca_teste OWNER cleison;
"@
    # Isca para os testes da trava: termina em _teste mas NAO tem a marca.
    Psql $senhaSuper 'cleison_isca_teste' @"
REVOKE ALL ON DATABASE cleison_isca_teste FROM PUBLIC;
GRANT CONNECT ON DATABASE cleison_isca_teste TO cleison, cleison_app;
SET ROLE cleison;
CREATE TABLE sentinela (id int PRIMARY KEY, nota text NOT NULL);
INSERT INTO sentinela VALUES (1, 'isca: se esta linha sumir, um comando destrutivo atingiu o alvo errado');
"@

    foreach ($banco in @('cleison_dev', 'cleison_teste')) {
      Psql $senhaSuper $banco @"
REVOKE ALL ON DATABASE $banco FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE $banco TO cleison, cleison_app;
GRANT USAGE ON SCHEMA public TO cleison_app;
ALTER DEFAULT PRIVILEGES FOR ROLE cleison IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO cleison_app;
ALTER DEFAULT PRIVILEGES FOR ROLE cleison IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO cleison_app;
"@
    }

    @(
      "# Credenciais do PostgreSQL LOCAL de desenvolvimento. Nao usar em producao.",
      "PG_SUPERUSUARIO=postgres",
      "PG_SUPERUSUARIO_SENHA=$senhaSuper",
      "DB_HOST=127.0.0.1",
      "DB_PORT=$Porta",
      "DB_USERNAME=cleison_app",
      "DB_PASSWORD=$senhaApp",
      "DB_MIGRACAO_USERNAME=cleison",
      "DB_MIGRACAO_PASSWORD=$senhaDono",
      "DB_DATABASE=cleison_dev",
      "DB_DATABASE_TESTE=cleison_teste"
    ) | Set-Content -Encoding ascii $Credenc

    Write-Host ''
    Write-Host "Pronto. Credenciais em $Credenc"
    Write-Host 'Copie DB_PORT, DB_USERNAME/DB_PASSWORD e DB_MIGRACAO_USERNAME/DB_MIGRACAO_PASSWORD para backend/.env.'
  }
  'iniciar' { Garantir-Binarios; Iniciar }
  'parar'   { & (Join-Path $Bin 'pg_ctl.exe') -D $Dados -m fast -w stop }
  'status'  { & (Join-Path $Bin 'pg_ctl.exe') -D $Dados status }
}
