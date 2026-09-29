<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Desfaz e reaplica todas as migrations. So roda no banco *_teste (trava
 * em Tests\TestCase); nunca no banco de desenvolvimento.
 */
class MigracoesReversiveisTest extends TestCase
{
    private function funcoesDoProjeto(): int
    {
        return (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'cleison\\_%'");
    }

    public function test_reset_remove_tudo_e_reaplicar_recria(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']));
        $this->assertTrue(Schema::hasTable('ocupacoes_agenda'));

        $this->assertSame(0, Artisan::call('migrate:reset', ['--force' => true, '--database' => 'pgsql_migracao']), Artisan::output());
        foreach (['users', 'estabelecimento', 'servicos', 'clientes', 'agendamentos', 'ocupacoes_agenda'] as $tabela) {
            $this->assertFalse(Schema::hasTable($tabela), "{$tabela} sobrou depois do reset");
        }
        $this->assertSame(0, $this->funcoesDoProjeto(), 'funcoes cleison_* sobraram depois do reset');

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']), Artisan::output());
        $this->assertTrue(Schema::hasTable('ocupacoes_agenda'));
        $this->assertGreaterThan(0, $this->funcoesDoProjeto());
        $this->assertTrue(DB::table('pg_constraint')->where('conname', 'ocupacoes_sem_sobreposicao')->exists());
    }
}
