<?php

namespace App\Domain\Agenda;

use App\Models\Agendamento;

/** $repetida: mesma chave de idempotencia e mesmo pedido; nada foi gravado agora. */
final readonly class ResultadoDaReserva
{
    public function __construct(
        public Agendamento $agendamento,
        public bool $repetida,
    ) {}
}
