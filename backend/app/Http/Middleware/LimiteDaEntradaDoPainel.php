<?php

namespace App\Http\Middleware;

use App\Support\ChaveDeLimite;
use App\Support\LimiteDeLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite por IP (no cache, chave HMAC) de pedidos a /painel/entrar, GET e POST,
 * ANTES de a sessao abrir: cada visita abre uma linha na tabela de sessoes, e
 * um flood anonimo nao pode enche-la. Passou do limite: 429 com Retry-After
 * (a pagina de erro e os cabecalhos do painel saem pelo handler de excecoes).
 */
final class LimiteDaEntradaDoPainel
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('painel/entrar')) {
            return $next($request);
        }

        $chave = ChaveDeLimite::de('painel-entrar', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($chave, LimiteDeLogin::inteiro('entrar_por_minuto'))) {
            abort(429, '', ['Retry-After' => (string) max(1, RateLimiter::availableIn($chave))]);
        }
        RateLimiter::hit($chave, 60);

        return $next($request);
    }
}
