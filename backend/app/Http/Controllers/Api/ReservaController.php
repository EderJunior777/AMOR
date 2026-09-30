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
use Illuminate\Http\JsonResponse;

/**
 * Reserva pelo site. Fino: request (so formato) -> ReservarHorario -> resource.
 * Toda regra comercial e recusa moram no dominio (ReservaRecusada, 422; 23P01, 409).
 */
class ReservaController extends Controller
{
    /** 201 na criacao; 200 na repeticao idempotente (mesmo corpo). Canal: sempre o site. */
    public function criar(ReservarRequest $request, ReservarHorario $reservas): JsonResponse
    {
        $dados = $request->validated();

        $resultado = $reservas->executar(
            PedidoDeReserva::deDados($dados, (string) $dados['idempotency_key']),
            Canal::Site,
        );

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
