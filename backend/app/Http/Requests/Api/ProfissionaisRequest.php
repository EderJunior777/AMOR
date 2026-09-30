<?php

namespace App\Http\Requests\Api;

/** GET /api/v1/profissionais?servicos[]= */
class ProfissionaisRequest extends RequisicaoDaApi
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->regrasDeServicos();
    }
}
