<?php

use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\DisponibilidadeController;
use App\Http\Controllers\Api\ReservaController;
use Illuminate\Support\Facades\Route;

/*
 * API publica v1 do site (prefixo api/v1 vem do bootstrap/app.php). Sem
 * estado: o grupo "api" nao tem sessao, cookie nem CSRF; sem CORS (o site
 * usa proxy na mesma origem). Codigo e telefone da reserva vao no CORPO,
 * nunca na URL. Limites: RateLimiter::for no AppServiceProvider.
 */
Route::middleware('throttle:api-geral')->group(function () {
    Route::get('servicos', [CatalogoController::class, 'servicos']);
    Route::get('regioes', [CatalogoController::class, 'regioes']);
    Route::get('profissionais', [CatalogoController::class, 'profissionais']);
    Route::get('disponibilidade', DisponibilidadeController::class);

    Route::post('reservas', [ReservaController::class, 'criar'])->middleware('throttle:api-criar-reserva');

    Route::middleware('throttle:api-reserva-existente')->group(function () {
        Route::post('reservas/consultar', [ReservaController::class, 'consultar']);
        Route::post('reservas/cancelar', [ReservaController::class, 'cancelar']);
        Route::post('reservas/remarcar', [ReservaController::class, 'remarcar']);
    });
});
