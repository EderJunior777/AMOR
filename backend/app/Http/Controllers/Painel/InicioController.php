<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pagina inicial do painel. Provisoria (Fase 2): a tela "Pedidos" vem na Fase 3.
 */
final class InicioController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('painel.inicio', ['usuario' => $request->user()]);
    }
}
