<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ConfigurationUrlParser;

/**
 * Trava de boot: em APP_ENV=production, um processo que ATENDE HTTP nao
 * sobe com configuracao insegura. Chamada em AppServiceProvider::boot().
 *
 * Quem e verificado:
 *   - requisicoes web (fora do console);
 *   - comandos artisan que servem HTTP (COMANDOS_HTTP).
 * Quem NAO e: os demais comandos de console. O pipeline de deploy precisa
 * rodar `composer install` (package:discover), `composer migrar` (com a
 * senha do dono, que so ele tem) e `config:cache`; workers de fila nao
 * atendem ninguem. Ver backend/README.md, "Deploy".
 *
 * Tudo e lido do config (funciona com config:cache), com a `url` da
 * conexao ja expandida: uma DB_URL com ?sslmode=disable ou com senha
 * tambem e pega.
 */
final class TravaDeProducao
{
    public const SSLMODES_ACEITOS = ['require', 'verify-ca', 'verify-full'];

    /** Comandos artisan que atendem requisicoes HTTP. */
    public const COMANDOS_HTTP = ['serve', 'octane:start', 'octane:frankenphp', 'octane:roadrunner', 'octane:swoole'];

    public static function servindoHttp(bool $console, ?string $comando): bool
    {
        return ! $console || in_array($comando, self::COMANDOS_HTTP, true);
    }

    /**
     * @param  ?string  $senhaMigracaoNoAmbiente  DB_MIGRACAO_PASSWORD do ambiente do processo
     *                                            (com config:cache o config nao a mostra)
     *
     * @throws ConfiguracaoInsegura
     */
    public static function verificar(string $ambiente, bool $servindoHttp, Repository $config, ?string $senhaMigracaoNoAmbiente): void
    {
        if ($ambiente !== 'production' || ! $servindoHttp) {
            return;
        }

        $problemas = self::problemas($config, $senhaMigracaoNoAmbiente);
        if ($problemas !== []) {
            throw new ConfiguracaoInsegura(
                'Configuracao de producao insegura; a aplicacao nao sobe. Corrija: '.implode('; ', $problemas).'.'
            );
        }
    }

    /** @return list<string> descricoes sem valores */
    private static function problemas(Repository $config, ?string $senhaMigracaoNoAmbiente): array
    {
        $problemas = [];

        if ((bool) $config->get('app.debug')) {
            $problemas[] = 'APP_DEBUG ligado (exige false)';
        }
        if (trim((string) $config->get('app.key')) === '') {
            $problemas[] = 'APP_KEY vazia';
        }
        if ($config->get('session.secure') !== true) {
            $problemas[] = 'SESSION_SECURE_COOKIE desligado (deixe ausente ou true)';
        }

        $padrao = self::conexao($config, (string) $config->get('database.default'));
        if (($padrao['driver'] ?? null) !== 'pgsql'
            || ! in_array($padrao['sslmode'] ?? null, self::SSLMODES_ACEITOS, true)) {
            $problemas[] = 'DB_SSLMODE da conexao da aplicacao fora de require/verify-ca/verify-full';
        }

        $migracao = self::conexao($config, 'pgsql_migracao');
        if ((string) ($migracao['password'] ?? '') !== '' || (string) $senhaMigracaoNoAmbiente !== '') {
            $problemas[] = 'DB_MIGRACAO_PASSWORD presente no servidor web (a senha do papel dono fica so no pipeline de deploy)';
        }

        $proxies = (array) $config->get('app.proxies_confiaveis', []);
        if (array_intersect($proxies, ['*', '**']) !== []) {
            $problemas[] = 'TRUSTED_PROXIES com curinga (liste os IPs/CIDR do proxy)';
        }

        return $problemas;
    }

    /** Configuracao efetiva da conexao, com a `url` aplicada. */
    private static function conexao(Repository $config, string $nome): array
    {
        $bruta = $config->get("database.connections.{$nome}");

        return is_array($bruta) ? (new ConfigurationUrlParser)->parseConfiguration($bruta) : [];
    }
}
