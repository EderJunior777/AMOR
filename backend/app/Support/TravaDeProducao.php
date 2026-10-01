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

    /**
     * @param  list<string>  $argumentos  argv sem o script (php artisan ...)
     *
     * Qualquer argumento igual a um comando HTTP conta, nao so o primeiro:
     * "artisan --env=production octane:start" ou "-v serve" tambem servem
     * HTTP (argv[1] seria a opcao, e a trava ficaria desligada).
     */
    public static function servindoHttp(bool $console, array $argumentos): bool
    {
        return ! $console || array_intersect($argumentos, self::COMANDOS_HTTP) !== [];
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
        // Prefixo __Host-: o navegador so o aceita com Secure, Path=/ e sem Domain.
        if (! str_starts_with((string) $config->get('session.cookie'), '__Host-')) {
            $problemas[] = 'SESSION_PREFIXO_HOST desligado (o cookie de sessao precisa do prefixo __Host-; deixe ausente ou true)';
        }
        if (trim((string) $config->get('session.domain')) !== '') {
            $problemas[] = 'SESSION_DOMAIN preenchido (o prefixo __Host- exige cookie sem Domain)';
        }
        if ($config->get('session.path') !== '/') {
            $problemas[] = 'SESSION_PATH diferente de / (o prefixo __Host- exige Path=/)';
        }

        // Painel: o limite de tentativas de login mora no cache (sem armazenamento
        // que persista entre requisicoes ele nao limita nada), e desativar um
        // usuario derruba as sessoes apagando linhas de `sessions` (so vale com
        // a sessao no banco).
        if (in_array((string) $config->get('cache.default'), ['', 'array', 'null'], true)) {
            $problemas[] = 'CACHE_STORE nao persiste entre requisicoes (use database ou redis: os limites de login dependem dele)';
        }
        if ($config->get('session.driver') !== 'database') {
            $problemas[] = 'SESSION_DRIVER diferente de database (desativar um usuario nao derrubaria as sessoes dele)';
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
        if (array_intersect($proxies, ['*', '**']) !== [] || array_filter($proxies, self::faixaAmplaDemais(...)) !== []) {
            $problemas[] = 'TRUSTED_PROXIES com curinga ou faixa ampla demais (liste os IPs/CIDR do proxy)';
        }

        // Achado #3: declarar se ha proxy na frente e obrigatorio. Atras de
        // proxy sem TRUSTED_PROXIES, todo cliente teria o IP do proxy.
        $atrasDeProxy = self::booleanoDeclarado($config->get('cleison.api.atras_de_proxy'));
        if ($atrasDeProxy === null) {
            $problemas[] = 'API_ATRAS_DE_PROXY ausente ou invalida (defina true ou false)';
        } elseif ($atrasDeProxy && array_filter($proxies, fn ($p) => is_string($p) && trim($p) !== '') === []) {
            $problemas[] = 'API_ATRAS_DE_PROXY=true com TRUSTED_PROXIES vazio (liste os IPs/CIDR do proxy)';
        }

        return $problemas;
    }

    /** true/false (booleano, ou o texto "true"/"false" em qualquer caixa); qualquer outra coisa e nulo. */
    private static function booleanoDeclarado(mixed $valor): ?bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        if (is_string($valor) && in_array(strtolower(trim($valor)), ['true', 'false'], true)) {
            return strtolower(trim($valor)) === 'true';
        }

        return null;
    }

    /**
     * CIDR que equivale a confiar em (quase) todo mundo: /0 em qualquer
     * familia (0.0.0.0/0, ::/0) ou IPv4 mais largo que /8 (0.0.0.0/1 +
     * 128.0.0.0/1 cobre a internet inteira).
     */
    private static function faixaAmplaDemais(mixed $proxy): bool
    {
        if (! is_string($proxy) || ! preg_match('#^(.+)/(\d{1,3})$#', trim($proxy), $m)) {
            return false;
        }
        $prefixo = (int) $m[2];

        return $prefixo === 0 || (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $prefixo < 8);
    }

    /** Configuracao efetiva da conexao, com a `url` aplicada. */
    private static function conexao(Repository $config, string $nome): array
    {
        $bruta = $config->get("database.connections.{$nome}");

        return is_array($bruta) ? (new ConfigurationUrlParser)->parseConfiguration($bruta) : [];
    }
}
