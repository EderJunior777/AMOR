<?php

namespace App\Console\Commands;

use App\Domain\Agenda\ReservarHorario;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Expira reservas "solicitado" vencidas (achado #1a da Fase 5). Quem grava e
 * ReservarHorario::expirarSolicitados; aqui so a saida. Agendado a cada 5
 * minutos (routes/console.php). A saida so leva a contagem.
 */
class ExpirarSolicitados extends Command
{
    protected $signature = 'cleison:expirar-solicitados';

    protected $description = 'Cancela (motivo "expirado") reservas solicitadas nao confirmadas no prazo ou cujo inicio ja chegou.';

    public function handle(ReservarHorario $reservas): int
    {
        try {
            $expiradas = $reservas->expirarSolicitados();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$expiradas} reserva(s) solicitada(s) expirada(s).");

        return self::SUCCESS;
    }
}
