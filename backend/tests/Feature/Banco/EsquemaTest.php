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

    /**
     * Toda FK precisa de um indice que comece pela sua primeira coluna.
     * Sem ele, cada DELETE/UPDATE no pai (e cada conferencia RESTRICT)
     * varre a tabela filha inteira. Parcial "WHERE col IS NOT NULL" serve:
     * a busca da FK e por igualdade, o que ja implica NOT NULL.
     */
    public function test_toda_chave_estrangeira_tem_indice(): void
    {
        $semIndice = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text || '(' || a.attname || ')' AS fk
              FROM pg_constraint c
              JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
             WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace
               AND NOT EXISTS (SELECT 1 FROM pg_index i
                                WHERE i.indrelid = c.conrelid AND i.indkey[0] = c.conkey[1])
             ORDER BY 1
        SQL);

        $this->assertSame([], array_column($semIndice, 'fk'));
    }

    /** O esquema depende da aritmetica de intervalos infinitos do PG 17+. */
    public function test_postgresql_e_17_ou_mais(): void
    {
        $this->assertGreaterThanOrEqual(170000, (int) DB::scalar('SHOW server_version_num'));
    }
}
