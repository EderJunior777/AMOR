<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabecalhos de seguranca de TODA resposta sob /painel (etapa 3).
 *
 *   - CSP estrita: nada de script nem estilo inline, nada de origem externa
 *     (a pagina so carrega o proprio CSS e JS), formulario so para a mesma
 *     origem, sem <base>, sem plugin, e frame-ancestors none (anti-clickjacking);
 *   - X-Frame-Options DENY (navegadores antigos), nosniff, Referrer-Policy
 *     no-referrer, COOP/CORP same-origin;
 *   - no-store: dado pessoal de cliente nunca fica em cache do navegador nem
 *     de intermediario, nem volta pelo botao "voltar" depois de sair.
 *
 * Roda como middleware global (respostas normais e redirecionamentos) E pelo
 * handler de excecoes (404, 405, 419, 500...), que o Laravel monta fora do
 * pipeline global: aplicar() e o ponto unico. So /painel e /painel/*; a API
 * publica (JSON) e a saude (/up) ficam como estao.
 */
final class CabecalhosDoPainel
{
    private const CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; "
        ."connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        return self::aplicar($request, $next($request));
    }

    public static function aplicar(Request $request, Response $resposta): Response
    {
        if (! $request->is('painel', 'painel/*')) {
            return $resposta;
        }

        $resposta->headers->set('Content-Security-Policy', self::CSP);
        $resposta->headers->set('X-Frame-Options', 'DENY');
        $resposta->headers->set('X-Content-Type-Options', 'nosniff');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');
        $resposta->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $resposta->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $resposta->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $resposta->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $resposta->headers->set('Pragma', 'no-cache');

        return $resposta;
    }
}
