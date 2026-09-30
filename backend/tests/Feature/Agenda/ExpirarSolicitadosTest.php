<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\RepetirEmConflito;
use App\Domain\Agenda\ReservarHorario;
use App\Enums\EstadoAgendamento;
use App\Models\Agendamento;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * Achado #1a da Fase 5: reserva "solicitado" nao segura o horario sem prazo.
 * Vira "cancelado" (ator sistema, motivo "expirado") depois de
 * cleison.reservas.solicitado_expira_horas desde a criacao OU quando o
 * inicio chega, o que vier primeiro. Relogio fixo: criada em 2026-10-05
 * 13:00 UTC (10:00 SP), para 2026-10-07 10:00 SP.
 */
class ExpirarSolicitadosTest extends TestCase
{
    use BancoDeTeste, DadosDeReserva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();
        config(['cleison.reservas.solicitado_expira_horas' => 12]);
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

    private function reserva(array $sobrescrever = []): Agendamento
    {
        return $this->servico()->executar($this->pedido($sobrescrever), Canal::Site)->agendamento;
    }

    private function em(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function linha(Agendamento $a): object
    {
        return DB::table('agendamentos')->where('id', $a->id)->first();
    }

    public function test_expira_no_prazo_e_nao_antes(): void
    {
        $criada = $this->reserva();

        $this->em('2026-10-06 00:59:59');
        $this->assertSame(0, $this->servico()->expirarSolicitados());
        $this->assertSame('solicitado', $this->linha($criada)->estado);

        $this->em('2026-10-06 01:00:00');
        $this->assertSame(1, $this->servico()->expirarSolicitados());

        $linha = $this->linha($criada);
        $this->assertSame('cancelado', $linha->estado);
        $this->assertSame('expirado', $linha->motivo_cancelamento);
        $this->assertSame('2026-10-06 01:00:00', Carbon::parse($linha->cancelado_em)->utc()->format('Y-m-d H:i:s'));

        // De novo: nada muda, nada duplica.
        $this->assertSame(0, $this->servico()->expirarSolicitados());
    }

    public function test_evento_registra_ator_sistema_sem_usuario_e_motivo_expirado(): void
    {
        $criada = $this->reserva();
        $this->em('2026-10-06 02:00:00');

        $this->servico()->expirarSolicitados();

        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $criada->id)->orderByDesc('id')->first();
        $this->assertSame('estado_alterado', $evento->tipo);
        $this->assertSame('solicitado', $evento->estado_anterior);
        $this->assertSame('cancelado', $evento->estado_novo);
        $this->assertSame('sistema', $evento->ator);
        $this->assertNull($evento->usuario_id);
        $this->assertSame(['motivo' => 'expirado'], json_decode($evento->dados, true));
    }

    public function test_expira_quando_o_inicio_chega_antes_do_prazo(): void
    {
        // Hoje 11:00 SP (14:00 UTC), uma hora depois da criacao.
        $criada = $this->reserva(['data' => '2026-10-05', 'hora' => '11:00']);

        $this->em('2026-10-05 13:59:59');
        $this->assertSame(0, $this->servico()->expirarSolicitados());

        $this->em('2026-10-05 14:00:00');
        $this->assertSame(1, $this->servico()->expirarSolicitados());
        $this->assertSame('cancelado', $this->linha($criada)->estado);
    }

    public function test_nao_expira_confirmado_nem_encerrado(): void
    {
        $confirmada = $this->reserva();
        $this->servico()->confirmar($confirmada->codigo_publico, $this->novoOperador());
        $cancelada = $this->reserva(['hora' => '11:00', 'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]);
        $this->servico()->cancelarPeloCliente($cancelada->codigo_publico, '+5511912345678');
        $eventosAntes = DB::table('agendamento_eventos')->count();

        $this->em('2026-10-08 00:00:00'); // prazo e inicio passados

        $this->assertSame(0, $this->servico()->expirarSolicitados());
        $this->assertSame('confirmado', $this->linha($confirmada)->estado);
        $this->assertNull($this->linha($cancelada)->motivo_cancelamento);
        $this->assertSame($eventosAntes, DB::table('agendamento_eventos')->count());
    }

    public function test_expirar_libera_a_ocupacao_para_outro_cliente(): void
    {
        $criada = $this->reserva();
        $this->assertSame(1, DB::table('ocupacoes_agenda')->where('agendamento_id', $criada->id)->count());

        $this->em('2026-10-06 01:00:00');
        $this->servico()->expirarSolicitados();

        $this->assertSame(0, DB::table('ocupacoes_agenda')->where('agendamento_id', $criada->id)->count());
        $outro = $this->reserva(['cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]);
        $this->assertSame(EstadoAgendamento::Solicitado, $outro->estado);
        $this->assertSame('2026-10-07 13:00', $outro->inicio_servico->utc()->format('Y-m-d H:i'));
    }

    public function test_prazo_vem_da_configuracao(): void
    {
        config(['cleison.reservas.solicitado_expira_horas' => 2]);
        $criada = $this->reserva();

        $this->em('2026-10-05 14:59:00');
        $this->assertSame(0, $this->servico()->expirarSolicitados());
        $this->em('2026-10-05 15:00:00');
        $this->assertSame(1, $this->servico()->expirarSolicitados());
        $this->assertSame('cancelado', $this->linha($criada)->estado);
    }

    public function test_prazo_invalido_nao_expira_nada(): void
    {
        $criada = $this->reserva();
        $this->em('2026-10-06 02:00:00');

        foreach ([0, -1, 'doze', null] as $valor) {
            config(['cleison.reservas.solicitado_expira_horas' => $valor]);
            try {
                $this->servico()->expirarSolicitados();
                $this->fail('Prazo invalido deveria ser recusado: '.var_export($valor, true));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame('solicitado', $this->linha($criada)->estado);
    }

    public function test_comando_expira_e_informa_so_a_contagem(): void
    {
        $criada = $this->reserva();
        $this->em('2026-10-06 01:00:00');

        $this->artisan('cleison:expirar-solicitados')
            ->expectsOutput('1 reserva(s) solicitada(s) expirada(s).')
            ->assertSuccessful();
        $this->assertSame('cancelado', $this->linha($criada)->estado);

        config(['cleison.reservas.solicitado_expira_horas' => 0]);
        $this->artisan('cleison:expirar-solicitados')->assertFailed();
    }

    public function test_comando_roda_a_cada_cinco_minutos_sem_sobreposicao(): void
    {
        $evento = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'cleison:expirar-solicitados'));

        $this->assertNotNull($evento);
        $this->assertSame('*/5 * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
    }
}
