<?php

namespace App\Support;

use RuntimeException;

/**
 * Chave de limite de requisicao que nunca leva o valor cru (telefone,
 * codigo, IP): HMAC SHA-256 com chave derivada do APP_KEY por HKDF, com
 * rotulo proprio (a chave da aplicacao nao e usada direto e a derivada
 * serve so a isto). O cache padrao e uma tabela do banco; sem APP_KEY,
 * falha fechado. Usada pelos RateLimiter::for do AppServiceProvider e pelo
 * limite por telefone da criacao de reserva (ReservaController).
 */
final class ChaveDeLimite
{
    public static function de(string $rotulo, string ...$partes): string
    {
        $chave = (string) config('app.key');
        $material = str_starts_with($chave, 'base64:') ? (string) base64_decode(substr($chave, 7), true) : $chave;
        if ($material === '') {
            throw new RuntimeException('APP_KEY ausente: os limites da API nao podem ser calculados.');
        }

        $derivada = hash_hkdf('sha256', $material, 32, 'cleison.limites');

        return $rotulo.':'.hash_hmac('sha256', implode("\0", $partes), $derivada);
    }
}
