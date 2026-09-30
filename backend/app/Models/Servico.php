<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Item do catalogo. Preco em centavos (integer). Desativar (ativo=false)
 * tira o servico do site sem apagar o historico que o referencia.
 */
class Servico extends Model
{
    protected $table = 'servicos';

    protected $fillable = [
        'codigo', 'nome', 'descricao', 'preco_centavos', 'duracao_minutos',
        'conta_como_corte', 'permite_barbearia', 'permite_domicilio', 'ativo', 'ordem',
    ];

    protected function casts(): array
    {
        return [
            'preco_centavos' => 'integer',
            'duracao_minutos' => 'integer',
            'conta_como_corte' => 'boolean',
            'permite_barbearia' => 'boolean',
            'permite_domicilio' => 'boolean',
            'ativo' => 'boolean',
            'ordem' => 'integer',
        ];
    }

    public function profissionais(): BelongsToMany
    {
        return $this->belongsToMany(Profissional::class, 'profissional_servico');
    }
}
