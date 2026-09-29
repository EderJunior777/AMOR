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
        // Nem membro do dono: SET ROLE ou heranca dariam current_user = dono e
        // abririam as excecoes de imutabilidade (anonimizacao). Em PostgreSQL
        // gerenciado a criacao de papeis as vezes concede isso sozinha.
        $this->assertFalse((bool) DB::scalar("SELECT pg_has_role(current_user, ?, 'MEMBER')", [$dono]), 'O papel da aplicacao e membro do dono.');
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

    /**
     * Ocupacoes e historico so sao escritos pelos triggers (SECURITY
     * DEFINER, papel dono). A aplicacao le, mas nao escreve: sem isso,
     * criaria uma ocupacao "fantasma" apontando para um agendamento
     * cancelado (bloqueando o horario) ou forjaria a autoria do historico.
     */
    public function test_aplicacao_so_le_ocupacoes_e_historico(): void
    {
        $app = config('database.connections.pgsql.username');
        $this->assertSame($app, DB::scalar('SELECT current_user'));

        foreach (['ocupacoes_agenda', 'agendamento_eventos'] as $tabela) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilegio) {
                $this->assertFalse(
                    (bool) DB::scalar('SELECT has_table_privilege(?, ?, ?)', [$app, "public.{$tabela}", $privilegio]),
                    "{$app} ainda tem {$privilegio} em {$tabela}"
                );
            }
            $this->assertTrue((bool) DB::scalar('SELECT has_table_privilege(?, ?, ?)', [$app, "public.{$tabela}", 'SELECT']));
        }
    }

    /**
     * Sem INSERT na tabela, a sequencia so serviria para queimar ids com
     * nextval(). Quem gera os ids e o dono (triggers SECURITY DEFINER).
     */
    public function test_aplicacao_nao_mexe_nas_sequencias_de_ocupacoes_e_historico(): void
    {
        $app = config('database.connections.pgsql.username');

        foreach (['ocupacoes_agenda', 'agendamento_eventos'] as $tabela) {
            $sequencia = DB::scalar('SELECT pg_get_serial_sequence(?, ?)', ["public.{$tabela}", 'id']);
            $this->assertNotNull($sequencia);
            foreach (['USAGE', 'UPDATE', 'SELECT'] as $privilegio) {
                $this->assertFalse(
                    (bool) DB::scalar('SELECT has_sequence_privilege(?, ?, ?)', [$app, $sequencia, $privilegio]),
                    "{$app} ainda tem {$privilegio} em {$sequencia}"
                );
            }
        }
    }

    public function test_aplicacao_nao_forja_ocupacao_nem_historico(): void
    {
        $id = $this->novoAgendamento('10:00', '10:30');
        $profissional = DB::table('agendamentos')->where('id', $id)->value('profissional_id');

        $tentativas = [
            'ocupacao direta' => fn () => DB::table('ocupacoes_agenda')->insert([
                'profissional_id' => $profissional, 'agendamento_id' => $id,
                'periodo' => DB::raw("tstzrange('2026-10-01 18:00-03', '2026-10-01 18:30-03', '[)')"),
            ]),
            'mover ocupacao' => fn () => DB::table('ocupacoes_agenda')->where('agendamento_id', $id)
                ->update(['periodo' => DB::raw("tstzrange('2026-10-01 18:00-03', '2026-10-01 18:30-03', '[)')")]),
            'apagar ocupacao' => fn () => DB::table('ocupacoes_agenda')->where('agendamento_id', $id)->delete(),
            'evento forjado' => fn () => DB::table('agendamento_eventos')->insert([
                'agendamento_id' => $id, 'tipo' => 'estado_alterado', 'estado_novo' => 'cancelado', 'ator' => 'cliente',
            ]),
        ];

        foreach ($tentativas as $nome => $tentativa) {
            try {
                DB::transaction($tentativa);
                $this->fail("A aplicacao conseguiu: {$nome}");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0] ?? null, "{$nome}: ".$e->getMessage());
            }
        }
    }

    /**
     * Os tres triggers que escrevem em tabelas fechadas rodam com o papel
     * dono e com search_path fixo (pg_temp no fim: um objeto temporario com
     * o mesmo nome nao sequestra a funcao).
     */
    public function test_funcoes_que_escrevem_sao_security_definer_do_dono(): void
    {
        $dono = DB::scalar("SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ocupacoes_agenda'");

        $funcoes = DB::select(
            'SELECT p.proname, p.prosecdef, pg_get_userbyid(p.proowner) AS dono, p.proconfig
               FROM pg_proc p WHERE p.pronamespace = ?::regnamespace AND p.proname = ANY (?::text[])',
            ['public', '{cleison_sincronizar_ocupacao_agendamento,cleison_sincronizar_ocupacao_bloqueio,cleison_registrar_evento_agendamento}']
        );

        $this->assertCount(3, $funcoes);
        foreach ($funcoes as $f) {
            $this->assertTrue($f->prosecdef, "{$f->proname} nao e SECURITY DEFINER");
            $this->assertSame($dono, $f->dono, "{$f->proname} nao pertence ao dono do schema");
            $this->assertSame('{"search_path=pg_catalog, public, pg_temp"}', $f->proconfig, "{$f->proname}: search_path");
        }
    }

    /**
     * Remarcar (horario e profissional) chega a ocupacao pelo ON UPDATE
     * CASCADE da FK composta, que roda com o dono da tabela: continua
     * funcionando sem UPDATE da aplicacao em ocupacoes_agenda. E o ator
     * definido na transacao (current_setting) continua chegando ao
     * historico, mesmo com a funcao rodando como dono.
     */
    public function test_aplicacao_remarca_e_o_historico_registra_quem_fez(): void
    {
        $operador = DB::table('users')->insertGetId([
            'name' => 'Operador Teste', 'email' => 'operador@exemplo.invalid', 'password' => 'x',
            'papel' => 'recepcao', 'ativo' => true,
        ]);
        $id = $this->novoAgendamento('10:00', '10:30');
        $outro = $this->novoProfissional('Outro');

        $this->comoAtor('operador', $operador);
        DB::table('agendamentos')->where('id', $id)->update([
            'profissional_id' => $outro,
            'inicio_servico' => $this->em('11:00'), 'fim_servico' => $this->em('11:30'),
            'inicio_ocupado' => $this->em('11:00'), 'fim_ocupado' => $this->em('11:30'),
        ]);

        $ocupacao = DB::table('ocupacoes_agenda')->where('agendamento_id', $id)->first();
        $this->assertSame($outro, (int) $ocupacao->profissional_id);
        $this->assertSame(
            DB::scalar('SELECT periodo_ocupado::text FROM agendamentos WHERE id = ?', [$id]),
            DB::scalar('SELECT periodo::text FROM ocupacoes_agenda WHERE agendamento_id = ?', [$id]),
        );

        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $id)->where('tipo', 'remarcado')->first();
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($operador, (int) $evento->usuario_id);
    }
}
