<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Limite de tentativas de login do painel, no cache (RateLimiter). As chaves
 * sao HMAC (ChaveDeLimite): nem e-mail nem IP crus no cache, e nada disso vai
 * para o banco de auditoria nem para o log.
 *
 * TRES contadores por tentativa (existente ou nao o e-mail: igual, sem pista
 * de quem tem conta):
 *   - e-mail + IP (padrao 5): quem erra a senha de um e-mail so se tranca a si.
 *     Um atacante em outro IP NAO tranca o dono do e-mail fora;
 *   - e-mail, somando todos os IPs (padrao 30): teto contra palpite distribuido;
 *   - IP, qualquer e-mail (padrao 20): contra varredura de contas.
 * Bloqueado, nem a senha certa entra ate a janela acabar.
 *
 * contar() acontece ANTES de conferir a senha: a conferencia (bcrypt) demora
 * centenas de ms, e requisicoes paralelas passariam todas pela checagem antes
 * de qualquer contagem. Com a contagem antes, so as primeiras N conferem.
 * Janela FIXA, aberta na primeira tentativa. Valor invalido de configuracao
 * falha fechado (excecao), nunca "sem limite".
 */
final class LimiteDeLogin
{
    /** Segundos ate liberar, ou nulo se nenhum limite estourou. Nao conta nada. */
    public static function espera(string $email, string $ip): ?int
    {
        $esperas = [];
        foreach (self::limites($email, $ip) as [$chave, $maximo]) {
            if (RateLimiter::tooManyAttempts($chave, $maximo)) {
                $esperas[] = max(1, RateLimiter::availableIn($chave));
            }
        }

        return $esperas === [] ? null : max($esperas);
    }

    /**
     * Conta a tentativa nos tres contadores e devolve a espera em segundos se
     * ALGUM passou do maximo (essa tentativa nao pode conferir a senha), ou nulo.
     */
    public static function contar(string $email, string $ip): ?int
    {
        $janela = self::inteiro('login_janela_minutos') * 60;
        $esperas = [];
        foreach (self::limites($email, $ip) as [$chave, $maximo]) {
            if (RateLimiter::hit($chave, $janela) > $maximo) {
                $esperas[] = max(1, RateLimiter::availableIn($chave));
            }
        }

        return $esperas === [] ? null : max($esperas);
    }

    /** Login certo: zera as tentativas do par e-mail + IP (o teto por e-mail e o do IP seguem). */
    public static function zerar(string $email, string $ip): void
    {
        RateLimiter::clear(self::chaveDoPar($email, $ip));
    }

    /**
     * Verdadeiro so na PRIMEIRA tentativa bloqueada da janela (do par e-mail +
     * IP): a trilha de auditoria e imutavel e nao pode crescer a cada
     * tentativa de um flood.
     */
    public static function primeiroBloqueio(string $email, string $ip, int $esperaEmSegundos): bool
    {
        return Cache::add(ChaveDeLimite::de('painel-bloqueio-auditado', self::normalizarEmail($email), $ip), 1, max(1, $esperaEmSegundos));
    }

    /** Normaliza como o login: minusculo, sem espacos nas pontas, no maximo 254. */
    public static function normalizarEmail(string $email): string
    {
        return mb_substr(mb_strtolower(trim($email)), 0, 254);
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

    /** @return list<array{0: string, 1: int}> */
    private static function limites(string $email, string $ip): array
    {
        return [
            [self::chaveDoPar($email, $ip), self::inteiro('login_max_falhas_por_email_e_ip')],
            [ChaveDeLimite::de('painel-login-email', self::normalizarEmail($email)), self::inteiro('login_max_falhas_por_email_total')],
            [ChaveDeLimite::de('painel-login-ip', $ip), self::inteiro('login_max_falhas_por_ip')],
        ];
    }

    private static function chaveDoPar(string $email, string $ip): string
    {
        return ChaveDeLimite::de('painel-login-par', self::normalizarEmail($email), $ip);
    }
}
