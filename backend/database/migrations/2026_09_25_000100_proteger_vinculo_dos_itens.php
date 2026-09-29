<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Correcao pos-revisao externa (achado B).
 *
 * Problema: cleison_conferir_itens_agendamento() conferia so o agendamento
 * de DESTINO quando um UPDATE mudava agendamento_itens.agendamento_id.
 * Mover o unico item de A para B (ajustando B) deixava A persistido sem
 * servico e ainda ocupando a agenda (reproduzido com COMMIT real).
 *
 * Decisao: o vinculo do item com o agendamento e IMUTAVEL. Remarcar nao
 * transfere itens (o agendamento e o mesmo, muda o horario). Trocar o
 * servico de um agendamento aberto = alterar/apagar/incluir itens DELE.
 * Como segunda camada, a conferencia adiada passa a revalidar origem e
 * destino em qualquer UPDATE de item.
 *
 * Instalacao existente: antes de mudar qualquer coisa, a migration procura
 * agendamentos que ja estejam inconsistentes (sem item ou com soma de
 * duracoes diferente do horario). Se houver, aborta SEM alterar nada e lista
 * os ids; a correcao e manual (devolver o item ao agendamento certo, ou
 * cancelar com registro), nunca apagar nem inventar servico.
 *
 * Rollback (down): volta a funcao anterior e remove a trava do vinculo,
 * reabrindo a lacuna. So para banco descartavel (ver AlvoDescartavel).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE
              v_qtd integer;
              v_ids text;
            BEGIN
              WITH situacao AS (
                SELECT a.id,
                       count(i.id) AS itens,
                       coalesce(sum(i.duracao_minutos), 0) AS minutos,
                       a.fim_servico - a.inicio_servico AS duracao
                  FROM agendamentos a
                  LEFT JOIN agendamento_itens i ON i.agendamento_id = a.id
                 GROUP BY a.id, a.inicio_servico, a.fim_servico
              ), ruins AS (
                SELECT id FROM situacao
                 WHERE itens = 0 OR minutos * interval '1 minute' <> duracao
              )
              SELECT count(*),
                     (SELECT string_agg(id::text, ', ' ORDER BY id)
                        FROM (SELECT id FROM ruins ORDER BY id LIMIT 50) x)
                INTO v_qtd, v_ids
                FROM ruins;

              IF v_qtd > 0 THEN
                RAISE EXCEPTION '[migracao_itens_inconsistentes] % agendamento(s) sem servico ou com duracao divergente (ids: %). Nada foi alterado. Corrija esses registros (sem apagar historico) e rode a migration de novo.',
                  v_qtd, v_ids
                  USING ERRCODE = 'check_violation';
              END IF;
            END $$;

            CREATE OR REPLACE FUNCTION cleison_conferir_itens_agendamento() RETURNS trigger
              LANGUAGE plpgsql AS $$
            DECLARE
              v_ids     bigint[];
              v_id      bigint;
              v_ag      agendamentos%ROWTYPE;
              v_qtd     integer;
              v_minutos integer;
            BEGIN
              -- Todos os agendamentos afetados: no UPDATE de item, o de
              -- origem (OLD) E o de destino (NEW).
              IF TG_TABLE_NAME = 'agendamentos' THEN
                v_ids := ARRAY[NEW.id];
              ELSIF TG_OP = 'DELETE' THEN
                v_ids := ARRAY[OLD.agendamento_id];
              ELSIF TG_OP = 'UPDATE' THEN
                v_ids := ARRAY[OLD.agendamento_id, NEW.agendamento_id];
              ELSE
                v_ids := ARRAY[NEW.agendamento_id];
              END IF;

              FOREACH v_id IN ARRAY v_ids LOOP
                SELECT * INTO v_ag FROM agendamentos WHERE id = v_id;
                CONTINUE WHEN NOT FOUND;

                SELECT count(*), coalesce(sum(duracao_minutos), 0) INTO v_qtd, v_minutos
                  FROM agendamento_itens WHERE agendamento_id = v_id;

                IF v_qtd = 0 THEN
                  RAISE EXCEPTION '[agendamentos_com_servico] Agendamento % sem nenhum servico', v_id
                    USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_com_servico';
                END IF;

                IF v_minutos * interval '1 minute' <> v_ag.fim_servico - v_ag.inicio_servico THEN
                  RAISE EXCEPTION '[agendamentos_duracao_dos_itens] Agendamento %: servicos somam % min, mas o horario do servico tem %',
                    v_id, v_minutos, v_ag.fim_servico - v_ag.inicio_servico
                    USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_duracao_dos_itens';
                END IF;
              END LOOP;
              RETURN NULL;
            END $$;

            CREATE OR REPLACE FUNCTION cleison_vinculo_do_item_imutavel() RETURNS trigger
              LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.agendamento_id IS DISTINCT FROM OLD.agendamento_id THEN
                RAISE EXCEPTION '[agendamento_itens_vinculo_imutavel] Item % pertence ao agendamento % e nao pode ser transferido para %',
                  OLD.id, OLD.agendamento_id, NEW.agendamento_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamento_itens_vinculo_imutavel';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER agendamento_itens_vinculo_imutavel
              BEFORE UPDATE OF agendamento_id ON agendamento_itens
              FOR EACH ROW EXECUTE FUNCTION cleison_vinculo_do_item_imutavel();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS agendamento_itens_vinculo_imutavel ON agendamento_itens;
            DROP FUNCTION IF EXISTS cleison_vinculo_do_item_imutavel();

            -- Versao anterior (2026_09_24_000300), com a lacuna do achado B.
            CREATE OR REPLACE FUNCTION cleison_conferir_itens_agendamento() RETURNS trigger
              LANGUAGE plpgsql AS $$
            DECLARE
              v_id      bigint;
              v_ag      agendamentos%ROWTYPE;
              v_qtd     integer;
              v_minutos integer;
            BEGIN
              IF TG_TABLE_NAME = 'agendamentos' THEN
                v_id := NEW.id;
              ELSIF TG_OP = 'DELETE' THEN
                v_id := OLD.agendamento_id;
              ELSE
                v_id := NEW.agendamento_id;
              END IF;

              SELECT * INTO v_ag FROM agendamentos WHERE id = v_id;
              IF NOT FOUND THEN RETURN NULL; END IF;

              SELECT count(*), coalesce(sum(duracao_minutos), 0) INTO v_qtd, v_minutos
                FROM agendamento_itens WHERE agendamento_id = v_id;

              IF v_qtd = 0 THEN
                RAISE EXCEPTION '[agendamentos_com_servico] Agendamento % sem nenhum servico', v_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_com_servico';
              END IF;

              IF v_minutos * interval '1 minute' <> v_ag.fim_servico - v_ag.inicio_servico THEN
                RAISE EXCEPTION '[agendamentos_duracao_dos_itens] Agendamento %: servicos somam % min, mas o horario do servico tem %',
                  v_id, v_minutos, v_ag.fim_servico - v_ag.inicio_servico
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_duracao_dos_itens';
              END IF;
              RETURN NULL;
            END $$;
        SQL);
    }
};
