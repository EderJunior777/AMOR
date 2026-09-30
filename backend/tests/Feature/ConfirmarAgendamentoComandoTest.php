<?php

namespace Tests\Feature;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\ReservarHorario;
use App\Models\Agendamento;
use Carbon\Carbon;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/** cleison:confirmar-agendamento: solicitado -> confirmado pelo operador, sem PII na saida nem no log. */
class ConfirmarAgendamentoComandoTest extends TestCase
{
    use BancoDeTeste, DadosDeReserva;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    protected function tearDown(): void
    {
        $log = implode("\n", $this->logs);
        foreach (DB::table('agendamentos')->pluck('codigo_publico') as $codigo) {
            $this->assertStringNotContainsString($codigo, $log);
        }
        $this->assertStringNotContainsString('11987651234', $log);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function solicitada(): Agendamento
    {
        return $this->app->make(ReservarHorario::class)->executar($this->pedido(), Canal::Site)->agendamento;
    }

    private function estado(Agendamento $a): string
    {
        return (string) DB::table('agendamentos')->where('id', $a->id)->value('estado');
    }

    public function test_confirma_e_a_saida_nao_leva_codigo_nem_dados_do_cliente(): void
    {
        $a = $this->solicitada();
        $operador = $this->novoOperador();

        $saida = $this->executar([$a->codigo_publico, '--usuario' => $operador->id], $codigo);

        $this->assertSame(0, $codigo, $saida);
        $this->assertSame('confirmado', $this->estado($a));
        $this->assertStringContainsString('confirmado', $saida);
        foreach ([$a->codigo_publico, substr($a->codigo_publico, 0, 8), 'Quixabeira', 'Zebedeu', '11987651234'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $saida);
        }
        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderByDesc('id')->first();
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($operador->id, (int) $evento->usuario_id);
    }

    public function test_usuario_inexistente_ou_inativo_falha_sem_gravar(): void
    {
        $a = $this->solicitada();
        $inativo = $this->novoOperador();
        DB::table('users')->where('id', $inativo->id)->update(['ativo' => false]);

        foreach ([999999, $inativo->id, 'abc'] as $usuario) {
            $saida = $this->executar([$a->codigo_publico, '--usuario' => $usuario], $codigo);

            $this->assertSame(1, $codigo, $saida);
            $this->assertStringNotContainsString($a->codigo_publico, $saida);
            $this->assertSame('solicitado', $this->estado($a));
        }
    }

    public function test_sem_usuario_falha(): void
    {
        $a = $this->solicitada();

        $saida = $this->executar([$a->codigo_publico], $codigo);

        $this->assertSame(1, $codigo, $saida);
        $this->assertStringContainsString('--usuario', $saida);
        $this->assertSame('solicitado', $this->estado($a));
    }

    public function test_codigo_inexistente_ou_malformado_falha_com_a_mensagem_da_recusa(): void
    {
        $operador = $this->novoOperador();

        foreach (['11111111-2222-4333-8444-555555555555', 'lixo'] as $invalido) {
            $saida = $this->executar([$invalido, '--usuario' => $operador->id], $codigo);

            $this->assertSame(1, $codigo, $saida);
            $this->assertStringContainsString('Reserva nao encontrada.', $saida);
        }
    }

    public function test_reserva_que_nao_esta_solicitada_falha_com_a_mensagem_da_recusa(): void
    {
        $a = $this->solicitada();
        $operador = $this->novoOperador();
        $this->assertSame(0, Artisan::call('cleison:confirmar-agendamento', ['codigo' => $a->codigo_publico, '--usuario' => $operador->id]));

        $saida = $this->executar([$a->codigo_publico, '--usuario' => $operador->id], $codigo);

        $this->assertSame(1, $codigo, $saida);
        $this->assertStringContainsString('Esta reserva nao pode ser alterada no estado atual.', $saida);
        $this->assertSame('confirmado', $this->estado($a));
    }

    /** @param array<int|string, mixed> $argumentos */
    private function executar(array $argumentos, ?int &$codigo): string
    {
        $argumentos = ['codigo' => array_shift($argumentos)] + $argumentos;
        $codigo = Artisan::call('cleison:confirmar-agendamento', $argumentos);

        return Artisan::output();
    }
}
