<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisao E2, opcao (a): o motivo do encaixe do operador (fora do
 * expediente ou da antecedencia) fica no historico, em
 * agendamento_eventos.dados.motivo, nos eventos "criado" e "remarcado".
 *
 * A aplicacao nao escreve no historico (so o trigger, SECURITY DEFINER): o
 * motivo chega como cleison.motivo, definido pela TransacaoAuditada com
 * escopo de transacao e so para ator operador. Vazio = sem a chave.
 *
 * "motivo" ja e texto livre para a anonimizacao
 * (cleison_evento_dados_anonimos remove; AnonimizacaoTest amarra a lista).
 *
 * Corpo da 2026_09_29_000100 (SECURITY DEFINER, search_path fixo, nomes
 * qualificados) + o motivo. down() volta exatamente a ele.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.cleison_registrar_evento_agendamento() RETURNS trigger
              LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              v_ator    varchar := coalesce(nullif(current_setting('cleison.ator', true), ''), 'sistema');
              v_usuario bigint  := nullif(current_setting('cleison.usuario_id', true), '')::bigint;
              v_motivo  jsonb   := CASE WHEN nullif(btrim(current_setting('cleison.motivo', true)), '') IS NULL THEN '{}'::jsonb
                                        ELSE jsonb_build_object('motivo', left(btrim(current_setting('cleison.motivo', true)), 300)) END;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                INSERT INTO public.agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
                VALUES (NEW.id, 'criado', NULL, NEW.estado,
                        jsonb_build_object('inicio_servico', NEW.inicio_servico, 'fim_servico', NEW.fim_servico,
                                           'profissional_id', NEW.profissional_id, 'origem', NEW.origem) || v_motivo,
                        v_ator, v_usuario);
                RETURN NULL;
              END IF;

              IF NEW.inicio_servico  IS DISTINCT FROM OLD.inicio_servico
                 OR NEW.fim_servico     IS DISTINCT FROM OLD.fim_servico
                 OR NEW.inicio_ocupado  IS DISTINCT FROM OLD.inicio_ocupado
                 OR NEW.fim_ocupado     IS DISTINCT FROM OLD.fim_ocupado
                 OR NEW.profissional_id IS DISTINCT FROM OLD.profissional_id THEN
                INSERT INTO public.agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
                VALUES (NEW.id, 'remarcado', OLD.estado, NEW.estado,
                        jsonb_build_object(
                          'de',   jsonb_build_object('inicio_servico', OLD.inicio_servico, 'fim_servico', OLD.fim_servico,
                                                     'inicio_ocupado', OLD.inicio_ocupado, 'fim_ocupado', OLD.fim_ocupado,
                                                     'profissional_id', OLD.profissional_id),
                          'para', jsonb_build_object('inicio_servico', NEW.inicio_servico, 'fim_servico', NEW.fim_servico,
                                                     'inicio_ocupado', NEW.inicio_ocupado, 'fim_ocupado', NEW.fim_ocupado,
                                                     'profissional_id', NEW.profissional_id)) || v_motivo,
                        v_ator, v_usuario);
              END IF;

              IF NEW.estado IS DISTINCT FROM OLD.estado THEN
                INSERT INTO public.agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
                VALUES (NEW.id, 'estado_alterado', OLD.estado, NEW.estado,
                        CASE WHEN NEW.estado = 'cancelado'
                             THEN jsonb_build_object('motivo', NEW.motivo_cancelamento)
                             ELSE '{}'::jsonb END,
                        v_ator, v_usuario);
              END IF;
              RETURN NULL;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.cleison_registrar_evento_agendamento() RETURNS trigger
              LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              v_ator    varchar := coalesce(nullif(current_setting('cleison.ator', true), ''), 'sistema');
              v_usuario bigint  := nullif(current_setting('cleison.usuario_id', true), '')::bigint;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                INSERT INTO public.agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
                VALUES (NEW.id, 'criado', NULL, NEW.estado,
                        jsonb_build_object('inicio_servico', NEW.inicio_servico, 'fim_servico', NEW.fim_servico,
                                           'profissional_id', NEW.profissional_id, 'origem', NEW.origem),
                        v_ator, v_usuario);
                RETURN NULL;
              END IF;

              IF NEW.inicio_servico  IS DISTINCT FROM OLD.inicio_servico
                 OR NEW.fim_servico     IS DISTINCT FROM OLD.fim_servico
                 OR NEW.inicio_ocupado  IS DISTINCT FROM OLD.inicio_ocupado
                 OR NEW.fim_ocupado     IS DISTINCT FROM OLD.fim_ocupado
                 OR NEW.profissional_id IS DISTINCT FROM OLD.profissional_id THEN
                INSERT INTO public.agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
                VALUES (NEW.id, 'remarcado', OLD.estado, NEW.estado,
                        jsonb_build_object(
                          'de',   jsonb_build_object('inicio_servico', OLD.inicio_servico, 'fim_servico', OLD.fim_servico,
                                                     'inicio_ocupado', OLD.inicio_ocupado, 'fim_ocupado', OLD.fim_ocupado,
                                                     'profissional_id', OLD.profissional_id),
                          'para', jsonb_build_object('inicio_servico', NEW.inicio_servico, 'fim_servico', NEW.fim_servico,
                                                     'inicio_ocupado', NEW.inicio_ocupado, 'fim_ocupado', NEW.fim_ocupado,
                                                     'profissional_id', NEW.profissional_id)),
                        v_ator, v_usuario);
              END IF;

              IF NEW.estado IS DISTINCT FROM OLD.estado THEN
                INSERT INTO public.agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
                VALUES (NEW.id, 'estado_alterado', OLD.estado, NEW.estado,
                        CASE WHEN NEW.estado = 'cancelado'
                             THEN jsonb_build_object('motivo', NEW.motivo_cancelamento)
                             ELSE '{}'::jsonb END,
                        v_ator, v_usuario);
              END IF;
              RETURN NULL;
            END $$;
        SQL);
    }
};
