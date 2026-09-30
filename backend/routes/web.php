<?php

use Illuminate\Support\Facades\Route;

// Sem pagina publica aqui: a raiz so confirma que o servidor responde, sem
// anunciar servico, versao ou etapa. Saude (com o banco): GET /up.
Route::get('/', fn () => response()->noContent());
