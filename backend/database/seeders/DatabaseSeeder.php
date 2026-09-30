<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seed padrao: SOMENTE dados de demonstracao, e so fora de producao.
 * Nao cria usuario nem senha (o proprietario nasce por
 * `php artisan cleison:criar-proprietario`).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemonstracaoSeeder::class);
    }
}
