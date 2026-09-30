<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\RepetirEmConflito;
use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\ReservarHorario;
use App\Models\Agendamento;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * Achado #1b da Fase 5: no site, no maximo
 * cleison.reservas.maximo_em_aberto_por_telefone reservas em aberto
 * (solicitado ou confirmado, com inicio no futuro) por telefone, contadas
 * dentro da transacao com o cliente travado (a disputa com COMMIT real esta
 * no ReservarHorarioConcorrenciaTest). Relogio fixo: 2026-10-05 10:00 SP.
 */
class LimiteDeReservasEmAbertoTest extends TestCase
{
    use BancoDeTeste, DadosDeReserva;

    private const TELEFONE = '+5511987651234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();
        // Sem override: os testes abaixo rodam com o limite PADRAO (2), o do
        // config/cleison.php. So os que dizem o contrario trocam o valor.
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

    private function reserva(string $hora, array $sobrescrever = [], ?string $chave = null): Agendamento
    {
        return $this->servico()->executar($this->pedido(['hora' => $hora] + $sobrescrever, $chave), Canal::Site)->agendamento;
    }

    private function recusadaPorLimite(callable $acao): void
    {
        try {
            $acao();
        } catch (ReservaRecusada $e) {
            $this->assertSame('limite_de_reservas_em_aberto', $e->codigo);

            return;
        }
        $this->fail('Deveria ter sido recusada por limite de reservas em aberto.');
    }

    public function test_terceira_reserva_em_aberto_do_mesmo_telefone_e_recusada_sem_gravar(): void
    {
        $this->reserva('10:00');
        $this->reserva('11:00', ['cliente' => ['nome' => 'Quixabeira Zebedeu', 'telefone' => '(11) 98765-1234']]);

        $this->recusadaPorLimite(fn () => $this->reserva('14:00'));

        $this->assertSame(2, DB::table('agendamentos')->count());
        $this->assertSame(2, DB::table('ocupacoes_agenda')->count());
    }

    public function test_padroes_dos_freios_no_arquivo_de_config(): void
    {
        $arquivo = require config_path('cleison.php');

        $this->assertSame(2, $arquivo['reservas']['maximo_em_aberto_por_telefone']);
        $this->assertSame(12, $arquivo['reservas']['solicitado_expira_horas']);
        $this->assertSame(500, $arquivo['reservas']['teto_diario_do_site']);
        $this->assertSame(2, config('cleison.reservas.maximo_em_aberto_por_telefone'), 'o ambiente de teste nao troca o padrao');
    }

    public function test_mensagem_da_recusa_e_fixa_e_sem_dado(): void
    {
        $this->assertSame(
            'Voce ja tem o maximo de reservas em aberto. Cancele uma delas ou fale com a barbearia.',
            ReservaRecusada::por('limite_de_reservas_em_aberto')->getMessage(),
        );
    }

    public function test_confirmada_conta_como_em_aberto(): void
    {
        $primeira = $this->reserva('10:00');
        $this->servico()->confirmar($primeira->codigo_publico, $this->novoOperador());
        $this->reserva('11:00');

        $this->recusadaPorLimite(fn () => $this->reserva('14:00'));
    }

    public function test_cancelada_libera_a_vaga(): void
    {
        $primeira = $this->reserva('10:00');
        $this->reserva('11:00');
        $this->servico()->cancelarPeloCliente($primeira->codigo_publico, self::TELEFONE);

        $this->assertSame('solicitado', $this->reserva('14:00')->estado->value);
    }

    public function test_reserva_cujo_inicio_ja_passou_nao_conta(): void
    {
        $this->reserva('11:00', ['data' => '2026-10-05']); // hoje, 11:00 SP
        $this->reserva('10:00');

        Carbon::setTestNow(Carbon::parse('2026-10-05 14:00:00', 'UTC')); // 11:00 SP: a primeira comecou
        $this->assertSame('solicitado', $this->reserva('14:00')->estado->value);
    }

    public function test_outro_telefone_nao_e_afetado(): void
    {
        $this->reserva('10:00');
        $this->reserva('11:00');

        $outra = $this->reserva('14:00', ['cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]);

        $this->assertSame('solicitado', $outra->estado->value);
    }

    public function test_repeticao_idempotente_da_reserva_existente_nao_e_recusada(): void
    {
        $this->reserva('10:00', [], 'chave-limite-0000001');
        $this->reserva('11:00', [], 'chave-limite-0000002');

        $repetida = $this->servico()->executar($this->pedido(['hora' => '11:00'], 'chave-limite-0000002'), Canal::Site);

        $this->assertTrue($repetida->repetida);
        $this->assertSame(2, DB::table('agendamentos')->count());
    }

    public function test_operador_nao_tem_esse_limite(): void
    {
        $this->reserva('10:00');
        $this->reserva('11:00');

        $pelo = $this->servico()->executar($this->pedido(['hora' => '14:00']), Canal::Whatsapp, $this->novoOperador());

        $this->assertSame('confirmado', $pelo->agendamento->estado->value);
    }

    public function test_reservas_do_operador_contam_para_o_site(): void
    {
        $operador = $this->novoOperador();
        $this->servico()->executar($this->pedido(['hora' => '10:00']), Canal::Whatsapp, $operador);
        $this->servico()->executar($this->pedido(['hora' => '11:00']), Canal::Presencial, $operador);

        $this->recusadaPorLimite(fn () => $this->reserva('14:00'));
    }

    public function test_maximo_vem_da_configuracao(): void
    {
        config(['cleison.reservas.maximo_em_aberto_por_telefone' => 1]);
        $this->reserva('10:00');

        $this->recusadaPorLimite(fn () => $this->reserva('11:00'));
    }

    public function test_maximo_invalido_falha_fechado(): void
    {
        foreach ([0, -1, 'dois', null] as $valor) {
            config(['cleison.reservas.maximo_em_aberto_por_telefone' => $valor]);
            try {
                $this->reserva('10:00');
                $this->fail('Maximo invalido deveria ser recusado: '.var_export($valor, true));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, DB::table('agendamentos')->count());
    }
}
