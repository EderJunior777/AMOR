<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A migration do E1 desfaz e refaz sozinha (down() funcional). So no banco
 * *_teste (trava em Tests\TestCase), sem RefreshDatabase: rollback e
 * migrate de verdade, pelo papel dono.
 */
class MigracaoCatalogoNoSiteTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_09_30_000100_exigir_catalogo_valido_no_site.php';

    private function existe(): array
    {
        $funcoes = DB::scalar(
            "SELECT count(*) FROM unnest(ARRAY['public.cleison_conferir_catalogo_no_site(bigint, bigint)',
                                                'public.cleison_item_exige_catalogo_no_site()',
                                                'public.cleison_agendamento_exige_catalogo_no_site()']) f
              WHERE to_regprocedure(f) IS NOT NULL"
        );
        $triggers = DB::table('pg_trigger')
            ->whereIn('tgname', ['agendamento_itens_catalogo_no_site', 'agendamentos_catalogo_no_site'])->count();

        return ['funcao' => $funcoes === 3 ? true : ($funcoes === 0 ? false : "parcial:{$funcoes}"),
            'trigger' => $triggers === 2 ? true : ($triggers === 0 ? false : "parcial:{$triggers}")];
    }

    public function test_down_remove_e_up_recria_a_regra(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']));
        $this->assertSame(['funcao' => true, 'trigger' => true], $this->existe());

        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--force' => true, '--database' => 'pgsql_migracao', '--path' => self::MIGRATION,
            ]), Artisan::output());
            $this->assertSame(['funcao' => false, 'trigger' => false], $this->existe());
        } finally {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']), Artisan::output());
        }

        $this->assertSame(['funcao' => true, 'trigger' => true], $this->existe());
    }
}
