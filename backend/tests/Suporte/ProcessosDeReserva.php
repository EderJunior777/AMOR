<?php

namespace Tests\Suporte;

use Illuminate\Support\Facades\DB;

/**
 * Dispara tests/Suporte/reservar_pelo_dominio.php em PROCESSOS separados
 * (cada um sobe o app, com conexao propria e o papel da aplicacao),
 * sincronizados por um advisory lock (portao) conferido em pg_locks: todos
 * saem juntos, sem "esperar N segundos e torcer". Exige a conexao pgsql_b
 * configurada no setUp e limparArquivosDeProcessos() no tearDown.
 */
trait ProcessosDeReserva
{
    /** @var list<string> */
    private array $arquivos = [];

    protected function limparArquivosDeProcessos(): void
    {
        foreach ($this->arquivos as $arquivo) {
            @unlink($arquivo);
        }
        $this->arquivos = [];
    }

    /**
     * @param  list<array<string, mixed>>  $tentativas  JSON de cada filho (ver o script)
     * @return list<array<string, mixed>> saida de cada filho, na mesma ordem
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
