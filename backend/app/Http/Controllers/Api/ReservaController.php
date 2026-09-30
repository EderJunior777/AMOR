<?php

namespace App\Http\Controllers\Api;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\PedidoDeReserva;
use App\Domain\Agenda\ReservarHorario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RemarcarRequest;
use App\Http\Requests\Api\ReservaExistenteRequest;
use App\Http\Requests\Api\ReservarRequest;
use App\Http\Resources\ReservaResource;
use App\Support\ChaveDeLimite;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Reserva pelo site. Fino: request (so formato) -> ReservarHorario -> resource.
 * Toda regra comercial e recusa moram no dominio (ReservaRecusada, 422; 23P01, 409).
 */
class ReservaController extends Controller
{
    private const UMA_HORA = 3600;

    /**
     * 201 na criacao; 200 na repeticao idempotente (mesmo corpo). Canal: sempre o site.
     *
     * Limite por telefone (achado #2): conferido aqui, DEPOIS da validacao, e
     * so reserva CRIADA consome. Recusa (422), conflito (409) e repeticao nao
     * contam: quem sabe o telefone de alguem nao esgota o limite dessa pessoa.
     */
    public function criar(ReservarRequest $request, ReservarHorario $reservas): JsonResponse
    {
        $dados = $request->validated();
        $pedido = PedidoDeReserva::deDados($dados, (string) $dados['idempotency_key']);

        $chave = ChaveDeLimite::de('criar-telefone', $pedido->clienteTelefone);
        if (RateLimiter::tooManyAttempts($chave, (int) config('cleison.api.limites.criar_por_hora_telefone'))) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($chave)]);
        }

        $resultado = $reservas->executar($pedido, Canal::Site);
        if (! $resultado->repetida) {
            RateLimiter::hit($chave, self::UMA_HORA);
        }

        return (new ReservaResource($resultado->agendamento))
            ->response($request)
            ->setStatusCode($resultado->repetida ? 200 : 201);
    }

    public function consultar(ReservaExistenteRequest $request, ReservarHorario $reservas): ReservaResource
    {
        $dados = $request->validated();

        return new ReservaResource($reservas->consultarPeloCliente((string) $dados['codigo'], (string) $dados['telefone']));
    }

    public function cancelar(ReservaExistenteRequest $request, ReservarHorario $reservas): ReservaResource
    {
        $dados = $request->validated();

        return new ReservaResource($reservas->cancelarPeloCliente((string) $dados['codigo'], (string) $dados['telefone']));
    }

    public function remarcar(RemarcarRequest $request, ReservarHorario $reservas): ReservaResource
    {
        $dados = $request->validated();

        return new ReservaResource($reservas->remarcarPeloCliente(
            (string) $dados['codigo'],
            (string) $dados['telefone'],
            (string) $dados['data'],
            (string) $dados['hora'],
        ));
    }
}
