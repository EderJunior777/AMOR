<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\PedidoDeReserva;
use App\Domain\Agenda\ReservarHorario;
use App\Models\Agendamento;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\DadosDeReserva;
use Tests\Suporte\ProcessosDeReserva;
use Tests\TestCase;

/**
 * Dois OPERADORES (ou operador e cliente) agindo ao mesmo tempo na MESMA
 * reserva, em processos separados com COMMIT real. A reserva e travada (FOR
 * UPDATE) e o estado e conferido dentro da transacao: UM vence e o outro recebe
 * a recusa clara "estado_nao_permite" (a tela mostra "outra pessoa ja agiu"),
 * nunca um erro cru. Invariante: o historico e uma cadeia sem buracos, ha um
 * evento por acao que teve sucesso, e a ocupacao da agenda e coerente.
 */
class AcoesDoOperadorConcorrenciaTest extends TestCase
{
    use DadosDeReserva, ProcessosDeReserva;

    private const AGORA = '2026-10-05 13:00:00';

    private User $operadorA;

    private User $operadorB;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limparTabelasDoDominio();
        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        Carbon::setTestNow(Carbon::parse(self::AGORA, 'UTC'));
        $this->montarAgendaDeReserva();
        $this->operadorA = $this->novoOperador();
        $this->operadorB = $this->novoOperador();
    }

    protected function tearDown(): void
    {
        $this->limparArquivosDeProcessos();
        DB::connection('pgsql_b')->disconnect();
        $this->limparTabelasDoDominio();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reservaDoSite(string $hora, string $telefone): Agendamento
    {
        return $this->app->make(ReservarHorario::class)->executar(
            PedidoDeReserva::deDados($this->dadosDoPedido([
                'hora' => $hora, 'cliente' => ['nome' => 'Cliente da Disputa', 'telefone' => $telefone],
            ])),
            Canal::Site,
        )->agendamento;
    }

    /** @param array<string, mixed> $extra */
    private function op(string $operacao, Agendamento $a, User $operador, array $extra = []): array
    {
        return $extra + [
            'operacao' => $operacao, 'codigo' => $a->codigo_publico, 'telefone' => null,
            'canal' => 'whatsapp', 'operador_id' => $operador->id, 'agora' => self::AGORA, 'motivo' => 'motivo da disputa',
        ];
    }

    /**
     * Cadeia do historico sem buracos; devolve o estado final.
     *
     * @param  int  $sucessos  quantas acoes tiveram sucesso depois da criacao
     */
    private function assertHistoricoEmCadeia(Agendamento $a, int $sucessos): string
    {
        $eventos = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderBy('id')->get();
        $criado = $eventos->shift();
        $this->assertSame('criado', $criado->tipo);
        $estado = $criado->estado_novo;

        foreach ($eventos as $e) {
            $this->assertSame($estado, $e->estado_anterior, "evento {$e->tipo} nao parte do estado anterior");
            $this->assertSame('estado_alterado', $e->tipo);
            $estado = $e->estado_novo;
        }
        $this->assertCount($sucessos, $eventos, 'um evento por acao com sucesso');
        $this->assertSame($estado, $a->fresh()->estado->value, 'estado final = ultimo evento');

        $ocupacoes = DB::table('ocupacoes_agenda')->where('agendamento_id', $a->id)->count();
        $this->assertSame(in_array($estado, ['cancelado', 'nao_compareceu'], true) ? 0 : 1, $ocupacoes, 'ocupacao coerente com o estado');

        return $estado;
    }

    /** @param list<array<string, mixed>> $resultados */
    private function exigirUmVencedorEUmaRecusaClara(array $resultados): void
    {
        $vencedores = array_filter($resultados, fn ($r) => $r['ok']);
        $perdedores = array_filter($resultados, fn ($r) => ! $r['ok']);

        $this->assertCount(1, $vencedores, json_encode($resultados));
        $this->assertCount(1, $perdedores, json_encode($resultados));
        $perdedor = array_values($perdedores)[0];
        $this->assertSame('ReservaRecusada', $perdedor['tipo'], 'recusa de regra, nunca erro cru: '.json_encode($resultados));
        $this->assertSame('estado_nao_permite', $perdedor['codigo']);
    }

    public function test_dois_operadores_confirmando_a_mesma_reserva_um_vence_e_o_outro_recebe_recusa_clara(): void
    {
        foreach ([['09:00', '+5511911110001'], ['10:00', '+5511911110002'], ['11:00', '+5511911110003']] as [$hora, $tel]) {
            $a = $this->reservaDoSite($hora, $tel);

            $resultados = $this->dispararProcessos([
                $this->op('confirmar', $a, $this->operadorA),
                $this->op('confirmar', $a, $this->operadorB),
            ]);

            $this->exigirUmVencedorEUmaRecusaClara($resultados);
            $this->assertSame('confirmado', $this->assertHistoricoEmCadeia($a, 1));
        }
    }

    public function test_confirmar_e_recusar_ao_mesmo_tempo_um_vence_e_o_estado_final_e_o_do_vencedor(): void
    {
        foreach ([['09:00', '+5511911110011'], ['10:00', '+5511911110012'], ['11:00', '+5511911110013']] as [$hora, $tel]) {
            $a = $this->reservaDoSite($hora, $tel);

            $resultados = $this->dispararProcessos([
                $this->op('confirmar', $a, $this->operadorA),
                $this->op('recusar', $a, $this->operadorB),
            ]);

            $this->exigirUmVencedorEUmaRecusaClara($resultados);
            $estado = $this->assertHistoricoEmCadeia($a, 1);
            $vencedor = array_values(array_filter($resultados, fn ($r) => $r['ok']))[0];
            $this->assertSame($vencedor['estado'], $estado, 'o estado final e o da acao que venceu');
            $this->assertContains($estado, ['confirmado', 'cancelado']);
        }
    }

    public function test_dois_operadores_recusando_a_mesma_reserva_so_um_cancela(): void
    {
        $a = $this->reservaDoSite('09:00', '+5511911110021');

        $resultados = $this->dispararProcessos([
            $this->op('recusar', $a, $this->operadorA, ['motivo' => 'motivo do primeiro']),
            $this->op('recusar', $a, $this->operadorB, ['motivo' => 'motivo do segundo']),
        ]);

        $this->exigirUmVencedorEUmaRecusaClara($resultados);
        $this->assertSame('cancelado', $this->assertHistoricoEmCadeia($a, 1));
        $this->assertContains($a->fresh()->motivo_cancelamento, ['motivo do primeiro', 'motivo do segundo'], 'o motivo e o de quem venceu, inteiro');
    }

    public function test_concluir_e_marcar_falta_ao_mesmo_tempo_na_confirmada_um_vence(): void
    {
        $a = $this->reservaDoSite('09:00', '+5511911110031');
        $this->app->make(ReservarHorario::class)->confirmar($a->codigo_publico, $this->operadorA);

        $resultados = $this->dispararProcessos([
            $this->op('concluir', $a, $this->operadorA),
            $this->op('faltou', $a, $this->operadorB),
        ]);

        $this->exigirUmVencedorEUmaRecusaClara($resultados);
        $this->assertContains($this->assertHistoricoEmCadeia($a, 2), ['concluido', 'nao_compareceu']);
    }

    public function test_cliente_cancelando_e_operador_recusando_ao_mesmo_tempo_um_vence(): void
    {
        $a = $this->reservaDoSite('10:00', '+5511911110041');

        $resultados = $this->dispararProcessos([
            $this->op('recusar', $a, $this->operadorA),
            ['operacao' => 'cancelar_cliente', 'codigo' => $a->codigo_publico, 'telefone' => '+5511911110041',
                'canal' => 'site', 'operador_id' => null, 'agora' => self::AGORA],
        ]);

        $this->exigirUmVencedorEUmaRecusaClara($resultados);
        $this->assertSame('cancelado', $this->assertHistoricoEmCadeia($a, 1));
        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderByDesc('id')->first();
        $this->assertContains($evento->ator, ['operador', 'cliente'], 'quem venceu fica registrado');
    }

    public function test_o_barbeiro_de_outro_profissional_nao_age_nem_em_disputa(): void
    {
        $a = $this->reservaDoSite('09:00', '+5511911110051');
        $outro = (int) DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Outro Barbeiro']);

        $resultados = $this->dispararProcessos([
            $this->op('confirmar', $a, $this->operadorA, ['profissional_id' => $outro]),
            $this->op('confirmar', $a, $this->operadorB, ['profissional_id' => $this->profissionalId]),
        ]);

        $this->assertFalse($resultados[0]['ok']);
        $this->assertSame('reserva_nao_encontrada', $resultados[0]['codigo'], 'o de outro profissional nem enxerga a reserva');
        $this->assertTrue($resultados[1]['ok']);
        $this->assertSame('confirmado', $this->assertHistoricoEmCadeia($a, 1));
    }
}
