<?php

namespace App\Policies;

use App\Enums\PapelUsuario;
use App\Models\User;

/**
 * Gestao da equipe (menu "Equipe", etapa 3). So o PROPRIETARIO ATIVO gere, e
 * so barbeiro e recepcao: proprietario nunca e alvo, nem o proprio autor
 * (GerenciarEquipe confere de novo, no banco, dentro da transacao).
 */
final class UserPolicy
{
    /** Ver e usar o menu Equipe (listar, criar). */
    public function gerirEquipe(User $usuario): bool
    {
        return $usuario->ativo && $usuario->papel === PapelUsuario::Proprietario;
    }

    /** Agir em UM usuario (desativar, reativar, redefinir senha). */
    public function gerir(User $usuario, User $alvo): bool
    {
        return $this->gerirEquipe($usuario)
            && $alvo->papel !== PapelUsuario::Proprietario
            && ! $usuario->is($alvo);
    }
}
