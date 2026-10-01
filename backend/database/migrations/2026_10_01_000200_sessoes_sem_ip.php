<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Etapa 3, Fase 2 (achado do security-reviewer): a tabela de sessoes do
 * Laravel guardava o IP e o navegador de cada sessao. Pela decisao da etapa 3
 * o IP nunca vai para o banco; as colunas saem e o handler SessaoSemIp
 * (AppServiceProvider) nao as preenche. A migration 0001_01_01_000000 nao e
 * editada: a remocao e esta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("SET LOCAL lock_timeout = '5s'");
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.sessions
              DROP COLUMN IF EXISTS ip_address,
              DROP COLUMN IF EXISTS user_agent;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.sessions
              ADD COLUMN IF NOT EXISTS ip_address varchar(45),
              ADD COLUMN IF NOT EXISTS user_agent text;
        SQL);
    }
};
