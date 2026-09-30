<?php

namespace App\Domain\Agenda;

use App\Support\ErroDeBanco;
use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDOException;

/**
 * Repete a operacao INTEIRA (a closure abre a propria transacao) quando o
 * PostgreSQL aborta por deadlock (40P01) ou falha de serializacao (40001)
 * (docs/ESPEC-RESERVA.md, secao 5): ate $maximoDeTentativas no total, com
 * espera aleatoria de 10 a 50 ms entre elas. Esgotadas, relanca a ULTIMA
 * excecao, que o ErroDeBanco traduz em 503 + Retry-After.
 *
 * Nao repete nenhum outro erro (23P01 e conflito definitivo, nao transitorio).
 * O log de cada nova tentativa leva so SQLSTATE e numero da tentativa: nunca
 * a query nem os parametros (nome, telefone, endereco).
 *
 * Chame FORA de qualquer transacao: dentro de uma, o PostgreSQL ja abortou a
 * transacao externa e repetir so a interna nao adianta.
 */
final class RepetirEmConflito
{
    private const REPETIVEIS = ['40P01', '40001'];

    public function __construct(
        private readonly int $maximoDeTentativas = 3,
        private readonly bool $esperar = true,
    ) {
        if ($maximoDeTentativas < 1) {
            throw new InvalidArgumentException('O maximo de tentativas precisa ser pelo menos 1.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operacao
     * @return T
     */
    public function executar(Closure $operacao): mixed
    {
        for ($tentativa = 1; ; $tentativa++) {
            try {
                return $operacao();
            } catch (PDOException $e) {
                $estado = ErroDeBanco::sqlstate($e);
                if ($tentativa >= $this->maximoDeTentativas || ! in_array($estado, self::REPETIVEIS, true)) {
                    throw $e;
                }

                Log::warning('Reserva repetida por conflito de banco', [
                    'sqlstate' => $estado,
                    'tentativa' => $tentativa + 1,
                ]);
                if ($this->esperar) {
                    usleep(random_int(10_000, 50_000));
                }
            }
        }
    }
}
