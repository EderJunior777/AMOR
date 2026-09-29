<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/** Propriedades gerais do esquema, conferidas no catalogo do PostgreSQL. */
class EsquemaTest extends TestCase
{
    use BancoDeTeste;

    public function test_roda_em_postgresql_com_btree_gist(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertStringEndsWith('_teste', DB::connection()->getDatabaseName());
        $this->assertTrue(DB::table('pg_extension')->where('extname', 'btree_gist')->exists());
    }

    public function test_dinheiro_so_em_centavos_inteiros(): void
    {
        $colunasDeDinheiro = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'like', '%centavos%')
            ->get(['table_name', 'column_name', 'data_type']);

        $this->assertNotEmpty($colunasDeDinheiro);
        foreach ($colunasDeDinheiro as $c) {
            $this->assertSame('integer', $c->data_type, "{$c->table_name}.{$c->column_name}");
        }

        $pontoFlutuante = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->whereIn('data_type', ['real', 'double precision', 'numeric', 'money'])
            ->get(['table_name', 'column_name'])
            ->map(fn ($c) => "{$c->table_name}.{$c->column_name}")
            ->all();

        $this->assertSame([], $pontoFlutuante, 'Nenhuma coluna float/numeric/money no esquema.');
    }

    public function test_nenhum_instante_sem_fuso(): void
    {
        $semFuso = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('data_type', 'timestamp without time zone')
            ->get(['table_name', 'column_name'])
            ->map(fn ($c) => "{$c->table_name}.{$c->column_name}")
            ->all();

        $this->assertSame([], $semFuso);
    }

    public function test_constraint_de_exclusao_de_ocupacao_existe(): void
    {
        $existe = DB::table('pg_constraint')
            ->where('conname', 'ocupacoes_sem_sobreposicao')
            ->where('contype', 'x')
            ->exists();

        $this->assertTrue($existe);
    }
}
