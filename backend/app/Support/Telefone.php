<?php

namespace App\Support;

/**
 * Normalizacao de telefone para E.164 (formato guardado em clientes.telefone).
 *
 * O site original guarda o que o cliente digitou ("(11) 98765-4321") e
 * compara so os digitos. Aqui a regra fica explicita:
 *   - 10 ou 11 digitos: numero brasileiro com DDD, recebe +55;
 *   - 12 ou 13 digitos comecando com 55: ja tem o codigo do pais;
 *   - qualquer outra coisa: invalido (null). Numero estrangeiro exige
 *     informar o "+" explicitamente.
 *
 * Normalizar NAO autentica ninguem: telefone e identificador, nao prova de
 * posse (ver docs/ARQUITETURA.md, "Clientes e acesso").
 */
final class Telefone
{
    public static function normalizar(?string $bruto): ?string
    {
        $bruto = trim((string) $bruto);
        if ($bruto === '') {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $bruto);

        if (str_starts_with($bruto, '+')) {
            return preg_match('/^[1-9]\d{7,14}$/', $digitos) ? '+'.$digitos : null;
        }

        $tamanho = strlen($digitos);

        if (($tamanho === 10 || $tamanho === 11) && self::dddValido($digitos)) {
            return '+55'.$digitos;
        }

        if (($tamanho === 12 || $tamanho === 13) && str_starts_with($digitos, '55')
            && self::dddValido(substr($digitos, 2))) {
            return '+'.$digitos;
        }

        return null;
    }

    // DDD brasileiro: dois digitos de 11 a 99, sem zero em nenhuma posicao.
    private static function dddValido(string $nacional): bool
    {
        return (bool) preg_match('/^[1-9][1-9]/', $nacional);
    }
}
