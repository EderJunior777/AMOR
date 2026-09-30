<?php

namespace App\Support;

use App\Enums\Ator;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Unico ponto de entrada para escrever em agendamentos e bloqueios.
 *
 * Abre a transacao e define quem esta agindo (cleison.ator e
 * cleison.usuario_id, lidos pelo trigger de historico) com escopo de
 * TRANSACAO (set_config(..., true)): nao vaza para a proxima transacao da
 * mesma conexao. Sem isso, o historico registraria "sistema" para
 * qualquer um, ou quem esquecesse herdaria o ator anterior.
 *
 * Regras:
 *   - operador exige um User salvo e ATIVO no banco (conferido dentro da
 *     transacao: um usuario desativado depois de carregado e recusado);
 *   - cliente e sistema nao levam User (clientes nao sao Users);
 *   - $motivo (encaixe fora do expediente/antecedencia, decisao E2) so com
 *     operador: vira cleison.motivo e o trigger grava em dados.motivo dos
 *     eventos "criado"/"remarcado".
 *
 * Aninhada (chamada dentro de outra transacao), vale o ator de dentro
 * durante o $fn e o anterior e restaurado ao sair; em erro, o rollback do
 * savepoint ja desfaz a troca.
 */
final class TransacaoAuditada
{
    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     *
     * @throws AutoriaInvalida
     */
    public static function executar(Ator $ator, ?User $usuario, Closure $fn, ?string $motivo = null): mixed
    {
        if ($ator === Ator::Operador && ($usuario === null || ! $usuario->exists)) {
            throw new AutoriaInvalida('Ator operador exige um usuario cadastrado.');
        }
        if ($ator !== Ator::Operador && $usuario !== null) {
            throw new AutoriaInvalida("Ator {$ator->value} nao leva usuario; use operador.");
        }
        // E2: so o operador justifica um encaixe (vai para o historico).
        if ($motivo !== null && $ator !== Ator::Operador) {
            throw new AutoriaInvalida("Ator {$ator->value} nao informa motivo de encaixe.");
        }

        return DB::transaction(function () use ($ator, $usuario, $fn, $motivo) {
            if ($usuario !== null
                // FOR SHARE: ninguem desativa o operador ate o fim da transacao.
                && User::query()->whereKey($usuario->getKey())->where('ativo', true)->sharedLock()->first(['id']) === null) {
                throw new AutoriaInvalida('Ator operador exige usuario ativo.');
            }

            $anterior = DB::selectOne(
                "SELECT current_setting('cleison.ator', true) AS ator, current_setting('cleison.usuario_id', true) AS usuario,
                        current_setting('cleison.motivo', true) AS motivo"
            );
            self::definir($ator->value, $usuario === null ? '' : (string) $usuario->getKey(), (string) $motivo);

            $resultado = $fn();

            self::definir((string) $anterior->ator, (string) $anterior->usuario, (string) $anterior->motivo);

            return $resultado;
        });
    }

    private static function definir(string $ator, string $usuarioId, string $motivo): void
    {
        DB::select(
            "SELECT set_config('cleison.ator', ?, true), set_config('cleison.usuario_id', ?, true),
                    set_config('cleison.motivo', ?, true)",
            [$ator, $usuarioId, $motivo]
        );
    }
}
