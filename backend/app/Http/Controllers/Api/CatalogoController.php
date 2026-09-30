<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProfissionaisRequest;
use App\Http\Resources\ProfissionalResource;
use App\Http\Resources\RegiaoResource;
use App\Http\Resources\ServicoResource;
use App\Models\Estabelecimento;
use App\Models\Profissional;
use App\Models\RegiaoAtendimento;
use App\Models\Servico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Catalogo publico (leitura): servicos, regioes e profissionais. */
class CatalogoController extends Controller
{
    public function servicos(Request $request): JsonResponse
    {
        $servicos = Servico::query()->where('ativo', true)->orderBy('ordem')->orderBy('nome')->orderBy('id')->get();

        return response()->json(['servicos' => ServicoResource::collection($servicos)->resolve($request)]);
    }

    /** Sem atendimento a domicilio ligado, nao ha regiao para oferecer. */
    public function regioes(Request $request): JsonResponse
    {
        $regioes = Estabelecimento::atual()?->domicilio_ativo
            ? RegiaoAtendimento::query()->where('ativo', true)->orderBy('ordem')->orderBy('nome')->orderBy('id')->get()
            : collect();

        return response()->json(['regioes' => RegiaoResource::collection($regioes)->resolve($request)]);
    }

    /** Profissionais ATIVOS habilitados em TODOS os servicos pedidos. */
    public function profissionais(ProfissionaisRequest $request): JsonResponse
    {
        $servicos = array_values(array_unique(array_map('intval', $request->validated('servicos'))));

        $habilitados = DB::table('profissional_servico')
            ->select('profissional_id')
            ->whereIn('servico_id', $servicos)
            ->groupBy('profissional_id')
            ->havingRaw('count(distinct servico_id) = ?', [count($servicos)]);

        $profissionais = Profissional::query()
            ->where('ativo', true)
            ->whereIn('id', $habilitados)
            ->orderBy('ordem')->orderBy('nome_exibicao')->orderBy('id')
            ->get();

        return response()->json(['profissionais' => ProfissionalResource::collection($profissionais)->resolve($request)]);
    }
}
