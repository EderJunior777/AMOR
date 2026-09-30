<?php

namespace Tests\Feature\Api;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\ReservarHorario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Achado #1c da Fase 5: freio de emergencia. No maximo
 * cleison.reservas.teto_diario_do_site reservas criadas pelo site por dia
 * (dia de CRIACAO, no fuso do estabelecimento), de qualquer telefone. Acima
 * disso, 503 generico: nao diz que e um teto nem quantas houve.
 * "Agora": 2026-10-05 10:00 SP (13:00 UTC).
 */
class TetoDiarioDoSiteApiTest extends ApiTestCase
{
    private const CORPO_503 = [
        'mensagem' => 'Servico temporariamente indisponivel. Tente novamente mais tarde.',
        'codigo' => 'indisponivel',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cleison.reservas.teto_diario_do_site' => 2]);
    }

    private function pedidoDe(int $n, string $hora): array
    {
        return $this->corpo(['hora' => $hora, 'cliente' => ['nome' => "Cliente Numero {$n}", 'telefone' => sprintf('+55219000000%02d', $n)]]);
    }

    public function test_acima_do_teto_responde_503_generico_sem_gravar(): void
    {
        $this->reservar($this->pedidoDe(1, '10:00'), 'chave-teto-000000001')->assertCreated();
        $this->reservar($this->pedidoDe(2, '11:00'), 'chave-teto-000000002')->assertCreated();

        $resposta = $this->reservar($this->pedidoDe(3, '14:00'), 'chave-teto-000000003');

        $resposta->assertStatus(503);
        $this->assertSame(self::CORPO_503, $resposta->json());
        $this->assertSame(2, DB::table('agendamentos')->count());
        $this->assertSame(2, DB::table('clientes')->count(), 'nem o cliente e criado');
    }

    public function test_canceladas_continuam_contando_no_dia(): void
    {
        $codigo = $this->codigoDeUmaReserva([], 'chave-teto-000000001');
        $this->postJson('/api/v1/reservas/cancelar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])->assertOk();
        $this->reservar($this->pedidoDe(2, '11:00'), 'chave-teto-000000002')->assertCreated();

        $this->reservar($this->pedidoDe(3, '14:00'), 'chave-teto-000000003')->assertStatus(503);
    }

    public function test_o_dia_e_o_do_fuso_do_estabelecimento(): void
    {
        $this->reservar($this->pedidoDe(1, '10:00'), 'chave-teto-000000001')->assertCreated();
        $this->reservar($this->pedidoDe(2, '11:00'), 'chave-teto-000000002')->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-10-06 02:59:59', 'UTC')); // 23:59:59 SP, ainda dia 5
        $this->reservar($this->pedidoDe(3, '14:00'), 'chave-teto-000000003')->assertStatus(503);

        Carbon::setTestNow(Carbon::parse('2026-10-06 03:00:00', 'UTC')); // 00:00 SP do dia 6
        $this->reservar($this->pedidoDe(3, '14:00'), 'chave-teto-000000003')->assertCreated();
    }

    public function test_repeticao_idempotente_passa_mesmo_no_teto(): void
    {
        $this->reservar($this->pedidoDe(1, '10:00'), 'chave-teto-000000001')->assertCreated();
        $this->reservar($this->pedidoDe(2, '11:00'), 'chave-teto-000000002')->assertCreated();

        $this->reservar($this->pedidoDe(2, '11:00'), 'chave-teto-000000002')->assertOk();
    }

    public function test_reservas_do_operador_nao_contam_nem_sao_freadas(): void
    {
        $operador = $this->novoOperador();
        $reservas = $this->app->make(ReservarHorario::class);
        $reservas->executar($this->pedido($this->pedidoDe(1, '10:00')), Canal::Whatsapp, $operador);
        $reservas->executar($this->pedido($this->pedidoDe(2, '11:00')), Canal::Presencial, $operador);

        $this->reservar($this->pedidoDe(3, '14:00'), 'chave-teto-000000003')->assertCreated();
        $this->reservar($this->pedidoDe(4, '15:00'), 'chave-teto-000000004')->assertCreated();
        $this->reservar($this->pedidoDe(5, '16:00'), 'chave-teto-000000005')->assertStatus(503);

        $pelo = $reservas->executar($this->pedido($this->pedidoDe(6, '17:00')), Canal::Whatsapp, $operador);
        $this->assertSame('confirmado', $pelo->agendamento->estado->value);
    }

    public function test_teto_atingido_vai_ao_log_como_aviso_sem_dado_do_pedido(): void
    {
        $this->reservar($this->pedidoDe(1, '10:00'), 'chave-teto-000000001')->assertCreated();
        $this->reservar($this->pedidoDe(2, '11:00'), 'chave-teto-000000002')->assertCreated();
        $this->segredos[] = '2190000003';

        $this->reservar($this->pedidoDe(3, '14:00'), 'chave-teto-000000003')->assertStatus(503);

        $this->assertCount(1, $this->logs, implode("\n", $this->logs));
        $this->assertStringContainsString('Teto diario de reservas do site atingido', $this->logs[0]);
    }

    public function test_teto_invalido_falha_fechado(): void
    {
        foreach ([0, -1, 'muitas', null] as $valor) {
            config(['cleison.reservas.teto_diario_do_site' => $valor]);
            try {
                $this->app->make(ReservarHorario::class)->executar($this->pedido($this->pedidoDe(1, '10:00')), Canal::Site);
                $this->fail('Teto invalido deveria ser recusado: '.var_export($valor, true));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, DB::table('agendamentos')->count());
    }
}
