<?php

namespace App\Console\Protegidos;

use App\Support\AlvoDescartavel;

/**
 * Antes de rodar, o comando destrutivo exige que o alvo (--database ou a
 * conexao padrao) e a conexao padrao sejam o MESMO banco descartavel
 * comprovado (App\Support\AlvoDescartavel). Vale em qualquer ambiente.
 *
 * Fica dentro do handle() de proposito: os eventos de console do Laravel
 * (CommandStarting) sao desligados quando APP_ENV=testing
 * (Kernel::__construct, runningUnitTests), justamente onde mais se usa
 * migrate:fresh.
 */
trait ExigeAlvoDescartavel
{
    public function handle()
    {
        $padrao = config('database.default');
        $alvo = $this->hasOption('database') ? $this->option('database') : null;

        AlvoDescartavel::exigir($this->laravel['db'], [$alvo ?: $padrao, $padrao]);

        return parent::handle();
    }
}
