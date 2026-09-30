<?php

use App\Console\Kernel;
use App\Domain\Agenda\AgendaSobrecarregada;
use App\Domain\Agenda\ReservaRecusada;
use App\Http\Controllers\SaudeController;
use App\Support\ErroDeBanco;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            PreventRequestsDuringMaintenance::except('/up');
            Route::get('/up', SaudeController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sem CORS: o site chama a API por proxy na MESMA origem. O HandleCors
        // do framework, sem config/cors.php, usa a config padrao do framework
        // (paths api/*, allowed_origins *), que abriria a API a qualquer
        // origem. Removido de proposito; nao publicar config/cors.php.
        $middleware->remove(HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $pedeJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($pedeJson);

        // API publica v1: erros sempre em JSON estavel, sem SQL, stack nem o
        // valor enviado. So para api/*; o resto segue o padrao do Laravel.
        $daApi = fn (Request $request) => $request->is('api/*');

        // Recusa de regra de negocio: codigo estavel, mensagem fixa. Nao e erro
        // do sistema, entao nao vai para o log (o trace levaria argumentos).
        $exceptions->dontReport(ReservaRecusada::class);
        $exceptions->render(fn (ReservaRecusada $e, Request $request) => $daApi($request)
            ? response()->json(['mensagem' => $e->getMessage(), 'codigo' => $e->codigo], 422)
            : null);

        // Freio de emergencia do site (teto diario): 503 generico, sem dizer
        // que e um teto. O aviso no log ja sai do dominio, sem dado do pedido.
        $exceptions->dontReport(AgendaSobrecarregada::class);
        $exceptions->render(fn (AgendaSobrecarregada $e, Request $request) => $daApi($request)
            ? response()->json(['mensagem' => 'Servico temporariamente indisponivel. Tente novamente mais tarde.', 'codigo' => 'indisponivel'], 503)
            : null);

        $exceptions->render(fn (ValidationException $e, Request $request) => $daApi($request)
            ? response()->json(['mensagem' => 'Dados invalidos.', 'codigo' => 'dados_invalidos', 'erros' => $e->errors()], 422)
            : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $daApi($request)
            ? response()->json(
                ['mensagem' => 'Muitas tentativas. Aguarde um pouco e tente de novo.', 'codigo' => 'muitas_tentativas'],
                429,
                array_intersect_key($e->getHeaders(), ['Retry-After' => 1]),
            )
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $daApi($request)
            ? response()->json(['mensagem' => 'Recurso nao encontrado.', 'codigo' => 'nao_encontrado'], 404)
            : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => $daApi($request)
            ? response()->json(['mensagem' => 'Metodo nao permitido.', 'codigo' => 'metodo_nao_permitido'], 405, array_intersect_key($e->getHeaders(), ['Allow' => 1]))
            : null);

        // Erros do PostgreSQL (QueryException e a PDOException crua do COMMIT).
        // report: SEMPRE (HTTP, comando, fila, scheduler) pelo ErroDeBanco, no
        // lugar do log padrao, que traria SQL, bindings e a mensagem do trigger.
        // render: so em requisicao JSON; o resto segue o padrao do Laravel.
        $exceptions->report(fn (PDOException $e) => ErroDeBanco::registrar($e))->stop();
        $exceptions->render(fn (PDOException $e, Request $request) => $pedeJson($request) ? ErroDeBanco::resposta($e) : null);

        // Ultimo recurso em api/*: qualquer outro erro sai fixo, sem trace nem
        // mensagem interna, mesmo com APP_DEBUG ligado.
        $exceptions->render(fn (Throwable $e, Request $request) => $daApi($request)
            ? ($e instanceof HttpExceptionInterface
                ? response()->json(['mensagem' => 'Requisicao recusada.', 'codigo' => 'requisicao_recusada'], $e->getStatusCode())
                : response()->json(['mensagem' => 'Erro interno.', 'codigo' => 'erro_interno'], 500))
            : null);
    })->create();

// Kernel de console proprio so para imprimir erro de banco sem PII (ver a
// classe). Os afterResolving do builder conferem por instanceof e continuam valendo.
$app->singleton(ConsoleKernel::class, Kernel::class);

return $app;
