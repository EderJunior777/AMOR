<?php

namespace App\Support;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Comprova que uma ou mais conexoes apontam para o MESMO banco descartavel
 * antes de qualquer operacao destrutiva.
 *
 * Por que nao basta olhar a configuracao: o Laravel aplica `url` por cima de
 * host/porta/banco/usuario (ConfigurationUrlParser, array_merge), variaveis de
 * ambiente herdadas vencem o phpunit.xml e configuracao em cache ignora o
 * ambiente. Por isso a verificacao e feita NA CONEXAO EFETIVA, com uma
 * consulta somente leitura ao proprio servidor:
 *
 *   1. driver efetivo pgsql;
 *   2. current_database() terminando em "_teste" (camada extra, nao basta);
 *   3. o banco carrega a marca "cleison:descartavel:<uuid>" no seu COMMENT
 *      (posta de proposito pelo scripts/postgres-local.ps1; o banco de
 *      desenvolvimento nao tem);
 *   4. todas as conexoes veem a mesma identidade: marca, OID do banco,
 *      endereco e porta do servidor.
 *
 * Qualquer falha (inclusive de conexao) aborta. Nao ha alternativa.
 *
 * Premissa: e uma trava contra ERRO de configuracao, nao contra quem ja e
 * dono do banco. COMMENT ON DATABASE exige ser dono (ou superusuario); quem
 * pode marcar um banco ja pode apaga-lo de qualquer forma.
 */
final class AlvoDescartavel
{
    public const PREFIXO_MARCA = 'cleison:descartavel:';

    /** Comandos que apagam ou desfazem schema/dados (protegidos em App\Console\Protegidos). */
    public const COMANDOS_DESTRUTIVOS = [
        'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe',
    ];

    /**
     * @param  list<string>  $conexoes
     * @return array<string, object{banco:string, usuario:string, oid:int, marca:string, endereco:?string, porta:?int}>
     */
    public static function exigir(DatabaseManager $db, array $conexoes): array
    {
        $conexoes = array_values(array_unique(array_filter($conexoes, fn ($c) => $c !== null)));
        if ($conexoes === []) {
            throw new AlvoNaoAutorizado('Nenhuma conexao informada para verificar o alvo.');
        }

        $identidades = [];
        foreach ($conexoes as $nome) {
            $identidades[$nome] = self::identificar($db, (string) $nome);
        }

        $chaves = array_map(
            fn ($i) => implode('|', [$i->marca, $i->oid, $i->banco, $i->endereco ?? '', $i->porta ?? '']),
            $identidades
        );
        if (count(array_unique($chaves)) !== 1) {
            $resumo = implode(', ', array_map(fn ($n, $i) => "{$n}={$i->banco}", array_keys($identidades), $identidades));

            throw new AlvoNaoAutorizado("As conexoes nao apontam para o mesmo banco descartavel ({$resumo}).");
        }

        return $identidades;
    }

    private static function identificar(DatabaseManager $db, string $nome): object
    {
        if ($nome === '' || ! is_array(config("database.connections.{$nome}"))) {
            throw new AlvoNaoAutorizado("Conexao desconhecida: {$nome}.");
        }

        try {
            $conexao = $db->connection($nome);
        } catch (Throwable) {
            throw new AlvoNaoAutorizado("Conexao {$nome}: configuracao invalida.");
        }

        // getConfig() ja traz a url aplicada (configuracao efetiva).
        $driver = $conexao->getConfig('driver');
        if ($driver !== 'pgsql' || $conexao->getDriverName() !== 'pgsql') {
            throw new AlvoNaoAutorizado("Conexao {$nome}: driver nao permitido ({$driver}).");
        }

        // Com pooling ("direct" configurado), db:wipe troca o alvo para
        // "<nome>::direct" (InteractsWithPooledConnections), que esta
        // verificacao nao cobriria. Alvo ambiguo: recusa.
        if ($conexao->hasDirectConnection()) {
            throw new AlvoNaoAutorizado("Conexao {$nome}: tem conexao direta (pooling) configurada; alvo ambiguo.");
        }

        $sql = "SELECT current_database() AS banco, current_user AS usuario, d.oid::bigint AS oid,
                       shobj_description(d.oid, 'pg_database') AS marca,
                       host(inet_server_addr()) AS endereco, inet_server_port() AS porta
                  FROM pg_database d WHERE d.datname = current_database()";

        try {
            if ($conexao->transactionLevel() === 0) {
                $linha = $conexao->transaction(function () use ($conexao, $sql) {
                    $conexao->statement('SET TRANSACTION READ ONLY');

                    return $conexao->selectOne($sql);
                });
            } else {
                $linha = $conexao->selectOne($sql);
            }
        } catch (Throwable $e) {
            // So o SQLSTATE: a mensagem do driver pode citar host/usuario.
            $estado = property_exists($e, 'errorInfo') && is_array($e->errorInfo) ? ($e->errorInfo[0] ?? '?') : '?';

            throw new AlvoNaoAutorizado("Conexao {$nome}: nao foi possivel verificar o alvo (SQLSTATE {$estado}).");
        }

        if ($linha === null) {
            throw new AlvoNaoAutorizado("Conexao {$nome}: banco nao identificado.");
        }
        if (! str_ends_with((string) $linha->banco, '_teste')) {
            throw new AlvoNaoAutorizado("Conexao {$nome}: o banco {$linha->banco} nao e de teste (sufixo _teste).");
        }
        if (! is_string($linha->marca) || ! preg_match('/^'.preg_quote(self::PREFIXO_MARCA, '/').'[0-9a-f]{32}$/', $linha->marca)) {
            throw new AlvoNaoAutorizado("Conexao {$nome}: o banco {$linha->banco} nao tem a marca de descartavel.");
        }

        $linha->oid = (int) $linha->oid;
        $linha->porta = $linha->porta === null ? null : (int) $linha->porta;

        return $linha;
    }
}
