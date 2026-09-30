<?php

namespace App\Models;

use App\Enums\PapelUsuario;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Identidade administrativa (proprietario, barbeiro, recepcao).
 * Clientes da barbearia nao sao Users.
 *
 * `papel` e `ativo` definem poder: nunca por atribuicao em massa. Quem os
 * define o faz explicitamente (forceFill), como CriarProprietario.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'papel' => PapelUsuario::class,
            'ativo' => 'boolean',
        ];
    }

    public function profissional(): HasOne
    {
        return $this->hasOne(Profissional::class);
    }
}
