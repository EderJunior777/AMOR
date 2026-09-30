<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\PedidoDeReserva;
use App\Domain\Agenda\RepetirEmConflito;
use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\ReservarHorario;
use App\Domain\Agenda\ResultadoDaReserva;
use App\Enums\EstadoAgendamento;
use App\Enums\OrigemAgendamento;
use App\Models\Agendamento;
use App\Models\AgendamentoEvento;
use App\Support\AutoriaInvalida;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\CenarioDeAnonimizacao;
use Tests\Suporte\DadosDeAgenda;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * ReservarHorario, unico ponto que grava em agendamentos e
 * agendamento_itens (docs/ESPEC-RESERVA.md, secoes 2 a 6). Transacao de
 * teste (RefreshDatabase): sem COMMIT real. O que exige COMMIT (corrida,
 * 23505, deadlock) esta em ReservarHorarioComCommitTest e
 * ReservarHorarioConcorrenciaTest.
 */
class ReservarHorarioTest extends TestCase
{
    use BancoDeTeste, CenarioDeAnonimizacao, DadosDeAgenda, DadosDeReserva;

    private const TELEFONE = '+5511987651234';

    /** @var list<string> mensagens de todas as recusas do teste */
    private array $mensagens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();
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

    /** @return array<string, int> */
    private function contagens(): array
    {
        return [
            'agendamentos' => DB::table('agendamentos')->count(),
            'itens' => DB::table('agendamento_itens')->count(),
            'clientes' => DB::table('clientes')->count(),
            'enderecos' => DB::table('enderecos_cliente')->count(),
        ];
    }

    /** Exige a recusa $codigo e que NADA tenha sido gravado. */
    private function recusa(string $codigo, callable $acao): void
    {
        $antes = $this->contagens();
        try {
            $acao();
        } catch (ReservaRecusada $e) {
            $this->assertSame($codigo, $e->codigo, $e->getMessage());
            $this->mensagens[] = $e->getMessage();
            $this->assertSame($antes, $this->contagens(), 'uma reserva recusada nao grava nada');

            return;
        }
        $this->fail("A reserva deveria ter sido recusada com {$codigo}.");
    }

    private function reservaDoSite(array $sobrescrever = [], ?string $chave = null): ResultadoDaReserva
    {
        return $this->servico()->executar($this->pedido($sobrescrever, $chave), Canal::Site);
    }

    private function recusaDoSite(string $codigo, array $sobrescrever = []): void
    {
        $this->recusa($codigo, fn () => $this->reservaDoSite($sobrescrever));
    }

    private function inicioUtc(Agendamento $a): string
    {
        return $a->inicio_servico->utc()->format('Y-m-d H:i');
    }

    // ------------------------------------------------------------------
    // Regressao da brecha (docs/ESPEC-RESERVA.md, secao 1)
    // ------------------------------------------------------------------

    public function test_brecha_as_3h_e_recusada_por_estar_fora_do_expediente(): void
    {
        $this->recusaDoSite('fora_do_expediente', ['hora' => '03:00', 'preco_centavos' => 1]);
    }

    public function test_brecha_servico_inativo_e_recusado(): void
    {
        DB::table('servicos')->where('id', $this->corteId)->update(['ativo' => false]);

        $this->recusaDoSite('servico_indisponivel', ['preco_centavos' => 1]);
    }

    public function test_brecha_profissional_sem_vinculo_e_recusado(): void
    {
        DB::table('profissional_servico')->where('servico_id', $this->corteId)->delete();

        $this->recusaDoSite('profissional_indisponivel', ['preco_centavos' => 1]);
    }

    public function test_brecha_fora_do_expediente_e_recusado(): void
    {
        $this->recusaDoSite('fora_do_expediente', ['hora' => '21:00', 'preco_centavos' => 1]);
    }

    public function test_brecha_preco_do_cliente_e_ignorado_e_grava_o_do_catalogo(): void
    {
        $r = $this->reservaDoSite(['preco_centavos' => 1, 'taxa_centavos' => 1, 'duracao_minutos' => 5, 'estado' => 'concluido']);

        $item = $r->agendamento->itens->sole();
        $this->assertSame(4000, $item->preco_centavos);
        $this->assertSame(30, $item->duracao_minutos);
        $this->assertSame(EstadoAgendamento::Solicitado, $r->agendamento->estado);
    }

    // ------------------------------------------------------------------
    // V1 a V4
    // ------------------------------------------------------------------

    public function test_v1_data_inexistente_29_de_fevereiro_em_ano_nao_bissexto(): void
    {
        $this->recusaDoSite('data_invalida', ['data' => '2027-02-29']);
        $this->recusaDoSite('data_invalida', ['data' => '2026-13-01']);
        $this->recusaDoSite('data_invalida', ['data' => 'amanha']);
    }

    public function test_v1_hora_fora_da_grade(): void
    {
        $this->recusaDoSite('fora_da_grade', ['hora' => '10:15']);
        $this->recusaDoSite('fora_da_grade', ['hora' => '25:00']);
    }

    public function test_v1_hora_na_grade_do_estabelecimento_e_aceita(): void
    {
        DB::table('estabelecimento')->where('id', 1)->update(['grade_minutos' => 15]);

        $r = $this->reservaDoSite(['hora' => '10:15']);

        $this->assertSame('2026-10-07 13:15', $this->inicioUtc($r->agendamento));
    }

    public function test_v2_fuso_new_york_horario_inexistente_na_troca_de_horario_de_verao(): void
    {
        DB::table('estabelecimento')->where('id', 1)->update(['fuso_horario' => 'America/New_York']);
        $this->fixarRelogio('2026-03-01 15:00:00');

        $this->recusaDoSite('hora_inexistente', ['data' => '2026-03-08', 'hora' => '02:30']);
    }

    public function test_v2_fuso_new_york_horario_ambiguo_no_fim_do_horario_de_verao(): void
    {
        DB::table('estabelecimento')->where('id', 1)->update(['fuso_horario' => 'America/New_York']);
        $this->fixarRelogio('2026-10-25 15:00:00');

        $this->recusaDoSite('hora_inexistente', ['data' => '2026-11-01', 'hora' => '01:30']);
    }

    public function test_v2_o_fuso_configurado_e_o_usado_para_converter(): void
    {
        DB::table('estabelecimento')->where('id', 1)->update(['fuso_horario' => 'America/New_York']);
        $this->fixarRelogio('2026-03-01 15:00:00');

        $r = $this->reservaDoSite(['data' => '2026-03-09', 'hora' => '10:00']);

        $this->assertSame('2026-03-09 14:00', $this->inicioUtc($r->agendamento), '10:00 em Nova York (EDT, -04:00)');
    }

    public function test_v3_antecedencia_igual_ao_minimo_passa_e_um_minuto_a_menos_nao(): void
    {
        // agora = 10:00 local; antecedencia minima = 30 min.
        $this->recusaDoSite('antecedencia', ['data' => '2026-10-05', 'hora' => '10:00']);

        $r = $this->reservaDoSite(['data' => '2026-10-05', 'hora' => '10:30']);
        $this->assertSame('2026-10-05 13:30', $this->inicioUtc($r->agendamento));
    }

    public function test_v3_horario_no_passado_e_recusado(): void
    {
        $this->recusaDoSite('antecedencia', ['data' => '2026-10-04', 'hora' => '10:00']);
    }

    public function test_v3_vale_tambem_para_o_operador_em_executar(): void
    {
        $this->recusa('antecedencia', fn () => $this->servico()->executar(
            $this->pedido(['data' => '2026-10-05', 'hora' => '10:00']), Canal::Presencial, $this->novoOperador()
        ));
    }

    public function test_v4_horizonte_no_ultimo_dia_passa_e_no_seguinte_nao(): void
    {
        // hoje local = 2026-10-05; horizonte = 30 dias -> ate 2026-11-04.
        $this->recusaDoSite('alem_do_horizonte', ['data' => '2026-11-05']);

        $r = $this->reservaDoSite(['data' => '2026-11-04']);
        $this->assertSame('2026-11-04 13:00', $this->inicioUtc($r->agendamento));
    }

    // ------------------------------------------------------------------
    // V5, V6, V7
    // ------------------------------------------------------------------

    public function test_v5_servico_inativo_ou_inexistente_em_qualquer_posicao(): void
    {
        DB::table('servicos')->where('id', $this->barbaId)->update(['ativo' => false]);

        $this->recusaDoSite('servico_indisponivel', ['servicos' => [$this->corteId, $this->barbaId]]);
        $this->recusaDoSite('servico_indisponivel', ['servicos' => [$this->barbaId, $this->corteId]]);
        $this->recusaDoSite('servico_indisponivel', ['servicos' => [999999]]);
    }

    public function test_v5_servico_que_nao_permite_a_modalidade(): void
    {
        DB::table('servicos')->where('id', $this->corteId)->update(['permite_barbearia' => false]);
        $this->recusaDoSite('servico_indisponivel');

        DB::table('servicos')->where('id', $this->corteId)->update(['permite_barbearia' => true, 'permite_domicilio' => false]);
        $this->recusa('servico_indisponivel', fn () => $this->servico()->executar(
            PedidoDeReserva::deDados($this->dadosDeDomicilio()), Canal::Site
        ));
    }

    public function test_v5_o_operador_tambem_exige_servico_ativo(): void
    {
        DB::table('servicos')->where('id', $this->corteId)->update(['ativo' => false]);

        $this->recusa('servico_indisponivel', fn () => $this->servico()->executar(
            $this->pedido(), Canal::Whatsapp, $this->novoOperador()
        ));
    }

    public function test_v6_profissional_inativo_inexistente_ou_sem_vinculo_com_um_dos_servicos(): void
    {
        $this->recusaDoSite('profissional_indisponivel', ['profissional_id' => 999999]);

        DB::table('profissional_servico')
            ->where('profissional_id', $this->profissionalId)->where('servico_id', $this->barbaId)->delete();
        $this->recusaDoSite('profissional_indisponivel', ['servicos' => [$this->corteId, $this->barbaId]]);

        DB::table('profissionais')->where('id', $this->profissionalId)->update(['ativo' => false]);
        $this->recusaDoSite('profissional_indisponivel');
    }

    public function test_v6_o_operador_tambem_exige_vinculo(): void
    {
        DB::table('profissional_servico')->where('servico_id', $this->corteId)->delete();

        $this->recusa('profissional_indisponivel', fn () => $this->servico()->executar(
            $this->pedido(), Canal::Presencial, $this->novoOperador()
        ));
    }

    public function test_v7_domicilio_recusado_por_estabelecimento_regiao_e_logradouro(): void
    {
        $domicilio = fn (array $dados) => fn () => $this->servico()->executar(
            PedidoDeReserva::deDados($dados), Canal::Site
        );

        // regiao inexistente, ausente e inativa
        $this->recusa('domicilio_indisponivel', $domicilio($this->dadosDeDomicilio(['regiao_id' => 999999])));
        $this->recusa('domicilio_indisponivel', $domicilio(array_diff_key($this->dadosDeDomicilio(), ['regiao_id' => 1])));
        // logradouro curto (< 5) e longo demais (> 200: nao cabe no banco)
        $this->recusa('domicilio_indisponivel', $domicilio($this->dadosDeDomicilio(['endereco' => ['logradouro' => 'Rua']])));
        $this->recusa('domicilio_indisponivel', $domicilio($this->dadosDeDomicilio(['endereco' => ['logradouro' => str_repeat('a', 201)]])));

        DB::table('regioes_atendimento')->where('id', $this->regiaoId)->update(['ativo' => false]);
        $this->recusa('domicilio_indisponivel', $domicilio($this->dadosDeDomicilio()));

        DB::table('regioes_atendimento')->where('id', $this->regiaoId)->update(['ativo' => true]);
        DB::table('estabelecimento')->where('id', 1)->update(['domicilio_ativo' => false]);
        $this->recusa('domicilio_indisponivel', $domicilio($this->dadosDeDomicilio()));
    }

    // ------------------------------------------------------------------
    // V8: expediente sobre o periodo OCUPADO
    // ------------------------------------------------------------------

    public function test_v8_termina_exatamente_no_fim_da_janela_passa(): void
    {
        $r = $this->reservaDoSite(['hora' => '19:30']);

        $this->assertSame('2026-10-07 22:30', $this->inicioUtc($r->agendamento));
        $this->assertSame('2026-10-07 23:00', $r->agendamento->fim_ocupado->utc()->format('Y-m-d H:i'));
    }

    public function test_v8_uma_hora_depois_do_fim_da_janela_e_recusada(): void
    {
        $this->recusaDoSite('fora_do_expediente', ['hora' => '20:00']);
    }

    public function test_v8_comecar_no_almoco_e_recusado_e_terminar_no_inicio_do_almoco_passa(): void
    {
        $this->recusaDoSite('fora_do_expediente', ['hora' => '12:00']);

        $r = $this->reservaDoSite(['hora' => '11:30']);
        $this->assertSame('2026-10-07 14:30', $this->inicioUtc($r->agendamento));
    }

    public function test_v8_nao_atravessa_o_almoco(): void
    {
        $this->recusaDoSite('fora_do_expediente', ['hora' => '11:30', 'servicos' => [$this->corteId, $this->barbaId]]);
    }

    public function test_v8_janelas_que_encostam_formam_uma_so(): void
    {
        // quarta (3): 08:00-12:00 e 12:00-16:00, sem buraco.
        DB::table('expedientes_semanais')->where('profissional_id', $this->profissionalId)->where('dia_semana', 3)->delete();
        DB::table('expedientes_semanais')->insert([
            ['profissional_id' => $this->profissionalId, 'dia_semana' => 3, 'hora_inicio' => '08:00', 'hora_fim' => '12:00'],
            ['profissional_id' => $this->profissionalId, 'dia_semana' => 3, 'hora_inicio' => '12:00', 'hora_fim' => '16:00'],
        ]);

        $r = $this->reservaDoSite(['hora' => '11:30', 'servicos' => [$this->corteId, $this->barbaId]]);

        $this->assertSame('2026-10-07 14:30', $this->inicioUtc($r->agendamento));
    }

    public function test_v8_excecao_substitui_a_semanal_do_dia(): void
    {
        DB::table('excecoes_expediente')->insert([
            'profissional_id' => $this->profissionalId, 'data' => '2026-10-07', 'hora_inicio' => '14:00', 'hora_fim' => '16:00',
        ]);

        $this->recusaDoSite('fora_do_expediente', ['hora' => '10:00']);
        $this->recusaDoSite('fora_do_expediente', ['hora' => '16:00']);
        $r = $this->reservaDoSite(['hora' => '14:00']);
        $this->assertSame('2026-10-07 17:00', $this->inicioUtc($r->agendamento));
    }

    public function test_v8_excecao_de_outro_profissional_ou_de_outra_data_nao_vale(): void
    {
        $outro = DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Outro Barbeiro']);
        DB::table('excecoes_expediente')->insert([
            ['profissional_id' => $outro, 'data' => '2026-10-07', 'hora_inicio' => '14:00', 'hora_fim' => '16:00'],
            ['profissional_id' => $this->profissionalId, 'data' => '2026-10-08', 'hora_inicio' => '14:00', 'hora_fim' => '16:00'],
        ]);

        $r = $this->reservaDoSite(['hora' => '10:00']);

        $this->assertSame('2026-10-07 13:00', $this->inicioUtc($r->agendamento));
    }

    public function test_v8_dia_sem_expediente_e_recusado(): void
    {
        DB::table('expedientes_semanais')->where('profissional_id', $this->profissionalId)->where('dia_semana', 3)->delete();

        $this->recusaDoSite('fora_do_expediente');
    }

    public function test_v8_deslocamento_empurra_o_ocupado_para_fora_da_janela(): void
    {
        $pedido = fn (string $hora) => PedidoDeReserva::deDados($this->dadosDeDomicilio(['hora' => $hora]));

        // servico 19:30-20:00; com 30 min de ida e volta o ocupado vai a 20:30.
        $this->recusa('fora_do_expediente', fn () => $this->servico()->executar($pedido('19:30'), Canal::Site));
        // comeca 07:30 (ida) antes da abertura das 08:00.
        $this->recusa('fora_do_expediente', fn () => $this->servico()->executar($pedido('08:00'), Canal::Site));

        $r = $this->servico()->executar($pedido('19:00'), Canal::Site);
        $a = $r->agendamento;
        $this->assertSame('2026-10-07 22:00', $this->inicioUtc($a));
        $this->assertSame('2026-10-07 21:30', $a->inicio_ocupado->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-07 23:00', $a->fim_ocupado->utc()->format('Y-m-d H:i'));
    }

    // ------------------------------------------------------------------
    // Gravacao: canal, autoria, snapshot
    // ------------------------------------------------------------------

    public function test_site_nasce_solicitado_com_origem_site_e_ator_cliente_sem_usuario(): void
    {
        $r = $this->reservaDoSite();
        $a = $r->agendamento;

        $this->assertFalse($r->repetida);
        $this->assertSame(EstadoAgendamento::Solicitado, $a->estado);
        $this->assertSame(OrigemAgendamento::Site, $a->origem);
        $this->assertNull($a->criado_por_user_id);
        $this->assertNotEmpty($a->codigo_publico, 'codigo_publico gerado pelo banco e devolvido');
        $this->assertTrue($a->relationLoaded('itens'));
        $this->assertTrue($a->relationLoaded('profissional'));

        $evento = AgendamentoEvento::query()->where('agendamento_id', $a->id)->where('tipo', 'criado')->sole();
        $this->assertSame('cliente', $evento->ator);
        $this->assertNull($evento->usuario_id);
    }

    public function test_operador_nasce_confirmado_com_ator_operador_e_usuario_no_historico(): void
    {
        foreach ([Canal::Presencial, Canal::Whatsapp] as $i => $canal) {
            $operador = $this->novoOperador();
            $r = $this->servico()->executar($this->pedido([
                'hora' => $i === 0 ? '10:00' : '11:00',
                'cliente' => ['nome' => 'Cliente '.$canal->value, 'telefone' => '+551190000000'.$i],
            ]), $canal, $operador);
            $a = $r->agendamento;

            $this->assertSame(EstadoAgendamento::Confirmado, $a->estado);
            $this->assertSame($canal->value, $a->origem->value);
            $this->assertSame($operador->id, $a->criado_por_user_id);

            $evento = AgendamentoEvento::query()->where('agendamento_id', $a->id)->where('tipo', 'criado')->sole();
            $this->assertSame('operador', $evento->ator);
            $this->assertSame($operador->id, $evento->usuario_id);
            $this->assertArrayNotHasKey('motivo', $evento->dados, 'sem encaixe, sem motivo');
        }
    }

    public function test_autoria_incoerente_com_o_canal_e_erro_de_programacao(): void
    {
        $antes = $this->contagens();

        try {
            $this->servico()->executar($this->pedido(), Canal::Site, $this->novoOperador());
            $this->fail('site nao leva operador');
        } catch (AutoriaInvalida) {
            $this->addToAssertionCount(1);
        }
        foreach ([Canal::Presencial, Canal::Whatsapp] as $canal) {
            try {
                $this->servico()->executar($this->pedido(), $canal);
                $this->fail('canal de operador exige operador');
            } catch (AutoriaInvalida) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($antes, $this->contagens());
    }

    public function test_grava_snapshot_dos_itens_na_ordem_pedida_e_periodo_calculado(): void
    {
        $r = $this->reservaDoSite(['servicos' => [$this->barbaId, $this->corteId]]);
        $a = $r->agendamento;

        $this->assertSame(['Barba', 'Corte'], $a->itens->pluck('servico_nome')->all());
        $this->assertSame([1, 2], $a->itens->pluck('ordem')->all());
        $this->assertSame([3000, 4000], $a->itens->pluck('preco_centavos')->all());
        $this->assertSame([false, true], $a->itens->pluck('conta_como_corte')->all());
        $this->assertSame('2026-10-07 13:00', $this->inicioUtc($a));
        $this->assertSame('2026-10-07 14:00', $a->fim_servico->utc()->format('Y-m-d H:i'));
        $this->assertEquals($a->inicio_servico, $a->inicio_ocupado);
        $this->assertEquals($a->fim_servico, $a->fim_ocupado);
        $this->assertSame('barbearia', $a->modalidade->value);
        $this->assertSame(0, $a->deslocamento_minutos);
        $this->assertSame(0, $a->taxa_deslocamento_centavos);
        $this->assertNull($a->endereco_texto);
        $this->assertNull($a->regiao_id);
        $this->assertSame('Ze do Corte', $a->profissional->nome_exibicao);
    }

    public function test_observacao_do_cliente_e_gravada_e_o_preco_do_catalogo_vale_no_dia(): void
    {
        DB::table('servicos')->where('id', $this->corteId)->update(['preco_centavos' => 5500]);

        $r = $this->reservaDoSite(['observacao' => '  chego  cedo ']);

        $this->assertSame('chego cedo', $r->agendamento->observacao_cliente);
        $this->assertSame(5500, $r->agendamento->itens->sole()->preco_centavos);
    }

    public function test_sem_chave_grava_chave_e_hash_nulos_e_com_chave_grava_os_dois(): void
    {
        $sem = $this->reservaDoSite();
        $this->assertNull($sem->agendamento->chave_idempotencia);
        $this->assertNull($sem->agendamento->hash_requisicao);

        $com = $this->reservaDoSite(['hora' => '11:00'], 'chave-0123456789abcdef');
        $this->assertSame('chave-0123456789abcdef', $com->agendamento->chave_idempotencia);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $com->agendamento->hash_requisicao);
    }

    // ------------------------------------------------------------------
    // Cliente (E3)
    // ------------------------------------------------------------------

    public function test_cliente_existente_pelo_telefone_mantem_o_nome(): void
    {
        $existente = DB::table('clientes')->insertGetId(['nome' => 'Nome Cadastrado', 'telefone' => self::TELEFONE]);

        $r = $this->reservaDoSite(['cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '(11) 98765-1234']]);

        $this->assertSame($existente, $r->agendamento->cliente_id);
        $this->assertSame('Nome Cadastrado', DB::table('clientes')->where('id', $existente)->value('nome'));
        $this->assertSame(1, DB::table('clientes')->count());
    }

    public function test_telefone_novo_cria_cliente_com_o_nome_do_pedido(): void
    {
        $r = $this->reservaDoSite();

        $cliente = DB::table('clientes')->sole();
        $this->assertSame($r->agendamento->cliente_id, $cliente->id);
        $this->assertSame('Quixabeira Zebedeu', $cliente->nome);
        $this->assertSame(self::TELEFONE, $cliente->telefone);
    }

    public function test_o_mesmo_telefone_em_duas_reservas_usa_o_mesmo_cliente(): void
    {
        $a = $this->reservaDoSite();
        $b = $this->reservaDoSite(['hora' => '11:00']);

        $this->assertSame($a->agendamento->cliente_id, $b->agendamento->cliente_id);
        $this->assertSame(1, DB::table('clientes')->count());
    }

    public function test_cliente_anonimizado_nao_tem_telefone_e_quem_volta_vira_cliente_novo(): void
    {
        $antigo = DB::table('clientes')->insertGetId(['nome' => 'Fulano Antigo', 'telefone' => self::TELEFONE]);
        $this->anonimizar($antigo, $this->proprietario()->id);

        $r = $this->reservaDoSite();

        $this->assertNotSame($antigo, $r->agendamento->cliente_id);
        $this->assertNull(DB::table('clientes')->where('id', $antigo)->value('telefone'));
        $this->assertSame(self::TELEFONE, DB::table('clientes')->where('id', $r->agendamento->cliente_id)->value('telefone'));
    }

    // ------------------------------------------------------------------
    // Endereco (domicilio)
    // ------------------------------------------------------------------

    private function reservaEmDomicilio(array $sobrescrever = []): ResultadoDaReserva
    {
        return $this->servico()->executar(
            PedidoDeReserva::deDados($this->dadosDeDomicilio($sobrescrever)), Canal::Site
        );
    }

    public function test_domicilio_grava_regiao_taxa_deslocamento_e_endereco_montado(): void
    {
        $a = $this->reservaEmDomicilio()->agendamento;

        $this->assertSame('domicilio', $a->modalidade->value);
        $this->assertSame($this->regiaoId, $a->regiao_id);
        $this->assertSame('Zona Sul', $a->regiao_nome);
        $this->assertSame(30, $a->deslocamento_minutos);
        $this->assertSame(2000, $a->taxa_deslocamento_centavos);
        $this->assertNotNull($a->endereco_cliente_id);
        $this->assertStringContainsString('Rua Verdejante, 4321', $a->endereco_texto);
        $this->assertStringContainsString('Bloco 7', $a->endereco_texto);
        $this->assertLessThanOrEqual(300, mb_strlen($a->endereco_texto));
        // 10:00-10:30 com 30 min de ida e volta = ocupado 09:30-11:00 (13:00 UTC menos 30)
        $this->assertSame('2026-10-07 12:30', $a->inicio_ocupado->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-07 14:00', $a->fim_ocupado->utc()->format('Y-m-d H:i'));
    }

    public function test_endereco_igual_do_mesmo_cliente_e_reusado(): void
    {
        $a = $this->reservaEmDomicilio()->agendamento;
        $b = $this->reservaEmDomicilio(['hora' => '15:00'])->agendamento;

        $this->assertSame($a->endereco_cliente_id, $b->endereco_cliente_id);
        $this->assertSame(1, DB::table('enderecos_cliente')->count());
    }

    public function test_endereco_diferente_cria_novo(): void
    {
        $a = $this->reservaEmDomicilio()->agendamento;
        $b = $this->reservaEmDomicilio([
            'hora' => '15:00',
            'endereco' => ['logradouro' => 'Rua Verdejante, 4321', 'complemento' => 'Bloco 8', 'referencia' => 'Portao azul'],
        ])->agendamento;
        $c = $this->reservaEmDomicilio([
            'hora' => '16:30',
            'endereco' => ['logradouro' => 'Avenida Nova, 99'],
        ])->agendamento;

        $this->assertCount(3, array_unique([$a->endereco_cliente_id, $b->endereco_cliente_id, $c->endereco_cliente_id]));
        $this->assertSame(3, DB::table('enderecos_cliente')->count());
    }

    public function test_endereco_de_outro_cliente_nunca_e_reusado(): void
    {
        $a = $this->reservaEmDomicilio()->agendamento;
        $b = $this->reservaEmDomicilio([
            'hora' => '15:00',
            'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511977776666'],
        ])->agendamento;

        $this->assertNotSame($a->endereco_cliente_id, $b->endereco_cliente_id);
        $this->assertSame($b->cliente_id, DB::table('enderecos_cliente')->where('id', $b->endereco_cliente_id)->value('cliente_id'));
    }

    public function test_endereco_arquivado_ou_de_outra_regiao_nao_e_reusado(): void
    {
        $primeiro = $this->reservaEmDomicilio()->agendamento;
        DB::table('enderecos_cliente')->where('id', $primeiro->endereco_cliente_id)->update(['arquivado_em' => now()]);

        $segundo = $this->reservaEmDomicilio(['hora' => '15:00'])->agendamento;
        $this->assertNotSame($primeiro->endereco_cliente_id, $segundo->endereco_cliente_id, 'arquivado nao volta');

        $outraRegiao = DB::table('regioes_atendimento')->insertGetId([
            'codigo' => 'zona-norte', 'nome' => 'Zona Norte', 'deslocamento_minutos' => 60, 'taxa_centavos' => 3000,
        ]);
        $terceiro = $this->reservaEmDomicilio(['hora' => '17:00', 'regiao_id' => $outraRegiao])->agendamento;
        $this->assertNotSame($segundo->endereco_cliente_id, $terceiro->endereco_cliente_id, 'outra regiao, outro endereco');
    }

    public function test_endereco_texto_nunca_passa_de_300_caracteres(): void
    {
        $a = $this->reservaEmDomicilio(['endereco' => [
            'logradouro' => str_repeat('L', 200), 'complemento' => str_repeat('C', 100), 'referencia' => str_repeat('R', 200),
        ]])->agendamento;

        $this->assertLessThanOrEqual(300, mb_strlen($a->endereco_texto));
        $this->assertStringStartsWith(str_repeat('L', 200), $a->endereco_texto);
    }

    // ------------------------------------------------------------------
    // Idempotencia (passo 0; a corrida 23505 esta nas outras classes)
    // ------------------------------------------------------------------

    public function test_repeticao_com_o_mesmo_pedido_devolve_a_mesma_reserva_sem_gravar(): void
    {
        $primeira = $this->reservaDoSite([], 'chave-0123456789abcdef');
        $antes = $this->contagens();

        $segunda = $this->reservaDoSite([], 'chave-0123456789abcdef');

        $this->assertFalse($primeira->repetida);
        $this->assertTrue($segunda->repetida);
        $this->assertSame($primeira->agendamento->id, $segunda->agendamento->id);
        $this->assertSame($primeira->agendamento->codigo_publico, $segunda->agendamento->codigo_publico);
        $this->assertSame($antes, $this->contagens());
        $this->assertTrue($segunda->agendamento->relationLoaded('itens'));
        $this->assertTrue($segunda->agendamento->relationLoaded('profissional'));
    }

    public function test_mesma_chave_com_outro_pedido_e_conflito_sem_vazar_dado_da_existente(): void
    {
        $primeira = $this->reservaDoSite(['cliente' => ['nome' => 'Zebedeu Quixabeira', 'telefone' => self::TELEFONE]], 'chave-0123456789abcdef');
        $antes = $this->contagens();

        try {
            $this->reservaDoSite(['hora' => '11:00', 'cliente' => ['nome' => 'Outro Nome', 'telefone' => '+5511955554444']], 'chave-0123456789abcdef');
            $this->fail('deveria dar idempotencia_conflito');
        } catch (ReservaRecusada $e) {
            $this->assertSame('idempotencia_conflito', $e->codigo);
            $this->assertSame(ReservaRecusada::MENSAGENS['idempotencia_conflito'], $e->getMessage());
            foreach (['Zebedeu', 'Quixabeira', '987651234', $primeira->agendamento->codigo_publico, '10:00', 'Ze do Corte'] as $segredo) {
                $this->assertStringNotContainsString($segredo, $e->getMessage());
            }
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame($antes, $this->contagens());
    }

    public function test_mesma_chave_em_outro_canal_e_conflito(): void
    {
        $this->reservaDoSite([], 'chave-0123456789abcdef');

        $this->recusa('idempotencia_conflito', fn () => $this->servico()->executar(
            $this->pedido([], 'chave-0123456789abcdef'), Canal::Presencial, $this->novoOperador()
        ));
    }

    public function test_falha_depois_do_commit_e_retry_com_a_mesma_chave_devolve_a_mesma_reserva(): void
    {
        // A resposta da primeira chamada "se perdeu": o cliente nunca a viu.
        $codigoPerdido = $this->reservaDoSite([], 'chave-0123456789abcdef')->agendamento->codigo_publico;

        $retry = $this->reservaDoSite([], 'chave-0123456789abcdef');

        $this->assertTrue($retry->repetida);
        $this->assertSame($codigoPerdido, $retry->agendamento->codigo_publico);
        $this->assertSame(1, DB::table('agendamentos')->count());
    }

    public function test_repeticao_depois_que_o_horario_passou_da_antecedencia_devolve_a_mesma_reserva(): void
    {
        $primeira = $this->reservaDoSite(['data' => '2026-10-05', 'hora' => '10:30'], 'chave-0123456789abcdef');

        // 11:00 local: o horario reservado (10:30) ja passou; V3 recusaria um pedido novo.
        $this->fixarRelogio('2026-10-05 14:00:00');
        $this->recusaDoSite('antecedencia', ['data' => '2026-10-05', 'hora' => '10:30']);

        $retry = $this->reservaDoSite(['data' => '2026-10-05', 'hora' => '10:30'], 'chave-0123456789abcdef');

        $this->assertTrue($retry->repetida);
        $this->assertSame($primeira->agendamento->id, $retry->agendamento->id);
    }

    // ------------------------------------------------------------------
    // encaixar() (E2)
    // ------------------------------------------------------------------

    private function encaixe(array $sobrescrever = [], ?string $motivo = 'Cliente fiel, atendo antes de abrir', ?string $chave = null): ResultadoDaReserva
    {
        return $this->servico()->encaixar(
            $this->pedido($sobrescrever, $chave, $motivo), Canal::Presencial, $this->novoOperador()
        );
    }

    public function test_encaixe_fora_do_expediente_e_dentro_da_antecedencia_grava_com_motivo_no_historico(): void
    {
        // agora = 10:00 local: 10:00 de hoje (antecedencia) e 07:00 de amanha (antes de abrir).
        $r = $this->encaixe(['data' => '2026-10-06', 'hora' => '07:00']);
        $dentro = $this->encaixe(['data' => '2026-10-05', 'hora' => '10:00', 'cliente' => ['nome' => 'Segundo Cliente', 'telefone' => '+5511966665555']], 'Retorno rapido');

        $this->assertSame(EstadoAgendamento::Confirmado, $r->agendamento->estado);
        $this->assertSame('presencial', $r->agendamento->origem->value);
        $evento = AgendamentoEvento::query()->where('agendamento_id', $r->agendamento->id)->where('tipo', 'criado')->sole();
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($r->agendamento->criado_por_user_id, $evento->usuario_id);
        $this->assertSame('Cliente fiel, atendo antes de abrir', $evento->dados['motivo']);

        $evento2 = AgendamentoEvento::query()->where('agendamento_id', $dentro->agendamento->id)->where('tipo', 'criado')->sole();
        $this->assertSame('Retorno rapido', $evento2->dados['motivo']);
    }

    public function test_encaixe_pula_a_antecedencia_mas_nao_aceita_o_passado(): void
    {
        // agora = 10:00 local de 2026-10-05. Registrar o que ja passou e o
        // atendimento espontaneo da etapa 3, nao encaixe.
        $this->recusa('antecedencia', fn () => $this->encaixe(['data' => '2026-10-05', 'hora' => '07:00']));
        $this->recusa('antecedencia', fn () => $this->encaixe(['data' => '2026-10-04', 'hora' => '10:00']));
    }

    public function test_encaixe_sem_motivo_e_recusado(): void
    {
        $this->recusa('motivo_obrigatorio', fn () => $this->encaixe(['hora' => '07:00'], null));
        $this->recusa('motivo_obrigatorio', fn () => $this->encaixe(['hora' => '07:00'], '   '));
    }

    public function test_o_motivo_so_e_exigido_no_encaixe_e_o_site_nao_pode_encaixar(): void
    {
        $antes = $this->contagens();

        try {
            $this->servico()->encaixar($this->pedido(['hora' => '07:00'], null, 'motivo'), Canal::Site, $this->novoOperador());
            $this->fail('site nao encaixa');
        } catch (AutoriaInvalida) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->servico()->encaixar($this->pedido(['hora' => '07:00'], null, 'motivo'), Canal::Presencial, null);
            $this->fail('encaixe exige operador');
        } catch (\TypeError|AutoriaInvalida) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($antes, $this->contagens());
    }

    public function test_encaixe_continua_recusando_servico_inativo_vinculo_e_horizonte(): void
    {
        DB::table('servicos')->where('id', $this->barbaId)->update(['ativo' => false]);
        $this->recusa('servico_indisponivel', fn () => $this->encaixe(['servicos' => [$this->barbaId]]));

        DB::table('profissional_servico')->where('servico_id', $this->corteId)->delete();
        $this->recusa('profissional_indisponivel', fn () => $this->encaixe());

        $this->recusa('alem_do_horizonte', fn () => $this->encaixe(['data' => '2026-12-31']));
        $this->recusa('data_invalida', fn () => $this->encaixe(['data' => '2027-02-29']));
        $this->recusa('fora_da_grade', fn () => $this->encaixe(['hora' => '10:10']));
    }

    public function test_encaixe_e_idempotente(): void
    {
        $a = $this->encaixe(['hora' => '07:00'], 'motivo', 'chave-0123456789abcdef');
        $b = $this->encaixe(['hora' => '07:00'], 'motivo', 'chave-0123456789abcdef');

        $this->assertTrue($b->repetida);
        $this->assertSame($a->agendamento->id, $b->agendamento->id);
    }

    public function test_executar_do_operador_continua_exigindo_expediente(): void
    {
        $this->recusa('fora_do_expediente', fn () => $this->servico()->executar(
            $this->pedido(['hora' => '07:00']), Canal::Presencial, $this->novoOperador()
        ));
    }

    // ------------------------------------------------------------------
    // Sem dado de outro cliente
    // ------------------------------------------------------------------

    public function test_agenda_indisponivel_quando_nao_ha_estabelecimento(): void
    {
        DB::table('estabelecimento')->delete();

        $this->recusaDoSite('agenda_indisponivel');
    }

    public function test_nenhuma_recusa_tem_dado_de_cliente_id_ou_codigo(): void
    {
        $primeira = $this->reservaDoSite(['cliente' => ['nome' => 'Zebedeu Quixabeira', 'telefone' => self::TELEFONE]], 'chave-0123456789abcdef');
        $this->recusaDoSite('fora_do_expediente', ['hora' => '21:00']);
        $this->recusaDoSite('servico_indisponivel', ['servicos' => [999999]]);
        $this->recusa('idempotencia_conflito', fn () => $this->reservaDoSite(['hora' => '11:00'], 'chave-0123456789abcdef'));

        $this->assertNotEmpty($this->mensagens);
        foreach (array_merge($this->mensagens, array_values(ReservaRecusada::MENSAGENS)) as $mensagem) {
            foreach (['Zebedeu', 'Quixabeira', '987651234', $primeira->agendamento->codigo_publico, (string) $primeira->agendamento->id] as $segredo) {
                $this->assertStringNotContainsString($segredo, $mensagem);
            }
            $this->assertDoesNotMatchRegularExpression('/\d/', $mensagem, 'mensagem sem numero/id');
        }
    }
}
