<?php

namespace App\Domain\Agenda;

/** Copia imutavel da regiao de atendimento a domicilio no momento da reserva. */
final readonly class SnapshotRegiao
{
    public function __construct(
        public int $id,
        public string $nome,
        public int $deslocamentoMinutos,
        public int $taxaCentavos,
    ) {}
}
