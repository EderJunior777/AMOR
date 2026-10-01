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
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * Tela "Agenda" do painel (etapa 3, Fase 3): confirmadas e em atendimento de
 * hoje e dos proximos dias, no escopo do usuario, com iniciar atendimento,
 * concluir, nao compareceu e cancelar (motivo obrigatorio). Autorizacao no
 * servidor: IDOR trocando o codigo em TODA rota de acao.
 *
 * "Agora" fixo: 2026-10-05 10:00 em Sao Paulo (13:00 UTC), segunda-feira.
 */
class AgendaTest extends TestCase
{
    use BancoDeTeste, DadosDeReserva;

    private const MSG_INDISPONIVEL = 'Esta reserva não está mais disponível. A agenda foi atualizada.';

    private const MSG_ESTADO = 'Outra pessoa já mudou esta reserva, ou o estado dela não permite essa ação. A agenda foi atualizada.';

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

    private function servico(): ReservarHorario
    {
        return $this->app->make(ReservarHorario::class);
    }

    /**
     * Reserva confirmada (ou no estado pedido), em horario proprio.
     *
     * @param  array<string, mixed>  $sobrescrever
     */
    private function reserva(?int $profissional = null, array $sobrescrever = [], EstadoAgendamento $estado = EstadoAgendamento::Confirmado): Agendamento
    {
        $i = $this->sequencia++;
        $dados = $sobrescrever + [
            'profissional_id' => $profissional ?? $this->profissionalId,
            'data' => sprintf('2026-10-%02d', 7 + intdiv($i, 8)),
            'hora' => sprintf('%02d:00', [8, 9, 10, 11, 13, 14, 15, 16][$i % 8]),
            'cliente' => ['nome' => "Cliente Numero {$i}", 'telefone' => sprintf('+55119%08d', 87650000 + $i)],
        ];
        $a = $this->servico()->executar(PedidoDeReserva::deDados($this->dadosDoPedido($dados)), Canal::Site)->agendamento;

        $codigo = $a->codigo_publico;
        match ($estado) {
            EstadoAgendamento::Solicitado => null,
            EstadoAgendamento::Confirmado => $this->servico()->confirmar($codigo, $this->dono),
            EstadoAgendamento::EmAtendimento => [$this->servico()->confirmar($codigo, $this->dono), $this->servico()->iniciar($codigo, $this->dono)],
            default => throw new LogicException('estado nao usado nestes testes'),
        };

        return $a->fresh();
    }

    /** @param array<string, mixed> $extra */
    private function agir(string $rota, Agendamento $a, array $extra = []): TestResponse
    {
        return $this->post("/painel/agenda/{$rota}", ['reserva' => $a->codigo_publico] + $extra);
    }

    // -------------------------------------------------------------- acesso

    public function test_sem_login_nada_da_agenda_funciona(): void
    {
        $a = $this->reserva();

        $this->get('/painel/agenda')->assertRedirect('/painel/entrar');
        foreach (['iniciar', 'concluir', 'faltou'] as $rota) {
            $this->agir($rota, $a)->assertRedirect('/painel/entrar');
        }
        $this->agir('cancelar', $a, ['motivo' => 'x'])->assertRedirect('/painel/entrar');
        $this->assertSame(EstadoAgendamento::Confirmado, $a->fresh()->estado);
    }

    // ------------------------------------------------------------ a lista

    public function test_o_barbeiro_ve_so_a_agenda_do_proprio_profissional(): void
    {
        $this->reserva($this->profissionalId, ['cliente' => ['nome' => 'Cliente do Barbeiro A', 'telefone' => '+5511911110001']]);
        $this->reserva($this->profissionalB, ['cliente' => ['nome' => 'Cliente do Barbeiro B', 'telefone' => '+5511911110002']]);

        $resposta = $this->actingAs($this->barbeiroA)->get('/painel/agenda');

        $resposta->assertOk();
        $resposta->assertSee('Cliente do Barbeiro A');
        $resposta->assertDontSee('Cliente do Barbeiro B');
        $resposta->assertDontSee('11911110002');
    }

    public function test_proprietario_e_recepcao_veem_a_agenda_toda(): void
    {
        $this->reserva($this->profissionalId, ['cliente' => ['nome' => 'Cliente do Barbeiro A', 'telefone' => '+5511911110001']]);
        $this->reserva($this->profissionalB, ['cliente' => ['nome' => 'Cliente do Barbeiro B', 'telefone' => '+5511911110002']]);

        foreach ([$this->dono, $this->recepcao] as $usuario) {
            $this->actingAs($usuario)->get('/painel/agenda')->assertSee('Cliente do Barbeiro A')->assertSee('Cliente do Barbeiro B');
        }
    }

    public function test_so_confirmadas_e_em_atendimento_aparecem_e_os_pedidos_ficam_na_outra_tela(): void
    {
        $this->reserva(null, ['cliente' => ['nome' => 'Ja Confirmada', 'telefone' => '+5511911110011']]);
        $this->reserva(null, ['cliente' => ['nome' => 'Em Atendimento Agora', 'telefone' => '+5511911110012']], EstadoAgendamento::EmAtendimento);
        $this->reserva(null, ['cliente' => ['nome' => 'So Pedido Solicitado', 'telefone' => '+5511911110013']], EstadoAgendamento::Solicitado);

        $html = $this->actingAs($this->barbeiroA)->get('/painel/agenda')->getContent();

        $this->assertStringContainsString('Ja Confirmada', $html);
        $this->assertStringContainsString('Em Atendimento Agora', $html);
        $this->assertStringNotContainsString('So Pedido Solicitado', $html);
    }

    public function test_agrupa_por_dia_com_hoje_e_amanha_e_ordena_pelo_horario(): void
    {
        $this->reserva(null, ['data' => '2026-10-05', 'hora' => '15:00', 'cliente' => ['nome' => 'Hoje Tarde', 'telefone' => '+5511911110021']]);
        $this->reserva(null, ['data' => '2026-10-05', 'hora' => '13:00', 'cliente' => ['nome' => 'Hoje Cedo', 'telefone' => '+5511911110022']]);
        $this->reserva(null, ['data' => '2026-10-06', 'hora' => '09:00', 'cliente' => ['nome' => 'Amanha Manha', 'telefone' => '+5511911110023']]);
        $this->reserva(null, ['data' => '2026-10-09', 'hora' => '09:00', 'cliente' => ['nome' => 'Quinta Manha', 'telefone' => '+5511911110024']]);

        $resposta = $this->actingAs($this->barbeiroA)->get('/painel/agenda');
        $html = $resposta->getContent();

        $resposta->assertSee('Hoje');
        $resposta->assertSee('Amanhã');
        $resposta->assertSee('sexta-feira, 09/10');
        $this->assertLessThan(strpos($html, 'Hoje Tarde'), strpos($html, 'Hoje Cedo'), 'dentro do dia, pelo horario');
        $this->assertLessThan(strpos($html, 'Amanha Manha'), strpos($html, 'Hoje Tarde'), 'hoje antes de amanha');
        $this->assertLessThan(strpos($html, 'Quinta Manha'), strpos($html, 'Amanha Manha'));
    }

    public function test_so_os_proximos_sete_dias_aparecem(): void
    {
        $this->reserva(null, ['data' => '2026-10-11', 'hora' => '09:00', 'cliente' => ['nome' => 'Dentro da Semana', 'telefone' => '+5511911110031']]);
        $this->reserva(null, ['data' => '2026-10-13', 'hora' => '09:00', 'cliente' => ['nome' => 'Fora da Semana', 'telefone' => '+5511911110032']]);

        $html = $this->actingAs($this->barbeiroA)->get('/painel/agenda')->getContent();

        $this->assertStringContainsString('Dentro da Semana', $html);
        $this->assertStringNotContainsString('Fora da Semana', $html);
    }

    public function test_reserva_de_dia_anterior_ainda_aberta_aparece_como_atrasada(): void
    {
        $a = $this->reserva(null, ['data' => '2026-10-06', 'hora' => '09:00', 'cliente' => ['nome' => 'Esqueceram de Fechar', 'telefone' => '+5511911110041']]);
        Carbon::setTestNow(Carbon::parse('2026-10-08 13:00:00', 'UTC'));

        $resposta = $this->actingAs($this->barbeiroA)->get('/painel/agenda');

        $resposta->assertSee('Esqueceram de Fechar');
        $resposta->assertSee('Atrasadas');
        $this->assertSame(EstadoAgendamento::Confirmado, $a->fresh()->estado);
    }

    public function test_os_botoes_mudam_conforme_o_estado(): void
    {
        $confirmada = $this->reserva(null, ['cliente' => ['nome' => 'Confirmada Cliente', 'telefone' => '+5511911110051']]);
        $this->reserva(null, ['cliente' => ['nome' => 'Atendendo Cliente', 'telefone' => '+5511911110052']], EstadoAgendamento::EmAtendimento);

        $html = $this->actingAs($this->barbeiroA)->get('/painel/agenda')->getContent();
        $blocoConfirmada = $this->cartaoDe($html, 'Confirmada Cliente');
        $blocoEmAtendimento = $this->cartaoDe($html, 'Atendendo Cliente');

        foreach (['/painel/agenda/iniciar', '/painel/agenda/concluir', '/painel/agenda/faltou', '/painel/agenda/cancelar'] as $acao) {
            $this->assertStringContainsString($acao, $blocoConfirmada, "confirmada: {$acao}");
        }
        $this->assertStringContainsString('Confirmado', $blocoConfirmada);
        $this->assertStringContainsString('name="reserva" value="'.$confirmada->codigo_publico.'"', $blocoConfirmada);

        $this->assertStringNotContainsString('/painel/agenda/iniciar', $blocoEmAtendimento, 'ja esta em atendimento');
        $this->assertStringNotContainsString('/painel/agenda/faltou', $blocoEmAtendimento, 'quem esta sendo atendido nao faltou');
        $this->assertStringContainsString('/painel/agenda/concluir', $blocoEmAtendimento);
        $this->assertStringContainsString('/painel/agenda/cancelar', $blocoEmAtendimento);
        $this->assertStringContainsString('Em atendimento', $blocoEmAtendimento);
    }

    public function test_agenda_vazia_diz_que_nao_ha_reservas(): void
    {
        $this->actingAs($this->barbeiroA)->get('/painel/agenda')->assertOk()->assertSee('Nenhuma reserva');
    }

    public function test_barbeiro_sem_profissional_nao_ve_agenda_nenhuma(): void
    {
        $this->reserva();
        $solto = User::factory()->create();

        $this->actingAs($solto)->get('/painel/agenda')->assertOk()->assertSee('Nenhuma reserva');
    }

    // --------------------------------------------------------------- acoes

    public function test_iniciar_concluir_e_registrar_falta_mudam_o_estado_com_o_operador_da_sessao(): void
    {
        $a = $this->reserva(null, [], EstadoAgendamento::Confirmado);
        $b = $this->reserva();

        $this->actingAs($this->barbeiroA)->agir('iniciar', $a)->assertRedirect('/painel/agenda')->assertSessionHas('sucesso', 'Atendimento iniciado.');
        $this->assertSame(EstadoAgendamento::EmAtendimento, $a->fresh()->estado);

        $this->agir('concluir', $a)->assertRedirect('/painel/agenda')->assertSessionHas('sucesso', 'Atendimento concluído.');
        $this->assertSame(EstadoAgendamento::Concluido, $a->fresh()->estado);

        $this->agir('faltou', $b)->assertRedirect('/painel/agenda')->assertSessionHas('sucesso', 'Falta registrada.');
        $this->assertSame(EstadoAgendamento::NaoCompareceu, $b->fresh()->estado);

        foreach ([$a, $b] as $reserva) {
            $ultimo = DB::table('agendamento_eventos')->where('agendamento_id', $reserva->id)->orderByDesc('id')->first();
            $this->assertSame('operador', $ultimo->ator);
            $this->assertSame($this->barbeiroA->id, (int) $ultimo->usuario_id);
        }
    }

    public function test_concluir_direto_da_confirmada_funciona(): void
    {
        $a = $this->reserva();

        $this->actingAs($this->barbeiroA)->agir('concluir', $a)->assertSessionHas('sucesso', 'Atendimento concluído.');

        $this->assertSame(EstadoAgendamento::Concluido, $a->fresh()->estado);
    }

    public function test_o_usuario_do_corpo_da_requisicao_nunca_vale(): void
    {
        $a = $this->reserva();

        $this->actingAs($this->barbeiroA)->agir('iniciar', $a, ['usuario_id' => $this->dono->id, 'operador_id' => $this->dono->id]);

        $ultimo = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderByDesc('id')->first();
        $this->assertSame($this->barbeiroA->id, (int) $ultimo->usuario_id);
    }

    public function test_cancelar_exige_motivo_e_guarda_no_historico_e_oferece_avisar_o_cliente(): void
    {
        $a = $this->reserva(null, ['hora' => '10:00', 'cliente' => ['nome' => 'Joao da Silva', 'telefone' => '+5511987651234']]);

        $this->actingAs($this->barbeiroA)->agir('cancelar', $a, ['motivo' => '  '])
            ->assertRedirect('/painel/agenda')
            ->assertSessionHas('erro', 'Escreva o motivo do cancelamento.');
        $this->assertSame(EstadoAgendamento::Confirmado, $a->fresh()->estado);

        $this->agir('cancelar', $a, ['motivo' => 'Cliente pediu para desmarcar'])->assertRedirect('/painel/agenda');

        $depois = $a->fresh();
        $this->assertSame(EstadoAgendamento::Cancelado, $depois->estado);
        $this->assertSame('Cliente pediu para desmarcar', $depois->motivo_cancelamento);
        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderByDesc('id')->first();
        $this->assertSame('Cliente pediu para desmarcar', json_decode($evento->dados, true)['motivo']);

        $tela = $this->get('/painel/agenda');
        $tela->assertSee('Reserva cancelada');
        $this->assertSame(1, preg_match('/href="(https:\/\/wa\.me\/5511987651234\?text=[^"]+)"/', $tela->getContent(), $m));
        $link = html_entity_decode($m[1]);
        $texto = urldecode(substr($link, strpos($link, 'text=') + 5));
        $this->assertStringContainsString('Joao', $texto);
        $this->assertStringContainsString('cancelar', $texto);
        $this->assertStringContainsString('10:00', $texto);
        $this->assertStringNotContainsString('desmarcar', $texto, 'o motivo e interno');
    }

    public function test_motivo_de_301_caracteres_e_recusado_e_nao_cortado(): void
    {
        $a = $this->reserva();

        $this->actingAs($this->barbeiroA)->agir('cancelar', $a, ['motivo' => str_repeat('a', 301)])
            ->assertSessionHas('erro', 'O motivo pode ter no máximo 300 caracteres.');
        $this->assertSame(EstadoAgendamento::Confirmado, $a->fresh()->estado);
        $this->assertNull($a->fresh()->motivo_cancelamento);

        $this->agir('cancelar', $a, ['motivo' => str_repeat('ç', 300)])->assertRedirect('/painel/agenda');
        $this->assertSame(300, mb_strlen((string) $a->fresh()->motivo_cancelamento));
    }

    /** @return array<string, array{0: string}> */
    public static function rotasDeAcao(): array
    {
        return ['iniciar' => ['iniciar'], 'concluir' => ['concluir'], 'faltou' => ['faltou'], 'cancelar' => ['cancelar']];
    }

    #[DataProvider('rotasDeAcao')]
    public function test_barbeiro_nao_age_na_reserva_de_outro_profissional(string $rota): void
    {
        $alheia = $this->reserva($this->profissionalB);

        $this->actingAs($this->barbeiroA)->agir($rota, $alheia, ['motivo' => 'tentando'])
            ->assertRedirect('/painel/agenda')
            ->assertSessionHas('erro', self::MSG_INDISPONIVEL);

        $this->assertSame(EstadoAgendamento::Confirmado, $alheia->fresh()->estado);
        $this->assertSame(2, DB::table('agendamento_eventos')->where('agendamento_id', $alheia->id)->count(), 'nenhum evento novo');
        $this->assertNull($alheia->fresh()->motivo_cancelamento);
    }

    #[DataProvider('rotasDeAcao')]
    public function test_a_resposta_para_reserva_alheia_e_igual_a_de_reserva_inexistente(string $rota): void
    {
        $alheia = $this->reserva($this->profissionalB);

        $paraAlheia = $this->actingAs($this->barbeiroA)->agir($rota, $alheia, ['motivo' => 'x']);
        $erroAlheia = session('erro');
        $paraInexistente = $this->post("/painel/agenda/{$rota}", ['reserva' => '00000000-0000-4000-8000-000000000000', 'motivo' => 'x']);

        $this->assertSame($paraInexistente->getStatusCode(), $paraAlheia->getStatusCode());
        $this->assertSame($paraInexistente->headers->get('Location'), $paraAlheia->headers->get('Location'));
        $this->assertSame($erroAlheia, session('erro'));
    }

    #[DataProvider('rotasDeAcao')]
    public function test_campos_ausentes_ou_do_tipo_errado_nao_derrubam(string $rota): void
    {
        $this->reserva();

        foreach ([[], ['reserva' => ''], ['reserva' => ['x']], ['reserva' => 'nao-e-uuid']] as $corpo) {
            $this->actingAs($this->barbeiroA)->post("/painel/agenda/{$rota}", $corpo)
                ->assertRedirect('/painel/agenda')
                ->assertSessionHas('erro', self::MSG_INDISPONIVEL);
        }
    }

    public function test_proprietario_e_recepcao_agem_em_qualquer_reserva(): void
    {
        $a = $this->reserva($this->profissionalId);
        $b = $this->reserva($this->profissionalB);

        $this->actingAs($this->dono)->agir('iniciar', $a)->assertRedirect('/painel/agenda');
        $this->actingAs($this->recepcao)->agir('cancelar', $b, ['motivo' => 'sem barbeiro'])->assertRedirect('/painel/agenda');

        $this->assertSame(EstadoAgendamento::EmAtendimento, $a->fresh()->estado);
        $this->assertSame(EstadoAgendamento::Cancelado, $b->fresh()->estado);
    }

    public function test_barbeiro_sem_profissional_e_usuario_inativo_nao_agem(): void
    {
        $a = $this->reserva();
        $solto = User::factory()->create();
        $inativo = User::factory()->proprietario()->state(['ativo' => false])->create();

        $this->actingAs($solto)->agir('iniciar', $a)->assertSessionHas('erro', self::MSG_INDISPONIVEL);
        $this->actingAs($inativo)->agir('iniciar', $a)->assertRedirect('/painel/entrar');

        $this->assertSame(EstadoAgendamento::Confirmado, $a->fresh()->estado);
    }

    public function test_acao_que_o_estado_nao_permite_da_mensagem_clara_e_nada_muda(): void
    {
        $emAtendimento = $this->reserva(null, [], EstadoAgendamento::EmAtendimento);
        $confirmada = $this->reserva();
        $this->servico()->concluir($confirmada->codigo_publico, $this->dono);

        $this->actingAs($this->barbeiroA)->agir('iniciar', $emAtendimento)->assertSessionHas('erro', self::MSG_ESTADO);
        $this->agir('faltou', $emAtendimento)->assertSessionHas('erro', self::MSG_ESTADO);
        $this->agir('concluir', $confirmada)->assertSessionHas('erro', self::MSG_ESTADO);
        $this->agir('cancelar', $confirmada, ['motivo' => 'tarde'])->assertSessionHas('erro', self::MSG_ESTADO);

        $this->assertSame(EstadoAgendamento::EmAtendimento, $emAtendimento->fresh()->estado);
        $this->assertSame(EstadoAgendamento::Concluido, $confirmada->fresh()->estado);
    }

    public function test_o_aviso_de_cancelamento_nao_aparece_para_quem_nao_tem_escopo(): void
    {
        $a = $this->reserva($this->profissionalId, ['cliente' => ['nome' => 'Joao da Silva', 'telefone' => '+5511987651234']]);
        $this->actingAs($this->barbeiroA)->agir('cancelar', $a, ['motivo' => 'sem vaga']);

        $this->flushSession();
        $this->actingAs($this->barbeiroB)->withSession(['acao' => ['codigo' => $a->codigo_publico, 'tipo' => 'cancelado']])
            ->get('/painel/agenda')
            ->assertDontSee('Avisar cliente no WhatsApp')
            ->assertDontSee('11987651234');
    }

    // --------------------------------------------------------------- higiene

    public function test_a_tela_e_mobile_first_sem_script_nem_estilo_inline_e_sem_cache(): void
    {
        $this->reserva();

        $resposta = $this->actingAs($this->barbeiroA)->get('/painel/agenda');

        $html = $resposta->getContent();
        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<style/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        $this->assertStringContainsString('href="/painel/agenda"', $html, 'a navegacao leva a Agenda');
    }

    public function test_dado_pessoal_nunca_vai_para_o_log(): void
    {
        $a = $this->reserva(null, ['cliente' => ['nome' => 'Fulano Sigiloso', 'telefone' => '+5511999998888']]);
        $this->actingAs($this->barbeiroA)->get('/painel/agenda');
        $this->agir('cancelar', $a, ['motivo' => 'motivo interno']);
        $this->get('/painel/agenda');

        $log = implode("\n", $this->logs);
        foreach (['Fulano', '999998888', $a->codigo_publico, 'motivo interno'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $log);
        }
    }

    /** Pedaco do HTML do cartao do cliente (do <li> que o contem ate o </li>). */
    private function cartaoDe(string $html, string $nome): string
    {
        $posicaoDoNome = strpos($html, $nome);
        $this->assertNotFalse($posicaoDoNome, "cartao de {$nome} nao encontrado");
        $inicio = (int) strrpos(substr($html, 0, $posicaoDoNome), '<li');
        $fim = (int) strpos($html, '</li>', $posicaoDoNome);

        return substr($html, $inicio, $fim - $inicio);
    }
}
