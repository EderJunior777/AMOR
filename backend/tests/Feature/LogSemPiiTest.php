<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Route;
use PDOException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Todo erro de banco reportado (nao so em requisicao JSON: HTML, comando,
 * fila, scheduler) vai para o log pelo ErroDeBanco, sem SQL, bindings nem
 * a mensagem crua do trigger. Log gravado em arquivo de verdade, para pegar
 * tambem o log padrao do handler (que nao passa pelo facade Log).
 */
class LogSemPiiTest extends TestCase
{
    private const BINDINGS = ['Fulano da Silva', '+5511999990000'];

    private const MENSAGEM_TRIGGER = '[agendamentos_transicao_estado] Transicao de concluido para cancelado nao permitida (agendamento 42)';

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arquivo = storage_path('logs/teste-log-sem-pii-'.getmypid().'.log');
        @unlink($this->arquivo);
        config([
            'logging.default' => 'teste_sem_pii',
            'logging.channels.teste_sem_pii' => ['driver' => 'single', 'path' => $this->arquivo, 'level' => 'debug'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);

        parent::tearDown();
    }

    private function erro(): QueryException
    {
        $pdo = new PDOException('SQLSTATE[23514]: Check violation: 7 ERROR:  '.self::MENSAGEM_TRIGGER);
        $pdo->errorInfo = ['23514', 7, 'ERROR:  '.self::MENSAGEM_TRIGGER];

        return new QueryException('pgsql', 'update agendamentos set estado = ? where cliente_nome = ? and telefone = ?', ['cancelado', ...self::BINDINGS], $pdo);
    }

    private function assertLogSoTraduzido(): void
    {
        $log = (string) @file_get_contents($this->arquivo);

        $this->assertSame(1, substr_count($log, 'Erro de banco'), $log);
        $this->assertStringContainsString('"sqlstate":"23514"', $log);
        $this->assertStringContainsString('"constraint":"agendamentos_transicao_estado"', $log);
        $this->assertMatchesRegularExpression('/"correlacao":"[0-9a-f-]{36}"/', $log);

        foreach ([...self::BINDINGS, 'Transicao de concluido', 'agendamento 42', 'update agendamentos', 'SQLSTATE', 'Stacktrace', '#0 '] as $proibido) {
            $this->assertStringNotContainsString($proibido, $log);
        }
    }

    public function test_rota_html(): void
    {
        $erro = $this->erro();
        Route::get('/_teste/erro-banco-html', fn () => throw $erro);

        $this->get('/_teste/erro-banco-html', ['Accept' => 'text/html'])->assertStatus(500);

        $this->assertLogSoTraduzido();
    }

    public function test_comando_artisan(): void
    {
        $erro = $this->erro();
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->registerCommand(new ClosureCommand('teste:erro-banco', fn () => throw $erro));

        $codigo = $kernel->handle(new ArrayInput(['command' => 'teste:erro-banco']), new BufferedOutput);

        $this->assertNotSame(0, $codigo);
        $this->assertLogSoTraduzido();
    }
}
