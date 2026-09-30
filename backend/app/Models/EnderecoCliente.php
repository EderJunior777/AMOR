<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Endereco de atendimento a domicilio. O agendamento guarda um snapshot em
 * texto (endereco_texto); mudar o endereco depois nao altera o combinado.
 */
class EnderecoCliente extends Model
{
    protected $table = 'enderecos_cliente';

    /** O dono vem da relacao ($cliente->enderecos()->create()), nunca da requisicao. */
    protected $fillable = ['regiao_id', 'logradouro', 'complemento', 'referencia'];

    protected function casts(): array
    {
        return ['arquivado_em' => 'immutable_datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function regiao(): BelongsTo
    {
        return $this->belongsTo(RegiaoAtendimento::class, 'regiao_id');
    }
}
