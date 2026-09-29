<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

class CatalogoEExpedienteTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    public function test_estabelecimento_e_unico(): void
    {
        DB::table('estabelecimento')->insert(['nome' => 'Barbearia Teste']);

        $this->assertBancoRecusa('estabelecimento_unico', fn () => DB::table('estabelecimento')->insert(['id' => 2, 'nome' => 'Filial']));
        $this->assertBancoRecusa('estabelecimento_pkey', fn () => DB::table('estabelecimento')->insert(['nome' => 'Outra']));
    }

    public function test_estabelecimento_valida_whatsapp_e_grade(): void
    {
        $this->assertBancoRecusa('estabelecimento_whatsapp', fn () => DB::table('estabelecimento')->insert(['nome' => 'X Teste', 'whatsapp' => '(11) 98765-4321']));
        $this->assertBancoRecusa('estabelecimento_grade', fn () => DB::table('estabelecimento')->insert(['nome' => 'X Teste', 'grade_minutos' => 7]));
    }

    public function test_estabelecimento_so_aceita_fuso_conhecido(): void
    {
        $this->assertBancoRecusa('estabelecimento_fuso_valido',
            fn () => DB::table('estabelecimento')->insert(['nome' => 'X Teste', 'fuso_horario' => 'America/Sao_Pablo']));

        DB::table('estabelecimento')->insert(['nome' => 'X Teste']);
        $this->assertSame('America/Sao_Paulo', DB::table('estabelecimento')->value('fuso_horario'));
        $this->assertBancoRecusa('estabelecimento_fuso_valido',
            fn () => DB::table('estabelecimento')->update(['fuso_horario' => 'Marte/Olimpo']));
    }

    public function test_servico_recusa_valores_invalidos(): void
    {
        $this->assertBancoRecusa('servicos_preco', fn () => $this->novoServico(['preco_centavos' => -1]));
        $this->assertBancoRecusa('servicos_duracao', fn () => $this->novoServico(['duracao_minutos' => 0]));
        $this->assertBancoRecusa('servicos_modalidade', fn () => $this->novoServico(['permite_barbearia' => false, 'permite_domicilio' => false]));
        $this->assertBancoRecusa('servicos_codigo', fn () => $this->novoServico(['codigo' => 'Corte Top']));

        $this->novoServico(['codigo' => 'corte']);
        $this->assertBancoRecusa('servicos_codigo_key', fn () => $this->novoServico(['codigo' => 'corte']));
    }

    public function test_servico_gratis_e_permitido(): void
    {
        $id = $this->novoServico(['preco_centavos' => 0]);
        $this->assertSame(0, DB::table('servicos')->where('id', $id)->value('preco_centavos'));
    }

    public function test_regiao_recusa_taxa_e_deslocamento_invalidos(): void
    {
        $this->assertBancoRecusa('regioes_taxa', fn () => $this->novaRegiao(['taxa_centavos' => -500]));
        $this->assertBancoRecusa('regioes_deslocamento', fn () => $this->novaRegiao(['deslocamento_minutos' => 300]));
    }

    public function test_vinculo_profissional_servico(): void
    {
        $ze = $this->novoProfissional('Ze');
        $corte = $this->novoServico();

        DB::table('profissional_servico')->insert(['profissional_id' => $ze, 'servico_id' => $corte]);

        $this->assertBancoRecusa('profissional_servico_pkey', fn () => DB::table('profissional_servico')->insert(['profissional_id' => $ze, 'servico_id' => $corte]));
        $this->assertBancoRecusa('profissional_servico_servico_id_fkey', fn () => DB::table('profissional_servico')->insert(['profissional_id' => $ze, 'servico_id' => 999999]));
    }

    public function test_expediente_valida_intervalo_e_dia(): void
    {
        $ze = $this->novoProfissional();

        $this->assertBancoRecusa('expedientes_intervalo', fn () => DB::table('expedientes_semanais')->insert(
            ['profissional_id' => $ze, 'dia_semana' => 1, 'hora_inicio' => '12:00', 'hora_fim' => '12:00']
        ));
        $this->assertBancoRecusa('expedientes_dia_semana', fn () => DB::table('expedientes_semanais')->insert(
            ['profissional_id' => $ze, 'dia_semana' => 7, 'hora_inicio' => '08:00', 'hora_fim' => '12:00']
        ));
    }

    public function test_expediente_com_almoco_e_sem_sobreposicao(): void
    {
        $ze = $this->novoProfissional('Ze');
        $beto = $this->novoProfissional('Beto');
        $janela = fn (int $prof, int $dia, string $ini, string $fim) => DB::table('expedientes_semanais')->insert(
            ['profissional_id' => $prof, 'dia_semana' => $dia, 'hora_inicio' => $ini, 'hora_fim' => $fim]
        );

        // Segunda com almoco das 12 as 13, e uma janela encostada as 20h.
        $janela($ze, 1, '08:00', '12:00');
        $janela($ze, 1, '13:00', '20:00');
        $janela($ze, 1, '20:00', '21:00');

        $this->assertBancoRecusa('expedientes_sem_sobreposicao', fn () => $janela($ze, 1, '11:30', '13:30'), '23P01');

        // Outro dia e outro profissional nao conflitam.
        $janela($ze, 2, '11:30', '13:30');
        $janela($beto, 1, '08:00', '20:00');

        $this->assertSame(5, DB::table('expedientes_semanais')->count());
    }

    public function test_excecao_de_expediente_sem_sobreposicao_na_mesma_data(): void
    {
        $ze = $this->novoProfissional();
        $excecao = fn (string $data, string $ini, string $fim) => DB::table('excecoes_expediente')->insert(
            ['profissional_id' => $ze, 'data' => $data, 'hora_inicio' => $ini, 'hora_fim' => $fim, 'motivo' => 'Vespera de feriado']
        );

        $excecao('2026-12-24', '08:00', '14:00');
        $this->assertBancoRecusa('excecoes_sem_sobreposicao', fn () => $excecao('2026-12-24', '13:00', '15:00'), '23P01');
        $excecao('2026-12-31', '08:00', '14:00');

        $this->assertBancoRecusa('excecoes_intervalo', fn () => $excecao('2026-12-26', '14:00', '08:00'));
    }

    public function test_servico_usado_nao_some_e_historico_guarda_o_preco_da_epoca(): void
    {
        $corte = $this->novoServico(['preco_centavos' => 4000]);
        $agendamento = $this->novoAgendamento('10:00', '10:30', [], [[
            'servico_id' => $corte, 'servico_nome' => 'Corte', 'preco_centavos' => 4000, 'duracao_minutos' => 30,
        ]]);

        $this->assertBancoRecusa('agendamento_itens_servico_id_fkey', fn () => DB::table('servicos')->where('id', $corte)->delete());

        // Reajuste e desativacao do catalogo nao mexem no que ja foi marcado.
        DB::table('servicos')->where('id', $corte)->update(['preco_centavos' => 5500, 'nome' => 'Corte premium', 'ativo' => false]);

        $item = DB::table('agendamento_itens')->where('agendamento_id', $agendamento)->first();
        $this->assertSame(4000, $item->preco_centavos);
        $this->assertSame('Corte', $item->servico_nome);
    }
}
