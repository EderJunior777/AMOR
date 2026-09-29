<?php

namespace Tests\Feature\Banco;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * A garantia contra sobreposicao mora em constraints e triggers. Se o
 * papel que a aplicacao usa pudesse desliga-los, qualquer falha futura na
 * aplicacao (ou um comando errado) anularia a garantia. Aqui se prova que
 * o papel de runtime nao tem esse poder; so o papel dono (migrations) tem.
 */
class PrivilegiosTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    public function test_aplicacao_nao_roda_como_dono_do_schema(): void
    {
        $usuario = DB::scalar('SELECT current_user');
        $dono = DB::scalar("SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ocupacoes_agenda'");

        $this->assertNotSame($dono, $usuario, 'A conexao da aplicacao usa o papel dono das tabelas.');
        $this->assertFalse((bool) DB::scalar('SELECT rolsuper FROM pg_roles WHERE rolname = current_user'));
    }

    public function test_aplicacao_nao_consegue_desligar_as_garantias(): void
    {
        $tentativas = [
            'desligar a fila' => 'ALTER TABLE ocupacoes_agenda DISABLE TRIGGER ocupacoes_fila_por_profissional',
            'desligar todos os triggers' => 'ALTER TABLE agendamentos DISABLE TRIGGER ALL',
            'apagar a exclusao' => 'ALTER TABLE ocupacoes_agenda DROP CONSTRAINT ocupacoes_sem_sobreposicao',
            'truncar ocupacoes' => 'TRUNCATE ocupacoes_agenda',
            'truncar historico' => 'TRUNCATE agendamento_eventos CASCADE',
            'apagar tabela' => 'DROP TABLE agendamento_eventos',
            'trocar funcao' => 'CREATE OR REPLACE FUNCTION cleison_transicao_permitida(de varchar, para varchar) RETURNS boolean LANGUAGE sql AS $$ SELECT true $$',
            'criar tabela' => 'CREATE TABLE atalho (id int)',
        ];

        foreach ($tentativas as $nome => $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("A aplicacao conseguiu: {$nome}");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0] ?? null, "{$nome}: ".$e->getMessage());
                $this->assertMatchesRegularExpression('/permission denied|must be owner/', $e->getMessage(), $nome);
            }
        }

        $this->assertTrue((bool) DB::scalar(
            "SELECT tgenabled = 'O' FROM pg_trigger WHERE tgname = 'ocupacoes_fila_por_profissional'"
        ));
    }

    public function test_aplicacao_consegue_operar_normalmente(): void
    {
        // O que a aplicacao precisa (DML + sequencias + funcoes dos triggers).
        $id = $this->novoAgendamento('10:00', '10:30');
        DB::table('agendamentos')->where('id', $id)->update(['estado' => 'cancelado', 'cancelado_em' => now()]);

        $this->assertSame(0, DB::table('ocupacoes_agenda')->where('agendamento_id', $id)->count());
        $this->assertSame(2, DB::table('agendamento_eventos')->where('agendamento_id', $id)->count());
    }
}
