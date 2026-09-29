<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrida entre anonimizacao e agendamento novo (revisao de codigo final,
 * reproduzida em AnonimizacaoPeloDonoTest):
 *
 *   A: cleison_anonimizar_cliente trava o cliente (FOR UPDATE), ainda sem COMMIT;
 *   B: INSERT de agendamento para o mesmo cliente. O trigger BEFORE lia
 *      anonimizado_em sem trava, via NULL, passava; a FK entao esperava A
 *      e, apos o COMMIT, aceitava: agendamento em aberto para cliente
 *      anonimizado.
 *
 * Correcao: o trigger trava o cliente pela chave (FOR KEY SHARE), espera A
 * e le anonimizado_em da versao commitada (READ COMMITTED).
 *
 * Tambem: cleison_agendamento_anonimizado e cleison_evento_dados_anonimos
 * passam de IMMUTABLE para STABLE (jsonb_build_object e jsonb_object_agg
 * sao STABLE).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.cleison_cliente_anonimizado_fechado() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              v_anonimizado_em timestamptz;
            BEGIN
              -- Trava pela CHAVE, sem condicao em anonimizado_em: com a condicao
              -- no WHERE, a versao do snapshot (ainda NULL) seria descartada
              -- antes de pedir a trava. Assim espera a anonimizacao em
              -- andamento (FOR UPDATE) e le a versao commitada.
              SELECT c.anonimizado_em INTO v_anonimizado_em
                FROM public.clientes c WHERE c.id = NEW.cliente_id
                FOR KEY SHARE;
              IF v_anonimizado_em IS NOT NULL THEN
                RAISE EXCEPTION '[clientes_anonimizado_fechado] Cliente % foi anonimizado e nao recebe novos dados', NEW.cliente_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'clientes_anonimizado_fechado';
              END IF;
              RETURN NEW;
            END $$;

            ALTER FUNCTION public.cleison_agendamento_anonimizado(jsonb) STABLE;
            ALTER FUNCTION public.cleison_evento_dados_anonimos(jsonb) STABLE;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            -- Versao da 2026_09_29_000300.
            CREATE OR REPLACE FUNCTION public.cleison_cliente_anonimizado_fechado() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              IF EXISTS (SELECT 1 FROM public.clientes c WHERE c.id = NEW.cliente_id AND c.anonimizado_em IS NOT NULL) THEN
                RAISE EXCEPTION '[clientes_anonimizado_fechado] Cliente % foi anonimizado e nao recebe novos dados', NEW.cliente_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'clientes_anonimizado_fechado';
              END IF;
              RETURN NEW;
            END $$;

            ALTER FUNCTION public.cleison_agendamento_anonimizado(jsonb) IMMUTABLE;
            ALTER FUNCTION public.cleison_evento_dados_anonimos(jsonb) IMMUTABLE;
        SQL);
    }
};
