<?php

use Illuminate\Support\Facades\Schedule;

// Reserva "solicitado" nao segura horario sem prazo (achado #1a da Fase 5).
Schedule::command('cleison:expirar-solicitados')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Retencao LGPD (docs/LGPD-ANONIMIZACAO.md, secao 5). Inerte enquanto o prazo
// nao for decidido (D5): sem CLEISON_RETENCAO_CLIENTE_INATIVO_MESES nem entra na agenda.
Schedule::command('cleison:anonimizar-inativos')
    ->dailyAt('03:30')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    // Mesmo criterio do comando: vazio, zero ou texto nao ligam a rotina.
    ->when(fn () => filter_var(config('cleison.retencao.meses'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false);
