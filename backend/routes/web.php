<?php

use App\Http\Controllers\Painel\AcoesDoPedidoController;
use App\Http\Controllers\Painel\LoginController;
use App\Http\Controllers\Painel\PedidosController;
use App\Http\Controllers\Painel\SenhaController;
use App\Http\Middleware\ExigeSenhaDefinitiva;
use App\Http\Middleware\UsuarioAtivo;
use Illuminate\Support\Facades\Route;

// Sem pagina publica aqui: a raiz so confirma que o servidor responde, sem
// anunciar servico, versao ou etapa. Saude (com o banco): GET /up.
Route::get('/', fn () => response()->noContent());

// Painel do operador (etapa 3). Cabecalhos de seguranca em toda resposta sob
// /painel: App\Http\Middleware\CabecalhosDoPainel (bootstrap/app.php). CSRF em
// todo POST (grupo "web"). Tudo, menos a entrada, exige login. Nenhuma rota
// leva o codigo ou o id de uma reserva na URL: vai por POST.
Route::prefix('painel')->name('painel.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('entrar', [LoginController::class, 'formulario'])->name('entrar');
        Route::post('entrar', [LoginController::class, 'entrar'])->name('entrar.enviar');
    });

    Route::middleware(['auth', UsuarioAtivo::class, ExigeSenhaDefinitiva::class])->group(function () {
        // Pedidos: a tela inicial.
        Route::get('/', [PedidosController::class, 'index'])->name('inicio');
        Route::get('pedidos/resumo', [PedidosController::class, 'resumo'])->name('pedidos.resumo');
        Route::post('pedidos/confirmar', [AcoesDoPedidoController::class, 'confirmar'])->name('pedidos.confirmar');
        Route::post('pedidos/recusar', [AcoesDoPedidoController::class, 'recusar'])->name('pedidos.recusar');

        Route::post('sair', [LoginController::class, 'sair'])->name('sair');
        Route::get('conta/senha', [SenhaController::class, 'formulario'])->name('conta.senha');
        Route::post('conta/senha', [SenhaController::class, 'salvar'])->name('conta.senha.salvar');
    });
});
