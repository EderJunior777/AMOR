<?php

namespace App\Domain\Agenda;

use RuntimeException;

/**
 * Freio de emergencia (achado #1c da Fase 5): o site atingiu o teto diario
 * de reservas (cleison.reservas.teto_diario_do_site). Nao e recusa de regra
 * do cliente: a API responde 503 generico, sem dizer que e um teto nem
 * quantas reservas houve (bootstrap/app.php).
 */
final class AgendaSobrecarregada extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Teto diario de reservas do site atingido.');
    }
}
