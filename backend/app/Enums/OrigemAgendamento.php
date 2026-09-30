<?php

namespace App\Enums;

/**
 * Por onde o agendamento chegou. Nao tem relacao com forma ou canal de
 * pagamento (etapa 4): marcar pelo site e pagar no Pix em casa sao tres
 * informacoes diferentes.
 */
enum OrigemAgendamento: string
{
    case Site = 'site';
    case Whatsapp = 'whatsapp';
    case Presencial = 'presencial';
}
