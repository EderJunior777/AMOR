<?php

namespace App\Providers;

use App\Console\Protegidos\FreshProtegido;
use App\Console\Protegidos\RefreshProtegido;
use App\Console\Protegidos\ResetProtegido;
use App\Console\Protegidos\RollbackProtegido;
use App\Console\Protegidos\WipeProtegido;
use App\Support\Telefone;
use App\Support\TravaDeProducao;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // migrate:fresh/refresh/reset/rollback e db:wipe so rodam contra um
        // banco comprovadamente descartavel, em QUALQUER ambiente (inclusive
        // testes). Schema de desenvolvimento/producao so evolui para frente.
        $this->app->extend(FreshCommand::class, fn ($c, $app) => new FreshProtegido($app['migrator']));
        $this->app->extend(ResetCommand::class, fn ($c, $app) => new ResetProtegido($app['migrator']));
        $this->app->extend(RollbackCommand::class, fn ($c, $app) => new RollbackProtegido($app['migrator']));
        $this->app->extend(RefreshCommand::class, fn () => new RefreshProtegido);
        $this->app->extend(WipeCommand::class, fn () => new WipeProtegido);
    }

    public function boot(): void
    {
        TravaDeProducao::verificar(
            (string) $this->app->environment(),
            TravaDeProducao::servindoHttp($this->app->runningInConsole(), array_slice((array) ($_SERVER['argv'] ?? []), 1)),
            $this->app['config'],
            Env::get('DB_MIGRACAO_PASSWORD'),
        );

        // Fora de producao: lazy loading, atributo descartado em silencio
        // (mass assignment) e atributo ausente viram excecao.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Proxies confiaveis (TRUSTED_PROXIES). Sem lista, nenhum: o IP e o
        // esquema vem da conexao, e X-Forwarded-* de terceiros e ignorado.
        $proxies = config('app.proxies_confiaveis', []);
        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }

        $this->limitesDaApi();
    }

    /**
     * Limites da API publica v1 (valores em config cleison.api.limites, lidos a
     * cada requisicao). Todos por IP; alem disso, criar reserva por telefone
     * normalizado e reserva existente por IP + codigo. Tentativa invalida
     * tambem conta (o limitador roda antes da validacao).
     */
    private function limitesDaApi(): void
    {
        $limite = fn (string $nome): int => (int) config("cleison.api.limites.{$nome}");

        RateLimiter::for('api-geral', fn (Request $request) => Limit::perMinute($limite('geral_por_minuto'))
            ->by(self::chaveDeLimite('geral', (string) $request->ip())));

        RateLimiter::for('api-criar-reserva', function (Request $request) use ($limite) {
            $limites = [
                Limit::perMinute($limite('criar_por_minuto_ip'))->by(self::chaveDeLimite('criar-ip', (string) $request->ip())),
            ];

            $cliente = $request->input('cliente');
            $telefone = is_array($cliente) ? ($cliente['telefone'] ?? null) : null;
            if (is_string($telefone) && trim($telefone) !== '') {
                $limites[] = Limit::perHour($limite('criar_por_hora_telefone'))
                    ->by(self::chaveDeLimite('criar-telefone', Telefone::normalizar($telefone) ?? trim($telefone)));
            }

            return $limites;
        });

        RateLimiter::for('api-reserva-existente', function (Request $request) use ($limite) {
            $codigo = $request->input('codigo');
            $codigo = is_string($codigo) ? strtolower(trim($codigo)) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute($limite('reserva_por_minuto_ip_codigo'))->by(self::chaveDeLimite('reserva-ip-codigo', $ip, $codigo)),
                Limit::perHour($limite('reserva_por_hora_ip'))->by(self::chaveDeLimite('reserva-ip', $ip)),
            ];
        });
    }

    /**
     * Chave de limite que nunca leva o valor cru (telefone, codigo, IP): HMAC
     * SHA-256 com chave derivada do APP_KEY por HKDF, com rotulo proprio (a
     * chave da aplicacao nao e usada direto e a derivada serve so a isto).
     * O cache padrao e uma tabela do banco; sem APP_KEY, falha fechado.
     */
    private static function chaveDeLimite(string $rotulo, string ...$partes): string
    {
        $chave = (string) config('app.key');
        $material = str_starts_with($chave, 'base64:') ? (string) base64_decode(substr($chave, 7), true) : $chave;
        if ($material === '') {
            throw new \RuntimeException('APP_KEY ausente: os limites da API nao podem ser calculados.');
        }

        $derivada = hash_hkdf('sha256', $material, 32, 'cleison.limites');

        return $rotulo.':'.hash_hmac('sha256', implode("\0", $partes), $derivada);
    }
}
