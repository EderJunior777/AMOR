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
 */
#[Fillable(['name', 'email', 'password', 'papel', 'ativo'])]
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
