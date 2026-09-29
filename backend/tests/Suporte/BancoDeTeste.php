<?php

namespace Tests\Suporte;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase com os dois papeis do banco:
 *   - migrate:fresh roda com o papel DONO (conexao pgsql_migracao);
 *   - os testes rodam na conexao padrao, com o papel da APLICACAO (sem DDL),
 *     exatamente como a aplicacao roda. Assim uma regra que so "funcionasse"
 *     com privilegio de dono nao passaria despercebida.
 */
trait BancoDeTeste
{
    use RefreshDatabase;

    protected function migrateFreshUsing()
    {
        return [
            '--database' => 'pgsql_migracao',
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => false,
        ];
    }
}
