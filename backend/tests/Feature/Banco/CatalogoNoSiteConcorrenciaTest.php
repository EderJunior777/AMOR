<?php

namespace Tests\Feature\Banco;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * E1 com COMMIT de verdade (sem RefreshDatabase): enquanto uma reserva do
 * site esta gravando o item, desativar o servico ou apagar o vinculo em
 * outra conexao ESPERA o fim da transacao (FOR SHARE / FOR KEY SHARE na
 * conferencia). Com lock_timeout curto, a espera aparece como 55P03.
 * Tudo pelo papel da aplicacao: prova tambem que ele consegue travar.
 */
class CatalogoNoSiteConcorrenciaTest extends TestCase
{
    use DadosDeAgenda;

    private int $profissional;

    private int $servico;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limparTabelasDoDominio();
        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);

        $this->profissional = $this->novoProfissional();
        $this->servico = $this->novoServico();
        DB::table('profissional_servico')->insert(['profissional_id' => $this->profissional, 'servico_id' => $this->servico]);
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql_b')->disconnect();
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    /** Abre a transacao A com um item do site gravado (e as travas da conferencia). */
    private function reservaAbertaNoSite(): void
    {
        DB::beginTransaction();
        $id = DB::table('agendamentos')->insertGetId([
            'profissional_id' => $this->profissional, 'cliente_id' => $this->novoCliente(),
            'estado' => 'solicitado', 'origem' => 'site', 'modalidade' => 'barbearia',
            'inicio_servico' => $this->em('10:00'), 'fim_servico' => $this->em('10:30'),
            'inicio_ocupado' => $this->em('10:00'), 'fim_ocupado' => $this->em('10:30'),
        ]);
        DB::table('agendamento_itens')->insert([
            'agendamento_id' => $id, 'servico_id' => $this->servico, 'ordem' => 1,
            'servico_nome' => 'Corte', 'preco_centavos' => 4000, 'duracao_minutos' => 30, 'conta_como_corte' => true,
        ]);
    }

    private function sqlstateEmB(callable $acao): ?string
    {
        $b = DB::connection('pgsql_b');
        try {
            $b->transaction(function () use ($b, $acao) {
                $b->statement("SET LOCAL lock_timeout = '300ms'");
                $acao($b);
            });

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? 'desconhecido';
        }
    }

    public function test_desativar_o_servico_espera_a_reserva_do_site(): void
    {
        $this->reservaAbertaNoSite();

        $estado = $this->sqlstateEmB(fn ($b) => $b->table('servicos')->where('id', $this->servico)->update(['ativo' => false]));

        $this->assertSame('55P03', $estado, 'a desativacao deveria esperar a trava do servico');
    }

    public function test_apagar_o_vinculo_espera_a_reserva_do_site(): void
    {
        $this->reservaAbertaNoSite();

        $estado = $this->sqlstateEmB(fn ($b) => $b->table('profissional_servico')
            ->where('profissional_id', $this->profissional)->where('servico_id', $this->servico)->delete());

        $this->assertSame('55P03', $estado, 'apagar o vinculo deveria esperar a trava');
    }

    public function test_depois_do_commit_o_catalogo_volta_a_ser_editavel(): void
    {
        $this->reservaAbertaNoSite();
        DB::commit();

        $estado = $this->sqlstateEmB(fn ($b) => $b->table('servicos')->where('id', $this->servico)->update(['ativo' => false]));

        $this->assertNull($estado);
    }
}
