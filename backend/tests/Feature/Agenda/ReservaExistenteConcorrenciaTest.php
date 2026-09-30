<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\PedidoDeReserva;
use App\Domain\Agenda\ReservarHorario;
use App\Models\Agendamento;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\DadosDeReserva;
use Tests\Suporte\ProcessosDeReserva;
use Tests\TestCase;

/**
 * Operacoes simultaneas sobre a MESMA reserva, em processos separados com
 * COMMIT real: remarcar, cancelar e confirmar travam a linha (FOR UPDATE) no
 * inicio da transacao e revalidam estado e horario sobre ela. Invariante
 * conferida em todos os casos: o historico e uma cadeia sem buracos (cada
 * evento parte do estado/horario deixado pelo anterior), o estado e o
 * horario finais sao os do ultimo evento, e ha exatamente um evento por
 * operacao que teve sucesso. Ninguem sobrescreve ninguem em silencio.
 */
class ReservaExistenteConcorrenciaTest extends TestCase
{
    use DadosDeReserva, ProcessosDeReserva;

    private const AGORA = '2026-10-05 13:00:00';

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limparTabelasDoDominio();
        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        Carbon::setTestNow(Carbon::parse(self::AGORA, 'UTC'));
        $this->montarAgendaDeReserva();
        $this->operador = $this->novoOperador();
    }

    protected function tearDown(): void
    {
        $this->limparArquivosDeProcessos();
        DB::connection('pgsql_b')->disconnect();
        $this->limparTabelasDoDominio();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Reserva do site (solicitado), gravada com COMMIT. */
    private function reservaDoSite(string $hora, string $telefone): Agendamento
    {
        return $this->app->make(ReservarHorario::class)->executar(
            PedidoDeReserva::deDados($this->dadosDoPedido([
                'hora' => $hora, 'cliente' => ['nome' => 'Cliente da Disputa', 'telefone' => $telefone],
            ])),
            Canal::Site,
        )->agendamento;
    }

    private function op(string $operacao, Agendamento $a, string $telefone, array $extra = []): array
    {
        return $extra + [
            'operacao' => $operacao, 'codigo' => $a->codigo_publico, 'telefone' => $telefone,
            'canal' => 'site', 'operador_id' => $operacao === 'confirmar' ? $this->operador->id : null,
            'agora' => self::AGORA,
        ];
    }

    /**
     * Confere a cadeia do historico e devolve [estado final, inicio final].
     *
     * @return array{0: string, 1: string}
     */
    private function assertHistoricoEmCadeia(Agendamento $a, int $sucessos): array
    {
        $eventos = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderBy('id')->get();
        $criado = $eventos->shift();
        $this->assertSame('criado', $criado->tipo);
        $estado = $criado->estado_novo;
        $inicio = json_decode($criado->dados, true)['inicio_servico'];

        foreach ($eventos as $e) {
            $this->assertSame($estado, $e->estado_anterior, "evento {$e->tipo} nao parte do estado anterior");
            if ($e->tipo === 'remarcado') {
                $dados = json_decode($e->dados, true);
                $this->assertSame($inicio, $dados['de']['inicio_servico'], 'remarcacao nao parte do horario deixado pela anterior');
                $inicio = $dados['para']['inicio_servico'];
            }
            $estado = $e->estado_novo;
        }

        $this->assertCount($sucessos, $eventos, 'um evento por operacao com sucesso');
        $final = $a->fresh();
        $this->assertSame($estado, $final->estado->value, 'estado final = ultimo evento');
        $this->assertTrue(CarbonImmutable::parse($inicio)->equalTo($final->inicio_servico), 'horario final = ultimo evento');

        $ocupacoes = DB::table('ocupacoes_agenda')->where('agendamento_id', $a->id)->count();
        $this->assertSame($estado === 'cancelado' ? 0 : 1, $ocupacoes, 'ocupacao coerente com o estado');

        return [$estado, $inicio];
    }

    private function sucessos(array $resultados): int
    {
        return count(array_filter($resultados, fn ($r) => $r['ok']));
    }

    public function test_a_duas_remarcacoes_simultaneas_da_mesma_reserva_nao_se_sobrescrevem_em_silencio(): void
    {
        foreach ([['09:00', '+5511911110001', '2026-10-08'], ['11:00', '+5511911110002', '2026-10-09'], ['15:00', '+5511911110003', '2026-10-13']] as [$hora, $tel, $destino]) {
            $a = $this->reservaDoSite($hora, $tel);

            $resultados = $this->dispararProcessos([
                $this->op('remarcar_cliente', $a, $tel, ['data' => $destino, 'hora' => '14:00']),
                $this->op('remarcar_cliente', $a, $tel, ['data' => $destino, 'hora' => '16:00']),
            ]);

            // Cada uma ou aplica sobre o estado novo (com evento), ou recebe erro claro.
            foreach ($resultados as $r) {
                $this->assertTrue($r['ok'] || in_array($r['tipo'], ['ReservaRecusada', 'QueryException'], true), json_encode($resultados));
            }
            $this->assertGreaterThanOrEqual(1, $this->sucessos($resultados), json_encode($resultados));
            $this->assertHistoricoEmCadeia($a, $this->sucessos($resultados));
        }
    }

    public function test_b_cliente_cancelando_enquanto_o_operador_confirma_o_final_bate_com_o_historico(): void
    {
        foreach (['09:00' => '+5511922220001', '11:00' => '+5511922220002', '15:00' => '+5511922220003'] as $hora => $tel) {
            $a = $this->reservaDoSite($hora, $tel);

            $resultados = $this->dispararProcessos([
                $this->op('cancelar_cliente', $a, $tel),
                $this->op('confirmar', $a, $tel),
            ]);
            [$cancelar, $confirmar] = $resultados;

            // Cancelar sempre vale (de solicitado ou de confirmado); confirmar
            // so se chegou antes do cancelamento, senao recusa clara.
            $this->assertTrue($cancelar['ok'], json_encode($resultados));
            $this->assertTrue($confirmar['ok'] || ($confirmar['codigo'] ?? null) === 'estado_nao_permite', json_encode($resultados));

            [$estado] = $this->assertHistoricoEmCadeia($a, $this->sucessos($resultados));
            $this->assertSame('cancelado', $estado);
        }
    }

    /**
     * Janela forcada: um trigger de TESTE (criado pelo dono, removido no fim)
     * segura por 1,5 s a transacao que muda o estado, com a linha ja
     * alterada e ainda sem COMMIT. A outra operacao comeca 300 ms depois.
     * Com FOR UPDATE no inicio, ela espera e enxerga o estado novo; sem a
     * trava, leria o estado antigo e aplicaria por cima.
     */
    private function comTransicaoLenta(callable $cenario): void
    {
        $dono = $this->conexaoDono();
        $dono->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.teste_transicao_lenta() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.estado IS DISTINCT FROM OLD.estado THEN PERFORM pg_sleep(1.5); END IF;
              RETURN NULL;
            END $$;
            CREATE TRIGGER zz_teste_transicao_lenta AFTER UPDATE ON public.agendamentos
              FOR EACH ROW EXECUTE FUNCTION public.teste_transicao_lenta();
        SQL);
        try {
            $cenario();
        } finally {
            $dono->unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS zz_teste_transicao_lenta ON public.agendamentos;
                DROP FUNCTION IF EXISTS public.teste_transicao_lenta();
            SQL);
        }
    }

    public function test_c2_confirmacao_em_andamento_faz_a_remarcacao_do_cliente_esperar_e_ser_recusada(): void
    {
        $this->comTransicaoLenta(function () {
            $tel = '+5511944440001';
            $a = $this->reservaDoSite('09:00', $tel);

            [$confirmar, $remarcar] = $resultados = $this->dispararProcessos([
                $this->op('confirmar', $a, $tel),
                $this->op('remarcar_cliente', $a, $tel, ['data' => '2026-10-08', 'hora' => '14:00', 'atraso_ms' => 300]),
            ]);

            $this->assertTrue($confirmar['ok'], json_encode($resultados));
            $this->assertSame('remarcacao_exige_novo_pedido', $remarcar['codigo'] ?? null, json_encode($resultados));
            [$estado] = $this->assertHistoricoEmCadeia($a, 1);
            $this->assertSame('confirmado', $estado);
        });
    }

    public function test_b2_cancelamento_em_andamento_faz_a_confirmacao_esperar_e_ser_recusada_com_clareza(): void
    {
        $this->comTransicaoLenta(function () {
            $tel = '+5511944440002';
            $a = $this->reservaDoSite('11:00', $tel);

            [$cancelar, $confirmar] = $resultados = $this->dispararProcessos([
                $this->op('cancelar_cliente', $a, $tel),
                $this->op('confirmar', $a, $tel, ['atraso_ms' => 300]),
            ]);

            $this->assertTrue($cancelar['ok'], json_encode($resultados));
            // Recusa de dominio clara, nao erro de banco (23514 da transicao).
            $this->assertSame(['ReservaRecusada', 'estado_nao_permite'], [$confirmar['tipo'] ?? null, $confirmar['codigo'] ?? null], json_encode($resultados));
            [$estado] = $this->assertHistoricoEmCadeia($a, 1);
            $this->assertSame('cancelado', $estado);
        });
    }

    public function test_c_cliente_so_remarca_o_que_ainda_esta_solicitado_mesmo_com_confirmacao_simultanea(): void
    {
        foreach ([['09:00', '+5511933330001', '2026-10-08'], ['11:00', '+5511933330002', '2026-10-09'], ['15:00', '+5511933330003', '2026-10-13']] as [$hora, $tel, $destino]) {
            $a = $this->reservaDoSite($hora, $tel);

            $resultados = $this->dispararProcessos([
                $this->op('remarcar_cliente', $a, $tel, ['data' => $destino, 'hora' => '14:00']),
                $this->op('confirmar', $a, $tel),
            ]);
            [$remarcar, $confirmar] = $resultados;

            $this->assertTrue($confirmar['ok'], json_encode($resultados));
            $this->assertTrue($remarcar['ok'] || ($remarcar['codigo'] ?? null) === 'remarcacao_exige_novo_pedido', json_encode($resultados));
            $this->assertHistoricoEmCadeia($a, $this->sucessos($resultados));

            // A remarcacao do cliente, se houve, partiu de uma reserva ainda solicitada.
            $this->assertSame(0, DB::table('agendamento_eventos')->where('agendamento_id', $a->id)
                ->where('tipo', 'remarcado')->where('ator', 'cliente')->where('estado_anterior', '<>', 'solicitado')->count());
        }
    }
}
