<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisao E1 (docs/ESPEC-RESERVA.md, secao 10): defesa em profundidade para
 * o canal site.
 *
 * Brecha (revisao externa): com o papel da aplicacao, o banco aceitou um
 * agendamento com servico inativo e profissional sem vinculo. O dominio
 * (App\Domain\Agenda\ReservarHorario) passa a recusar isso; aqui fica a
 * segunda camada, so para o que NAO depende do relogio:
 *   - item de agendamento com origem 'site' exige servico ATIVO e vinculo
 *     profissional_servico do profissional do agendamento;
 *   - conferido quando o item entra ou troca de servico, e quando um
 *     agendamento do site troca de profissional ou PASSA a ser do site
 *     (senao bastaria gravar como presencial e trocar a origem depois);
 *   - presencial/whatsapp (operador, historico, encaixe) nao passam por aqui;
 *   - desativar o servico ou tirar o vinculo DEPOIS nao invalida o que ja
 *     foi gravado.
 * Expediente, antecedencia e horizonte continuam no dominio.
 *
 * Concorrencia: a conferencia trava o servico (FOR SHARE) e o vinculo (FOR
 * KEY SHARE) ate o fim da transacao, entao desativar o servico ou apagar o
 * vinculo ao mesmo tempo espera o COMMIT (e a reavaliacao de "s.ativo"
 * depois da espera ve o valor novo). Isso exige que o papel da aplicacao
 * tenha UPDATE em servicos e profissional_servico (hoje tem); se o catalogo
 * for fechado para escrita, o lock falha com 42501 (CatalogoNoSiteConcorrenciaTest).
 *
 * SECURITY INVOKER (roda com o papel de quem grava, que ja le o catalogo),
 * search_path fixo com pg_temp por ultimo e nomes qualificados. OR REPLACE:
 * migrate:fresh apaga tabelas e triggers, mas nao funcoes (42723 sem ele).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.cleison_conferir_catalogo_no_site(p_profissional bigint, p_servico bigint) RETURNS void
              LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              PERFORM 1 FROM public.servicos s WHERE s.id = p_servico AND s.ativo FOR SHARE;
              IF FOUND THEN
                PERFORM 1 FROM public.profissional_servico ps
                  WHERE ps.profissional_id = p_profissional AND ps.servico_id = p_servico FOR KEY SHARE;
              END IF;
              IF NOT FOUND THEN
                RAISE EXCEPTION '[agendamento_itens_catalogo_no_site] Reserva pelo site exige servico ativo e atendido pelo profissional'
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamento_itens_catalogo_no_site';
              END IF;
            END $$;

            CREATE OR REPLACE FUNCTION public.cleison_item_exige_catalogo_no_site() RETURNS trigger
              LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              v_origem       varchar;
              v_profissional bigint;
            BEGIN
              -- Regravar o mesmo servico (ORM que salva todas as colunas) nao e troca.
              IF TG_OP = 'UPDATE' AND NEW.servico_id IS NOT DISTINCT FROM OLD.servico_id THEN
                RETURN NEW;
              END IF;

              SELECT a.origem, a.profissional_id INTO v_origem, v_profissional
                FROM public.agendamentos a
               WHERE a.id = NEW.agendamento_id;

              IF v_origem = 'site' THEN
                PERFORM public.cleison_conferir_catalogo_no_site(v_profissional, NEW.servico_id);
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER agendamento_itens_catalogo_no_site
              BEFORE INSERT OR UPDATE OF servico_id ON public.agendamento_itens
              FOR EACH ROW EXECUTE FUNCTION public.cleison_item_exige_catalogo_no_site();

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

            -- AFTER: nao interfere nos BEFORE de agendamentos (estado,
            -- imutabilidade). No INSERT ainda nao ha itens; eles passam pelo
            -- trigger acima.
            CREATE TRIGGER agendamentos_catalogo_no_site
              AFTER UPDATE OF origem, profissional_id ON public.agendamentos
              FOR EACH ROW
              WHEN (NEW.origem = 'site'
                    AND (OLD.origem IS DISTINCT FROM NEW.origem OR OLD.profissional_id IS DISTINCT FROM NEW.profissional_id))
              EXECUTE FUNCTION public.cleison_agendamento_exige_catalogo_no_site();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS agendamentos_catalogo_no_site ON public.agendamentos;
            DROP TRIGGER IF EXISTS agendamento_itens_catalogo_no_site ON public.agendamento_itens;
            DROP FUNCTION IF EXISTS public.cleison_agendamento_exige_catalogo_no_site();
            DROP FUNCTION IF EXISTS public.cleison_item_exige_catalogo_no_site();
            DROP FUNCTION IF EXISTS public.cleison_conferir_catalogo_no_site(bigint, bigint);
        SQL);
    }
};
