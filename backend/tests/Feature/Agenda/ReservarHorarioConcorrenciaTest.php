<?php

namespace Tests\Feature\Agenda;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * ReservarHorario em PROCESSOS separados (cada um sobe o app Laravel, com a
 * propria conexao e o papel da aplicacao), sincronizados por um advisory
 * lock (portao) conferido em pg_locks, como no ConcorrenciaTest: nada de
 * "esperar N segundos e torcer". Sem RefreshDatabase (COMMIT real); limpa as
 * tabelas do banco de TESTE antes e depois de cada caso.
 */
class ReservarHorarioConcorrenciaTest extends TestCase
{
    use DadosDeReserva;

    private const CHAVE = 'chave-0123456789abcdef';

    /** @var list<string> */
    private array $arquivos = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limparTabelasDoDominio();
        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        $this->montarAgendaDeReserva();
    }

    protected function tearDown(): void
    {
        foreach ($this->arquivos as $arquivo) {
            @unlink($arquivo);
        }
        DB::connection('pgsql_b')->disconnect();
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    private function outroProfissional(string $nome): int
    {
        $id = DB::table('profissionais')->insertGetId(['nome_exibicao' => $nome]);
        $this->vincular($id, [$this->corteId, $this->barbaId]);
        $this->expedientePadrao($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function tentativa(array $dados, ?string $chave = null): array
    {
        return ['dados' => $dados, 'canal' => 'site', 'chave' => $chave, 'operador_id' => null, 'agora' => '2026-10-05 13:00:00'];
    }

    /** @return array<string, mixed> */
    private function cliente(int $n): array
    {
        return ['nome' => "Cliente Numero {$n}", 'telefone' => sprintf('+55119000000%02d', $n)];
    }

    private function assertAgendaConsistente(): void
    {
        $this->assertSame(0, (int) DB::scalar(
            'SELECT count(*) FROM ocupacoes_agenda x JOIN ocupacoes_agenda y
                ON x.profissional_id = y.profissional_id AND x.id < y.id AND x.periodo && y.periodo'
        ), 'ocupacoes sobrepostas gravadas');
        $this->assertSame(0, (int) DB::scalar(
            'SELECT count(*) FROM agendamentos a WHERE NOT EXISTS (SELECT 1 FROM agendamento_itens i WHERE i.agendamento_id = a.id)'
        ), 'agendamento sem itens');
        $this->assertSame(
            DB::table('agendamentos')->count(),
            DB::table('ocupacoes_agenda')->count(),
            'todo agendamento ativo tem uma ocupacao'
        );
    }

    public function test_dois_clientes_no_mesmo_horario_e_profissional_um_grava_e_o_outro_leva_23p01(): void
    {
        $resultados = $this->dispararProcessos([
            $this->tentativa($this->dadosDoPedido(['cliente' => $this->cliente(1)])),
            $this->tentativa($this->dadosDoPedido(['cliente' => $this->cliente(2)])),
        ]);

        $this->assertCount(1, array_filter($resultados, fn ($r) => $r['ok']), json_encode($resultados));
        $perdedor = array_values(array_filter($resultados, fn ($r) => ! $r['ok']))[0];
        $this->assertSame('QueryException', $perdedor['tipo'], json_encode($resultados));
        $this->assertSame('23P01', $perdedor['sqlstate']);

        $this->assertSame(1, DB::table('agendamentos')->count());
        $this->assertSame(1, DB::table('clientes')->count(), 'o cliente de quem perdeu foi desfeito junto');
        $this->assertSame(1, DB::table('agendamento_itens')->count());
        $this->assertAgendaConsistente();
    }

    public function test_oito_clientes_no_mesmo_horario_um_grava_e_sete_levam_23p01(): void
    {
        $tentativas = [];
        foreach (range(1, 8) as $n) {
            $tentativas[] = $this->tentativa($this->dadosDoPedido(['cliente' => $this->cliente($n)]));
        }

        $resultados = $this->dispararProcessos($tentativas);

        $this->assertCount(1, array_filter($resultados, fn ($r) => $r['ok']), json_encode($resultados));
        foreach (array_filter($resultados, fn ($r) => ! $r['ok']) as $r) {
            $this->assertSame('QueryException', $r['tipo'], json_encode($resultados));
            $this->assertSame('23P01', $r['sqlstate'], json_encode($resultados));
        }
        $this->assertSame(1, DB::table('agendamentos')->count());
        $this->assertAgendaConsistente();
    }

    public function test_profissionais_diferentes_no_mesmo_horario_gravam_os_dois(): void
    {
        $beto = $this->outroProfissional('Beto');

        $resultados = $this->dispararProcessos([
            $this->tentativa($this->dadosDoPedido(['cliente' => $this->cliente(1)])),
            $this->tentativa($this->dadosDoPedido(['cliente' => $this->cliente(2), 'profissional_id' => $beto])),
        ]);

        $this->assertSame([true, true], array_column($resultados, 'ok'), json_encode($resultados));
        $this->assertSame(2, DB::table('agendamentos')->count());
        $this->assertAgendaConsistente();
    }

    public function test_o_mesmo_telefone_em_paralelo_cria_um_cliente_so(): void
    {
        $beto = $this->outroProfissional('Beto');
        $mesmoTelefone = ['nome' => 'Mesmo Telefone', 'telefone' => '+5511988887777'];

        $resultados = $this->dispararProcessos([
            $this->tentativa($this->dadosDoPedido(['cliente' => $mesmoTelefone])),
            $this->tentativa($this->dadosDoPedido(['cliente' => $mesmoTelefone, 'profissional_id' => $beto])),
        ]);

        $this->assertSame([true, true], array_column($resultados, 'ok'), json_encode($resultados));
        $this->assertSame(1, DB::table('clientes')->count(), 'ON CONFLICT (telefone): um cliente para as duas reservas');
        $this->assertSame(1, DB::table('agendamentos')->distinct()->count('cliente_id'));
    }

    public function test_mesma_chave_e_mesmo_pedido_em_paralelo_um_cria_e_os_outros_repetem(): void
    {
        $tentativas = array_fill(0, 4, $this->tentativa($this->dadosDoPedido(), self::CHAVE));

        $resultados = $this->dispararProcessos($tentativas);

        $this->assertSame([true, true, true, true], array_column($resultados, 'ok'), json_encode($resultados));
        $this->assertSame(1, count(array_filter($resultados, fn ($r) => ! $r['repetida'])), 'so um criou: '.json_encode($resultados));
        $this->assertSame(3, count(array_filter($resultados, fn ($r) => $r['repetida'])));
        $this->assertCount(1, array_unique(array_column($resultados, 'id')), 'todos devolvem a mesma reserva');
        $this->assertSame(1, DB::table('agendamentos')->count());
        $this->assertSame(1, DB::table('clientes')->count());
        $this->assertAgendaConsistente();
    }

    public function test_mesma_chave_com_pedidos_diferentes_em_paralelo_um_cria_e_o_outro_recebe_conflito(): void
    {
        $resultados = $this->dispararProcessos([
            $this->tentativa($this->dadosDoPedido(['hora' => '10:00']), self::CHAVE),
            $this->tentativa($this->dadosDoPedido(['hora' => '11:00']), self::CHAVE),
        ]);

        $this->assertCount(1, array_filter($resultados, fn ($r) => $r['ok']), json_encode($resultados));
        $perdedor = array_values(array_filter($resultados, fn ($r) => ! $r['ok']))[0];
        $this->assertSame(['ReservaRecusada', 'idempotencia_conflito'], [$perdedor['tipo'], $perdedor['codigo']], json_encode($resultados));
        $this->assertSame(1, DB::table('agendamentos')->count());
    }

    /**
     * Sobe um processo PHP por tentativa e so os libera quando TODOS estao
     * conectados e bloqueados no portao (conferido em pg_locks).
     *
     * @param  list<array<string, mixed>>  $tentativas
     * @return list<array<string, mixed>>
     */
    private function dispararProcessos(array $tentativas): array
    {
        $cfg = config('database.connections.pgsql');
        $ambiente = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_URL' => '',
            'DB_HOST' => (string) $cfg['host'],
            'DB_PORT' => (string) $cfg['port'],
            'DB_DATABASE' => (string) $cfg['database'],
            'DB_USERNAME' => (string) $cfg['username'],
            'DB_PASSWORD' => (string) $cfg['password'],
            'LOG_CHANNEL' => 'null',
        ]);
        $script = base_path('tests/Suporte/reservar_pelo_dominio.php');
        $portao = random_int(1000, 2_000_000_000);
        $quem = DB::connection('pgsql_b');
        $quem->select('SELECT pg_advisory_lock(?)', [$portao]);

        $processos = [];
        try {
            foreach ($tentativas as $tentativa) {
                $arquivo = (string) tempnam(sys_get_temp_dir(), 'reserva');
                $this->arquivos[] = $arquivo;
                file_put_contents($arquivo, json_encode($tentativa, JSON_THROW_ON_ERROR));

                $processo = proc_open(
                    [PHP_BINARY, $script, (string) $portao, $arquivo],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $tubos,
                    base_path(),
                    $ambiente,
                );
                $this->assertIsResource($processo);
                $processos[] = [$processo, $tubos];
            }

            // Espera (com limite) ate todos os filhos estarem na fila do portao.
            $limite = microtime(true) + 90;
            do {
                $esperando = (int) DB::scalar(
                    "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 0 AND objid = ? AND NOT granted",
                    [$portao]
                );
                if ($esperando === count($tentativas)) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $limite);

            $this->assertSame(count($tentativas), $esperando, 'nem todos os processos chegaram ao portao');
        } finally {
            $quem->select('SELECT pg_advisory_unlock(?)', [$portao]);
        }

        $resultados = [];
        foreach ($processos as [$processo, $tubos]) {
            $saida = stream_get_contents($tubos[1]);
            $erro = stream_get_contents($tubos[2]);
            fclose($tubos[1]);
            fclose($tubos[2]);
            proc_close($processo);
            $resultados[] = json_decode(trim((string) $saida), true)
                ?? ['ok' => false, 'tipo' => 'SAIDA_INVALIDA', 'erro' => $saida.$erro];
        }

        return $resultados;
    }
}
