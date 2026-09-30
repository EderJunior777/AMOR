<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fecha a escrita direta em ocupacoes_agenda e agendamento_eventos.
 *
 * Brecha (revisao externa, reproduzida): o papel da aplicacao tinha
 * INSERT/UPDATE/DELETE nas duas tabelas (ALTER DEFAULT PRIVILEGES) e podia
 *   - criar uma ocupacao "fantasma" apontando para um agendamento CANCELADO
 *     (a FK composta aceita: o cancelado mantem periodo_ocupado),
 *     bloqueando o horario sem ninguem marcado;
 *   - forjar eventos do historico (ator/usuario de outra pessoa).
 *
 * Correcao:
 *   1. exige PostgreSQL >= 17;
 *   2. recusa migrar se ja existir ocupacao fantasma (diz quantas);
 *   3. os tres triggers que escrevem nessas tabelas passam a SECURITY
 *      DEFINER (rodam como o dono), com search_path fixo e nomes
 *      qualificados;
 *   4. REVOKE INSERT/UPDATE/DELETE/TRUNCATE de TODO papel que nao seja o
 *      dono da tabela (o nome do papel da aplicacao varia por ambiente);
 *      SELECT continua;
 *   5. trigger ocupacoes_pai_ativo: nem o dono cria ocupacao para
 *      agendamento que nao ocupa agenda ou bloqueio cancelado;
 *   6. indices nas FKs que nao tinham.
 *
 * Remarcar continua funcionando: o ON UPDATE CASCADE da FK composta roda
 * com o dono da tabela, sem precisar de UPDATE da aplicacao.
 */
return new class extends Migration
{
    public function exigirPostgres17(int $serverVersionNum): void
    {
        if ($serverVersionNum < 170000) {
            throw new RuntimeException(sprintf(
                'Este esquema exige PostgreSQL 17 ou superior (servidor: %d.%d). '
                .'No 16, instantes infinitos falham com 22008 antes da constraint '
                .'agendamentos_instantes_finitos. Atualize o PostgreSQL antes de migrar.',
                intdiv($serverVersionNum, 10000),
                $serverVersionNum % 10000,
            ));
        }
    }

    public function up(): void
    {
        $this->exigirPostgres17((int) DB::scalar('SHOW server_version_num'));

        DB::unprepared(<<<'SQL'
            ------------------------------------------------------------------
            -- 2. Nada de ocupacao fantasma ja gravada.
            ------------------------------------------------------------------
            DO $$
            DECLARE
              n bigint;
            BEGIN
              SELECT count(*) INTO n
                FROM public.ocupacoes_agenda o
               WHERE (o.agendamento_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM public.agendamentos a
                         WHERE a.id = o.agendamento_id AND public.cleison_estado_ocupa_agenda(a.estado)))
                  OR (o.bloqueio_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM public.bloqueios_agenda b
                         WHERE b.id = o.bloqueio_id AND b.cancelado_em IS NULL));
              IF n > 0 THEN
                RAISE EXCEPTION '[migracao_ocupacoes_fantasma] % ocupacao(oes) apontam para agendamento que nao ocupa agenda ou bloqueio cancelado. Revise e remova (como dono) antes de migrar.', n
                  USING ERRCODE = 'check_violation';
              END IF;
            END $$;

            ------------------------------------------------------------------
            -- 3. Triggers que escrevem: SECURITY DEFINER (papel dono).
            --    search_path fixo com pg_temp POR ULTIMO (sem ele na lista,
            --    o schema temporario seria consultado PRIMEIRO para tabelas)
            --    e nomes qualificados.
            ------------------------------------------------------------------
            CREATE OR REPLACE FUNCTION public.cleison_sincronizar_ocupacao_agendamento() RETURNS trigger
              LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              ocupava boolean := TG_OP = 'UPDATE' AND public.cleison_estado_ocupa_agenda(OLD.estado);
              ocupa   boolean := public.cleison_estado_ocupa_agenda(NEW.estado);
            BEGIN
              IF ocupa AND NOT ocupava THEN
                INSERT INTO public.ocupacoes_agenda (profissional_id, periodo, agendamento_id)
                VALUES (NEW.profissional_id, NEW.periodo_ocupado, NEW.id);
              ELSIF ocupava AND NOT ocupa THEN
                DELETE FROM public.ocupacoes_agenda WHERE agendamento_id = NEW.id;
              END IF;
              RETURN NULL;
            END $$;

            -- O ator vem de set_config(..., true) na transacao da aplicacao.
            -- current_setting le a configuracao da SESSAO, que nao muda com
            -- SECURITY DEFINER (so o papel e o search_path mudam).
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

            CREATE OR REPLACE FUNCTION public.cleison_sincronizar_ocupacao_bloqueio() RETURNS trigger
              LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              ocupava boolean := TG_OP = 'UPDATE' AND OLD.cancelado_em IS NULL;
              ocupa   boolean := NEW.cancelado_em IS NULL;
            BEGIN
              IF ocupa AND NOT ocupava THEN
                INSERT INTO public.ocupacoes_agenda (profissional_id, periodo, bloqueio_id)
                VALUES (NEW.profissional_id, NEW.periodo, NEW.id);
              ELSIF ocupava AND NOT ocupa THEN
                DELETE FROM public.ocupacoes_agenda WHERE bloqueio_id = NEW.id;
              END IF;
              RETURN NULL;
            END $$;

            ------------------------------------------------------------------
            -- 4. So o dono escreve. Vale para qualquer papel (inclusive
            --    PUBLIC) que nao seja o dono da tabela: o nome do papel da
            --    aplicacao muda de um ambiente para outro. SELECT continua.
            ------------------------------------------------------------------
            DO $$
            DECLARE
              r record;
            BEGIN
              FOR r IN
                SELECT DISTINCT a.grantee
                  FROM pg_catalog.pg_class c
                 CROSS JOIN LATERAL pg_catalog.aclexplode(c.relacl) a
                 WHERE c.oid IN ('public.ocupacoes_agenda'::regclass, 'public.agendamento_eventos'::regclass)
                   AND a.grantee <> c.relowner
                   AND a.privilege_type IN ('INSERT', 'UPDATE', 'DELETE', 'TRUNCATE')
              LOOP
                EXECUTE pg_catalog.format(
                  'REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.ocupacoes_agenda, public.agendamento_eventos FROM %s',
                  CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE pg_catalog.quote_ident(pg_catalog.pg_get_userbyid(r.grantee)) END);
              END LOOP;
            END $$;

            ------------------------------------------------------------------
            -- 5. Ocupacao so existe para pai ativo, inclusive para o dono.
            ------------------------------------------------------------------
            -- OR REPLACE: migrate:fresh apaga tabelas (e triggers), mas nao
            -- funcoes; sem ele, reaplicar depois de um fresh falharia (42723).
            CREATE OR REPLACE FUNCTION public.cleison_ocupacao_exige_pai_ativo() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              IF NEW.agendamento_id IS NOT NULL AND NOT EXISTS (
                   SELECT 1 FROM public.agendamentos a
                    WHERE a.id = NEW.agendamento_id AND public.cleison_estado_ocupa_agenda(a.estado)) THEN
                RAISE EXCEPTION '[ocupacoes_pai_ativo] Ocupacao so existe para agendamento em estado que ocupa a agenda'
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'ocupacoes_pai_ativo';
              END IF;
              IF NEW.bloqueio_id IS NOT NULL AND NOT EXISTS (
                   SELECT 1 FROM public.bloqueios_agenda b
                    WHERE b.id = NEW.bloqueio_id AND b.cancelado_em IS NULL) THEN
                RAISE EXCEPTION '[ocupacoes_pai_ativo] Ocupacao so existe para bloqueio nao cancelado'
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'ocupacoes_pai_ativo';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER ocupacoes_pai_ativo
              BEFORE INSERT OR UPDATE OF agendamento_id, bloqueio_id ON public.ocupacoes_agenda
              FOR EACH ROW EXECUTE FUNCTION public.cleison_ocupacao_exige_pai_ativo();

            ------------------------------------------------------------------
            -- 6. Indices das FKs (parciais: a busca da FK e por igualdade,
            --    o que ja implica NOT NULL).
            ------------------------------------------------------------------
            CREATE INDEX agendamentos_por_regiao ON public.agendamentos (regiao_id) WHERE regiao_id IS NOT NULL;
            CREATE INDEX agendamentos_por_endereco ON public.agendamentos (endereco_cliente_id) WHERE endereco_cliente_id IS NOT NULL;
            CREATE INDEX agendamentos_por_criador ON public.agendamentos (criado_por_user_id) WHERE criado_por_user_id IS NOT NULL;
            CREATE INDEX agendamento_eventos_por_usuario ON public.agendamento_eventos (usuario_id) WHERE usuario_id IS NOT NULL;
            CREATE INDEX bloqueios_por_criador ON public.bloqueios_agenda (criado_por_user_id) WHERE criado_por_user_id IS NOT NULL;
            CREATE INDEX enderecos_por_regiao ON public.enderecos_cliente (regiao_id) WHERE regiao_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS public.enderecos_por_regiao;
            DROP INDEX IF EXISTS public.bloqueios_por_criador;
            DROP INDEX IF EXISTS public.agendamento_eventos_por_usuario;
            DROP INDEX IF EXISTS public.agendamentos_por_criador;
            DROP INDEX IF EXISTS public.agendamentos_por_endereco;
            DROP INDEX IF EXISTS public.agendamentos_por_regiao;

            DROP TRIGGER IF EXISTS ocupacoes_pai_ativo ON public.ocupacoes_agenda;
            DROP FUNCTION IF EXISTS public.cleison_ocupacao_exige_pai_ativo();

            -- Devolve a escrita a quem ainda le as tabelas (estado anterior:
            -- o papel da aplicacao tinha SELECT/INSERT/UPDATE/DELETE).
            DO $$
            DECLARE
              r record;
            BEGIN
              FOR r IN
                SELECT DISTINCT a.grantee
                  FROM pg_catalog.pg_class c
                 CROSS JOIN LATERAL pg_catalog.aclexplode(c.relacl) a
                 WHERE c.oid IN ('public.ocupacoes_agenda'::regclass, 'public.agendamento_eventos'::regclass)
                   AND a.grantee <> c.relowner
                   AND a.privilege_type = 'SELECT'
              LOOP
                EXECUTE pg_catalog.format(
                  'GRANT INSERT, UPDATE, DELETE ON public.ocupacoes_agenda, public.agendamento_eventos TO %s',
                  CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE pg_catalog.quote_ident(pg_catalog.pg_get_userbyid(r.grantee)) END);
              END LOOP;
            END $$;

            -- Versoes anteriores (2026_09_24_000300), SECURITY INVOKER e sem
            -- SET (RESET ALL tira o search_path fixo).
            CREATE OR REPLACE FUNCTION cleison_sincronizar_ocupacao_agendamento() RETURNS trigger
              LANGUAGE plpgsql SECURITY INVOKER AS $$
            DECLARE
              ocupava boolean := TG_OP = 'UPDATE' AND cleison_estado_ocupa_agenda(OLD.estado);
              ocupa   boolean := cleison_estado_ocupa_agenda(NEW.estado);
            BEGIN
              IF ocupa AND NOT ocupava THEN
                INSERT INTO ocupacoes_agenda (profissional_id, periodo, agendamento_id)
                VALUES (NEW.profissional_id, NEW.periodo_ocupado, NEW.id);
              ELSIF ocupava AND NOT ocupa THEN
                DELETE FROM ocupacoes_agenda WHERE agendamento_id = NEW.id;
              END IF;
              RETURN NULL;
            END $$;
            ALTER FUNCTION cleison_sincronizar_ocupacao_agendamento() RESET ALL;

            CREATE OR REPLACE FUNCTION cleison_registrar_evento_agendamento() RETURNS trigger
              LANGUAGE plpgsql SECURITY INVOKER AS $$
            DECLARE
              v_ator    varchar := coalesce(nullif(current_setting('cleison.ator', true), ''), 'sistema');
              v_usuario bigint  := nullif(current_setting('cleison.usuario_id', true), '')::bigint;
            BEGIN
              IF TG_OP = 'INSERT' THEN
                INSERT INTO agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
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
                INSERT INTO agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
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
                INSERT INTO agendamento_eventos (agendamento_id, tipo, estado_anterior, estado_novo, dados, ator, usuario_id)
                VALUES (NEW.id, 'estado_alterado', OLD.estado, NEW.estado,
                        CASE WHEN NEW.estado = 'cancelado'
                             THEN jsonb_build_object('motivo', NEW.motivo_cancelamento)
                             ELSE '{}'::jsonb END,
                        v_ator, v_usuario);
              END IF;
              RETURN NULL;
            END $$;
            ALTER FUNCTION cleison_registrar_evento_agendamento() RESET ALL;

            CREATE OR REPLACE FUNCTION cleison_sincronizar_ocupacao_bloqueio() RETURNS trigger
              LANGUAGE plpgsql SECURITY INVOKER AS $$
            DECLARE
              ocupava boolean := TG_OP = 'UPDATE' AND OLD.cancelado_em IS NULL;
              ocupa   boolean := NEW.cancelado_em IS NULL;
            BEGIN
              IF ocupa AND NOT ocupava THEN
                INSERT INTO ocupacoes_agenda (profissional_id, periodo, bloqueio_id)
                VALUES (NEW.profissional_id, NEW.periodo, NEW.id);
              ELSIF ocupava AND NOT ocupa THEN
                DELETE FROM ocupacoes_agenda WHERE bloqueio_id = NEW.id;
              END IF;
              RETURN NULL;
            END $$;
            ALTER FUNCTION cleison_sincronizar_ocupacao_bloqueio() RESET ALL;
        SQL);
    }
};
