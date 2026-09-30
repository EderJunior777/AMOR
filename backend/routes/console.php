<?php

use Illuminate\Support\Facades\Schedule;

// Reserva "solicitado" nao segura horario sem prazo (achado #1a da Fase 5).
Schedule::command('cleison:expirar-solicitados')
    ->everyFiveMinutes()
    // Prazo da trava: se um schedule:run morrer no meio, a expiracao volta
    // em 10 minutos (o padrao do framework seria 24 h).
    ->withoutOverlapping(10);

// Idempotencia: chave e hash de agendamentos criados ha mais de 7 dias viram
// NULL (docs/ESPEC-RESERVA.md, secao 4; LGPD D6).
Schedule::command('cleison:limpar-idempotencia')
    ->dailyAt('03:45')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping(60);

// Retencao LGPD (docs/LGPD-ANONIMIZACAO.md, secao 5). Inerte enquanto o prazo
// nao for decidido (D5): sem CLEISON_RETENCAO_CLIENTE_INATIVO_MESES nem entra na agenda.
Schedule::command('cleison:anonimizar-inativos')
    ->dailyAt('03:30')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    // Mesmo criterio do comando: vazio, zero ou texto nao ligam a rotina.
    ->when(fn () => filter_var(config('cleison.retencao.meses'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false);
