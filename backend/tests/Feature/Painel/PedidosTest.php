<?php

namespace Tests\Feature\Painel;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\PedidoDeReserva;
use App\Domain\Agenda\RepetirEmConflito;
use App\Domain\Agenda\ReservarHorario;
use App\Enums\EstadoAgendamento;
use App\Enums\PapelUsuario;
use App\Models\Agendamento;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * Tela "Pedidos" do painel (etapa 3, Fase 3): reservas solicitadas do
 * profissional do barbeiro (todas, para proprietario e recepcao), mais antigas
 * primeiro; Confirmar e Recusar (motivo obrigatorio); depois de agir, o botao
 * "Avisar cliente no WhatsApp" (wa.me, sem envio automatico). Autorizacao no
 * servidor: IDOR trocando o codigo da reserva em TODA rota de acao.
 *
 * "Agora" fixo: 2026-10-05 10:00 em Sao Paulo (13:00 UTC), segunda-feira.
 */
class PedidosTest extends TestCase
{
    use BancoDeTeste, DadosDeReserva;

    private const MSG_INDISPONIVEL = 'Este pedido não está mais disponível. A lista foi atualizada.';

    private const MSG_JA_AGIRAM = 'Outra pessoa já agiu neste pedido, o cliente cancelou ou ele expirou. A lista foi atualizada.';

    private User $dono;

    private User $recepcao;

    private User $barbeiroA;

    private User $barbeiroB;

    private int $profissionalB;

    private int $sequencia = 0;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();

        $this->dono = User::factory()->proprietario()->create();
        $this->recepcao = User::factory()->state(['papel' => PapelUsuario::Recepcao])->create();
        $this->barbeiroA = User::factory()->state(['name' => 'Barbeiro A'])->create();
        $this->barbeiroB = User::factory()->state(['name' => 'Barbeiro B'])->create();

        DB::table('profissionais')->where('id', $this->profissionalId)->update(['user_id' => $this->barbeiroA->id]);
        $this->profissionalB = (int) DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Barbeiro B', 'user_id' => $this->barbeiroB->id]);
        $this->vincular($this->profissionalB, [$this->corteId, $this->barbaId]);
        $this->expedientePadrao($this->profissionalB);

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Pedido do site (nasce solicitado), em horario proprio.
     *
     * @param  array<string, mixed>  $sobrescrever
     */
    private function pedido(?int $profissional = null, array $sobrescrever = []): Agendamento
    {
        $i = $this->sequencia++;
        $dados = $sobrescrever + [
            'profissional_id' => $profissional ?? $this->profissionalId,
            'data' => sprintf('2026-10-%02d', 7 + intdiv($i, 8)),
            'hora' => sprintf('%02d:00', [8, 9, 10, 11, 13, 14, 15, 16][$i % 8]),
            'cliente' => ['nome' => "Cliente Numero {$i}", 'telefone' => sprintf('+55119%08d', 87650000 + $i)],
        ];

        return $this->app->make(ReservarHorario::class)->executar(
            PedidoDeReserva::deDados($this->dadosDoPedido($dados)),
            Canal::Site,
        )->agendamento;
    }

    /** @param array<string, mixed> $extra */
    private function agir(string $rota, Agendamento $a, array $extra = []): TestResponse
    {
        return $this->post("/painel/pedidos/{$rota}", ['reserva' => $a->codigo_publico] + $extra);
    }

    // -------------------------------------------------------------- acesso

    public function test_sem_login_nada_do_painel_de_pedidos_funciona(): void
    {
        $a = $this->pedido();

        $this->get('/painel')->assertRedirect('/painel/entrar');
        $this->getJson('/painel/pedidos/resumo')->assertStatus(401);
        $this->agir('confirmar', $a)->assertRedirect('/painel/entrar');
        $this->agir('recusar', $a, ['motivo' => 'x'])->assertRedirect('/painel/entrar');
        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);
    }

    public function test_nenhuma_rota_do_painel_leva_o_codigo_ou_id_da_reserva_na_url(): void
    {
        foreach (Route::getRoutes() as $rota) {
            if (str_starts_with($rota->uri(), 'painel')) {
                $this->assertStringNotContainsString('{', $rota->uri(), "a rota {$rota->uri()} tem parametro na URL: o codigo e dado pessoal e vai por POST");
            }
        }
    }

    // ------------------------------------------------------------- a lista

    public function test_o_barbeiro_ve_so_os_pedidos_do_proprio_profissional(): void
    {
        $meu = $this->pedido($this->profissionalId, ['cliente' => ['nome' => 'Cliente do Barbeiro A', 'telefone' => '+5511911110001']]);
        $alheio = $this->pedido($this->profissionalB, ['cliente' => ['nome' => 'Cliente do Barbeiro B', 'telefone' => '+5511911110002']]);

        $resposta = $this->actingAs($this->barbeiroA)->get('/painel');

        $resposta->assertOk();
        $resposta->assertSee('Cliente do Barbeiro A');
        $resposta->assertDontSee('Cliente do Barbeiro B');
        $resposta->assertDontSee('11911110002');
        $resposta->assertDontSee($alheio->codigo_publico);
        $resposta->assertSee($meu->codigo_publico, false);
    }

    public function test_proprietario_e_recepcao_veem_todos(): void
    {
        $this->pedido($this->profissionalId, ['cliente' => ['nome' => 'Cliente do Barbeiro A', 'telefone' => '+5511911110001']]);
        $this->pedido($this->profissionalB, ['cliente' => ['nome' => 'Cliente do Barbeiro B', 'telefone' => '+5511911110002']]);

        foreach ([$this->dono, $this->recepcao] as $usuario) {
            $this->actingAs($usuario)->get('/painel')->assertSee('Cliente do Barbeiro A')->assertSee('Cliente do Barbeiro B');
        }
    }

    public function test_so_os_solicitados_aparecem_do_mais_antigo_para_o_mais_novo(): void
    {
        $this->pedido(null, ['cliente' => ['nome' => 'Primeiro Pedido', 'telefone' => '+5511911110011']]);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->pedido(null, ['cliente' => ['nome' => 'Segundo Pedido', 'telefone' => '+5511911110012']]);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->pedido(null, ['cliente' => ['nome' => 'Terceiro Pedido', 'telefone' => '+5511911110013']]);
        $confirmado = $this->pedido(null, ['cliente' => ['nome' => 'Ja Confirmado', 'telefone' => '+5511911110014']]);
        $this->app->make(ReservarHorario::class)->confirmar($confirmado->codigo_publico, $this->dono);

        $html = $this->actingAs($this->barbeiroA)->get('/painel')->getContent();

        $this->assertStringNotContainsString('Ja Confirmado', $html, 'confirmado nao e pedido');
        $this->assertLessThan(strpos($html, 'Segundo Pedido'), strpos($html, 'Primeiro Pedido'));
        $this->assertLessThan(strpos($html, 'Terceiro Pedido'), strpos($html, 'Segundo Pedido'));
    }

    public function test_lista_vazia_diz_que_nao_ha_pedidos(): void
    {
        $this->actingAs($this->barbeiroA)->get('/painel')->assertOk()->assertSee('Nenhum pedido');
    }

    public function test_o_cartao_mostra_o_que_o_barbeiro_precisa_para_decidir(): void
    {
        $a = $this->pedido(null, [
            'hora' => '10:00',
            'servicos' => [$this->corteId, $this->barbaId],
            'observacao' => 'Pode ser depois do almoco',
            'cliente' => ['nome' => 'Joao da Silva', 'telefone' => '+5511987651234'],
        ]);

        $resposta = $this->actingAs($this->barbeiroA)->get('/painel');

        $resposta->assertSee('Joao da Silva');
        $resposta->assertSee('(11) 98765-1234');
        $resposta->assertSee('href="tel:+5511987651234"', false);
        $resposta->assertSee('Corte');
        $resposta->assertSee('Barba');
        $resposta->assertSee('1 h', false);
        $resposta->assertSee('quarta-feira, 07/10');
        $resposta->assertSee('10:00');
        $resposta->assertSee('Na barbearia');
        $resposta->assertSee('Pode ser depois do almoco');
        $resposta->assertSee('Confirmar');
        $resposta->assertSee('Recusar');
        $resposta->assertSee('name="reserva" value="'.$a->codigo_publico.'"', false);
        $resposta->assertSee('name="motivo"', false);
        $resposta->assertSee('maxlength="300"', false);
    }

    public function test_pedido_a_domicilio_mostra_o_endereco_e_a_regiao(): void
    {
        $this->pedido(null, [
            'hora' => '10:00', // com o deslocamento antes e depois, longe das bordas do expediente
            'modalidade' => 'domicilio',
            'regiao_id' => $this->regiaoId,
            'endereco' => ['logradouro' => 'Rua das Flores, 123', 'complemento' => 'apto 4'],
        ]);

        $resposta = $this->actingAs($this->barbeiroA)->get('/painel');

        $resposta->assertSee('Na casa do cliente');
        $resposta->assertSee('Rua das Flores, 123');
        $resposta->assertSee('Zona Sul');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function temposRestantes(): array
    {
        // Pedido criado as 13:00 UTC; expira 12 h depois (01:00 UTC do dia seguinte).
        return [
            'logo depois de criar' => ['2026-10-05 13:00:00', 'expira em 12 h'],
            'uma hora depois' => ['2026-10-05 14:00:00', 'expira em 11 h'],
            'horas e minutos' => ['2026-10-05 14:30:00', 'expira em 10 h 30 min'],
            'menos de uma hora' => ['2026-10-06 00:15:00', 'expira em 45 min'],
            'ja passou do prazo' => ['2026-10-06 01:30:00', 'vai expirar'],
        ];
    }

    #[DataProvider('temposRestantes')]
    public function test_tempo_restante_antes_de_expirar(string $momento, string $esperado): void
    {
        $this->pedido(null, ['data' => '2026-10-08']);
        Carbon::setTestNow(Carbon::parse($momento, 'UTC'));

        $this->actingAs($this->barbeiroA)->get('/painel')->assertSee($esperado);
    }

    public function test_quando_o_inicio_chega_antes_do_prazo_vale_o_inicio(): void
    {
        // Hoje 15:00 em SP = 18:00 UTC: expira ao chegar a hora, em 5 h (antes das 12 h do prazo).
        $this->pedido(null, ['data' => '2026-10-05', 'hora' => '15:00']);

        $this->actingAs($this->barbeiroA)->get('/painel')->assertSee('expira em 5 h');
    }

    // --------------------------------------------------------------- resumo

    public function test_o_resumo_conta_so_o_escopo_do_usuario_e_nao_e_guardado_em_cache(): void
    {
        $meu1 = $this->pedido($this->profissionalId, ['cliente' => ['nome' => 'A1', 'telefone' => '+5511911110021']]);
        $meu2 = $this->pedido($this->profissionalId, ['cliente' => ['nome' => 'A2', 'telefone' => '+5511911110022']]);
        $alheio = $this->pedido($this->profissionalB, ['cliente' => ['nome' => 'B1', 'telefone' => '+5511911110023']]);

        $barbeiro = $this->actingAs($this->barbeiroA)->getJson('/painel/pedidos/resumo');

        $barbeiro->assertOk()->assertJson(['total' => 2]);
        $this->assertEqualsCanonicalizing([$meu1->id, $meu2->id], $barbeiro->json('ids'));
        $this->assertNotContains($alheio->id, $barbeiro->json('ids'));
        $this->assertStringContainsString('no-store', (string) $barbeiro->headers->get('Cache-Control'));

        $this->actingAs($this->dono)->getJson('/painel/pedidos/resumo')->assertJson(['total' => 3]);
    }

    public function test_o_resumo_nao_leva_dado_pessoal_nem_codigo(): void
    {
        $a = $this->pedido(null, ['cliente' => ['nome' => 'Fulano Sigiloso', 'telefone' => '+5511999998888']]);

        $corpo = $this->actingAs($this->barbeiroA)->getJson('/painel/pedidos/resumo')->getContent();

        foreach (['Fulano', '999998888', $a->codigo_publico] as $segredo) {
            $this->assertStringNotContainsString($segredo, $corpo);
        }
    }

    public function test_barbeiro_sem_profissional_nao_ve_pedido_nenhum(): void
    {
        $this->pedido();
        $solto = User::factory()->create();

        $this->actingAs($solto)->get('/painel')->assertOk()->assertSee('Nenhum pedido');
        $this->actingAs($solto)->getJson('/painel/pedidos/resumo')->assertJson(['total' => 0, 'ids' => []]);
    }

    // ------------------------------------------------------------ confirmar

    public function test_confirmar_grava_o_operador_da_sessao_e_mostra_o_aviso_ao_cliente(): void
    {
        $a = $this->pedido(null, ['hora' => '10:00', 'servicos' => [$this->corteId], 'cliente' => ['nome' => 'Joao da Silva', 'telefone' => '+5511987651234']]);

        $this->actingAs($this->barbeiroA)->agir('confirmar', $a)->assertRedirect('/painel');

        $this->assertSame(EstadoAgendamento::Confirmado, $a->fresh()->estado);
        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderByDesc('id')->first();
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($this->barbeiroA->id, (int) $evento->usuario_id);

        $tela = $this->get('/painel');
        $tela->assertSee('Pedido confirmado');
        $tela->assertSee('Avisar cliente no WhatsApp');
        $link = $this->linkDoWhatsApp($tela);
        $this->assertStringStartsWith('https://wa.me/5511987651234?text=', $link);
        $texto = urldecode(substr($link, strlen('https://wa.me/5511987651234?text=')));
        $this->assertStringContainsString('Joao', $texto);
        $this->assertStringContainsString('confirmado', $texto);
        $this->assertStringContainsString('Corte', $texto);
        $this->assertStringContainsString('quarta-feira, 07/10', $texto);
        $this->assertStringContainsString('10:00', $texto);

        $this->get('/painel')->assertDontSee('Avisar cliente no WhatsApp', false);
    }

    public function test_o_usuario_do_corpo_da_requisicao_nunca_vale(): void
    {
        $a = $this->pedido();

        $this->actingAs($this->barbeiroA)->agir('confirmar', $a, ['usuario_id' => $this->dono->id, 'user_id' => $this->dono->id, 'operador_id' => $this->dono->id]);

        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderByDesc('id')->first();
        $this->assertSame($this->barbeiroA->id, (int) $evento->usuario_id, 'o ator vem da sessao');
    }

    public function test_confirmar_sem_o_campo_da_reserva_ou_com_lixo_nao_derruba_nada(): void
    {
        $this->pedido();

        foreach ([[], ['reserva' => ''], ['reserva' => 'nao-e-uuid'], ['reserva' => ['x']], ['reserva' => '00000000-0000-4000-8000-000000000000']] as $corpo) {
            $this->actingAs($this->barbeiroA)->post('/painel/pedidos/confirmar', $corpo)
                ->assertRedirect('/painel')
                ->assertSessionHas('erro', self::MSG_INDISPONIVEL);
        }
    }

    // --------------------------------------------------------------- recusar

    public function test_recusar_exige_motivo_e_guarda_o_motivo_no_historico(): void
    {
        $a = $this->pedido(null, ['cliente' => ['nome' => 'Joao da Silva', 'telefone' => '+5511987651234']]);

        $this->actingAs($this->barbeiroA)->agir('recusar', $a, ['motivo' => '   '])
            ->assertRedirect('/painel')
            ->assertSessionHas('erro', 'Escreva o motivo da recusa.');
        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);

        $this->agir('recusar', $a, ['motivo' => 'Nesse dia estou de folga'])->assertRedirect('/painel');

        $depois = $a->fresh();
        $this->assertSame(EstadoAgendamento::Cancelado, $depois->estado);
        $this->assertSame('Nesse dia estou de folga', $depois->motivo_cancelamento);
        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderByDesc('id')->first();
        $this->assertSame($this->barbeiroA->id, (int) $evento->usuario_id);
        $this->assertSame('Nesse dia estou de folga', json_decode($evento->dados, true)['motivo']);

        $tela = $this->get('/painel');
        $tela->assertSee('Pedido recusado');
        $link = $this->linkDoWhatsApp($tela);
        $texto = urldecode(substr($link, strpos($link, 'text=') + 5));
        $this->assertStringContainsString('Joao', $texto);
        $this->assertStringContainsString('quarta-feira, 07/10', $texto);
        $this->assertStringNotContainsString('folga', $texto, 'o motivo e interno: nao vai na mensagem ao cliente');
    }

    public function test_motivo_de_301_caracteres_e_recusado_e_nao_cortado(): void
    {
        $a = $this->pedido();

        $this->actingAs($this->barbeiroA)->agir('recusar', $a, ['motivo' => str_repeat('a', 301)])
            ->assertRedirect('/painel')
            ->assertSessionHas('erro', 'O motivo pode ter no máximo 300 caracteres.');

        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);
        $this->assertNull($a->fresh()->motivo_cancelamento);

        $this->agir('recusar', $a, ['motivo' => str_repeat('ç', 300)])->assertRedirect('/painel');
        $this->assertSame(300, mb_strlen((string) $a->fresh()->motivo_cancelamento));
    }

    public function test_recusar_com_motivo_do_tipo_errado_nao_derruba(): void
    {
        $a = $this->pedido();

        $this->actingAs($this->barbeiroA)->agir('recusar', $a, ['motivo' => ['x']])
            ->assertRedirect('/painel')
            ->assertSessionHas('erro', 'Escreva o motivo da recusa.');
        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);
    }

    // ------------------------------------------------------------------ IDOR

    public function test_barbeiro_nao_age_no_pedido_de_outro_profissional_em_nenhuma_rota(): void
    {
        $alheio = $this->pedido($this->profissionalB);

        foreach (['confirmar' => [], 'recusar' => ['motivo' => 'tentando']] as $rota => $extra) {
            $this->actingAs($this->barbeiroA)->agir($rota, $alheio, $extra)
                ->assertRedirect('/painel')
                ->assertSessionHas('erro', self::MSG_INDISPONIVEL);
        }

        $this->assertSame(EstadoAgendamento::Solicitado, $alheio->fresh()->estado);
        $this->assertSame(1, DB::table('agendamento_eventos')->where('agendamento_id', $alheio->id)->count(), 'nenhum evento novo');
        $this->assertNull($alheio->fresh()->motivo_cancelamento);
    }

    public function test_a_resposta_para_pedido_alheio_e_igual_a_de_pedido_inexistente(): void
    {
        $alheio = $this->pedido($this->profissionalB);

        $paraAlheio = $this->actingAs($this->barbeiroA)->agir('confirmar', $alheio);
        $erroAlheio = session('erro');
        $paraInexistente = $this->post('/painel/pedidos/confirmar', ['reserva' => '00000000-0000-4000-8000-000000000000']);

        $this->assertSame($paraInexistente->getStatusCode(), $paraAlheio->getStatusCode());
        $this->assertSame($paraInexistente->headers->get('Location'), $paraAlheio->headers->get('Location'));
        $this->assertSame($erroAlheio, session('erro'), 'sem pista de que a reserva existe');
    }

    public function test_proprietario_e_recepcao_agem_em_qualquer_pedido(): void
    {
        $a = $this->pedido($this->profissionalId);
        $b = $this->pedido($this->profissionalB);

        $this->actingAs($this->dono)->agir('confirmar', $a)->assertRedirect('/painel');
        $this->actingAs($this->recepcao)->agir('recusar', $b, ['motivo' => 'sem vaga'])->assertRedirect('/painel');

        $this->assertSame(EstadoAgendamento::Confirmado, $a->fresh()->estado);
        $this->assertSame(EstadoAgendamento::Cancelado, $b->fresh()->estado);
    }

    public function test_barbeiro_sem_profissional_e_usuario_inativo_nao_agem(): void
    {
        $a = $this->pedido();
        $solto = User::factory()->create();
        $inativo = User::factory()->proprietario()->state(['ativo' => false])->create();

        $this->actingAs($solto)->agir('confirmar', $a)->assertSessionHas('erro');
        $this->actingAs($inativo)->agir('confirmar', $a)->assertRedirect('/painel/entrar');

        $this->assertSame(EstadoAgendamento::Solicitado, $a->fresh()->estado);
    }

    public function test_o_aviso_ao_cliente_nao_aparece_para_quem_nao_tem_escopo(): void
    {
        $a = $this->pedido($this->profissionalId, ['cliente' => ['nome' => 'Joao da Silva', 'telefone' => '+5511987651234']]);
        $this->actingAs($this->barbeiroA)->agir('confirmar', $a);

        // Um codigo plantado na sessao de OUTRO barbeiro nunca mostra a reserva alheia.
        $this->flushSession();
        $this->actingAs($this->barbeiroB)->withSession(['acao' => ['codigo' => $a->codigo_publico, 'tipo' => 'confirmado']])
            ->get('/painel')
            ->assertDontSee('Avisar cliente no WhatsApp')
            ->assertDontSee('11987651234');
    }

    // ----------------------------------------------------- estado que mudou

    public function test_se_outro_operador_ja_agiu_a_mensagem_e_clara_e_nada_muda(): void
    {
        $a = $this->pedido();
        $this->app->make(ReservarHorario::class)->confirmar($a->codigo_publico, $this->dono);

        $this->actingAs($this->barbeiroA)->agir('confirmar', $a)
            ->assertRedirect('/painel')
            ->assertSessionHas('erro', self::MSG_JA_AGIRAM);
        $this->actingAs($this->barbeiroA)->agir('recusar', $a, ['motivo' => 'tarde demais'])
            ->assertSessionHas('erro', self::MSG_JA_AGIRAM);

        $this->assertSame(EstadoAgendamento::Confirmado, $a->fresh()->estado);
    }

    public function test_pedido_cancelado_pelo_cliente_ou_expirado_nao_pode_ser_confirmado(): void
    {
        $cancelado = $this->pedido(null, ['cliente' => ['nome' => 'Quem Cancelou', 'telefone' => '+5511911110031']]);
        $this->app->make(ReservarHorario::class)->cancelarPeloCliente($cancelado->codigo_publico, '+5511911110031');
        $expirado = $this->pedido(null, ['cliente' => ['nome' => 'Quem Expirou', 'telefone' => '+5511911110032']]);
        Carbon::setTestNow(Carbon::now()->addHours(13));
        $this->app->make(ReservarHorario::class)->expirarSolicitados();

        foreach ([$cancelado, $expirado] as $a) {
            $this->actingAs($this->barbeiroA)->agir('confirmar', $a)->assertSessionHas('erro', self::MSG_JA_AGIRAM);
        }
        $this->actingAs($this->barbeiroA)->get('/painel')->assertDontSee('Quem Cancelou')->assertDontSee('Quem Expirou');
    }

    // --------------------------------------------------------- higiene da tela

    public function test_a_tela_e_mobile_first_e_nao_tem_script_nem_estilo_inline(): void
    {
        $this->pedido();

        $resposta = $this->actingAs($this->barbeiroA)->get('/painel');

        $html = $resposta->getContent();
        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $html);
        $this->assertStringContainsString('lang="pt-BR"', $html);
        $this->assertStringContainsString('src="/painel/painel.js"', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<style/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        $resposta->assertSee('Atualizar agora');
        $resposta->assertSee('id="faixa-novos"', false);
        $this->assertStringContainsString('data-resumo="/painel/pedidos/resumo"', $html);
    }

    public function test_o_titulo_da_aba_mostra_o_contador_de_pedidos(): void
    {
        $this->pedido();
        $this->pedido();

        $this->actingAs($this->barbeiroA)->get('/painel')->assertSee('<title>(2) Pedidos', false);
    }

    public function test_dado_pessoal_nunca_vai_para_o_log(): void
    {
        $a = $this->pedido(null, ['cliente' => ['nome' => 'Fulano Sigiloso', 'telefone' => '+5511999998888']]);
        $this->actingAs($this->barbeiroA)->get('/painel');
        $this->agir('recusar', $a, ['motivo' => 'motivo interno']);
        $this->get('/painel');

        $log = implode("\n", $this->logs);
        foreach (['Fulano', '999998888', $a->codigo_publico, 'motivo interno'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $log);
        }
    }

    private function linkDoWhatsApp(TestResponse $tela): string
    {
        $this->assertSame(1, preg_match('/href="(https:\/\/wa\.me\/[^"]+)"/', $tela->getContent(), $m), 'o botao do WhatsApp nao apareceu');

        return html_entity_decode($m[1]);
    }
}
