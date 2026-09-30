<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Profissional para a escolha no site: so id e nome de exibicao (nunca user_id). */
class ProfissionalResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome_exibicao' => $this->nome_exibicao,
        ];
    }
}
