<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes do E1 aprovados na revisao da Fase 3 (2026_09_30_000100 fica
 * intacta; esta migration so vem depois dela):
 *
 *   a) origem e imutavel depois do INSERT, em QUALQUER direcao. Trocar para
 *      'site' furaria a conferencia do catalogo; sair de 'site' deixaria o
 *      item livre para trocar por um servico inativo. Vale ate para o dono.
 *   b) Reatribuir uma reserva do site a outro profissional exige SO o
 *      vinculo do novo profissional com cada servico, nao "servico ativo":
 *      desativar um servico nao pode travar a reatribuicao de reservas que
 *      ja existem. Trocar o servico do item por um inativo continua
 *      recusado (trigger do item, inalterado).
 *
 * Mesmo padrao: SECURITY INVOKER, search_path fixo, nomes qualificados.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.cleison_origem_imutavel() RETURNS trigger
              LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              IF NEW.origem IS DISTINCT FROM OLD.origem THEN
                RAISE EXCEPTION '[agendamentos_origem_imutavel] A origem do agendamento nao muda depois de criado'
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_origem_imutavel';
              END IF;
              RETURN NEW;
            END $$;

            -- Nome > 'agendamentos_validar': triggers BEFORE disparam em ordem
            -- alfabetica, e em agendamento encerrado a recusa continua sendo
            -- agendamentos_encerrado_imutavel (a regra mais geral) primeiro.
            CREATE TRIGGER agendamentos_validar_origem
              BEFORE UPDATE OF origem ON public.agendamentos
              FOR EACH ROW EXECUTE FUNCTION public.cleison_origem_imutavel();

            -- Reatribuicao: so o vinculo, travado ate o COMMIT (apagar o
            -- vinculo ao mesmo tempo espera).
            CREATE OR REPLACE FUNCTION public.cleison_agendamento_exige_catalogo_no_site() RETURNS trigger
              LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              r record;
            BEGIN
              FOR r IN SELECT i.servico_id FROM public.agendamento_itens i WHERE i.agendamento_id = NEW.id LOOP
                PERFORM 1 FROM public.profissional_servico ps
                  WHERE ps.profissional_id = NEW.profissional_id AND ps.servico_id = r.servico_id FOR KEY SHARE;
                IF NOT FOUND THEN
                  RAISE EXCEPTION '[agendamento_itens_catalogo_no_site] Reserva pelo site exige servico atendido pelo profissional'
                    USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamento_itens_catalogo_no_site';
                END IF;
              END LOOP;
              RETURN NULL;
            END $$;

            -- A origem nao muda mais: so a troca de profissional interessa.
            DROP TRIGGER agendamentos_catalogo_no_site ON public.agendamentos;
            CREATE TRIGGER agendamentos_catalogo_no_site
              AFTER UPDATE OF profissional_id ON public.agendamentos
              FOR EACH ROW
              WHEN (NEW.origem = 'site' AND OLD.profissional_id IS DISTINCT FROM NEW.profissional_id)
              EXECUTE FUNCTION public.cleison_agendamento_exige_catalogo_no_site();
        SQL);
    }

    /** Volta exatamente ao estado da 2026_09_30_000100. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS agendamentos_validar_origem ON public.agendamentos;
            DROP FUNCTION IF EXISTS public.cleison_origem_imutavel();

            CREATE OR REPLACE FUNCTION public.cleison_agendamento_exige_catalogo_no_site() RETURNS trigger
              LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              r record;
            BEGIN
              FOR r IN SELECT i.servico_id FROM public.agendamento_itens i WHERE i.agendamento_id = NEW.id LOOP
                PERFORM public.cleison_conferir_catalogo_no_site(NEW.profissional_id, r.servico_id);
              END LOOP;
              RETURN NULL;
            END $$;

            DROP TRIGGER IF EXISTS agendamentos_catalogo_no_site ON public.agendamentos;
            CREATE TRIGGER agendamentos_catalogo_no_site
              AFTER UPDATE OF origem, profissional_id ON public.agendamentos
              FOR EACH ROW
              WHEN (NEW.origem = 'site'
                    AND (OLD.origem IS DISTINCT FROM NEW.origem OR OLD.profissional_id IS DISTINCT FROM NEW.profissional_id))
              EXECUTE FUNCTION public.cleison_agendamento_exige_catalogo_no_site();
        SQL);
    }
};
