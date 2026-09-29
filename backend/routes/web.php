<?php

use Illuminate\Support\Facades\Route;

/*
 * Etapa 1: ainda nao ha API publica nem painel. O site em producao continua
 * sendo o original (Netlify). A API de agenda chega na etapa 2 e o /admin na
 * etapa 3. Health check: GET /up (SaudeController, confere o banco).
 */
Route::get('/', fn () => response()->json([
    'servico' => 'cleison-backend',
    'etapa' => 1,
    'mensagem' => 'Fundacao de dados. Sem rotas de negocio publicadas ainda.',
]));
