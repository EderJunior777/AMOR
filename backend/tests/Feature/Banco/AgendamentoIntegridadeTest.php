<?php

namespace Tests\Feature\Banco;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

class AgendamentoIntegridadeTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    private function domicilio(array $extra = []): array
    {
        $cliente = $this->novoCliente();

        return $extra + [
            'cliente_id' => $cliente,
            'modalidade' => 'domicilio',
            'endereco_texto' => 'Rua das Flores, 123',
            'regiao_id' => $this->novaRegiao(),
            'regiao_nome' => 'Zona Sul',
            'deslocamento_minutos' => 30,
            'taxa_deslocamento_centavos' => 2000,
            'inicio_ocupado' => $this->em('13:30'),
            'fim_ocupado' => $this->em('15:00'),
        ];
    }

    public function test_domicilio_completo_e_aceito_com_snapshot(): void
    {
        $id = $this->novoAgendamento('14:00', '14:30', $this->domicilio());

        $ag = DB::table('agendamentos')->find($id);
        $this->assertSame(2000, $ag->taxa_deslocamento_centavos);
        $this->assertSame('Zona Sul', $ag->regiao_nome);
    }

    public function test_domicilio_exige_endereco_e_regiao(): void
    {
        $this->assertBancoRecusa('agendamentos_domicilio_completo',
            fn () => $this->novoAgendamento('14:00', '14:30', $this->domicilio(['endereco_texto' => null])));
        $this->assertBancoRecusa('agendamentos_domicilio_completo',
            fn () => $this->novoAgendamento('14:00', '14:30', $this->domicilio(['regiao_id' => null])));
    }

    public function test_barbearia_nao_leva_taxa_nem_endereco(): void
    {
        $this->assertBancoRecusa('agendamentos_barbearia_sem_domicilio',
            fn () => $this->novoAgendamento('10:00', '10:30', ['taxa_deslocamento_centavos' => 2000]));
        $this->assertBancoRecusa('agendamentos_barbearia_sem_domicilio',
            fn () => $this->novoAgendamento('10:00', '10:30', ['endereco_texto' => 'Rua X, 1']));
    }

    public function test_intervalos_coerentes(): void
    {
        $this->assertBancoRecusa('agendamentos_servico_intervalo', fn () => $this->novoAgendamento('10:30', '10:30'));
        // Periodo ocupado que nao contem o servico.
        $this->assertBancoRecusa('agendamentos_deslocamento_reservado', fn () => $this->novoAgendamento('10:00', '10:30', [
            'inicio_ocupado' => $this->em('10:15'),
        ]));
        $this->assertBancoRecusa('agendamentos_minutos_cheios', fn () => $this->novoAgendamento('10:00', '10:30', [
            'inicio_servico' => '2026-10-01 10:00:30-03:00',
            'inicio_ocupado' => '2026-10-01 10:00:30-03:00',
        ]));
    }

    public function test_intervalo_invertido_infinito_ou_nulo_e_recusado(): void
    {
        $this->assertBancoRecusa('agendamentos_servico_intervalo', fn () => $this->novoAgendamento('11:00', '10:30', [
            'inicio_ocupado' => $this->em('10:30'), 'fim_ocupado' => $this->em('11:00'),
        ]));
        // Periodo OCUPADO invertido: a propria coluna gerada tstzrange()
        // recusa (22000), antes de qualquer CHECK.
        $this->assertBancoRecusa('range lower bound must be less than or equal', fn () => $this->novoAgendamento('10:00', '10:30', [
            'inicio_ocupado' => $this->em('11:00'), 'fim_ocupado' => $this->em('10:00'),
        ], [['duracao_minutos' => 30]]), '22000');
        $this->assertBancoRecusa('agendamentos_instantes_finitos', fn () => $this->novoAgendamento('10:00', '10:30', [
            'fim_ocupado' => 'infinity',
        ], [['duracao_minutos' => 30]]));
        $this->assertBancoRecusa('agendamentos_instantes_finitos', fn () => $this->novoAgendamento('10:00', '10:30', [
            'inicio_ocupado' => '-infinity',
        ], [['duracao_minutos' => 30]]));
        // Nulo nao contorna a exclusao: nem chega a existir.
        $this->assertBancoRecusa('inicio_ocupado', fn () => $this->novoAgendamento('10:00', '10:30', [
            'inicio_ocupado' => null,
        ], [['duracao_minutos' => 30]]), '23502');
    }

    public function test_deslocamento_declarado_precisa_estar_no_periodo_ocupado(): void
    {
        // 30 min de deslocamento, mas so 15 reservados antes do servico.
        $this->assertBancoRecusa('agendamentos_deslocamento_reservado',
            fn () => $this->novoAgendamento('14:00', '14:30', $this->domicilio(['inicio_ocupado' => $this->em('13:45')])));
        // Arredondado para a grade (mais tempo que o deslocamento): ok.
        $this->assertIsInt($this->novoAgendamento('14:00', '14:30', $this->domicilio([
            'deslocamento_minutos' => 15, 'inicio_ocupado' => $this->em('13:30'), 'fim_ocupado' => $this->em('15:00'),
        ])));
    }

    public function test_agendamento_encerrado_e_imutavel_inclusive_os_itens(): void
    {
        $id = $this->novoAgendamento('14:00', '14:30', $this->domicilio());
        DB::table('agendamentos')->where('id', $id)->update(['estado' => 'concluido']);

        foreach ([
            ['taxa_deslocamento_centavos' => 0],
            ['regiao_nome' => 'Outra'],
            ['endereco_texto' => 'Outro endereco, 1'],
            ['origem' => 'presencial'],
            ['observacao_cliente' => 'editado depois'],
        ] as $mudanca) {
            $this->assertBancoRecusa('agendamentos_encerrado_imutavel',
                fn () => DB::table('agendamentos')->where('id', $id)->update($mudanca));
        }

        $item = DB::table('agendamento_itens')->where('agendamento_id', $id)->value('id');
        $this->assertBancoRecusa('agendamento_itens_encerrado_imutavel',
            fn () => DB::table('agendamento_itens')->where('id', $item)->update(['preco_centavos' => 0]));
        $this->assertBancoRecusa('agendamento_itens_encerrado_imutavel',
            fn () => DB::table('agendamento_itens')->where('id', $item)->delete());
        $this->assertBancoRecusa('agendamento_itens_encerrado_imutavel',
            fn () => DB::table('agendamento_itens')->insert([
                'agendamento_id' => $id, 'servico_id' => DB::table('agendamento_itens')->where('id', $item)->value('servico_id'),
                'ordem' => 2, 'servico_nome' => 'Extra', 'preco_centavos' => 1, 'duracao_minutos' => 30, 'conta_como_corte' => false,
            ]));

        $this->assertSame(4000, DB::table('agendamento_itens')->where('id', $item)->value('preco_centavos'));
        $this->assertSame(2000, DB::table('agendamentos')->where('id', $id)->value('taxa_deslocamento_centavos'));
    }

    public function test_atendimento_espontaneo_ja_concluido_na_mesma_transacao(): void
    {
        // Quem chegou sem reserva: nasce em_atendimento, recebe itens, conclui.
        $this->assertBancoRecusa('agendamentos_estado_inicial',
            fn () => $this->novoAgendamento('10:00', '10:30', ['estado' => 'concluido', 'origem' => 'presencial']));

        $id = DB::transaction(function () {
            $id = $this->novoAgendamento('10:00', '10:30', ['estado' => 'em_atendimento', 'origem' => 'presencial']);
            DB::table('agendamentos')->where('id', $id)->update(['estado' => 'concluido']);

            return $id;
        });
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $this->assertSame('concluido', DB::table('agendamentos')->where('id', $id)->value('estado'));
        $this->assertSame(1, DB::table('ocupacoes_agenda')->where('agendamento_id', $id)->count(), 'atendimento realizado ocupa a agenda');
    }

    public function test_dominios_de_estado_origem_e_modalidade(): void
    {
        // Estado desconhecido esbarra primeiro no trigger (antes do CHECK),
        // tanto ao criar quanto ao mudar. O CHECK fica como segunda camada.
        $this->assertBancoRecusa('agendamentos_estado_inicial', fn () => $this->novoAgendamento('10:00', '10:30', ['estado' => 'pago']));
        $id = $this->novoAgendamento('10:00', '10:30');
        $this->assertBancoRecusa('agendamentos_transicao_estado', fn () => DB::table('agendamentos')->where('id', $id)->update(['estado' => 'pago']));
        $this->assertTrue(DB::table('pg_constraint')->where('conname', 'agendamentos_estado_valido')->exists());

        $this->assertBancoRecusa('agendamentos_origem_valida', fn () => $this->novoAgendamento('10:00', '10:30', ['origem' => 'instagram']));
        $this->assertBancoRecusa('agendamentos_modalidade_valida', fn () => $this->novoAgendamento('10:00', '10:30', ['modalidade' => 'online']));
    }

    public function test_cancelamento_exige_data(): void
    {
        $id = $this->novoAgendamento('10:00', '10:30');

        $this->assertBancoRecusa('agendamentos_cancelamento_coerente',
            fn () => DB::table('agendamentos')->where('id', $id)->update(['estado' => 'cancelado']));
    }

    public function test_chave_de_idempotencia_unica_e_com_hash(): void
    {
        $hash = hash('sha256', 'corpo');
        $this->novoAgendamento('10:00', '10:30', ['chave_idempotencia' => 'abc-123', 'hash_requisicao' => $hash]);

        $this->assertBancoRecusa('agendamentos_chave_idempotencia_unica', fn () => $this->novoAgendamento('11:00', '11:30', [
            'chave_idempotencia' => 'abc-123', 'hash_requisicao' => $hash,
        ]));
        $this->assertBancoRecusa('agendamentos_idempotencia_coerente', fn () => $this->novoAgendamento('12:00', '12:30', [
            'chave_idempotencia' => 'sem-hash',
        ]));
    }

    public function test_agendamento_precisa_de_servico(): void
    {
        $this->assertBancoRecusa('agendamentos_com_servico', fn () => $this->novoAgendamento('10:00', '10:30', [], []));
    }

    public function test_itens_precisam_cobrir_a_duracao_do_servico(): void
    {
        $this->assertBancoRecusa('agendamentos_duracao_dos_itens', fn () => $this->novoAgendamento('10:00', '11:00', [], [
            ['duracao_minutos' => 30],
        ]));

        // Corte (30) + barba (30) em 60 min: ok.
        $id = $this->novoAgendamento('10:00', '11:00', [], [
            ['duracao_minutos' => 30, 'servico_nome' => 'Corte'],
            ['duracao_minutos' => 30, 'servico_nome' => 'Barba', 'conta_como_corte' => false, 'preco_centavos' => 3000],
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $this->assertSame(7000, (int) DB::table('agendamento_itens')->where('agendamento_id', $id)->sum('preco_centavos'));
    }

    public function test_historico_e_somente_insercao(): void
    {
        $id = $this->novoAgendamento('10:00', '10:30');
        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $id)->value('id');

        // A aplicacao nao tem UPDATE/DELETE no historico; o trigger
        // agendamento_eventos_somente_insercao barra ate o papel dono
        // (EscritaDiretaNaAgendaTest).
        $this->assertBancoRecusa('agendamento_eventos',
            fn () => DB::table('agendamento_eventos')->where('id', $evento)->update(['estado_novo' => 'concluido']), '42501');
        $this->assertBancoRecusa('agendamento_eventos',
            fn () => DB::table('agendamento_eventos')->where('id', $evento)->delete(), '42501');
    }

    public function test_operador_precisa_ser_identificado_no_historico(): void
    {
        $this->assertBancoRecusa('agendamento_eventos_operador_identificado', function () {
            $this->comoAtor('operador');
            $this->novoAgendamento('10:00', '10:30');
        });
    }

    public function test_agendamento_nao_e_apagado(): void
    {
        $id = $this->novoAgendamento('10:00', '10:30');

        try {
            DB::transaction(fn () => DB::table('agendamentos')->where('id', $id)->delete());
            $this->fail('Agendamento com historico foi apagado.');
        } catch (QueryException $e) {
            // 23001 = restrict_violation (FKs ON DELETE RESTRICT de itens/historico)
            $this->assertSame('23001', $e->errorInfo[0], $e->getMessage());
        }
        $this->assertTrue(DB::table('agendamentos')->where('id', $id)->exists());
    }
}
