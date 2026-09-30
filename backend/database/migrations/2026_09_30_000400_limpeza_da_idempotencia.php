<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Limpeza da idempotencia (Fase 6; docs/ESPEC-RESERVA.md, secao 4; LGPD D6).
 *
 * chave_idempotencia e hash_requisicao so servem para repetir o mesmo
 * pedido; o hash cobre o telefone. Uma rotina diaria anula os dois
 * (JUNTOS: agendamentos_idempotencia_coerente) em agendamentos criados ha
 * mais de 7 dias, em qualquer estado. Unico caminho:
 * cleison_limpar_idempotencia(), SECURITY DEFINER do dono, sem parametro
 * (o prazo e o relogio sao do banco: a aplicacao nao encurta os 7 dias).
 *
 * O trigger de imutabilidade do encerrado ganha uma excecao ESTREITA, no
 * padrao da anonimizacao: current_user e o dono da tabela E a diferenca e
 * exatamente chave e hash indo a NULL. Qualquer outra coluna, ou outro
 * valor, continua recusado, mesmo para o dono. Corpo da
 * 2026_09_29_000300 + a excecao; down() volta exatamente a ele.
 *
 * EXECUTE: so o papel da aplicacao (lido da config), revogado de PUBLIC.
 */
return new class extends Migration
{
    public function up(): void
    {
        $papel = (string) config('database.connections.pgsql.username');
        if ($papel === '') {
            throw new RuntimeException('database.connections.pgsql.username vazio: sem o papel da aplicacao nao ha a quem dar EXECUTE na limpeza.');
        }
        $papelSql = DB::scalar('SELECT pg_catalog.quote_ident(?)', [$papel]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.cleison_validar_agendamento() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.estado NOT IN ('solicitado', 'confirmado', 'em_atendimento') THEN
                  RAISE EXCEPTION '[agendamentos_estado_inicial] Agendamento nao pode nascer no estado %', NEW.estado
                    USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_estado_inicial';
                END IF;
                RETURN NEW;
              END IF;

              IF NEW.estado IS DISTINCT FROM OLD.estado
                 AND NOT public.cleison_transicao_permitida(OLD.estado, NEW.estado) THEN
                RAISE EXCEPTION '[agendamentos_transicao_estado] Transicao de estado invalida: % -> %', OLD.estado, NEW.estado
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_transicao_estado';
              END IF;

              -- Encerrado e historico: nenhuma coluna muda. Duas excecoes, as
              -- duas so para o dono (dentro das funcoes SECURITY DEFINER) e so
              -- para a troca exata:
              --   anonimizacao: dado pessoal pelos valores anonimizados;
              --   limpeza da idempotencia: chave e hash indo a NULL, e so isso.
              -- periodo_ocupado fica fora da comparacao: e coluna gerada, ainda
              -- nao recalculada no NEW de um BEFORE, e deriva de inicio/fim_ocupado.
              IF OLD.estado IN ('concluido', 'cancelado', 'nao_compareceu')
                 AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at')
                 AND NOT (public.cleison_papel_atual_e_dono(TG_RELID)
                          AND ((to_jsonb(NEW) - 'updated_at' - 'periodo_ocupado')
                                 = (public.cleison_agendamento_anonimizado(to_jsonb(OLD)) - 'updated_at' - 'periodo_ocupado')
                               OR (to_jsonb(NEW) - 'updated_at' - 'periodo_ocupado')
                                 = ((to_jsonb(OLD) || '{"chave_idempotencia": null, "hash_requisicao": null}'::jsonb)
                                    - 'updated_at' - 'periodo_ocupado'))) THEN
                RAISE EXCEPTION '[agendamentos_encerrado_imutavel] Agendamento % esta encerrado (%) e nao pode ser alterado', OLD.id, OLD.estado
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_encerrado_imutavel';
              END IF;

              NEW.updated_at := now();
              RETURN NEW;
            END $$;

            ------------------------------------------------------------------
            -- A limpeza. Devolve quantos agendamentos perderam chave e hash.
            -- Nao gera evento (estado e horario nao mudam) e e idempotente.
            ------------------------------------------------------------------
            CREATE OR REPLACE FUNCTION public.cleison_limpar_idempotencia() RETURNS integer
              LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              v_limpos integer;
            BEGIN
              UPDATE public.agendamentos a
                 SET chave_idempotencia = NULL,  -- chave e hash juntos:
                     hash_requisicao = NULL      -- agendamentos_idempotencia_coerente
               WHERE a.chave_idempotencia IS NOT NULL
                 AND a.created_at < pg_catalog.now() - interval '7 days';
              GET DIAGNOSTICS v_limpos = ROW_COUNT;

              RETURN v_limpos;
            END $$;

            REVOKE ALL ON FUNCTION public.cleison_limpar_idempotencia() FROM PUBLIC;
        SQL);

        DB::statement("GRANT EXECUTE ON FUNCTION public.cleison_limpar_idempotencia() TO {$papelSql}");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.cleison_limpar_idempotencia();

            -- Versao da 2026_09_29_000300 (so a excecao da anonimizacao).
            CREATE OR REPLACE FUNCTION public.cleison_validar_agendamento() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.estado NOT IN ('solicitado', 'confirmado', 'em_atendimento') THEN
                  RAISE EXCEPTION '[agendamentos_estado_inicial] Agendamento nao pode nascer no estado %', NEW.estado
                    USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_estado_inicial';
                END IF;
                RETURN NEW;
              END IF;

              IF NEW.estado IS DISTINCT FROM OLD.estado
                 AND NOT public.cleison_transicao_permitida(OLD.estado, NEW.estado) THEN
                RAISE EXCEPTION '[agendamentos_transicao_estado] Transicao de estado invalida: % -> %', OLD.estado, NEW.estado
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_transicao_estado';
              END IF;

              -- Encerrado e historico: nenhuma coluna muda. Unica excecao: a
              -- anonimizacao, rodando como dono (dentro de
              -- cleison_anonimizar_cliente) e trocando SO o dado pessoal
              -- pelos valores anonimizados. periodo_ocupado fica fora da
              -- comparacao: e coluna gerada, ainda nao recalculada no NEW de
              -- um BEFORE, e deriva de inicio/fim_ocupado, que sao comparados.
              IF OLD.estado IN ('concluido', 'cancelado', 'nao_compareceu')
                 AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at')
                 AND NOT (public.cleison_papel_atual_e_dono(TG_RELID)
                          AND (to_jsonb(NEW) - 'updated_at' - 'periodo_ocupado')
                              = (public.cleison_agendamento_anonimizado(to_jsonb(OLD)) - 'updated_at' - 'periodo_ocupado')) THEN
                RAISE EXCEPTION '[agendamentos_encerrado_imutavel] Agendamento % esta encerrado (%) e nao pode ser alterado', OLD.id, OLD.estado
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_encerrado_imutavel';
              END IF;

              NEW.updated_at := now();
              RETURN NEW;
            END $$;
        SQL);
    }
};
