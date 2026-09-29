<?php

namespace App\Enums;

/**
 * Quem fez a mudanca registrada no historico (agendamento_eventos.ator,
 * CHECK agendamento_eventos_ator). Operador exige usuario
 * (agendamento_eventos_operador_identificado).
 */
enum Ator: string
{
    case Cliente = 'cliente';
    case Operador = 'operador';
    case Sistema = 'sistema';
}
