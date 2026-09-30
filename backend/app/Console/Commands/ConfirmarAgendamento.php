<?php

namespace App\Console\Commands;

use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\ReservarHorario;
use App\Models\User;
use App\Support\AutoriaInvalida;
use Illuminate\Console\Command;

/**
 * Confirma uma reserva solicitada (solicitado -> confirmado) ate o painel da
 * etapa 3 existir. Quem grava e ReservarHorario::confirmar; aqui so se
 * resolve o operador (--usuario = id, como em cleison:anonimizar-cliente).
 *
 * A saida nunca leva o codigo publico nem dado do cliente, e nada e logado.
 */
class ConfirmarAgendamento extends Command
{
    protected $signature = 'cleison:confirmar-agendamento
        {codigo : Codigo publico da reserva}
        {--usuario= : Id do operador que confirma}';

    protected $description = 'Confirma uma reserva solicitada (registra o operador no historico).';

    public function handle(ReservarHorario $reservas): int
    {
        $usuarioId = filter_var($this->option('usuario'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($usuarioId === false) {
            $this->error('Informe --usuario=<id do operador que confirma>.');

            return self::FAILURE;
        }

        $operador = User::query()->find($usuarioId);
        if ($operador === null || ! $operador->ativo) {
            $this->error('O usuario informado nao existe ou esta inativo.');

            return self::FAILURE;
        }

        try {
            $agendamento = $reservas->confirmar((string) $this->argument('codigo'), $operador);
        } catch (ReservaRecusada $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (AutoriaInvalida) {
            $this->error('O usuario informado nao existe ou esta inativo.');

            return self::FAILURE;
        }

        $this->info("Reserva confirmada (estado: {$agendamento->estado->value}).");

        return self::SUCCESS;
    }
}
