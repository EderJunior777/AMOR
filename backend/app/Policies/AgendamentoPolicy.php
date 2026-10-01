<?php

namespace App\Policies;

use App\Enums\PapelUsuario;
use App\Models\Agendamento;
use App\Models\Profissional;
use App\Models\User;

/**
 * Quem pode ver e agir em uma reserva (painel, etapa 3).
 *
 *   - usuario INATIVO: em nada, qualquer que seja o papel;
 *   - proprietario e recepcao: em todas;
 *   - barbeiro: SO nas reservas do PROPRIO profissional (profissionais.user_id).
 *     Sem profissional vinculado, ou reserva de outro (IDOR): em nada.
 *
 * A policy decide por QUEM e a reserva. O ESTADO (so confirma o que esta
 * solicitado, etc.) e do dominio (ReservarHorario), que o confere de novo,
 * travado, dentro da transacao. Uma acao que nao existe aqui e negada pelo Gate.
 */
final class AgendamentoPolicy
{
    public function ver(User $usuario, Agendamento $agendamento): bool
    {
        return $this->doEscopoDe($usuario, $agendamento);
    }

    public function confirmar(User $usuario, Agendamento $agendamento): bool
    {
        return $this->doEscopoDe($usuario, $agendamento);
    }

    public function recusar(User $usuario, Agendamento $agendamento): bool
    {
        return $this->doEscopoDe($usuario, $agendamento);
    }

    public function iniciar(User $usuario, Agendamento $agendamento): bool
    {
        return $this->doEscopoDe($usuario, $agendamento);
    }

    public function concluir(User $usuario, Agendamento $agendamento): bool
    {
        return $this->doEscopoDe($usuario, $agendamento);
    }

    public function faltou(User $usuario, Agendamento $agendamento): bool
    {
        return $this->doEscopoDe($usuario, $agendamento);
    }

    public function cancelar(User $usuario, Agendamento $agendamento): bool
    {
        return $this->doEscopoDe($usuario, $agendamento);
    }

    private function doEscopoDe(User $usuario, Agendamento $agendamento): bool
    {
        if (! $usuario->ativo) {
            return false;
        }

        return match ($usuario->papel) {
            PapelUsuario::Proprietario, PapelUsuario::Recepcao => true,
            PapelUsuario::Barbeiro => ($proprio = $this->profissionalDe($usuario)) !== null
                && $proprio === $agendamento->profissional_id,
        };
    }

    /** O profissional do barbeiro, lido do banco (nulo se nao houver vinculo). */
    private function profissionalDe(User $usuario): ?int
    {
        $id = Profissional::query()->where('user_id', $usuario->getKey())->value('id');

        return $id === null ? null : (int) $id;
    }
}
