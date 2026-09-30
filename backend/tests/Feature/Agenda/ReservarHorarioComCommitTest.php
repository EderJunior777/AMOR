<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\RepetirEmConflito;
use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\ReservarHorario;
use App\Support\ErroDeBanco;
use Carbon\Carbon;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * ReservarHorario com COMMIT de verdade (sem RefreshDatabase): o que so
 * existe com transacoes reais e conexoes separadas. A corrida entre duas
 * chamadas de mesma chave (23505) e simulada de forma deterministica: quando
 * a transacao da chamada A comeca, a chamada B (outra conexao) grava e
 * confirma a mesma chave. A versao com processos separados esta em
 * ReservarHorarioConcorrenciaTest.
 */
class ReservarHorarioComCommitTest extends TestCase
{
    use DadosDeReserva;

    private const CHAVE = 'chave-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limparTabelasDoDominio();
        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->conexaoDono()->unprepared(
            'DROP TRIGGER IF EXISTS cleison_teste_deadlock ON agendamentos; DROP FUNCTION IF EXISTS cleison_teste_deadlock();'
        );
        DB::setDefaultConnection('pgsql');
        DB::connection('pgsql_b')->disconnect();
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    private function servico(): ReservarHorario
    {
        return $this->app->make(ReservarHorario::class);
    }

    /**
     * Na primeira transacao aberta na conexao padrao, executa $rival na
     * conexao pgsql_b (que grava e confirma antes da nossa gravar).
     */
    private function rivalAoAbrirATransacao(callable $rival): void
    {
        $disparado = false;
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $evento) use (&$disparado, $rival) {
            if ($disparado || $evento->connection->getName() !== 'pgsql') {
                return;
            }
            $disparado = true;
            DB::setDefaultConnection('pgsql_b');
            try {
                $rival();
            } finally {
                DB::setDefaultConnection('pgsql');
            }
        });
    }

    public function test_23505_da_chave_com_o_mesmo_pedido_devolve_a_reserva_do_rival(): void
    {
        $rival = null;
        $this->rivalAoAbrirATransacao(function () use (&$rival) {
            $rival = $this->servico()->executar($this->pedido([], self::CHAVE), Canal::Site);
        });

        $r = $this->servico()->executar($this->pedido([], self::CHAVE), Canal::Site);

        $this->assertNotNull($rival, 'o rival gravou antes');
        $this->assertFalse($rival->repetida);
        $this->assertTrue($r->repetida, 'perdeu a corrida da chave: devolve a reserva do vencedor');
        $this->assertSame($rival->agendamento->id, $r->agendamento->id);
        $this->assertSame(1, DB::table('agendamentos')->count());
        $this->assertSame(1, DB::table('clientes')->count());
        $this->assertTrue($r->agendamento->relationLoaded('itens'));
    }

    public function test_23505_da_chave_com_outro_pedido_e_conflito_sem_vazar_dado(): void
    {
        $this->rivalAoAbrirATransacao(function () {
            $this->servico()->executar($this->pedido([
                'hora' => '11:00', 'cliente' => ['nome' => 'Zebedeu Rival', 'telefone' => '+5511955554444'],
            ], self::CHAVE), Canal::Site);
        });

        try {
            $this->servico()->executar($this->pedido([], self::CHAVE), Canal::Site);
            $this->fail('deveria dar idempotencia_conflito');
        } catch (ReservaRecusada $e) {
            $this->assertSame('idempotencia_conflito', $e->codigo);
            foreach (['Zebedeu', '955554444', '11:00'] as $segredo) {
                $this->assertStringNotContainsString($segredo, $e->getMessage());
            }
        }

        $this->assertSame(1, DB::table('agendamentos')->count(), 'so a do rival');
        $this->assertSame(1, DB::table('clientes')->count(), 'o cliente da perdedora foi desfeito junto');
    }

    public function test_um_23505_de_outra_constraint_sobe_sem_ser_tratado_como_idempotencia(): void
    {
        // Mesmo horario e mesmo profissional, com OUTRA chave: 23P01 (nao 23505).
        $this->servico()->executar($this->pedido([], self::CHAVE), Canal::Site);

        try {
            $this->servico()->executar($this->pedido([
                'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511977776666'],
            ], 'outra-chave-0123456789'), Canal::Site);
            $this->fail('deveria dar 23P01');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->errorInfo[0]);
            [$status, $codigo] = ErroDeBanco::classificar($e);
            $this->assertSame([409, 'horario_indisponivel'], [$status, $codigo]);
        }

        $this->assertSame(1, DB::table('agendamentos')->count());
        $this->assertSame(1, DB::table('ocupacoes_agenda')->count());
        $this->assertSame(1, DB::table('clientes')->count(), 'o cliente da reserva perdida nao ficou gravado');
        $this->assertSame(1, DB::table('agendamento_itens')->count());
    }

    public function test_falha_depois_do_commit_a_reserva_esta_gravada_e_o_retry_devolve_a_mesma(): void
    {
        $this->servico()->executar($this->pedido([], self::CHAVE), Canal::Site);
        // "resposta perdida": ninguem guardou o resultado. Outra conexao ve o COMMIT.
        $this->assertSame(1, DB::connection('pgsql_b')->table('agendamentos')->count());

        // O relogio andou: um pedido novo cairia em V3, o retry nao.
        $this->fixarRelogio('2026-10-07 13:30:00');
        $retry = $this->servico()->executar($this->pedido([], self::CHAVE), Canal::Site);

        $this->assertTrue($retry->repetida);
        $this->assertSame(1, DB::table('agendamentos')->count());
    }

    public function test_deadlock_sempre_relanca_40p01_depois_de_tres_tentativas_e_nada_e_gravado(): void
    {
        $this->conexaoDono()->unprepared(<<<'SQL'
            CREATE FUNCTION cleison_teste_deadlock() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'deadlock simulado' USING ERRCODE = '40P01';
            END $$;
            CREATE TRIGGER cleison_teste_deadlock BEFORE INSERT ON agendamentos
              FOR EACH ROW EXECUTE FUNCTION cleison_teste_deadlock();
        SQL);
        Log::spy();

        try {
            $this->servico()->executar($this->pedido(), Canal::Site);
            $this->fail('deveria relancar o 40P01');
        } catch (QueryException $e) {
            $this->assertSame('40P01', $e->errorInfo[0]);
            [$status, $codigo] = ErroDeBanco::classificar($e);
            $this->assertSame([503, 'tente_novamente'], [$status, $codigo]);
        }

        // 3 tentativas no total = 2 repeticoes logadas, so com SQLSTATE e numero.
        Log::shouldHaveReceived('warning')->twice();
        Log::shouldHaveReceived('warning')->with(\Mockery::type('string'), ['sqlstate' => '40P01', 'tentativa' => 2])->once();
        Log::shouldHaveReceived('warning')->with(\Mockery::type('string'), ['sqlstate' => '40P01', 'tentativa' => 3])->once();

        foreach (['agendamentos', 'agendamento_itens', 'ocupacoes_agenda', 'clientes', 'enderecos_cliente', 'agendamento_eventos'] as $tabela) {
            $this->assertSame(0, DB::table($tabela)->count(), "{$tabela}: nada gravado");
        }
    }

    public function test_deadlock_que_passa_na_segunda_tentativa_grava_uma_vez_so(): void
    {
        // O trigger falha so na primeira tentativa: o dono o remove no meio.
        $this->conexaoDono()->unprepared(<<<'SQL'
            CREATE FUNCTION cleison_teste_deadlock() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'deadlock simulado' USING ERRCODE = '40P01';
            END $$;
            CREATE TRIGGER cleison_teste_deadlock BEFORE INSERT ON agendamentos
              FOR EACH ROW EXECUTE FUNCTION cleison_teste_deadlock();
        SQL);
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $removido = false;
        Event::listen(TransactionBeginning::class, function () use (&$removido) {
            // 2a transacao aberta em diante: o problema "passou".
            static $abertas = 0;
            if (++$abertas === 2 && ! $removido) {
                $removido = true;
                $this->conexaoDono()->unprepared('DROP TRIGGER cleison_teste_deadlock ON agendamentos');
            }
        });

        $r = $this->servico()->executar($this->pedido(), Canal::Site);

        $this->assertTrue($removido);
        $this->assertFalse($r->repetida);
        $this->assertSame(1, DB::table('agendamentos')->count());
        $this->assertSame(1, DB::table('clientes')->count());
    }
}
