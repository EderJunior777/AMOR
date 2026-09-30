<?php

namespace App\Domain\Agenda;

use Carbon\CarbonImmutable;

/**
 * Resultado do calculo da reserva (docs/ESPEC-RESERVA.md, 2.3). Todos os
 * instantes em UTC e em minutos cheios.
 */
final readonly class ReservaCalculada
{
    /** @param list<SnapshotServico> $itens na ordem pedida */
    public function __construct(
        public array $itens,
        public int $duracaoMinutos,
        public CarbonImmutable $inicioServico,
        public CarbonImmutable $fimServico,
        public CarbonImmutable $inicioOcupado,
        public CarbonImmutable $fimOcupado,
        public int $deslocamentoMinutos,
        public int $taxaCentavos,
        public ?string $regiaoNome,
        public int $totalCentavos,
    ) {}
}
