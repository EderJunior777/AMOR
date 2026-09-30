<?php

namespace Tests\Feature\Api;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\ReservarHorario;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * GET /api/v1/disponibilidade. Agora: segunda 2026-10-05 10:00 SP; alvo padrao:
 * quarta 2026-10-07, expediente 08-12 e 13-20, grade 30, servico de 30 min.
 */
class DisponibilidadeApiTest extends ApiTestCase
{
    private const QUARTA = '2026-10-07';

    private const MANHA = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30'];

    private const TARDE = ['13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30', '17:00', '17:30', '18:00', '18:30', '19:00', '19:30'];

    /** @param array<string, mixed> $sobrescrever */
    private function consultar(array $sobrescrever = []): TestResponse
    {
        $query = $sobrescrever + [
            'data' => self::QUARTA,
            'servicos' => [$this->corteId],
            'profissional_id' => $this->profissionalId,
            'modalidade' => 'barbearia',
        ];

        return $this->getJson('/api/v1/disponibilidade?'.http_build_query($query));
    }

    private function reservarDireto(array $sobrescrever): void
    {
        $this->app->make(ReservarHorario::class)->executar($this->pedido($sobrescrever), Canal::Site);
    }

    public function test_dia_livre_respeita_expediente_e_almoco_com_formato_exato(): void
    {
        $this->consultar()->assertOk()->assertExactJson([
            'data' => self::QUARTA,
            'horarios' => [...self::MANHA, ...self::TARDE],
        ]);
    }

    public function test_horario_ocupado_some_e_a_sobreposicao_de_dois_servicos_tambem(): void
    {
        $this->reservarDireto(['hora' => '10:00']);

        $um = $this->consultar()->assertOk()->json('horarios');
        $this->assertNotContains('10:00', $um);
        $this->assertContains('09:30', $um);
        $this->assertContains('10:30', $um);

        // Corte + barba = 60 min: 09:30 termina 10:30 e bate no das 10:00.
        $dois = $this->consultar(['servicos' => [$this->corteId, $this->barbaId]])->assertOk()->json('horarios');
        $this->assertNotContains('09:30', $dois);
        $this->assertNotContains('10:00', $dois);
        $this->assertContains('09:00', $dois);
        $this->assertContains('10:30', $dois);
        // 11:30 + 60 min passa do fim da janela da manha (12:00).
        $this->assertNotContains('11:30', $dois);
        $this->assertContains('13:00', $dois);
    }

    public function test_reserva_cancelada_libera_o_horario(): void
    {
        $codigo = $this->codigoDeUmaReserva(['hora' => '10:00']);
        $this->assertNotContains('10:00', $this->consultar()->json('horarios'));

        $this->postJson('/api/v1/reservas/cancelar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])->assertOk();

        $this->assertContains('10:00', $this->consultar()->json('horarios'));
    }

    public function test_bloqueio_da_agenda_some_sem_revelar_o_motivo(): void
    {
        DB::table('bloqueios_agenda')->insert([
            'profissional_id' => $this->profissionalId, 'tipo' => 'folga',
            'inicio' => '2026-10-07 15:00:00+00', 'fim' => '2026-10-07 16:00:00+00', // 12:00-13:00 SP
            'motivo' => 'Consulta-medica-do-Ze',
        ]);
        DB::table('bloqueios_agenda')->insert([
            'profissional_id' => $this->profissionalId, 'tipo' => 'folga',
            'inicio' => '2026-10-07 17:00:00+00', 'fim' => '2026-10-07 18:00:00+00', // 14:00-15:00 SP
            'motivo' => 'Consulta-medica-do-Ze',
        ]);

        $resposta = $this->consultar()->assertOk();
        $horarios = $resposta->json('horarios');
        $this->assertNotContains('14:00', $horarios);
        $this->assertNotContains('14:30', $horarios);
        $this->assertContains('13:30', $horarios);
        $this->assertContains('15:00', $horarios);
        $this->assertStringNotContainsString('Consulta', $resposta->getContent());
    }

    public function test_bloqueio_de_outro_profissional_nao_afeta(): void
    {
        $outro = DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Outro']);
        DB::table('bloqueios_agenda')->insert([
            'profissional_id' => $outro, 'tipo' => 'folga',
            'inicio' => '2026-10-07 11:00:00+00', 'fim' => '2026-10-07 23:00:00+00',
        ]);

        $this->consultar()->assertOk()->assertJsonPath('horarios', [...self::MANHA, ...self::TARDE]);
    }

    public function test_excecao_de_expediente_substitui_a_semanal(): void
    {
        DB::table('excecoes_expediente')->insert([
            'profissional_id' => $this->profissionalId, 'data' => self::QUARTA,
            'hora_inicio' => '09:00', 'hora_fim' => '11:00',
        ]);

        $this->consultar()->assertOk()->assertJsonPath('horarios', ['09:00', '09:30', '10:00', '10:30']);
    }

    public function test_dia_sem_expediente_devolve_lista_vazia(): void
    {
        DB::table('expedientes_semanais')->where('profissional_id', $this->profissionalId)->where('dia_semana', 3)->delete();

        $this->consultar()->assertOk()->assertExactJson(['data' => self::QUARTA, 'horarios' => []]);
    }

    public function test_domicilio_desconta_o_deslocamento_nas_duas_pontas(): void
    {
        $r = $this->consultar(['modalidade' => 'domicilio', 'regiao_id' => $this->regiaoId])->assertOk();

        // Deslocamento de 30 min: 08:00 ocuparia 07:30 (fora); o ultimo da manha e 11:00.
        $this->assertSame(
            ['08:30', '09:00', '09:30', '10:00', '10:30', '11:00',
                '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30', '17:00', '17:30', '18:00', '18:30', '19:00'],
            $r->json('horarios'),
        );
    }

    public function test_antecedencia_minima_no_dia_de_hoje(): void
    {
        // Agora 10:00 SP + 30 min: 10:30 ainda vale (igual e permitido), 10:00 nao.
        $this->consultar(['data' => '2026-10-05'])->assertOk()->assertExactJson([
            'data' => '2026-10-05',
            'horarios' => ['10:30', '11:00', '11:30', ...self::TARDE],
        ]);
    }

    public function test_data_passada_e_recusada_por_antecedencia(): void
    {
        $this->consultar(['data' => '2026-10-04'])->assertStatus(422)
            ->assertJsonPath('codigo', 'antecedencia')->assertJsonStructure(['mensagem', 'codigo']);
    }

    public function test_horizonte_hoje_mais_30_dias_vale_e_o_dia_seguinte_nao(): void
    {
        $this->consultar(['data' => '2026-11-04'])->assertOk();
        $this->consultar(['data' => '2026-11-05'])->assertStatus(422)->assertJsonPath('codigo', 'alem_do_horizonte');
    }

    public function test_data_inexistente_no_calendario_e_data_invalida_do_dominio(): void
    {
        $this->consultar(['data' => '2026-02-30'])->assertStatus(422)->assertJsonPath('codigo', 'data_invalida');
    }

    public function test_formato_errado_e_parametro_ausente_dao_dados_invalidos(): void
    {
        foreach ([['data' => 'amanha'], ['data' => '2026-10-07 10:00'], ['data' => null]] as $ruim) {
            $this->consultar($ruim)->assertStatus(422)->assertJsonPath('codigo', 'dados_invalidos')
                ->assertJsonStructure(['erros' => ['data']]);
        }
        $this->consultar(['modalidade' => 'nave'])->assertStatus(422)->assertJsonStructure(['erros' => ['modalidade']]);
        $this->consultar(['profissional_id' => 'x'])->assertStatus(422)->assertJsonStructure(['erros' => ['profissional_id']]);
        $this->consultar(['servicos' => []])->assertStatus(422)->assertJsonStructure(['erros' => ['servicos']]);
        $this->consultar(['modalidade' => 'domicilio', 'regiao_id' => 'x'])->assertStatus(422)->assertJsonStructure(['erros' => ['regiao_id']]);
    }

    public function test_recusas_do_catalogo_saem_com_o_codigo_do_dominio(): void
    {
        $inativo = $this->novoServicoDeReserva('Velho', 1000, 30, ['ativo' => false]);
        $this->vincular($this->profissionalId, [$inativo]);
        $this->consultar(['servicos' => [$inativo]])->assertStatus(422)->assertJsonPath('codigo', 'servico_indisponivel');

        $semVinculo = DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Sem vinculo']);
        $this->consultar(['profissional_id' => $semVinculo])->assertStatus(422)->assertJsonPath('codigo', 'profissional_indisponivel');
        $this->consultar(['profissional_id' => 999999])->assertStatus(422)->assertJsonPath('codigo', 'profissional_indisponivel');

        $this->consultar(['modalidade' => 'domicilio'])->assertStatus(422)->assertJsonPath('codigo', 'domicilio_indisponivel');
        $this->consultar(['modalidade' => 'domicilio', 'regiao_id' => 999999])->assertStatus(422)->assertJsonPath('codigo', 'domicilio_indisponivel');

        $soBarbearia = $this->novoServicoDeReserva('Local', 1000, 30, ['permite_domicilio' => false]);
        $this->vincular($this->profissionalId, [$soBarbearia]);
        $this->consultar(['servicos' => [$soBarbearia], 'modalidade' => 'domicilio', 'regiao_id' => $this->regiaoId])
            ->assertStatus(422)->assertJsonPath('codigo', 'servico_indisponivel');

        DB::table('estabelecimento')->update(['domicilio_ativo' => false]);
        $this->consultar(['modalidade' => 'domicilio', 'regiao_id' => $this->regiaoId])
            ->assertStatus(422)->assertJsonPath('codigo', 'domicilio_indisponivel');
    }

    public function test_horario_inexistente_na_mudanca_de_horario_de_verao_e_pulado(): void
    {
        // Nova York, domingo 2026-03-08: 02:00 -> 03:00; 02:00 e 02:30 nao existem.
        $this->fixarRelogio('2026-03-01 12:00:00');
        DB::table('estabelecimento')->update(['fuso_horario' => 'America/New_York']);
        DB::table('excecoes_expediente')->insert([
            'profissional_id' => $this->profissionalId, 'data' => '2026-03-08',
            'hora_inicio' => '01:00', 'hora_fim' => '05:00',
        ]);

        $this->consultar(['data' => '2026-03-08'])->assertOk()
            ->assertJsonPath('horarios', ['01:00', '01:30', '03:00', '03:30', '04:00', '04:30']);
    }

    public function test_um_dia_por_chamada_e_nada_de_terceiros_nem_ids(): void
    {
        $this->reservarDireto(['hora' => '10:00', 'cliente' => ['nome' => 'Terceira Pessoa Unica', 'telefone' => '+5511912345678']]);
        $outroDia = $this->pedido(['data' => '2026-10-08', 'hora' => '09:00']);
        $this->app->make(ReservarHorario::class)->executar($outroDia, Canal::Site);

        $resposta = $this->consultar()->assertOk();

        $this->assertSame(['data', 'horarios'], array_keys($resposta->json()));
        $this->assertSame(self::QUARTA, $resposta->json('data'));
        foreach ($resposta->json('horarios') as $h) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $h);
        }
        $this->assertSemChavesDeId($resposta->json());
        $this->assertStringNotContainsString('Terceira', $resposta->getContent());
        $this->assertStringNotContainsString('5511912345678', $resposta->getContent());
        foreach (DB::table('agendamentos')->pluck('codigo_publico') as $codigo) {
            $this->assertStringNotContainsString($codigo, $resposta->getContent());
        }
    }

    public function test_e_so_leitura_nao_grava_nada(): void
    {
        $antes = [DB::table('agendamentos')->count(), DB::table('clientes')->count(), DB::table('ocupacoes_agenda')->count()];

        $this->consultar()->assertOk();

        $this->assertSame($antes, [DB::table('agendamentos')->count(), DB::table('clientes')->count(), DB::table('ocupacoes_agenda')->count()]);
    }
}
