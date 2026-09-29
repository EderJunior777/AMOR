<?php

use Illuminate\Support\Facades\Schedule;

// Retencao LGPD (docs/LGPD-ANONIMIZACAO.md, secao 5). Inerte enquanto o prazo
// nao for decidido (D5): sem CLEISON_RETENCAO_CLIENTE_INATIVO_MESES nem entra na agenda.
Schedule::command('cleison:anonimizar-inativos')
    ->dailyAt('03:30')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->when(fn () => config('cleison.retencao.meses') !== null);
