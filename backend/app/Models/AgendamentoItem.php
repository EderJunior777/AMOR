<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Servico do agendamento com snapshot de nome, preco, duracao e contagem. */
class AgendamentoItem extends Model
{
    protected $table = 'agendamento_itens';

    public const UPDATED_AT = null;

    protected $fillable = [
        'agendamento_id', 'servico_id', 'ordem', 'servico_nome',
        'preco_centavos', 'duracao_minutos', 'conta_como_corte',
    ];

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'preco_centavos' => 'integer',
            'duracao_minutos' => 'integer',
            'conta_como_corte' => 'boolean',
        ];
    }

    public function agendamento(): BelongsTo
    {
        return $this->belongsTo(Agendamento::class);
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }
}
