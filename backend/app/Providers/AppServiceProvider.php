<?php

namespace App\Providers;

use App\Console\Protegidos\FreshProtegido;
use App\Console\Protegidos\RefreshProtegido;
use App\Console\Protegidos\ResetProtegido;
use App\Console\Protegidos\RollbackProtegido;
use App\Console\Protegidos\WipeProtegido;
use App\Support\TravaDeProducao;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Env;
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
            TravaDeProducao::servindoHttp($this->app->runningInConsole(), $_SERVER['argv'][1] ?? null),
            $this->app['config'],
            Env::get('DB_MIGRACAO_PASSWORD'),
        );

        // Proxies confiaveis (TRUSTED_PROXIES). Sem lista, nenhum: o IP e o
        // esquema vem da conexao, e X-Forwarded-* de terceiros e ignorado.
        $proxies = config('app.proxies_confiaveis', []);
        if ($proxies !== []) {
            TrustProxies::at($proxies);
        }
    }
}
