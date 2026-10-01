<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Limite de tentativas de login do painel, por E-MAIL e por IP, no cache
 * (RateLimiter). As chaves sao HMAC (ChaveDeLimite): nem e-mail nem IP crus
 * no cache, e nada disso vai para o banco de auditoria nem para o log.
 *
 * O e-mail conta existente ou nao (o bloqueio e o mesmo para os dois: sem
 * pista de quem tem conta). Bloqueado, nem a senha certa entra ate a janela
 * acabar. Janela FIXA, aberta na primeira falha. Valor de configuracao
 * invalido falha fechado (excecao), nunca "sem limite".
 */
final class LimiteDeLogin
{
    /** Segundos ate liberar, ou nulo se nenhum dos dois limites estourou. */
    public static function espera(string $email, string $ip): ?int
    {
        $esperas = [];
        foreach ([[self::chaveDoEmail($email), 'login_max_falhas_por_email'], [self::chaveDoIp($ip), 'login_max_falhas_por_ip']] as [$chave, $maximo]) {
            if (RateLimiter::tooManyAttempts($chave, self::inteiro($maximo))) {
                $esperas[] = RateLimiter::availableIn($chave);
            }
        }

        return $esperas === [] ? null : max(1, ...$esperas);
    }

    public static function registrarFalha(string $email, string $ip): void
    {
        $janela = self::inteiro('login_janela_minutos') * 60;
        RateLimiter::hit(self::chaveDoEmail($email), $janela);
        RateLimiter::hit(self::chaveDoIp($ip), $janela);
    }

    /** Login certo: zera as falhas do e-mail (as do IP seguem). */
    public static function zerarEmail(string $email): void
    {
        RateLimiter::clear(self::chaveDoEmail($email));
    }

    /** Normaliza como o login: minusculo, sem espacos nas pontas, no maximo 254. */
    public static function normalizarEmail(string $email): string
    {
        return mb_substr(mb_strtolower(trim($email)), 0, 254);
    }

    private static function chaveDoEmail(string $email): string
    {
        return ChaveDeLimite::de('painel-login-email', self::normalizarEmail($email));
    }

    private static function chaveDoIp(string $ip): string
    {
        return ChaveDeLimite::de('painel-login-ip', $ip);
    }

    /** Valor de cleison.painel.$chave: inteiro >= 1, senao falha fechado. */
    public static function inteiro(string $chave): int
    {
        $valor = filter_var(config("cleison.painel.{$chave}"), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($valor === false) {
            throw new InvalidArgumentException("cleison.painel.{$chave} precisa ser um inteiro maior que zero.");
        }

        return $valor;
    }
}
