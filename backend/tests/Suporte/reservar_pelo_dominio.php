<?php

/*
 * Processo filho usado por ReservarHorarioConcorrenciaTest: sobe o app
 * Laravel (APP_ENV=testing, banco *_teste) e chama App\Domain\Agenda\ReservarHorario
 * numa conexao PROPRIA, como um segundo servidor/worker faria.
 *
 * Sincronizacao sem relogio, igual a reservar_concorrente.php: o pai segura
 * pg_advisory_lock(portao); cada filho, depois de subir o app, bloqueia em
 * pg_advisory_lock_shared(portao) e so segue quando o pai solta.
 *
 * Uso: php reservar_pelo_dominio.php <portao> <arquivo-json>
 *   arquivo-json: {"dados":{...}, "canal":"site", "chave":"..."|null,
 *                  "operador_id":N|null, "agora":"2026-10-05 13:00:00"}
 *   ou, para reserva existente: {"operacao":"remarcar_cliente"|"cancelar_cliente"|"confirmar",
 *                  "codigo":"...", "telefone":"...", "data":"...", "hora":"...",
 *                  "canal":"site", "operador_id":N|null, "agora":"..."}
 *   (o pedido vai por arquivo, nao por argumento: o telefone nao aparece na
 *   lista de processos.)
 * Saida (stdout, uma linha JSON):
 *   {"ok":true,"id":N,"repetida":false}
 *   {"ok":false,"tipo":"QueryException","sqlstate":"23P01","constraint":null}
 *   {"ok":false,"tipo":"ReservaRecusada","codigo":"..."}
 *   {"ok":false,"tipo":"<classe>"}
 */

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\PedidoDeReserva;
use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\ReservarHorario;
use App\Models\User;
use App\Support\ErroDeBanco;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Recusado: exige APP_ENV=testing.\n");
    exit(2);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_ends_with((string) config('database.connections.pgsql.database'), '_teste')) {
    fwrite(STDERR, "Recusado: o banco nao e de teste.\n");
    exit(2);
}

[, $portao, $arquivo] = $argv;
$pedido = json_decode((string) file_get_contents($arquivo), true, flags: JSON_THROW_ON_ERROR);

Carbon::setTestNow(Carbon::parse($pedido['agora'], 'UTC'));
$canal = Canal::from($pedido['canal']);
$operador = $pedido['operador_id'] !== null ? User::query()->findOrFail($pedido['operador_id']) : null;
$servico = $app->make(ReservarHorario::class);
$operacao = $pedido['operacao'] ?? 'executar';
$reserva = $operacao === 'executar' ? PedidoDeReserva::deDados($pedido['dados'], $pedido['chave']) : null;

// Portao: bloqueia aqui ate o pai soltar o lock exclusivo.
DB::select('SELECT pg_advisory_lock_shared(?)', [(int) $portao]);
DB::select('SELECT pg_advisory_unlock_shared(?)', [(int) $portao]);

// Opcional: comeca um pouco depois dos outros (janela de disputa forcada).
usleep(((int) ($pedido['atraso_ms'] ?? 0)) * 1000);

try {
    // Reserva existente (ReservaExistenteConcorrenciaTest): codigo, telefone, data, hora no JSON.
    $existente = match ($operacao) {
        'executar' => null,
        'remarcar_cliente' => $servico->remarcarPeloCliente($pedido['codigo'], $pedido['telefone'], $pedido['data'], $pedido['hora']),
        'cancelar_cliente' => $servico->cancelarPeloCliente($pedido['codigo'], $pedido['telefone']),
        'confirmar' => $servico->confirmar($pedido['codigo'], $operador),
    };
    if ($existente !== null) {
        $saida = ['ok' => true, 'id' => $existente->id, 'estado' => $existente->estado->value];
    } else {
        $resultado = $servico->executar($reserva, $canal, $operador);
        $saida = ['ok' => true, 'id' => $resultado->agendamento->id, 'repetida' => $resultado->repetida];
    }
} catch (QueryException $e) {
    $saida = ['ok' => false, 'tipo' => 'QueryException', 'sqlstate' => ErroDeBanco::sqlstate($e), 'constraint' => ErroDeBanco::constraint($e)];
} catch (ReservaRecusada $e) {
    $saida = ['ok' => false, 'tipo' => 'ReservaRecusada', 'codigo' => $e->codigo];
} catch (Throwable $e) {
    $saida = ['ok' => false, 'tipo' => $e::class];
}

echo json_encode($saida), PHP_EOL;
