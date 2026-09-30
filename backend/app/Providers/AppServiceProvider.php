<?php

namespace App\Providers;

use App\Console\Protegidos\FreshProtegido;
use App\Console\Protegidos\RefreshProtegido;
use App\Console\Protegidos\ResetProtegido;
use App\Console\Protegidos\RollbackProtegido;
use App\Console\Protegidos\WipeProtegido;
use App\Support\ChaveDeLimite;
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
     * cada requisicao). Todos por IP, e reserva existente tambem por IP +
     * codigo. Rodam antes da validacao: tentativa invalida conta por IP.
     * O limite por TELEFONE da criacao nao mora aqui (achado #2): fica no
     * ReservaController, depois da validacao, e so reserva criada conta.
     */
    private function limitesDaApi(): void
    {
        $limite = fn (string $nome): int => (int) config("cleison.api.limites.{$nome}");
        // cleison.api.limites_por_ip = false: IP nao confiavel (proxy sem
        // TRUSTED_PROXIES); ficam so os limites por telefone e por codigo.
        $porIp = fn (): bool => (bool) config('cleison.api.limites_por_ip', true);

        RateLimiter::for('api-geral', fn (Request $request) => $porIp()
            ? Limit::perMinute($limite('geral_por_minuto'))->by(ChaveDeLimite::de('geral', (string) $request->ip()))
            : Limit::none());

        RateLimiter::for('api-criar-reserva', fn (Request $request) => $porIp()
            ? Limit::perMinute($limite('criar_por_minuto_ip'))->by(ChaveDeLimite::de('criar-ip', (string) $request->ip()))
            : Limit::none());

        RateLimiter::for('api-reserva-existente', function (Request $request) use ($limite, $porIp) {
            $codigo = $request->input('codigo');
            $codigo = is_string($codigo) ? strtolower(trim($codigo)) : '';
            $ip = (string) $request->ip();

            if (! $porIp()) {
                return Limit::perMinute($limite('reserva_por_minuto_ip_codigo'))->by(ChaveDeLimite::de('reserva-codigo', $codigo));
            }

            return [
                Limit::perMinute($limite('reserva_por_minuto_ip_codigo'))->by(ChaveDeLimite::de('reserva-ip-codigo', $ip, $codigo)),
                Limit::perHour($limite('reserva_por_hora_ip'))->by(ChaveDeLimite::de('reserva-ip', $ip)),
            ];
        });
    }
}
