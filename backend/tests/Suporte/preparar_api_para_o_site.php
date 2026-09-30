<?php

/*
 * Prepara o banco de TESTE para os testes do site contra a API real
 * (frontend/testes/api-v1.test.mjs): aplica as migrations, esvazia as
 * tabelas do dominio e o cache (limites), e semeia o catalogo de
 * demonstracao (DemonstracaoSeeder: os mesmos slugs do assets/config.js).
 *
 * So roda com APP_ENV=testing, num banco *_teste comprovadamente
 * descartavel (AlvoDescartavel), como o resto da suite.
 *
 * Uso (da pasta backend): APP_ENV=testing php tests/Suporte/preparar_api_para_o_site.php
 * Saida: uma linha JSON {"ok":true} ou {"ok":false,"erro":"..."}.
 */

use App\Support\AlvoDescartavel;
use Database\Seeders\DemonstracaoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Recusado: exige APP_ENV=testing.\n");
    exit(2);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_ends_with((string) config('database.connections.pgsql.database'), '_teste')) {
    fwrite(STDERR, "Recusado: o banco nao e de teste.\n");
    exit(2);
}

try {
    AlvoDescartavel::exigir($app['db'], ['pgsql', 'pgsql_migracao']);

    if (Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']) !== 0) {
        throw new RuntimeException('migrate falhou');
    }

    DB::connection('pgsql_migracao')->statement(
        'TRUNCATE ocupacoes_agenda, agendamento_eventos, agendamento_itens, agendamentos, bloqueios_agenda, '
        .'enderecos_cliente, anonimizacoes, clientes, profissional_servico, expedientes_semanais, excecoes_expediente, '
        .'servicos, regioes_atendimento, profissionais, estabelecimento, users, cache, cache_locks RESTART IDENTITY CASCADE'
    );

    (new DemonstracaoSeeder)->run();

    echo json_encode(['ok' => true]), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => $e::class.': '.$e->getMessage()]), PHP_EOL;
    exit(1);
}
