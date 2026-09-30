<?php

namespace App\Domain\Agenda;

use App\Enums\Ator;
use App\Enums\EstadoAgendamento;
use App\Enums\OrigemAgendamento;

/**
 * Por onde a reserva entra. O canal (nunca a requisicao) decide origem,
 * estado inicial e quem aparece no historico: o site grava como cliente e
 * nasce "solicitado" (E5); WhatsApp e presencial sao o operador no painel.
 */
enum Canal: string
{
    case Site = 'site';
    case Whatsapp = 'whatsapp';
    case Presencial = 'presencial';

    public function origem(): OrigemAgendamento
    {
        return OrigemAgendamento::from($this->value);
    }

    public function estadoInicial(): EstadoAgendamento
    {
        return $this === self::Site ? EstadoAgendamento::Solicitado : EstadoAgendamento::Confirmado;
    }

    public function ator(): Ator
    {
        return $this === self::Site ? Ator::Cliente : Ator::Operador;
    }

    public function ehOperador(): bool
    {
        return $this !== self::Site;
    }
}
