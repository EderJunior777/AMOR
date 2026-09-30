<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * As migrations do E1 desfazem e refazem sozinhas (down() funcional), na
 * ordem inversa: 000200 volta ao estado da 000100, e a 000100 remove tudo.
 * So no banco *_teste (trava em Tests\TestCase), sem RefreshDatabase:
 * rollback e migrate de verdade, pelo papel dono. Termina sempre reaplicado.
 */
class MigracaoCatalogoNoSiteTest extends TestCase
{
    private const E1 = 'database/migrations/2026_09_30_000100_exigir_catalogo_valido_no_site.php';

    private const AJUSTES = 'database/migrations/2026_09_30_000200_origem_imutavel_e_reatribuicao_por_vinculo.php';

    private function rollback(string $migration): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--force' => true, '--database' => 'pgsql_migracao', '--path' => $migration,
        ]), Artisan::output());
    }

    private function funcoes(): int
    {
        return (int) DB::scalar(
            "SELECT count(*) FROM unnest(ARRAY['public.cleison_conferir_catalogo_no_site(bigint, bigint)',
                                                'public.cleison_item_exige_catalogo_no_site()',
                                                'public.cleison_agendamento_exige_catalogo_no_site()']) f
              WHERE to_regprocedure(f) IS NOT NULL"
        );
    }

    private function gatilho(string $nome): ?string
    {
        return DB::scalar('SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgname = ?', [$nome]);
    }

    public function test_down_desfaz_na_ordem_inversa_e_up_recria(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']));
        $this->assertNotNull($this->gatilho('agendamentos_validar_origem'));
        $this->assertStringContainsString('UPDATE OF profissional_id ON', (string) $this->gatilho('agendamentos_catalogo_no_site'));

        try {
            $this->rollback(self::AJUSTES);
            $this->assertNull($this->gatilho('agendamentos_validar_origem'));
            $this->assertStringContainsString('UPDATE OF origem, profissional_id ON', (string) $this->gatilho('agendamentos_catalogo_no_site'));
            $this->assertSame(3, $this->funcoes());

            $this->rollback(self::E1);
            $this->assertNull($this->gatilho('agendamentos_catalogo_no_site'));
            $this->assertNull($this->gatilho('agendamento_itens_catalogo_no_site'));
            $this->assertSame(0, $this->funcoes());
        } finally {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']), Artisan::output());
        }

        $this->assertNotNull($this->gatilho('agendamentos_validar_origem'));
        $this->assertNotNull($this->gatilho('agendamento_itens_catalogo_no_site'));
        $this->assertStringContainsString('UPDATE OF profissional_id ON', (string) $this->gatilho('agendamentos_catalogo_no_site'));
        $this->assertSame(3, $this->funcoes());
    }
}
