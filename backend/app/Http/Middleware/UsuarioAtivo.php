<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessao aberta de quem foi desativado: o usuario e lido do banco a cada
 * requisicao; se nao esta mais ativo, a sessao e encerrada e o painel nao
 * serve mais nada (GerenciarEquipe tambem apaga as sessoes dele).
 */
final class UsuarioAtivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->ativo) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('painel.entrar')->with('erro', 'Sua sessão foi encerrada. Entre de novo.');
        }

        return $next($request);
    }
}
