<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Servico do catalogo publico. O id do catalogo nao e dado pessoal. */
class ServicoResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'preco_centavos' => $this->preco_centavos,
            'duracao_minutos' => $this->duracao_minutos,
            'permite_barbearia' => $this->permite_barbearia,
            'permite_domicilio' => $this->permite_domicilio,
        ];
    }
}
