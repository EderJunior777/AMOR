<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente da barbearia. Sem senha: identificar-se por telefone nao da
 * acesso a nada; consultar historico exige prova de posse (etapa 2/3).
 */
class Cliente extends Model
{
    protected $table = 'clientes';

    protected $fillable = ['nome', 'telefone', 'observacoes'];

    public function agendamentos(): HasMany
    {
        return $this->hasMany(Agendamento::class);
    }
}
