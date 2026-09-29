<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Regiao atendida em domicilio: taxa (centavos) e deslocamento (minutos). */
class RegiaoAtendimento extends Model
{
    protected $table = 'regioes_atendimento';

    protected $fillable = ['codigo', 'nome', 'deslocamento_minutos', 'taxa_centavos', 'ativo', 'ordem'];

    protected function casts(): array
    {
        return [
            'deslocamento_minutos' => 'integer',
            'taxa_centavos' => 'integer',
            'ativo' => 'boolean',
            'ordem' => 'integer',
        ];
    }
}
