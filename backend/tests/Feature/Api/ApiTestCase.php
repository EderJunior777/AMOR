<?php

namespace Tests\Feature\Api;

use App\Domain\Agenda\RepetirEmConflito;
use Carbon\Carbon;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * Base dos testes HTTP da API publica v1 (docs/ESPEC-RESERVA.md, secoes 2, 6
 * e 8). Agenda de DadosDeReserva; "agora" fixo em segunda 2026-10-05 10:00
 * (Sao Paulo). Transacao de teste (RefreshDatabase).
 *
 * Toda classe filha herda a conferencia final: NENHUMA linha de log, em
 * nenhum teste, leva codigo publico nem telefone.
 */
abstract class ApiTestCase extends TestCase
{
    use BancoDeTeste, DadosDeReserva;

    protected const TELEFONE = '+5511987651234';

    protected const CHAVE = 'chave-0123456789abcdef';

    /** @var list<string> tudo que a aplicacao mandou para o log */
    protected array $logs = [];

    /** @var list<string> valores que jamais podem aparecer em log */
    protected array $segredos = ['11987651234', '11912345678'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();
        // Limites folgados: quem testa 429 (LimitesApiTest) baixa um de cada vez.
        config(['cleison.api.limites' => array_fill_keys([
            'geral_por_minuto', 'criar_por_minuto_ip', 'criar_por_hora_telefone',
            'reserva_por_minuto_ip_codigo', 'reserva_por_hora_ip', 'global_por_minuto_por_rota',
        ], 100000)]);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    protected function tearDown(): void
    {
        // parent::tearDown() SEMPRE roda (finally): se a transacao do teste
        // abortou, a consulta abaixo falha, e sem o rollback do RefreshDatabase
        // a conexao ficaria aberta segurando travas e a suite inteira pararia.
        try {
            $log = implode("\n", $this->logs);
            $codigos = array_merge($this->segredos, DB::table('agendamentos')->pluck('codigo_publico')->all());
            foreach ($codigos as $segredo) {
                $this->assertStringNotContainsString((string) $segredo, $log);
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    /** @param array<string, mixed> $corpo */
    protected function reservar(array $corpo, ?string $chave = self::CHAVE): TestResponse
    {
        $cabecalhos = $chave !== null ? ['Idempotency-Key' => $chave] : [];

        return $this->postJson('/api/v1/reservas', $corpo, $cabecalhos);
    }

    /** Corpo HTTP valido (barbearia, quarta 10:00), com sobrescritas rasas. */
    protected function corpo(array $sobrescrever = []): array
    {
        return $sobrescrever + $this->dadosDoPedido([
            'cliente' => ['nome' => 'Quixabeira Zebedeu', 'telefone' => self::TELEFONE],
        ]);
    }

    /** Cria uma reserva pelo HTTP e devolve o codigo publico. */
    protected function codigoDeUmaReserva(array $sobrescrever = [], ?string $chave = self::CHAVE): string
    {
        $resposta = $this->reservar($this->corpo($sobrescrever), $chave)->assertCreated();

        return (string) $resposta->json('codigo');
    }

    protected function assertSemChavesDeId(mixed $dados, string $caminho = ''): void
    {
        if (! is_array($dados)) {
            return;
        }
        foreach ($dados as $chave => $valor) {
            if (is_string($chave)) {
                $this->assertDoesNotMatchRegularExpression('/^id$|_id$/', $chave, "chave de id em {$caminho}.{$chave}");
            }
            $this->assertSemChavesDeId($valor, $caminho.'.'.$chave);
        }
    }
}
