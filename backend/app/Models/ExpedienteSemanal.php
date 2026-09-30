<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Janela de trabalho num dia da semana (0 = domingo), em hora local do
 * estabelecimento. Intervalo de almoco = buraco entre duas janelas.
 */
class ExpedienteSemanal extends Model
{
    protected $table = 'expedientes_semanais';

    protected $fillable = ['profissional_id', 'dia_semana', 'hora_inicio', 'hora_fim'];

    protected function casts(): array
    {
        return ['dia_semana' => 'integer'];
    }

    public function profissional(): BelongsTo
    {
        return $this->belongsTo(Profissional::class);
    }
}
