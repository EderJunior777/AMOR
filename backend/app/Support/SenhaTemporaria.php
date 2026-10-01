<?php

namespace App\Support;

/**
 * Senha temporaria do primeiro acesso (equipe, etapa 3).
 *
 * 16 caracteres de um alfabeto SEM os confundiveis (0/O, 1/l/I) e sem
 * simbolos: o barbeiro vai digitar no celular a partir de uma tela ou de um
 * recado, e "<", ">" ou "&" estragariam a copia. Tem sempre maiuscula,
 * minuscula e numero (a politica minima do painel: 12 caracteres com letras e
 * numeros). ~93 bits de entropia, de random_int (CSPRNG).
 */
final class SenhaTemporaria
{
    private const MAIUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const MINUSCULAS = 'abcdefghijkmnpqrstuvwxyz';

    private const NUMEROS = '23456789';

    public const TAMANHO = 16;

    public static function gerar(): string
    {
        $alfabeto = self::MAIUSCULAS.self::MINUSCULAS.self::NUMEROS;

        do {
            $senha = '';
            for ($i = 0; $i < self::TAMANHO; $i++) {
                $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
        } while (! self::completa($senha));

        return $senha;
    }

    private static function completa(string $senha): bool
    {
        return strpbrk($senha, self::MAIUSCULAS) !== false
            && strpbrk($senha, self::MINUSCULAS) !== false
            && strpbrk($senha, self::NUMEROS) !== false;
    }
}
