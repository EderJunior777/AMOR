<?php

namespace App\Enums;

/**
 * Perfil da identidade administrativa. A autorizacao por rota/recurso
 * (policies) entra na etapa 3; aqui so fica registrado o papel.
 */
enum PapelUsuario: string
{
    case Proprietario = 'proprietario';
    case Barbeiro = 'barbeiro';
    case Recepcao = 'recepcao';

    public function rotulo(): string
    {
        return match ($this) {
            self::Proprietario => 'Proprietário',
            self::Barbeiro => 'Barbeiro',
            self::Recepcao => 'Recepção',
        };
    }
}
