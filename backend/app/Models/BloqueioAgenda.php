<?php

namespace App\Models;

use App\Enums\Ator;
use App\Support\TransacaoAuditada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Folga, ferias, feriado, compromisso: ocupa a agenda do profissional com a
 * mesma garantia do banco que um agendamento (ocupacoes_agenda). Ativo =
 * cancelado_em nulo; cancelar libera o horario (trigger).
 *
 * `periodo` e coluna gerada: nunca e gravada pela aplicacao.
 */
class BloqueioAgenda extends Model
{
    protected $table = 'bloqueios_agenda';

    /** Cancelamento e autoria mudam por metodo de dominio, nunca em massa. */
    protected $fillable = ['profissional_id', 'tipo', 'inicio', 'fim', 'motivo'];

    protected function casts(): array
    {
        return [
            'inicio' => 'immutable_datetime',
            'fim' => 'immutable_datetime',
            'cancelado_em' => 'immutable_datetime',
        ];
    }

    public function profissional(): BelongsTo
    {
        return $this->belongsTo(Profissional::class);
    }

    public function ativo(): bool
    {
        return $this->cancelado_em === null;
    }

    /** Idempotente: cancelar um bloqueio ja cancelado nao muda nada. */
    public function cancelar(User $operador): void
    {
        TransacaoAuditada::executar(Ator::Operador, $operador, function (): void {
            static::query()
                ->whereKey($this->getKey())
                ->whereNull('cancelado_em')
                ->update(['cancelado_em' => now()]);
        });

        $this->refresh();
    }
}
