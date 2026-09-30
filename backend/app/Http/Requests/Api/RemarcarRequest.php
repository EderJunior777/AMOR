<?php

namespace App\Http\Requests\Api;

/** POST /api/v1/reservas/remarcar: codigo + telefone + novo data e hora. */
class RemarcarRequest extends RequisicaoDaApi
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->regrasDeCodigoETelefone() + $this->regrasDeDataEHora(hora: true);
    }
}
