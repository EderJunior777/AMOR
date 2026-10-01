<?php

namespace App\Http\Controllers\Painel;

use App\Domain\Painel\ConsultasDoPainel;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tela inicial do painel: os pedidos (reservas solicitadas) esperando decisao
 * e o resumo que a atualizacao automatica consulta.
 */
final class PedidosController extends Controller
{
    use ComReservasNaTela;

    public function __construct(private readonly ConsultasDoPainel $consultas) {}

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $resumo = $this->consultas->resumoDePedidos($usuario);

        return view('painel.pedidos', [
            'pedidos' => $this->apresentar($this->consultas->pedidos($usuario)),
            'total' => $resumo['total'],
            'resultado' => $this->resultadoDaAcao($request, $usuario, $this->consultas),
        ]);
    }

    /** JSON minimo para a atualizacao automatica: so contagem e ids internos (nenhum dado pessoal, nenhum codigo). */
    public function resumo(Request $request): JsonResponse
    {
        return response()
            ->json($this->consultas->resumoDePedidos($request->user()))
            ->header('Cache-Control', 'no-store, private, max-age=0');
    }
}
