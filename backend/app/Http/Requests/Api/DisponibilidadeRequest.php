<?php

namespace App\Http\Requests\Api;

/**
 * GET /api/v1/disponibilidade. Regiao ausente em domicilio nao e erro de
 * formato: o dominio recusa com domicilio_indisponivel (V7), o mesmo codigo
 * da reserva com regiao invalida.
 */
class DisponibilidadeRequest extends RequisicaoDaApi
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->regrasDeDataEHora(hora: false) + $this->regrasDeServicos() + [
            'profissional_id' => ['bail', 'required', 'integer', 'min:1'],
            'modalidade' => ['bail', 'required', 'string', 'in:barbearia,domicilio'],
            'regiao_id' => ['exclude_unless:modalidade,domicilio', 'nullable', 'integer', 'min:1'],
        ];
    }
}
