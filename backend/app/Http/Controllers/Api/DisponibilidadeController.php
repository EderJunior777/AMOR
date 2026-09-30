<?php

namespace App\Http\Controllers\Api;

use App\Domain\Agenda\ConsultarDisponibilidade;
use App\Enums\Modalidade;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DisponibilidadeRequest;
use App\Http\Resources\DisponibilidadeResource;

/** GET /api/v1/disponibilidade: horarios de inicio livres de UM dia (sugestao; a garantia e o INSERT). */
class DisponibilidadeController extends Controller
{
    public function __invoke(DisponibilidadeRequest $request, ConsultarDisponibilidade $consulta): DisponibilidadeResource
    {
        $dados = $request->validated();

        $horarios = $consulta->executar(
            (string) $dados['data'],
            array_map('intval', $dados['servicos']),
            (int) $dados['profissional_id'],
            Modalidade::from((string) $dados['modalidade']),
            isset($dados['regiao_id']) ? (int) $dados['regiao_id'] : null,
        );

        return new DisponibilidadeResource(['data' => (string) $dados['data'], 'horarios' => $horarios]);
    }
}
