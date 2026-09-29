<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * A garantia principal: nenhum profissional com dois compromissos ativos
 * sobrepostos, seja agendamento, deslocamento ou bloqueio.
 * (Concorrencia real entre conexoes/processos: ConcorrenciaTest.)
 */
class OcupacaoAgendaTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    private int $ze;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ze = $this->novoProfissional('Ze');
    }

    private function marcar(string $inicio, string $fim, array $extra = []): int
    {
        return $this->novoAgendamento($inicio, $fim, $extra + ['profissional_id' => $this->ze]);
    }

    private function bloquear(string $inicio, string $fim, string $tipo = 'compromisso'): int
    {
        return DB::table('bloqueios_agenda')->insertGetId([
            'profissional_id' => $this->ze, 'tipo' => $tipo,
            'inicio' => $this->em($inicio), 'fim' => $this->em($fim), 'motivo' => 'teste',
        ]);
    }

    public function test_agendamento_ativo_ocupa_exatamente_o_periodo(): void
    {
        $this->marcar('10:00', '10:30');

        $this->assertSame(['["2026-10-01 10:00:00-03","2026-10-01 10:30:00-03")'], $this->ocupacoes($this->ze));
    }

    public function test_sobreposicao_do_mesmo_profissional_e_recusada(): void
    {
        $this->marcar('10:00', '10:30');

        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao', fn () => $this->marcar('10:00', '10:30'), '23P01');
        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao', fn () => $this->marcar('10:15', '10:45'), '23P01');
        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao', fn () => $this->marcar('09:00', '12:00'), '23P01');
        $this->assertCount(1, $this->ocupacoes($this->ze));
    }

    public function test_horarios_encostados_cabem(): void
    {
        $this->marcar('10:00', '10:30');
        $this->marcar('10:30', '11:00');
        $this->marcar('09:30', '10:00');

        $this->assertCount(3, $this->ocupacoes($this->ze));
    }

    public function test_profissionais_diferentes_no_mesmo_horario(): void
    {
        $beto = $this->novoProfissional('Beto');
        $this->marcar('10:00', '10:30');
        $this->novoAgendamento('10:00', '10:30', ['profissional_id' => $beto]);

        $this->assertCount(1, $this->ocupacoes($this->ze));
        $this->assertCount(1, $this->ocupacoes($beto));
    }

    public function test_deslocamento_do_domicilio_ocupa_ida_e_volta(): void
    {
        // Corte 14:00-14:30 na Zona Sul (30 min): sai 13:30, volta 15:00.
        $this->marcar('14:00', '14:30', [
            'cliente_id' => $this->novoCliente(),
            'modalidade' => 'domicilio', 'endereco_texto' => 'Rua Teste, 10',
            'regiao_id' => $this->novaRegiao(), 'regiao_nome' => 'Zona Sul',
            'deslocamento_minutos' => 30, 'taxa_deslocamento_centavos' => 2000,
            'inicio_ocupado' => $this->em('13:30'), 'fim_ocupado' => $this->em('15:00'),
        ]);

        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao', fn () => $this->marcar('13:30', '14:00'));
        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao', fn () => $this->marcar('14:30', '15:00'));
        $this->marcar('13:00', '13:30');
        $this->marcar('15:00', '15:30');

        $this->assertCount(3, $this->ocupacoes($this->ze));
    }

    public function test_bloqueio_e_agendamento_disputam_a_mesma_agenda(): void
    {
        $this->marcar('10:00', '10:30');

        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao', fn () => $this->bloquear('10:00', '12:00'));

        $bloqueio = $this->bloquear('12:00', '13:00', 'folga');
        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao', fn () => $this->marcar('12:30', '13:00'));

        // Cancelar o bloqueio libera o horario.
        DB::table('bloqueios_agenda')->where('id', $bloqueio)->update(['cancelado_em' => now()]);
        $this->marcar('12:30', '13:00');

        $this->assertTrue(DB::table('bloqueios_agenda')->where('id', $bloqueio)->exists(), 'bloqueio cancelado continua registrado');
    }

    public function test_cancelar_libera_o_horario_e_preserva_registro_e_trilha(): void
    {
        $id = $this->marcar('10:00', '10:30');

        $this->comoAtor('cliente');
        DB::table('agendamentos')->where('id', $id)->update([
            'estado' => 'cancelado', 'cancelado_em' => now(), 'motivo_cancelamento' => 'Cliente desmarcou',
        ]);

        $this->assertSame([], $this->ocupacoes($this->ze));
        $this->assertSame('cancelado', DB::table('agendamentos')->where('id', $id)->value('estado'));

        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $id)->orderByDesc('id')->first();
        $this->assertSame('estado_alterado', $evento->tipo);
        $this->assertSame('confirmado', $evento->estado_anterior);
        $this->assertSame('cancelado', $evento->estado_novo);
        $this->assertSame('cliente', $evento->ator);
        $this->assertSame('Cliente desmarcou', json_decode($evento->dados, true)['motivo']);

        // Outro cliente pega o horario.
        $this->marcar('10:00', '10:30');
        $this->assertCount(1, $this->ocupacoes($this->ze));
    }

    public function test_falta_libera_e_concluido_mantem(): void
    {
        $faltou = $this->marcar('10:00', '10:30');
        $veio = $this->marcar('11:00', '11:30');

        DB::table('agendamentos')->where('id', $faltou)->update(['estado' => 'nao_compareceu']);
        DB::table('agendamentos')->where('id', $veio)->update(['estado' => 'concluido']);

        $this->assertSame(['["2026-10-01 11:00:00-03","2026-10-01 11:30:00-03")'], $this->ocupacoes($this->ze));
    }

    public function test_remarcacao_para_horario_ocupado_nao_perde_a_reserva_original(): void
    {
        $id = $this->marcar('10:00', '10:30');
        $this->marcar('11:00', '11:30');
        $eventosAntes = DB::table('agendamento_eventos')->where('agendamento_id', $id)->count();

        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao', fn () => DB::table('agendamentos')->where('id', $id)->update([
            'inicio_servico' => $this->em('11:00'), 'fim_servico' => $this->em('11:30'),
            'inicio_ocupado' => $this->em('11:00'), 'fim_ocupado' => $this->em('11:30'),
        ]));

        DB::statement("SET LOCAL TIME ZONE 'America/Sao_Paulo'");
        $this->assertStringStartsWith('2026-10-01 10:00:00', (string) DB::table('agendamentos')->where('id', $id)->value('inicio_servico'));
        $this->assertSame([
            '["2026-10-01 10:00:00-03","2026-10-01 10:30:00-03")',
            '["2026-10-01 11:00:00-03","2026-10-01 11:30:00-03")',
        ], $this->ocupacoes($this->ze));
        $this->assertSame($eventosAntes, DB::table('agendamento_eventos')->where('agendamento_id', $id)->count());
    }

    public function test_remarcacao_para_horario_livre_move_a_ocupacao_e_registra(): void
    {
        $id = $this->marcar('10:00', '10:30');

        DB::table('agendamentos')->where('id', $id)->update([
            'inicio_servico' => $this->em('16:00'), 'fim_servico' => $this->em('16:30'),
            'inicio_ocupado' => $this->em('16:00'), 'fim_ocupado' => $this->em('16:30'),
        ]);

        $this->assertSame(['["2026-10-01 16:00:00-03","2026-10-01 16:30:00-03")'], $this->ocupacoes($this->ze));

        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $id)->where('tipo', 'remarcado')->first();
        $dados = json_decode($evento->dados, true);
        $this->assertArrayHasKey('de', $dados);
        $this->assertArrayHasKey('para', $dados);

        // O horario antigo ficou livre.
        $this->marcar('10:00', '10:30');
    }

    public function test_troca_de_profissional_respeita_a_agenda_do_destino(): void
    {
        $beto = $this->novoProfissional('Beto');
        $id = $this->marcar('10:00', '10:30');
        $doBeto = $this->novoAgendamento('10:00', '10:30', ['profissional_id' => $beto]);

        $this->assertBancoRecusa('ocupacoes_sem_sobreposicao',
            fn () => DB::table('agendamentos')->where('id', $id)->update(['profissional_id' => $beto]));

        DB::table('agendamentos')->where('id', $doBeto)->update(['estado' => 'cancelado', 'cancelado_em' => now()]);
        DB::table('agendamentos')->where('id', $id)->update(['profissional_id' => $beto]);

        $this->assertSame([], $this->ocupacoes($this->ze));
        $this->assertCount(1, $this->ocupacoes($beto));
    }

    public function test_transacao_que_falha_nao_deixa_ocupacao_orfa(): void
    {
        $antes = DB::table('ocupacoes_agenda')->count();

        try {
            DB::transaction(function () {
                $id = $this->marcar('10:00', '10:30');
                // A ocupacao existe DENTRO da transacao...
                $this->assertSame(1, DB::table('ocupacoes_agenda')->where('agendamento_id', $id)->count());
                throw new \RuntimeException('falha depois de gravar');
            });
        } catch (\RuntimeException) {
        }

        // ...e some junto com ela.
        $this->assertSame($antes, DB::table('ocupacoes_agenda')->count());
        $this->assertSame(0, DB::table('agendamentos')->where('profissional_id', $this->ze)->count());
        $this->marcar('10:00', '10:30');
    }

    public function test_bloqueio_invalido_e_recusado(): void
    {
        // Invertido: a coluna gerada tstzrange() recusa (22000) antes do CHECK
        // bloqueios_intervalo; fim = inicio chega ao CHECK.
        $this->assertBancoRecusa('range lower bound must be less than or equal', fn () => $this->bloquear('12:00', '11:00'), '22000');
        $this->assertBancoRecusa('bloqueios_intervalo', fn () => $this->bloquear('12:00', '12:00'));
        $this->assertBancoRecusa('bloqueios_instantes_finitos', fn () => DB::table('bloqueios_agenda')->insert([
            'profissional_id' => $this->ze, 'tipo' => 'ferias', 'inicio' => $this->em('12:00'), 'fim' => 'infinity',
        ]));
        $this->assertBancoRecusa('bloqueios_max_90_dias', fn () => DB::table('bloqueios_agenda')->insert([
            'profissional_id' => $this->ze, 'tipo' => 'ferias', 'inicio' => '2026-10-01 00:00-03', 'fim' => '2027-03-01 00:00-03',
        ]));
    }

    public function test_ocupacao_nao_pode_ser_mexida_por_fora(): void
    {
        $id = $this->marcar('10:00', '10:30');

        $this->assertBancoRecusa('ocupacoes_protegidas',
            fn () => DB::table('ocupacoes_agenda')->where('agendamento_id', $id)->delete());
        $this->assertBancoRecusa('ocupacoes_do_agendamento',
            fn () => DB::table('ocupacoes_agenda')->where('agendamento_id', $id)
                ->update(['periodo' => DB::raw("tstzrange('2026-10-01 18:00-03', '2026-10-01 18:30-03', '[)')")]));
        $this->assertBancoRecusa('ocupacoes_uma_origem',
            fn () => DB::table('ocupacoes_agenda')->insert([
                'profissional_id' => $this->ze,
                'periodo' => DB::raw("tstzrange('2026-10-01 18:00-03', '2026-10-01 18:30-03', '[)')"),
            ]));
    }
}
