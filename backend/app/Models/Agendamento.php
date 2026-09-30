<?php

namespace App\Models;

use App\Enums\EstadoAgendamento;
use App\Enums\Modalidade;
use App\Enums\OrigemAgendamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agendamento = tambem o registro do atendimento (inclusive o espontaneo,
 * com origem presencial). Regras de integridade vivem no banco; ver
 * docs/MODELO-DE-DADOS.md.
 *
 * `periodo_ocupado` e coluna gerada: nunca e gravada pela aplicacao.
 */
class Agendamento extends Model
{
    protected $table = 'agendamentos';

    /**
     * So o texto livre do cliente entra em massa. Estado, cliente, autoria,
     * idempotencia, cancelamento e codigo publico mudam por metodos de
     * dominio; horarios, profissional, taxa e snapshot do endereco sao
     * calculados e validados no servidor (docs/ESPEC-RESERVA.md) e gravados
     * com forceFill, nunca copiados de uma requisicao.
     */
    protected $fillable = ['observacao_cliente'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoAgendamento::class,
            'origem' => OrigemAgendamento::class,
            'modalidade' => Modalidade::class,
            'inicio_servico' => 'immutable_datetime',
            'fim_servico' => 'immutable_datetime',
            'inicio_ocupado' => 'immutable_datetime',
            'fim_ocupado' => 'immutable_datetime',
            'cancelado_em' => 'immutable_datetime',
            'deslocamento_minutos' => 'integer',
            'taxa_deslocamento_centavos' => 'integer',
        ];
    }

    public function profissional(): BelongsTo
    {
        return $this->belongsTo(Profissional::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(AgendamentoItem::class)->orderBy('ordem');
    }

    /** Historico somente leitura, gravado pelo banco. */
    public function eventos(): HasMany
    {
        return $this->hasMany(AgendamentoEvento::class)->orderBy('id');
    }
}
