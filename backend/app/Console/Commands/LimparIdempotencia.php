<?php

namespace App\Console\Commands;

use App\Domain\Agenda\ReservarHorario;
use Illuminate\Console\Command;

/**
 * Limpeza diaria da idempotencia (Fase 6): chave e hash dos agendamentos
 * criados ha mais de 7 dias viram NULL. Quem grava e
 * ReservarHorario::limparIdempotencia (funcao do banco); aqui so a saida,
 * que leva so a contagem. Agendado todo dia as 03:45 (routes/console.php).
 */
class LimparIdempotencia extends Command
{
    protected $signature = 'cleison:limpar-idempotencia';

    protected $description = 'Anula chave e hash de idempotencia dos agendamentos criados ha mais de 7 dias.';

    public function handle(ReservarHorario $reservas): int
    {
        $limpos = $reservas->limparIdempotencia();

        $this->info("Idempotencia limpa em {$limpos} agendamento(s).");

        return self::SUCCESS;
    }
}
