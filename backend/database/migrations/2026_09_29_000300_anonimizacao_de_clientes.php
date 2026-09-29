<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Anonimizacao de clientes (LGPD, docs/LGPD-ANONIMIZACAO.md).
 *
 * O historico fica; o dado pessoal sai. Unico caminho:
 * cleison_anonimizar_cliente(cliente, usuario, origem, protocolo),
 * SECURITY DEFINER do dono, que:
 *   - so executa para usuario proprietario ATIVO (conferido aqui, no banco);
 *   - recusa cliente com agendamento em aberto;
 *   - e idempotente (cliente ja anonimizado: devolve NULL, nada muda);
 *   - registra em anonimizacoes (sem nenhum dado pessoal).
 *
 * Os triggers de imutabilidade ganham UMA excecao, com duas condicoes:
 * current_user e o dono da tabela (so dentro da funcao; nunca session_user
 * nem variavel de sessao, que a aplicacao controla) E a diferenca e
 * exatamente a troca de dado pessoal pelos valores anonimizados.
 *
 * EXECUTE da funcao: so o papel da aplicacao (lido da config, como na
 * 2026_09_29_000200), revogado de PUBLIC.
 */
return new class extends Migration
{
    public function up(): void
    {
        $papel = (string) config('database.connections.pgsql.username');
        if ($papel === '') {
            throw new RuntimeException('database.connections.pgsql.username vazio: sem o papel da aplicacao nao ha a quem dar EXECUTE na anonimizacao.');
        }
        $papelSql = DB::scalar('SELECT pg_catalog.quote_ident(?)', [$papel]);

        DB::unprepared(<<<'SQL'
            ------------------------------------------------------------------
            -- Cliente anonimizado: marca e coerencia.
            ------------------------------------------------------------------
            ALTER TABLE public.clientes ADD COLUMN anonimizado_em timestamptz;
            ALTER TABLE public.clientes ADD CONSTRAINT clientes_anonimizado_coerente CHECK (
              anonimizado_em IS NULL
              OR (nome = 'Cliente anonimizado' AND telefone IS NULL AND observacoes IS NULL));

            ------------------------------------------------------------------
            -- Quem esta rodando e o dono da tabela? current_user, e so ele:
            -- dentro de uma funcao SECURITY DEFINER e o dono; fora, e o papel
            -- que conectou. session_user nao serve (e sempre quem conectou) e
            -- variavel de sessao a aplicacao define como quiser.
            -- SECURITY INVOKER de proposito: como DEFINER, current_user aqui
            -- dentro seria sempre o dono.
            ------------------------------------------------------------------
            CREATE OR REPLACE FUNCTION public.cleison_papel_atual_e_dono(p_tabela oid) RETURNS boolean
              LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, pg_temp AS $$
              SELECT EXISTS (
                SELECT 1 FROM pg_catalog.pg_class c
                  JOIN pg_catalog.pg_roles r ON r.oid = c.relowner
                 WHERE c.oid = p_tabela AND r.rolname = current_user)
            $$;

            ------------------------------------------------------------------
            -- Agendamento anonimizado (como jsonb da linha): o que a funcao
            -- grava e o que o trigger aceita vem daqui.
            ------------------------------------------------------------------
            CREATE OR REPLACE FUNCTION public.cleison_agendamento_anonimizado(p_linha jsonb) RETURNS jsonb
              LANGUAGE sql IMMUTABLE SET search_path = pg_catalog, pg_temp AS $$
              SELECT p_linha || pg_catalog.jsonb_build_object(
                'endereco_texto', CASE WHEN p_linha -> 'endereco_texto' = 'null'::jsonb THEN NULL ELSE '[anonimizado]' END,
                'observacao_cliente', NULL,
                'motivo_cancelamento', NULL,
                'chave_idempotencia', NULL,
                'hash_requisicao', NULL)
            $$;

            ------------------------------------------------------------------
            -- Dados do evento sem texto livre: LISTA FECHADA do que fica
            -- (valores de dominio). Tudo o mais sai, inclusive "motivo" e
            -- qualquer chave que alguem acrescente ao trigger no futuro
            -- (AnonimizacaoTest amarra esta lista ao trigger).
            ------------------------------------------------------------------
            CREATE OR REPLACE FUNCTION public.cleison_evento_dados_anonimos(p_dados jsonb) RETURNS jsonb
              LANGUAGE sql IMMUTABLE SET search_path = pg_catalog, pg_temp AS $$
              SELECT CASE WHEN pg_catalog.jsonb_typeof(p_dados) IS DISTINCT FROM 'object' THEN '{}'::jsonb ELSE (
                SELECT coalesce(pg_catalog.jsonb_object_agg(e.chave,
                         CASE WHEN e.chave IN ('de', 'para') THEN (
                           SELECT coalesce(pg_catalog.jsonb_object_agg(i.chave, i.valor), '{}'::jsonb)
                             FROM pg_catalog.jsonb_each(e.valor) AS i(chave, valor)
                            WHERE i.chave IN ('inicio_servico', 'fim_servico', 'inicio_ocupado', 'fim_ocupado', 'profissional_id')
                              AND pg_catalog.jsonb_typeof(i.valor) IN ('string', 'number', 'null'))
                         ELSE e.valor END), '{}'::jsonb)
                  FROM pg_catalog.jsonb_each(p_dados) AS e(chave, valor)
                 WHERE (e.chave IN ('inicio_servico', 'fim_servico', 'inicio_ocupado', 'fim_ocupado', 'profissional_id', 'origem')
                        AND pg_catalog.jsonb_typeof(e.valor) IN ('string', 'number', 'null'))
                    OR (e.chave IN ('de', 'para') AND pg_catalog.jsonb_typeof(e.valor) = 'object'))
              END
            $$;

            ------------------------------------------------------------------
            -- Imutabilidade do agendamento encerrado, com a excecao.
            -- (Corpo da 2026_09_24_000300, com nomes qualificados.)
            ------------------------------------------------------------------
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

            ------------------------------------------------------------------
            -- Historico: so insercao, com a mesma excecao (dados sem texto
            -- livre, nada mais muda). DELETE continua proibido sempre.
            ------------------------------------------------------------------
            CREATE OR REPLACE FUNCTION public.cleison_eventos_somente_insercao() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              IF TG_OP = 'UPDATE'
                 AND public.cleison_papel_atual_e_dono(TG_RELID)
                 AND (to_jsonb(NEW) - 'dados') = (to_jsonb(OLD) - 'dados')
                 AND NEW.dados = public.cleison_evento_dados_anonimos(OLD.dados) THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION '[agendamento_eventos_somente_insercao] Historico de agendamento nao pode ser alterado nem apagado'
                USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamento_eventos_somente_insercao';
            END $$;

            ------------------------------------------------------------------
            -- Cliente anonimizado fica fechado: so a funcao marca, e depois
            -- nada nele muda (nem para o dono).
            ------------------------------------------------------------------
            CREATE OR REPLACE FUNCTION public.cleison_proteger_cliente_anonimizado() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.anonimizado_em IS NOT NULL THEN
                  RAISE EXCEPTION '[clientes_anonimizacao_so_pela_funcao] Cliente so e anonimizado por cleison_anonimizar_cliente'
                    USING ERRCODE = 'check_violation', CONSTRAINT = 'clientes_anonimizacao_so_pela_funcao';
                END IF;
                RETURN NEW;
              END IF;

              IF OLD.anonimizado_em IS NOT NULL THEN
                IF (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
                  RAISE EXCEPTION '[clientes_anonimizado_imutavel] Cliente % foi anonimizado e nao pode ser alterado', OLD.id
                    USING ERRCODE = 'check_violation', CONSTRAINT = 'clientes_anonimizado_imutavel';
                END IF;
              ELSIF NEW.anonimizado_em IS NOT NULL AND NOT public.cleison_papel_atual_e_dono(TG_RELID) THEN
                RAISE EXCEPTION '[clientes_anonimizacao_so_pela_funcao] Cliente so e anonimizado por cleison_anonimizar_cliente'
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'clientes_anonimizacao_so_pela_funcao';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER clientes_anonimizacao
              BEFORE INSERT OR UPDATE ON public.clientes
              FOR EACH ROW EXECUTE FUNCTION public.cleison_proteger_cliente_anonimizado();

            -- Nem endereco novo/alterado nem agendamento para cliente
            -- anonimizado: quem voltar vira um cliente novo. A funcao altera
            -- os enderecos ANTES de marcar o cliente.
            CREATE OR REPLACE FUNCTION public.cleison_cliente_anonimizado_fechado() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              IF EXISTS (SELECT 1 FROM public.clientes c WHERE c.id = NEW.cliente_id AND c.anonimizado_em IS NOT NULL) THEN
                RAISE EXCEPTION '[clientes_anonimizado_fechado] Cliente % foi anonimizado e nao recebe novos dados', NEW.cliente_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'clientes_anonimizado_fechado';
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER enderecos_cliente_anonimizado
              BEFORE INSERT OR UPDATE ON public.enderecos_cliente
              FOR EACH ROW EXECUTE FUNCTION public.cleison_cliente_anonimizado_fechado();

            CREATE TRIGGER agendamentos_cliente_anonimizado
              BEFORE INSERT OR UPDATE OF cliente_id ON public.agendamentos
              FOR EACH ROW EXECUTE FUNCTION public.cleison_cliente_anonimizado_fechado();

            ------------------------------------------------------------------
            -- Registro das anonimizacoes, sem nenhum dado pessoal. Serve
            -- tambem para reaplicar a anonimizacao depois de restaurar um
            -- backup (runbook no backend/README.md).
            ------------------------------------------------------------------
            CREATE TABLE public.anonimizacoes (
              id                    bigserial PRIMARY KEY,
              cliente_id            bigint NOT NULL REFERENCES public.clientes (id) ON DELETE RESTRICT,
              ocorrido_em           timestamptz NOT NULL DEFAULT now(),
              origem                varchar(20) NOT NULL,
              usuario_id            bigint NOT NULL REFERENCES public.users (id) ON DELETE RESTRICT,
              protocolo             text,
              enderecos_afetados    integer NOT NULL,
              agendamentos_afetados integer NOT NULL,
              eventos_afetados      integer NOT NULL,
              CONSTRAINT anonimizacoes_cliente_unico UNIQUE (cliente_id),
              CONSTRAINT anonimizacoes_origem CHECK (origem IN ('pedido_titular', 'retencao')),
              -- Formato fechado: nao cabe nome, telefone nem frase.
              CONSTRAINT anonimizacoes_protocolo CHECK (protocolo IS NULL OR protocolo ~ '^[A-Za-z0-9._/-]{1,40}$'),
              CONSTRAINT anonimizacoes_protocolo_do_pedido CHECK (origem <> 'pedido_titular' OR protocolo IS NOT NULL),
              CONSTRAINT anonimizacoes_contagens CHECK (
                enderecos_afetados >= 0 AND agendamentos_afetados >= 0 AND eventos_afetados >= 0)
            );
            CREATE INDEX anonimizacoes_por_usuario ON public.anonimizacoes (usuario_id);

            CREATE OR REPLACE FUNCTION public.cleison_anonimizacoes_somente_insercao() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              RAISE EXCEPTION '[anonimizacoes_somente_insercao] Registro de anonimizacao nao pode ser alterado nem apagado'
                USING ERRCODE = 'check_violation', CONSTRAINT = 'anonimizacoes_somente_insercao';
            END $$;

            CREATE TRIGGER anonimizacoes_imutaveis
              BEFORE UPDATE OR DELETE ON public.anonimizacoes
              FOR EACH ROW EXECUTE FUNCTION public.cleison_anonimizacoes_somente_insercao();

            -- So o dono escreve (via funcao), para qualquer papel que nao
            -- seja o dono, inclusive PUBLIC; sequencia fechada como na 000200.
            DO $$
            DECLARE
              r record;
            BEGIN
              FOR r IN
                SELECT DISTINCT a.grantee
                  FROM pg_catalog.pg_class c
                 CROSS JOIN LATERAL pg_catalog.aclexplode(c.relacl) a
                 WHERE c.oid = 'public.anonimizacoes'::regclass
                   AND a.grantee <> c.relowner
                   AND a.privilege_type IN ('INSERT', 'UPDATE', 'DELETE', 'TRUNCATE')
              LOOP
                EXECUTE pg_catalog.format('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.anonimizacoes FROM %s',
                  CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE pg_catalog.quote_ident(pg_catalog.pg_get_userbyid(r.grantee)) END);
              END LOOP;

              FOR r IN
                SELECT DISTINCT c.oid::regclass AS sequencia, a.grantee
                  FROM pg_catalog.pg_class c
                 CROSS JOIN LATERAL pg_catalog.aclexplode(c.relacl) a
                 WHERE c.oid = pg_catalog.pg_get_serial_sequence('public.anonimizacoes', 'id')::regclass
                   AND a.grantee <> c.relowner
              LOOP
                EXECUTE pg_catalog.format('REVOKE ALL ON SEQUENCE %s FROM %s', r.sequencia,
                  CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE pg_catalog.quote_ident(pg_catalog.pg_get_userbyid(r.grantee)) END);
              END LOOP;
            END $$;

            ------------------------------------------------------------------
            -- A anonimizacao.
            ------------------------------------------------------------------
            CREATE OR REPLACE FUNCTION public.cleison_anonimizar_cliente(
                p_cliente_id bigint, p_usuario_id bigint, p_origem varchar, p_protocolo varchar)
              RETURNS bigint
              LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
              v_anonimizado_em timestamptz;
              v_enderecos      integer;
              v_agendamentos   integer;
              v_eventos        integer;
              v_id             bigint;
            BEGIN
              -- Autorizacao no banco: proprietario ativo. FOR SHARE segura o
              -- usuario (ninguem o desativa ou rebaixa ate o fim).
              PERFORM 1 FROM public.users u
                WHERE u.id = p_usuario_id AND u.ativo AND u.papel = 'proprietario'
                FOR SHARE;
              IF NOT FOUND THEN
                RAISE EXCEPTION '[anonimizacao_exige_proprietario] Anonimizacao exige usuario proprietario ativo'
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'anonimizacao_exige_proprietario';
              END IF;

              IF p_origem IS NULL OR p_origem NOT IN ('pedido_titular', 'retencao') THEN
                RAISE EXCEPTION '[anonimizacoes_origem] Origem de anonimizacao invalida'
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'anonimizacoes_origem';
              END IF;

              -- Trava o cliente: chamadas simultaneas entram em fila, e um
              -- agendamento novo para ele (FOR KEY SHARE da FK) espera.
              SELECT c.anonimizado_em INTO v_anonimizado_em
                FROM public.clientes c WHERE c.id = p_cliente_id FOR UPDATE;
              IF NOT FOUND THEN
                RAISE EXCEPTION '[anonimizacao_cliente_inexistente] Cliente % nao existe', p_cliente_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'anonimizacao_cliente_inexistente';
              END IF;
              IF v_anonimizado_em IS NOT NULL THEN
                RETURN NULL; -- idempotente: ja anonimizado, nada muda
              END IF;

              IF EXISTS (SELECT 1 FROM public.agendamentos a
                          WHERE a.cliente_id = p_cliente_id
                            AND a.estado IN ('solicitado', 'confirmado', 'em_atendimento')) THEN
                RAISE EXCEPTION '[clientes_anonimizacao_com_ativo] Cliente % tem agendamento em aberto', p_cliente_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'clientes_anonimizacao_com_ativo';
              END IF;

              UPDATE public.agendamento_eventos e
                 SET dados = public.cleison_evento_dados_anonimos(e.dados)
               WHERE e.agendamento_id IN (SELECT a.id FROM public.agendamentos a WHERE a.cliente_id = p_cliente_id);
              GET DIAGNOSTICS v_eventos = ROW_COUNT;

              UPDATE public.agendamentos a
                 SET endereco_texto = CASE WHEN a.endereco_texto IS NULL THEN NULL ELSE '[anonimizado]' END,
                     observacao_cliente = NULL,
                     motivo_cancelamento = NULL,
                     chave_idempotencia = NULL,  -- chave e hash juntos:
                     hash_requisicao = NULL      -- agendamentos_idempotencia_coerente
               WHERE a.cliente_id = p_cliente_id;
              GET DIAGNOSTICS v_agendamentos = ROW_COUNT;

              UPDATE public.enderecos_cliente e
                 SET logradouro = '[anonimizado]', complemento = NULL, referencia = NULL,
                     arquivado_em = coalesce(e.arquivado_em, now()), updated_at = now()
               WHERE e.cliente_id = p_cliente_id;
              GET DIAGNOSTICS v_enderecos = ROW_COUNT;

              UPDATE public.clientes
                 SET nome = 'Cliente anonimizado', telefone = NULL, observacoes = NULL,
                     anonimizado_em = now(), updated_at = now()
               WHERE id = p_cliente_id;

              INSERT INTO public.anonimizacoes
                (cliente_id, origem, usuario_id, protocolo, enderecos_afetados, agendamentos_afetados, eventos_afetados)
              VALUES (p_cliente_id, p_origem, p_usuario_id, p_protocolo, v_enderecos, v_agendamentos, v_eventos)
              RETURNING id INTO v_id;

              RETURN v_id;
            END $$;

            REVOKE ALL ON FUNCTION public.cleison_anonimizar_cliente(bigint, bigint, varchar, varchar) FROM PUBLIC;
        SQL);

        DB::statement("GRANT EXECUTE ON FUNCTION public.cleison_anonimizar_cliente(bigint, bigint, varchar, varchar) TO {$papelSql}");
        DB::statement("GRANT SELECT ON public.anonimizacoes TO {$papelSql}");
    }

    /**
     * Recusa desfazer com anonimizacoes registradas: o registro e o que
     * permite reaplicar a anonimizacao depois de restaurar um backup.
     */
    public function down(): void
    {
        if ((bool) DB::scalar("SELECT to_regclass('public.anonimizacoes') IS NOT NULL")
            && (int) DB::scalar('SELECT count(*) FROM public.anonimizacoes') > 0) {
            throw new RuntimeException('Ha anonimizacoes registradas; desfazer esta migration perderia o registro necessario para reaplica-las apos restaurar um backup.');
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.cleison_anonimizar_cliente(bigint, bigint, varchar, varchar);

            DROP TABLE IF EXISTS public.anonimizacoes;
            DROP FUNCTION IF EXISTS public.cleison_anonimizacoes_somente_insercao();

            DROP TRIGGER IF EXISTS agendamentos_cliente_anonimizado ON public.agendamentos;
            DROP TRIGGER IF EXISTS enderecos_cliente_anonimizado ON public.enderecos_cliente;
            DROP FUNCTION IF EXISTS public.cleison_cliente_anonimizado_fechado();

            DROP TRIGGER IF EXISTS clientes_anonimizacao ON public.clientes;
            DROP FUNCTION IF EXISTS public.cleison_proteger_cliente_anonimizado();

            -- Versoes da 2026_09_24_000300 (sem SET: RESET ALL).
            CREATE OR REPLACE FUNCTION cleison_eventos_somente_insercao() RETURNS trigger
              LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION '[agendamento_eventos_somente_insercao] Historico de agendamento nao pode ser alterado nem apagado'
                USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamento_eventos_somente_insercao';
            END $$;
            ALTER FUNCTION cleison_eventos_somente_insercao() RESET ALL;

            CREATE OR REPLACE FUNCTION cleison_validar_agendamento() RETURNS trigger
              LANGUAGE plpgsql AS $$
            BEGIN
              -- Nasce em solicitado (site), confirmado (operador) ou
              -- em_atendimento (quem chegou sem reserva). Atendimento
              -- espontaneo ja terminado: cria em em_atendimento, grava os
              -- itens e conclui na mesma transacao (itens de agendamento
              -- encerrado sao imutaveis, ver agendamento_itens_encerrado_imutavel).
              IF TG_OP = 'INSERT' THEN
                IF NEW.estado NOT IN ('solicitado', 'confirmado', 'em_atendimento') THEN
                  RAISE EXCEPTION '[agendamentos_estado_inicial] Agendamento nao pode nascer no estado %', NEW.estado
                    USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_estado_inicial';
                END IF;
                RETURN NEW;
              END IF;

              IF NEW.estado IS DISTINCT FROM OLD.estado
                 AND NOT cleison_transicao_permitida(OLD.estado, NEW.estado) THEN
                RAISE EXCEPTION '[agendamentos_transicao_estado] Transicao de estado invalida: % -> %', OLD.estado, NEW.estado
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_transicao_estado';
              END IF;

              -- Encerrado (concluido, cancelado, nao_compareceu) e historico:
              -- nenhuma coluna muda depois disso (horario, profissional,
              -- cliente, taxa, endereco, regiao, modalidade, origem, motivo...).
              IF OLD.estado IN ('concluido', 'cancelado', 'nao_compareceu')
                 AND (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
                RAISE EXCEPTION '[agendamentos_encerrado_imutavel] Agendamento % esta encerrado (%) e nao pode ser alterado', OLD.id, OLD.estado
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamentos_encerrado_imutavel';
              END IF;

              NEW.updated_at := now();
              RETURN NEW;
            END $$;
            ALTER FUNCTION cleison_validar_agendamento() RESET ALL;

            DROP FUNCTION IF EXISTS public.cleison_evento_dados_anonimos(jsonb);
            DROP FUNCTION IF EXISTS public.cleison_agendamento_anonimizado(jsonb);
            DROP FUNCTION IF EXISTS public.cleison_papel_atual_e_dono(oid);

            ALTER TABLE public.clientes DROP CONSTRAINT IF EXISTS clientes_anonimizado_coerente;
            ALTER TABLE public.clientes DROP COLUMN IF EXISTS anonimizado_em;
        SQL);
    }
};
