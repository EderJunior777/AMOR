<?php

namespace App\Domain\Agenda;

use Carbon\CarbonImmutable;

/**
 * Intervalo [inicio, fim) de expediente, em instantes UTC
 * (docs/ESPEC-RESERVA.md, 3 V8). Sempre dentro de um so dia local.
 */
final readonly class Janela
{
    public function __construct(
        public CarbonImmutable $inicio,
        public CarbonImmutable $fim,
    ) {}

    /** O periodo [inicio, fim) cabe inteiro nesta janela. */
    public function contem(CarbonImmutable $inicio, CarbonImmutable $fim): bool
    {
        return $inicio >= $this->inicio && $fim <= $this->fim;
    }
}
