<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Janela de trabalho numa data especifica, em hora local do estabelecimento.
 * Se a data tem excecoes, elas SUBSTITUEM as janelas semanais do dia.
 */
class ExcecaoExpediente extends Model
{
    protected $table = 'excecoes_expediente';

    protected $fillable = ['profissional_id', 'data', 'hora_inicio', 'hora_fim', 'motivo'];

    protected function casts(): array
    {
        return ['data' => 'immutable_date'];
    }

    public function profissional(): BelongsTo
    {
        return $this->belongsTo(Profissional::class);
    }
}
