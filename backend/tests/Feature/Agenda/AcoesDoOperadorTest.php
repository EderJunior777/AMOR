<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\RepetirEmConflito;
use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\ReservarHorario;
use App\Enums\EstadoAgendamento;
use App\Models\Agendamento;
use App\Models\User;
use App\Support\AutoriaInvalida;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * Acoes do OPERADOR sobre a reserva (etapa 3, painel): confirmar, recusar,
 * iniciar atendimento, concluir, nao compareceu e cancelar com motivo. Todas
 * em ReservarHorario, via TransacaoAuditada (ator operador + o usuario que
 * agiu), com a reserva travada e o estado conferido dentro da transacao, e com
 * filtro OPCIONAL de profissional na MESMA consulta travada (o barbeiro so
 * age no proprio profissional). Transacao de teste: sem COMMIT real.
 */
class AcoesDoOperadorTest extends TestCase
{
    use BancoDeTeste, DadosDeReserva;

    private User $operador;

    private int $sequencia = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();
        $this->operador = $this->novoOperador();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servico(): ReservarHorario
    {
        return $this->app->make(ReservarHorario::class);
    }

    /** Reserva do site (nasce solicitada), em horario proprio: a agenda e unica por profissional. */
    private function reserva(): Agendamento
    {
        $i = $this->sequencia++;
        $dia = sprintf('2026-10-%02d', 7 + intdiv($i, 8));
        // Expediente do cenario: 08-12 e 13-20 (sem 12:00, que e o almoco).
        $hora = sprintf('%02d:00', [8, 9, 10, 11, 13, 14, 15, 16][$i % 8]);

        return $this->servico()->executar(
            $this->pedido(['data' => $dia, 'hora' => $hora, 'cliente' => ['nome' => 'Cliente '.$i, 'telefone' => sprintf('+55119%08d', 87650000 + $i)]]),
            Canal::Site,
        )->agendamento;
    }

    private function recusa(string $codigo, callable $acao): ReservaRecusada
    {
        try {
            $acao();
        } catch (ReservaRecusada $e) {
            $this->assertSame($codigo, $e->codigo, $e->getMessage());

            return $e;
        }
        $this->fail("Deveria ter sido recusado com {$codigo}.");
    }

    /** Leva uma reserva do site (solicitado) ate o estado pedido, pelos metodos do dominio. */
    private function levarAte(Agendamento $a, EstadoAgendamento $alvo): Agendamento
    {
        $s = $this->servico();
        $codigo = $a->codigo_publico;
        match ($alvo) {
            EstadoAgendamento::Solicitado => null,
            EstadoAgendamento::Confirmado => $s->confirmar($codigo, $this->operador),
            EstadoAgendamento::EmAtendimento => [$s->confirmar($codigo, $this->operador), $s->iniciar($codigo, $this->operador)],
            EstadoAgendamento::Concluido => [$s->confirmar($codigo, $this->operador), $s->concluir($codigo, $this->operador)],
            EstadoAgendamento::Cancelado => $s->recusar($codigo, $this->operador, 'sem horario'),
            EstadoAgendamento::NaoCompareceu => [$s->confirmar($codigo, $this->operador), $s->marcarFalta($codigo, $this->operador)],
        };

        return $a->fresh();
    }

    /** @return list<object> */
    private function eventos(Agendamento $a): array
    {
        return DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderBy('id')->get()->all();
    }

    // ------------------------------------------------------------ transicoes

    public function test_confirmar_iniciar_e_concluir_gravam_estado_e_historico_do_operador(): void
    {
        $a = $this->reserva();

        $this->assertSame(EstadoAgendamento::Confirmado, $this->servico()->confirmar($a->codigo_publico, $this->operador)->estado);
        $this->assertSame(EstadoAgendamento::EmAtendimento, $this->servico()->iniciar($a->codigo_publico, $this->operador)->estado);
        $this->assertSame(EstadoAgendamento::Concluido, $this->servico()->concluir($a->codigo_publico, $this->operador)->estado);

        $eventos = $this->eventos($a);
        $this->assertSame(['criado', 'estado_alterado', 'estado_alterado', 'estado_alterado'], array_column($eventos, 'tipo'));
        $this->assertSame(['solicitado', 'confirmado', 'em_atendimento'], array_column(array_slice($eventos, 1), 'estado_anterior'));
        $this->assertSame(['confirmado', 'em_atendimento', 'concluido'], array_column(array_slice($eventos, 1), 'estado_novo'));
        foreach (array_slice($eventos, 1) as $evento) {
            $this->assertSame('operador', $evento->ator);
            $this->assertSame($this->operador->id, (int) $evento->usuario_id, 'o usuario do evento e quem agiu');
        }
    }

    public function test_concluir_direto_da_confirmada_e_permitido_pelo_contrato(): void
    {
        $a = $this->reserva();
        $this->servico()->confirmar($a->codigo_publico, $this->operador);

        $this->assertSame(EstadoAgendamento::Concluido, $this->servico()->concluir($a->codigo_publico, $this->operador)->estado);
    }

    public function test_nao_compareceu_so_da_confirmada_e_libera_o_horario_na_agenda(): void
    {
        $a = $this->reserva();
        $this->servico()->confirmar($a->codigo_publico, $this->operador);

        $depois = $this->servico()->marcarFalta($a->codigo_publico, $this->operador);

        $this->assertSame(EstadoAgendamento::NaoCompareceu, $depois->estado);
        $this->assertSame('nao_compareceu', $this->eventos($a)[2]->estado_novo);
        $this->assertSame(0, DB::table('ocupacoes_agenda')->where('agendamento_id', $a->id)->count(), 'a falta libera o horario na agenda');
    }

    // ----------------------------------------------------------------- recusar

    public function test_recusar_cancela_com_o_motivo_no_historico_e_libera_o_horario(): void
    {
        $a = $this->reserva();

        $depois = $this->servico()->recusar($a->codigo_publico, $this->operador, "  Estou   de\nfolga  ");

        $this->assertSame(EstadoAgendamento::Cancelado, $depois->estado);
        $this->assertSame('Estou de folga', $depois->motivo_cancelamento, 'espacos normalizados');
        $this->assertNotNull($depois->cancelado_em);
        $evento = $this->eventos($a)[1];
        $this->assertSame('estado_alterado', $evento->tipo);
        $this->assertSame('cancelado', $evento->estado_novo);
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($this->operador->id, (int) $evento->usuario_id);
        $this->assertSame('Estou de folga', json_decode($evento->dados, true)['motivo']);
        $this->assertSame(0, DB::table('ocupacoes_agenda')->where('agendamento_id', $a->id)->count());
    }

    /** @return array<string, array{0: ?string}> */
    public static function motivosAusentes(): array
    {
        return ['nulo' => [null], 'vazio' => [''], 'so espacos' => ['   '], 'so quebras' => ["\n\t "]];
    }

    #[DataProvider('motivosAusentes')]
    public function test_recusar_exige_motivo_e_nao_muda_nada_sem_ele(?string $motivo): void
    {
        $a = $this->reserva();

        $this->recusa('motivo_da_recusa_obrigatorio', fn () => $this->servico()->recusar($a->codigo_publico, $this->operador, $motivo));

        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);
        $this->assertCount(1, $this->eventos($a));
    }

    public function test_o_motivo_de_ate_300_caracteres_passa_e_o_de_301_e_recusado_sem_cortar(): void
    {
        $a = $this->reserva();
        $b = $this->reserva();

        $this->recusa('motivo_muito_longo', fn () => $this->servico()->recusar($a->codigo_publico, $this->operador, str_repeat('a', 301)));
        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado, 'nada foi gravado, nada foi cortado');
        $this->assertNull($a->fresh()->motivo_cancelamento);

        $depois = $this->servico()->recusar($b->codigo_publico, $this->operador, str_repeat('ç', 300));
        $this->assertSame(300, mb_strlen((string) $depois->motivo_cancelamento), '300 caracteres (nao bytes) passam inteiros');
    }

    public function test_recusar_so_vale_para_solicitada(): void
    {
        foreach ([EstadoAgendamento::Confirmado, EstadoAgendamento::EmAtendimento, EstadoAgendamento::Concluido, EstadoAgendamento::Cancelado, EstadoAgendamento::NaoCompareceu] as $estado) {
            $a = $this->levarAte($this->reserva(), $estado);

            $this->recusa('estado_nao_permite', fn () => $this->servico()->recusar($a->codigo_publico, $this->operador, 'motivo'));
            $this->assertSame($estado, $a->fresh()->estado);
        }
    }

    // ------------------------------------------------------ cancelar com motivo

    public function test_cancelar_com_motivo_exige_motivo_e_vale_de_solicitada_confirmada_e_em_atendimento(): void
    {
        foreach ([EstadoAgendamento::Solicitado, EstadoAgendamento::Confirmado, EstadoAgendamento::EmAtendimento] as $estado) {
            $a = $this->levarAte($this->reserva(), $estado);

            $this->recusa('motivo_do_cancelamento_obrigatorio', fn () => $this->servico()->cancelarComMotivo($a->codigo_publico, $this->operador, ' '));
            $this->assertSame($estado, $a->fresh()->estado);

            $depois = $this->servico()->cancelarComMotivo($a->codigo_publico, $this->operador, 'cliente pediu');
            $this->assertSame(EstadoAgendamento::Cancelado, $depois->estado);
            $this->assertSame('cliente pediu', $depois->motivo_cancelamento);
        }
    }

    public function test_cancelar_com_motivo_longo_demais_e_recusado(): void
    {
        $a = $this->reserva();

        $this->recusa('motivo_muito_longo', fn () => $this->servico()->cancelarComMotivo($a->codigo_publico, $this->operador, str_repeat('a', 301)));
        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);
    }

    // ------------------------------------- contrato de estados: toda combinacao

    /** @return array<string, array{0: EstadoAgendamento, 1: string, 2: EstadoAgendamento}> */
    public static function matrizEstadoOperacao(): array
    {
        // operacao => estado de destino (o banco e a fonte: EstadoAgendamento::podeIrPara).
        $operacoes = [
            'confirmar' => EstadoAgendamento::Confirmado,
            'recusar' => EstadoAgendamento::Cancelado,
            'iniciar' => EstadoAgendamento::EmAtendimento,
            'concluir' => EstadoAgendamento::Concluido,
            'marcarFalta' => EstadoAgendamento::NaoCompareceu,
            'cancelarComMotivo' => EstadoAgendamento::Cancelado,
        ];
        $casos = [];
        foreach (EstadoAgendamento::cases() as $origem) {
            foreach ($operacoes as $operacao => $destino) {
                $casos["{$origem->value} -> {$operacao}"] = [$origem, $operacao, $destino];
            }
        }

        return $casos;
    }

    #[DataProvider('matrizEstadoOperacao')]
    public function test_cada_operacao_so_acontece_onde_o_contrato_de_estados_permite(EstadoAgendamento $origem, string $operacao, EstadoAgendamento $destino): void
    {
        $a = $this->levarAte($this->reserva(), $origem);
        // "recusar" e mais estreito que o contrato: so da solicitada (de outros estados, cancelar com motivo).
        $permitido = $origem->podeIrPara($destino) && ($operacao !== 'recusar' || $origem === EstadoAgendamento::Solicitado);
        $chamar = fn () => match ($operacao) {
            'recusar', 'cancelarComMotivo' => $this->servico()->{$operacao}($a->codigo_publico, $this->operador, 'motivo'),
            default => $this->servico()->{$operacao}($a->codigo_publico, $this->operador),
        };

        if ($permitido) {
            $this->assertSame($destino, $chamar()->estado);
            $this->assertSame($destino, $a->fresh()->estado);
        } else {
            $this->recusa('estado_nao_permite', $chamar);
            $this->assertSame($origem, $a->fresh()->estado, 'recusada pelo contrato: nada muda');
        }
    }

    // ------------------------------------------------- escopo por profissional

    public function test_com_o_filtro_de_profissional_so_age_na_reserva_dele(): void
    {
        $a = $this->reserva();
        $outro = (int) DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Outro Barbeiro']);

        foreach ([
            fn () => $this->servico()->confirmar($a->codigo_publico, $this->operador, $outro),
            fn () => $this->servico()->recusar($a->codigo_publico, $this->operador, 'motivo', $outro),
            fn () => $this->servico()->iniciar($a->codigo_publico, $this->operador, $outro),
            fn () => $this->servico()->concluir($a->codigo_publico, $this->operador, $outro),
            fn () => $this->servico()->marcarFalta($a->codigo_publico, $this->operador, $outro),
            fn () => $this->servico()->cancelarComMotivo($a->codigo_publico, $this->operador, 'motivo', $outro),
        ] as $acao) {
            $this->recusa('reserva_nao_encontrada', $acao);
        }
        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);
        $this->assertCount(1, $this->eventos($a), 'nenhum evento foi gravado');

        $this->assertSame(EstadoAgendamento::Confirmado, $this->servico()->confirmar($a->codigo_publico, $this->operador, $this->profissionalId)->estado);
    }

    public function test_codigo_mal_formado_ou_inexistente_da_a_mesma_recusa(): void
    {
        foreach (['nao-e-uuid', '00000000-0000-4000-8000-000000000000', ''] as $codigo) {
            $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->confirmar($codigo, $this->operador));
            $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->recusar($codigo, $this->operador, 'motivo'));
        }
    }

    // --------------------------------------------------------------- autoria

    public function test_operador_inativo_nao_age(): void
    {
        $a = $this->reserva();
        DB::table('users')->where('id', $this->operador->id)->update(['ativo' => false]);

        foreach (['confirmar', 'iniciar', 'concluir', 'marcarFalta'] as $metodo) {
            try {
                $this->servico()->{$metodo}($a->codigo_publico, $this->operador->fresh());
                $this->fail("{$metodo}: usuario inativo nao pode agir");
            } catch (AutoriaInvalida) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);
    }

    public function test_usuario_que_nao_foi_salvo_nao_age(): void
    {
        $a = $this->reserva();

        $this->expectException(AutoriaInvalida::class);
        $this->servico()->confirmar($a->codigo_publico, User::factory()->make(['id' => 777777]));
    }

    public function test_o_ator_e_sempre_operador_nunca_cliente_nem_sistema(): void
    {
        $a = $this->reserva();
        $this->servico()->confirmar($a->codigo_publico, $this->operador);
        $this->servico()->iniciar($a->codigo_publico, $this->operador);
        $this->servico()->cancelarComMotivo($a->codigo_publico, $this->operador, 'motivo');

        foreach (array_slice($this->eventos($a), 1) as $evento) {
            $this->assertSame('operador', $evento->ator);
            $this->assertNotNull($evento->usuario_id);
        }
    }
}
