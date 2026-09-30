<?php

namespace Tests\Unit;

use App\Support\Telefone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelefoneTest extends TestCase
{
    public static function validos(): array
    {
        return [
            'mascara do site' => ['(11) 98765-4321', '+5511987654321'],
            'so digitos celular' => ['11987654321', '+5511987654321'],
            'fixo com DDD' => ['1133334444', '+551133334444'],
            'com 55 sem +' => ['5511987654321', '+5511987654321'],
            'com +55' => ['+55 11 98765-4321', '+5511987654321'],
            'estrangeiro com +' => ['+1 (415) 555-2671', '+14155552671'],
        ];
    }

    #[DataProvider('validos')]
    public function test_normaliza_para_e164(string $bruto, string $esperado): void
    {
        $this->assertSame($esperado, Telefone::normalizar($bruto));
    }

    public static function invalidos(): array
    {
        return [
            'vazio' => [''],
            'nulo' => [null],
            'curto demais' => ['123'],
            'sem DDD' => ['98765-4321'],
            'DDD com zero' => ['(01) 98765-4321'],
            'digitos demais sem +' => ['00551198765432100'],
            '+ com zero no pais' => ['+0 11 98765-4321'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_recusa_o_que_nao_e_telefone(?string $bruto): void
    {
        $this->assertNull(Telefone::normalizar($bruto));
    }
}
