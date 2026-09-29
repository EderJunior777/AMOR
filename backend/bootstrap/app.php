<?php

use App\Http\Controllers\SaudeController;
use App\Support\ErroDeBanco;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
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

        // Erros do PostgreSQL (QueryException e a PDOException crua do COMMIT):
        // log sem SQL nem bindings (PII) no lugar do log padrao, e resposta por SQLSTATE.
        $exceptions->report(fn (PDOException $e) => ErroDeBanco::registrar($e))->stop();
        $exceptions->render(fn (PDOException $e, Request $request) => $pedeJson($request) ? ErroDeBanco::resposta($e) : null);
    })->create();
