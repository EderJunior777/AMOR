<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Hash de uma senha aleatoria que ninguem conhece, com o MESMO custo (rounds)
 * das senhas reais. O login confere a senha contra ele quando o e-mail nao
 * existe, para a resposta demorar o mesmo e nao revelar quem tem conta.
 * Guardado no cache (calculado uma vez por custo, nao a cada tentativa).
 */
final class HashIsca
{
    public static function obter(): string
    {
        $rounds = (int) config('hashing.bcrypt.rounds', 12);

        return Cache::rememberForever("painel:hash-isca:{$rounds}", fn () => Hash::make(Str::random(40)));
    }
}
