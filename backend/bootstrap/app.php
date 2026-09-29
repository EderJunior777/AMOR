<?php

use App\Console\Kernel;
use App\Http\Controllers\SaudeController;
use App\Support\ErroDeBanco;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            PreventRequestsDuringMaintenance::except('/up');
            Route::get('/up', SaudeController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $pedeJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($pedeJson);

        // Erros do PostgreSQL (QueryException e a PDOException crua do COMMIT).
        // report: SEMPRE (HTTP, comando, fila, scheduler) pelo ErroDeBanco, no
        // lugar do log padrao, que traria SQL, bindings e a mensagem do trigger.
        // render: so em requisicao JSON; o resto segue o padrao do Laravel.
        $exceptions->report(fn (PDOException $e) => ErroDeBanco::registrar($e))->stop();
        $exceptions->render(fn (PDOException $e, Request $request) => $pedeJson($request) ? ErroDeBanco::resposta($e) : null);
    })->create();

// Kernel de console proprio so para imprimir erro de banco sem PII (ver a
// classe). Os afterResolving do builder conferem por instanceof e continuam valendo.
$app->singleton(ConsoleKernel::class, Kernel::class);

return $app;
