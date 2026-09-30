<?php

namespace App\Domain\Agenda;

/** Copia imutavel do servico no momento da reserva (preco e duracao congelados). */
final readonly class SnapshotServico
{
    public function __construct(
        public int $id,
        public string $nome,
        public int $precoCentavos,
        public int $duracaoMinutos,
        public bool $contaComoCorte,
    ) {}
}
