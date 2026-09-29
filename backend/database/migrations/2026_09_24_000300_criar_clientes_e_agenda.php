<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Clientes, enderecos, agendamentos (com itens e historico), bloqueios e
 * a tabela de ocupacoes que garante, NO BANCO, que um profissional nao
 * tem dois compromissos ao mesmo tempo.
 *
 * Estrategia (detalhes em docs/ARQUITETURA.md, secao "Agenda"):
 *
 *   ocupacoes_agenda tem EXCLUDE USING gist (profissional_id =, periodo &&).
 *   Todo agendamento ATIVO e todo bloqueio ATIVO tem exatamente uma linha
 *   ali. A linha e criada/removida por trigger quando o estado muda, e
 *   fica presa ao pai por FK composta (id, profissional_id, periodo) com
 *   ON UPDATE CASCADE: remarcar = um UPDATE no agendamento; se o destino
 *   estiver ocupado, a exclusao falha e o UPDATE inteiro volta atras.
 *
 *   Intervalos sao [inicio, fim): 10:00-10:30 e 10:30-11:00 nao colidem.
 *
 *   Nao e "consultar antes de inserir": duas transacoes concorrentes que
 *   tentam o mesmo horario esbarram no indice de exclusao; a segunda
 *   espera a primeira e falha com SQLSTATE 23P01 se ela confirmar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ------------------------------------------------------------------
            -- Clientes (sem senha; separados de users)
            ------------------------------------------------------------------
            CREATE TABLE clientes (
              id          bigserial PRIMARY KEY,
              nome        varchar(120) NOT NULL,
              -- E.164 (+5511987654321). NULL = atendimento presencial de
              -- quem nao quis informar telefone.
              telefone    varchar(16),
              -- Observacoes OPERACIONAIS (ex.: "prefere maquina 2"). Nao e
              -- lugar para dado sensivel.
              observacoes varchar(1000),
              created_at  timestamptz NOT NULL DEFAULT now(),
              updated_at  timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT clientes_nome     CHECK (length(btrim(nome)) >= 2),
              CONSTRAINT clientes_telefone CHECK (telefone IS NULL OR telefone ~ '^\+[1-9][0-9]{7,14}$'),
              CONSTRAINT clientes_telefone_unico UNIQUE (telefone)
            );
            CREATE INDEX clientes_nome_busca ON clientes (lower(nome) text_pattern_ops);

            CREATE TABLE enderecos_cliente (
              id           bigserial PRIMARY KEY,
              cliente_id   bigint NOT NULL REFERENCES clientes (id) ON DELETE RESTRICT,
              regiao_id    bigint REFERENCES regioes_atendimento (id) ON DELETE RESTRICT,
              logradouro   varchar(200) NOT NULL,
              complemento  varchar(100),
              referencia   varchar(200),
              arquivado_em timestamptz,
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT enderecos_logradouro CHECK (length(btrim(logradouro)) >= 5),
              -- Alvo da FK composta em agendamentos: o endereco usado tem
              -- que ser do proprio cliente do agendamento.
              CONSTRAINT enderecos_id_cliente UNIQUE (id, cliente_id)
            );
            CREATE INDEX enderecos_por_cliente ON enderecos_cliente (cliente_id);

            ------------------------------------------------------------------
            -- Agendamentos
            --
            -- O agendamento TAMBEM e o registro do atendimento: quem chega
            -- sem reserva vira um agendamento com origem 'presencial' criado
            -- em 'em_atendimento' (e concluido na mesma transacao se ja terminou). Isso mantem uma so
            -- linha do tempo por profissional (o atendimento espontaneo
            -- tambem ocupa a agenda). A venda da etapa 4 tera
            -- agendamento_id UNIQUE, impedindo venda duplicada.
            ------------------------------------------------------------------
            CREATE TABLE agendamentos (
              id                         bigserial PRIMARY KEY,
              codigo_publico             uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
              profissional_id            bigint NOT NULL REFERENCES profissionais (id) ON DELETE RESTRICT,
              cliente_id                 bigint NOT NULL REFERENCES clientes (id) ON DELETE RESTRICT,
              estado                     varchar(20) NOT NULL,
              origem                     varchar(20) NOT NULL,
              modalidade                 varchar(20) NOT NULL,

              -- O servico em si...
              inicio_servico             timestamptz NOT NULL,
              fim_servico                timestamptz NOT NULL,
              -- ...e o tempo que sai da agenda (com ida e volta no domicilio,
              -- ja arredondado para a grade).
              inicio_ocupado             timestamptz NOT NULL,
              fim_ocupado                timestamptz NOT NULL,
              periodo_ocupado            tstzrange GENERATED ALWAYS AS
                                           (tstzrange(inicio_ocupado, fim_ocupado, '[)')) STORED,

              -- Snapshot do domicilio: mudar a regiao/endereco depois nao
              -- altera o que foi combinado.
              endereco_cliente_id        bigint,
              endereco_texto             varchar(300),
              regiao_id                  bigint REFERENCES regioes_atendimento (id) ON DELETE RESTRICT,
              regiao_nome                varchar(80),
              deslocamento_minutos       integer NOT NULL DEFAULT 0,
              taxa_deslocamento_centavos integer NOT NULL DEFAULT 0,

              observacao_cliente         varchar(300),

              -- Idempotencia da criacao (etapa 2): mesma chave + mesmo
              -- conteudo devolve a mesma reserva; mesma chave com outro
              -- conteudo e conflito.
              chave_idempotencia         varchar(100),
              hash_requisicao            char(64),

              criado_por_user_id         bigint REFERENCES users (id) ON DELETE RESTRICT,
              cancelado_em               timestamptz,
              motivo_cancelamento        varchar(300),
              created_at                 timestamptz NOT NULL DEFAULT now(),
              updated_at                 timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT agendamentos_estado_valido CHECK (estado IN
                ('solicitado', 'confirmado', 'em_atendimento', 'concluido', 'cancelado', 'nao_compareceu')),
              CONSTRAINT agendamentos_origem_valida CHECK (origem IN ('site', 'whatsapp', 'presencial')),
              CONSTRAINT agendamentos_modalidade_valida CHECK (modalidade IN ('barbearia', 'domicilio')),

              CONSTRAINT agendamentos_servico_intervalo CHECK (fim_servico > inicio_servico),
              CONSTRAINT agendamentos_ocupado_max_24h CHECK (fim_ocupado - inicio_ocupado <= interval '24 hours'),
              -- 'infinity' e um timestamptz valido; aqui nao e aceito.
              CONSTRAINT agendamentos_instantes_finitos CHECK (
                isfinite(inicio_servico) AND isfinite(fim_servico)
                AND isfinite(inicio_ocupado) AND isfinite(fim_ocupado)),
              -- O tempo de ida e o de volta declarados tem que estar dentro do
              -- periodo ocupado (pode haver mais, pelo arredondamento a grade).
              -- Com deslocamento >= 0 isto tambem garante que o periodo ocupado
              -- CONTEM o servico (substitui a antiga ocupado_contem_servico).
              CONSTRAINT agendamentos_deslocamento_reservado CHECK (
                    inicio_servico - inicio_ocupado >= deslocamento_minutos * interval '1 minute'
                AND fim_ocupado - fim_servico       >= deslocamento_minutos * interval '1 minute'),
              CONSTRAINT agendamentos_minutos_cheios CHECK (
                    date_trunc('minute', inicio_servico) = inicio_servico
                AND date_trunc('minute', fim_servico)    = fim_servico
                AND date_trunc('minute', inicio_ocupado) = inicio_ocupado
                AND date_trunc('minute', fim_ocupado)    = fim_ocupado),

              CONSTRAINT agendamentos_deslocamento CHECK (deslocamento_minutos BETWEEN 0 AND 240),
              CONSTRAINT agendamentos_taxa CHECK (taxa_deslocamento_centavos >= 0),
              CONSTRAINT agendamentos_domicilio_completo CHECK (
                modalidade <> 'domicilio'
                OR (endereco_texto IS NOT NULL AND length(btrim(endereco_texto)) >= 5
                    AND regiao_id IS NOT NULL AND regiao_nome IS NOT NULL)),
              CONSTRAINT agendamentos_barbearia_sem_domicilio CHECK (
                modalidade <> 'barbearia'
                OR (endereco_cliente_id IS NULL AND endereco_texto IS NULL AND regiao_id IS NULL
                    AND regiao_nome IS NULL AND deslocamento_minutos = 0
                    AND taxa_deslocamento_centavos = 0)),

              CONSTRAINT agendamentos_cancelamento_coerente
                CHECK ((estado = 'cancelado') = (cancelado_em IS NOT NULL)),
              CONSTRAINT agendamentos_idempotencia_coerente
                CHECK ((chave_idempotencia IS NULL) = (hash_requisicao IS NULL)),
              CONSTRAINT agendamentos_hash_formato
                CHECK (hash_requisicao IS NULL OR hash_requisicao ~ '^[0-9a-f]{64}$'),
              CONSTRAINT agendamentos_chave_idempotencia_unica UNIQUE (chave_idempotencia),

              CONSTRAINT agendamentos_endereco_do_cliente
                FOREIGN KEY (endereco_cliente_id, cliente_id)
                REFERENCES enderecos_cliente (id, cliente_id) ON DELETE RESTRICT,

              -- Alvo da FK composta de ocupacoes_agenda.
              CONSTRAINT agendamentos_alvo_ocupacao UNIQUE (id, profissional_id, periodo_ocupado)
            );
            CREATE INDEX agendamentos_profissional_inicio ON agendamentos (profissional_id, inicio_servico);
            CREATE INDEX agendamentos_cliente_inicio ON agendamentos (cliente_id, inicio_servico DESC);
            CREATE INDEX agendamentos_inicio ON agendamentos (inicio_servico);
            CREATE INDEX agendamentos_aguardando ON agendamentos (inicio_servico) WHERE estado = 'solicitado';

            -- Servicos do agendamento com SNAPSHOT de nome, preco, duracao e
            -- regra de contagem: mudar o catalogo depois nao muda o passado.
            CREATE TABLE agendamento_itens (
              id               bigserial PRIMARY KEY,
              agendamento_id   bigint   NOT NULL REFERENCES agendamentos (id) ON DELETE RESTRICT,
              servico_id       bigint   NOT NULL REFERENCES servicos (id) ON DELETE RESTRICT,
              ordem            smallint NOT NULL DEFAULT 1,
              servico_nome     varchar(80) NOT NULL,
              preco_centavos   integer  NOT NULL,
              duracao_minutos  integer  NOT NULL,
              conta_como_corte boolean  NOT NULL,
              created_at       timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT agendamento_itens_preco   CHECK (preco_centavos >= 0),
              CONSTRAINT agendamento_itens_duracao CHECK (duracao_minutos > 0 AND duracao_minutos <= 720),
              CONSTRAINT agendamento_itens_ordem   CHECK (ordem >= 1),
              CONSTRAINT agendamento_itens_ordem_unica UNIQUE (agendamento_id, ordem)
            );
            CREATE INDEX agendamento_itens_por_servico ON agendamento_itens (servico_id);

            -- Trilha: criado, mudancas de estado e remarcacoes. So insercao.
            CREATE TABLE agendamento_eventos (
              id              bigserial PRIMARY KEY,
              agendamento_id  bigint NOT NULL REFERENCES agendamentos (id) ON DELETE RESTRICT,
              tipo            varchar(20) NOT NULL,
              estado_anterior varchar(20),
              estado_novo     varchar(20) NOT NULL,
              dados           jsonb NOT NULL DEFAULT '{}'::jsonb,
              ator            varchar(20) NOT NULL,
              usuario_id      bigint REFERENCES users (id) ON DELETE RESTRICT,
              ocorrido_em     timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT agendamento_eventos_tipo CHECK (tipo IN ('criado', 'estado_alterado', 'remarcado')),
              CONSTRAINT agendamento_eventos_ator CHECK (ator IN ('cliente', 'operador', 'sistema')),
              CONSTRAINT agendamento_eventos_operador_identificado
                CHECK (ator <> 'operador' OR usuario_id IS NOT NULL)
            );
            CREATE INDEX agendamento_eventos_por_agendamento ON agendamento_eventos (agendamento_id, id);

            ------------------------------------------------------------------
            -- Bloqueios administrativos: folga, ferias, feriado, compromisso.
            ------------------------------------------------------------------
            CREATE TABLE bloqueios_agenda (
              id                 bigserial PRIMARY KEY,
              profissional_id    bigint NOT NULL REFERENCES profissionais (id) ON DELETE RESTRICT,
              tipo               varchar(20) NOT NULL,
              inicio             timestamptz NOT NULL,
              fim                timestamptz NOT NULL,
              periodo            tstzrange GENERATED ALWAYS AS (tstzrange(inicio, fim, '[)')) STORED,
              motivo             varchar(200),
              criado_por_user_id bigint REFERENCES users (id) ON DELETE RESTRICT,
              cancelado_em       timestamptz,
              created_at         timestamptz NOT NULL DEFAULT now(),
              updated_at         timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT bloqueios_tipo CHECK (tipo IN ('folga', 'ferias', 'feriado', 'compromisso', 'outro')),
              CONSTRAINT bloqueios_intervalo CHECK (fim > inicio),
              CONSTRAINT bloqueios_max_90_dias CHECK (fim - inicio <= interval '90 days'),
              CONSTRAINT bloqueios_instantes_finitos CHECK (isfinite(inicio) AND isfinite(fim)),
              CONSTRAINT bloqueios_alvo_ocupacao UNIQUE (id, profissional_id, periodo)
            );
            CREATE INDEX bloqueios_profissional_inicio ON bloqueios_agenda (profissional_id, inicio);

            ------------------------------------------------------------------
            -- Ocupacoes: A garantia contra sobreposicao.
            ------------------------------------------------------------------
            CREATE TABLE ocupacoes_agenda (
              id              bigserial PRIMARY KEY,
              profissional_id bigint    NOT NULL REFERENCES profissionais (id) ON DELETE RESTRICT,
              periodo         tstzrange NOT NULL,
              agendamento_id  bigint UNIQUE,
              bloqueio_id     bigint UNIQUE,
              created_at      timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT ocupacoes_uma_origem CHECK (num_nonnulls(agendamento_id, bloqueio_id) = 1),
              CONSTRAINT ocupacoes_periodo_fechado_aberto CHECK (
                NOT isempty(periodo) AND lower_inc(periodo) AND NOT upper_inc(periodo)
                AND NOT lower_inf(periodo) AND NOT upper_inf(periodo)
                -- upper_inf() e falso para o limite 'infinity' (so e verdadeiro
                -- para "sem limite"); por isso a conferencia explicita.
                AND isfinite(lower(periodo)) AND isfinite(upper(periodo))),
              CONSTRAINT ocupacoes_do_agendamento
                FOREIGN KEY (agendamento_id, profissional_id, periodo)
                REFERENCES agendamentos (id, profissional_id, periodo_ocupado)
                ON UPDATE CASCADE ON DELETE CASCADE,
              CONSTRAINT ocupacoes_do_bloqueio
                FOREIGN KEY (bloqueio_id, profissional_id, periodo)
                REFERENCES bloqueios_agenda (id, profissional_id, periodo)
                ON UPDATE CASCADE ON DELETE CASCADE,
              CONSTRAINT ocupacoes_sem_sobreposicao
                EXCLUDE USING gist (profissional_id WITH =, periodo WITH &&)
            );

            ------------------------------------------------------------------
            -- Funcoes e triggers
            ------------------------------------------------------------------

            -- Estados que seguram horario na agenda.
            CREATE OR REPLACE FUNCTION cleison_estado_ocupa_agenda(estado varchar) RETURNS boolean
              LANGUAGE sql IMMUTABLE AS
              $$ SELECT estado IN ('solicitado', 'confirmado', 'em_atendimento', 'concluido') $$;

            -- Contrato de estados. Espelhado em App\Enums\EstadoAgendamento;
            -- um teste confere que os dois concordam em todos os pares.
            CREATE OR REPLACE FUNCTION cleison_transicao_permitida(de varchar, para varchar) RETURNS boolean
              LANGUAGE sql IMMUTABLE AS
              $$ SELECT (de, para) IN (
                   ('solicitado',     'confirmado'),
                   ('solicitado',     'cancelado'),
                   ('confirmado',     'em_atendimento'),
                   ('confirmado',     'concluido'),
                   ('confirmado',     'cancelado'),
                   ('confirmado',     'nao_compareceu'),
                   ('em_atendimento', 'concluido'),
                   ('em_atendimento', 'cancelado')) $$;

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

            CREATE TRIGGER agendamentos_validar
              BEFORE INSERT OR UPDATE ON agendamentos
              FOR EACH ROW EXECUTE FUNCTION cleison_validar_agendamento();

            -- Cria/remove a ocupacao quando o agendamento entra/sai dos
            -- estados que seguram horario. Mudanca de horario/profissional
            -- de agendamento ativo chega a ocupacao pelo ON UPDATE CASCADE.
            CREATE OR REPLACE FUNCTION cleison_sincronizar_ocupacao_agendamento() RETURNS trigger
              LANGUAGE plpgsql AS $$
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

            CREATE TRIGGER agendamentos_sincronizar_ocupacao
              AFTER INSERT OR UPDATE OF estado ON agendamentos
              FOR EACH ROW EXECUTE FUNCTION cleison_sincronizar_ocupacao_agendamento();

            -- Quem fez a mudanca: a aplicacao define, dentro da transacao,
            --   SELECT set_config('cleison.ator', 'operador', true),
            --          set_config('cleison.usuario_id', '<id>', true);
            -- Sem isso, fica registrado como 'sistema'.
            CREATE OR REPLACE FUNCTION cleison_registrar_evento_agendamento() RETURNS trigger
              LANGUAGE plpgsql AS $$
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

            CREATE TRIGGER agendamentos_registrar_evento
              AFTER INSERT OR UPDATE ON agendamentos
              FOR EACH ROW EXECUTE FUNCTION cleison_registrar_evento_agendamento();

            CREATE OR REPLACE FUNCTION cleison_eventos_somente_insercao() RETURNS trigger
              LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION '[agendamento_eventos_somente_insercao] Historico de agendamento nao pode ser alterado nem apagado'
                USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamento_eventos_somente_insercao';
            END $$;

            -- Itens (snapshot de nome, preco, duracao, contagem) de agendamento
            -- encerrado nao mudam, nao somem e nao ganham companhia.
            CREATE OR REPLACE FUNCTION cleison_itens_de_encerrado_imutaveis() RETURNS trigger
              LANGUAGE plpgsql AS $$
            BEGIN
              IF EXISTS (
                   SELECT 1 FROM agendamentos
                    WHERE id IN (
                            CASE WHEN TG_OP <> 'INSERT' THEN OLD.agendamento_id END,
                            CASE WHEN TG_OP <> 'DELETE' THEN NEW.agendamento_id END)
                      AND estado IN ('concluido', 'cancelado', 'nao_compareceu')) THEN
                RAISE EXCEPTION '[agendamento_itens_encerrado_imutavel] Itens de agendamento encerrado nao podem ser alterados'
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'agendamento_itens_encerrado_imutavel';
              END IF;
              RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END $$;

            CREATE TRIGGER agendamento_itens_encerrado_imutavel
              BEFORE INSERT OR UPDATE OR DELETE ON agendamento_itens
              FOR EACH ROW EXECUTE FUNCTION cleison_itens_de_encerrado_imutaveis();

            CREATE TRIGGER agendamento_eventos_imutaveis
              BEFORE UPDATE OR DELETE ON agendamento_eventos
              FOR EACH ROW EXECUTE FUNCTION cleison_eventos_somente_insercao();

            -- Todo agendamento precisa de pelo menos um servico, e a soma das
            -- duracoes (snapshot) tem que bater com o intervalo do servico.
            -- Conferido no COMMIT, para permitir inserir o agendamento e os
            -- itens em comandos separados dentro da mesma transacao.
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

            CREATE CONSTRAINT TRIGGER agendamentos_conferir_itens
              AFTER INSERT OR UPDATE OF inicio_servico, fim_servico ON agendamentos
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION cleison_conferir_itens_agendamento();

            CREATE CONSTRAINT TRIGGER agendamento_itens_conferir
              AFTER INSERT OR UPDATE OR DELETE ON agendamento_itens
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION cleison_conferir_itens_agendamento();

            -- Bloqueio ativo = cancelado_em nulo.
            CREATE OR REPLACE FUNCTION cleison_sincronizar_ocupacao_bloqueio() RETURNS trigger
              LANGUAGE plpgsql AS $$
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

            CREATE TRIGGER bloqueios_sincronizar_ocupacao
              AFTER INSERT OR UPDATE OF cancelado_em ON bloqueios_agenda
              FOR EACH ROW EXECUTE FUNCTION cleison_sincronizar_ocupacao_bloqueio();

            -- Fila por profissional. So a constraint de exclusao ja impede
            -- sobreposicao, mas varias insercoes simultaneas na mesma
            -- constraint se esperam mutuamente (cada uma aguarda a tupla ainda
            -- nao confirmada da outra) e o PostgreSQL aborta com deadlock
            -- (40P01) depois de deadlock_timeout. Com este lock transacional,
            -- quem chega depois espera o anterior terminar e recebe direto o
            -- conflito definitivo (23P01). Profissionais diferentes nao se
            -- bloqueiam. Vale para agendamento, bloqueio e remarcacao (o
            -- ON UPDATE CASCADE tambem dispara este trigger).
            CREATE OR REPLACE FUNCTION cleison_fila_da_agenda() RETURNS trigger
              LANGUAGE plpgsql AS $$
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtextextended('cleison.agenda', NEW.profissional_id));
              RETURN NEW;
            END $$;

            CREATE TRIGGER ocupacoes_fila_por_profissional
              BEFORE INSERT OR UPDATE OF profissional_id, periodo ON ocupacoes_agenda
              FOR EACH ROW EXECUTE FUNCTION cleison_fila_da_agenda();

            -- Ninguem apaga a ocupacao de um compromisso ainda ativo "por
            -- fora" (liberando o horario sem cancelar). Para liberar, mude o
            -- estado do agendamento ou cancele o bloqueio.
            CREATE OR REPLACE FUNCTION cleison_proteger_ocupacao() RETURNS trigger
              LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.agendamento_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM agendamentos
                    WHERE id = OLD.agendamento_id AND cleison_estado_ocupa_agenda(estado)) THEN
                RAISE EXCEPTION '[ocupacoes_protegidas] Ocupacao do agendamento % ainda ativo', OLD.agendamento_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'ocupacoes_protegidas';
              END IF;
              IF OLD.bloqueio_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM bloqueios_agenda
                    WHERE id = OLD.bloqueio_id AND cancelado_em IS NULL) THEN
                RAISE EXCEPTION '[ocupacoes_protegidas] Ocupacao do bloqueio % ainda ativo', OLD.bloqueio_id
                  USING ERRCODE = 'check_violation', CONSTRAINT = 'ocupacoes_protegidas';
              END IF;
              RETURN OLD;
            END $$;

            CREATE TRIGGER ocupacoes_protegidas
              BEFORE DELETE ON ocupacoes_agenda
              FOR EACH ROW EXECUTE FUNCTION cleison_proteger_ocupacao();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS ocupacoes_agenda;
            DROP TABLE IF EXISTS bloqueios_agenda;
            DROP TABLE IF EXISTS agendamento_eventos;
            DROP TABLE IF EXISTS agendamento_itens;
            DROP TABLE IF EXISTS agendamentos;
            DROP TABLE IF EXISTS enderecos_cliente;
            DROP TABLE IF EXISTS clientes;
            DROP FUNCTION IF EXISTS cleison_proteger_ocupacao();
            DROP FUNCTION IF EXISTS cleison_fila_da_agenda();
            DROP FUNCTION IF EXISTS cleison_itens_de_encerrado_imutaveis();
            DROP FUNCTION IF EXISTS cleison_sincronizar_ocupacao_bloqueio();
            DROP FUNCTION IF EXISTS cleison_conferir_itens_agendamento();
            DROP FUNCTION IF EXISTS cleison_eventos_somente_insercao();
            DROP FUNCTION IF EXISTS cleison_registrar_evento_agendamento();
            DROP FUNCTION IF EXISTS cleison_sincronizar_ocupacao_agendamento();
            DROP FUNCTION IF EXISTS cleison_validar_agendamento();
            DROP FUNCTION IF EXISTS cleison_transicao_permitida(varchar, varchar);
            DROP FUNCTION IF EXISTS cleison_estado_ocupa_agenda(varchar);
        SQL);
    }
};
