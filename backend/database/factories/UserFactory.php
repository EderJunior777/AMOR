<?php

namespace Database\Factories;

use App\Enums\PapelUsuario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Somente para testes. Cada usuario ganha uma senha aleatoria: nao existe
 * "senha padrao" conhecida, nem em desenvolvimento.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Str::password(24),
            'papel' => PapelUsuario::Barbeiro,
            'ativo' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function proprietario(): static
    {
        return $this->state(fn () => ['papel' => PapelUsuario::Proprietario]);
    }
}
