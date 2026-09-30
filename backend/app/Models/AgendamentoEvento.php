<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Historico do agendamento (criado, estado_alterado, remarcado). Escrito SO
 * pelo trigger cleison_registrar_evento_agendamento; o papel da aplicacao nem
 * tem INSERT/UPDATE/DELETE na tabela. Aqui a escrita falha antes de chegar ao
 * banco, com um erro claro em vez de um 42501.
 */
class AgendamentoEvento extends Model
{
    protected $table = 'agendamento_eventos';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'dados' => 'array',
            'ocorrido_em' => 'immutable_datetime',
        ];
    }

    public function agendamento(): BelongsTo
    {
        return $this->belongsTo(Agendamento::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function save(array $options = []): bool
    {
        throw self::somenteLeitura();
    }

    public function delete(): ?bool
    {
        throw self::somenteLeitura();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw self::somenteLeitura();
    }

    private static function somenteLeitura(): LogicException
    {
        return new LogicException('O historico do agendamento e somente leitura (gravado pelo banco).');
    }
}
