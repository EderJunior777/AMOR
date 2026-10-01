<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Senha temporaria (gerada pelo proprietario): enquanto nao for trocada, o
 * unico lugar do painel acessivel e a tela de troca (e o botao de sair).
 */
final class ExigeSenhaDefinitiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && $usuario->senha_temporaria
            && ! $request->routeIs('painel.conta.senha', 'painel.conta.senha.salvar', 'painel.sair')) {
            return redirect()->route('painel.conta.senha');
        }

        return $next($request);
    }
}
