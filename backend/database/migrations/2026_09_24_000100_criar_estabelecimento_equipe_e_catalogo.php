<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Estabelecimento (singleton), profissionais, servicos, vinculo
 * profissional x servico e regioes de atendimento domiciliar.
 *
 * Regras gerais (ver docs/MODELO-DE-DADOS.md):
 *   - dinheiro sempre em centavos, integer, com CHECK >= 0;
 *   - datas/horas absolutas em timestamptz;
 *   - nada e apagado quando ja foi usado: desativar = ativo=false.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Necessaria para a constraint de exclusao por profissional
            -- (igualdade de bigint + sobreposicao de tstzrange no mesmo
            -- indice GiST). "Trusted" desde o PG 13: o dono do banco cria.
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            -- Um unico estabelecimento, de proposito (sem filiais/SaaS).
            -- Abrir um segundo exigiria uma migration consciente.
            CREATE TABLE estabelecimento (
              id                          smallint PRIMARY KEY DEFAULT 1,
              nome                        varchar(120) NOT NULL,
              fuso_horario                varchar(64)  NOT NULL DEFAULT 'America/Sao_Paulo',
              whatsapp                    varchar(16),
              endereco                    varchar(200),
              grade_minutos               smallint NOT NULL DEFAULT 30,
              antecedencia_minima_minutos integer  NOT NULL DEFAULT 30,
              horizonte_dias              integer  NOT NULL DEFAULT 30,
              domicilio_ativo             boolean  NOT NULL DEFAULT false,
              -- true = dados de exemplo (seed de desenvolvimento). Nunca
              -- deve estar true em producao.
              dados_demonstracao          boolean  NOT NULL DEFAULT false,
              created_at                  timestamptz NOT NULL DEFAULT now(),
              updated_at                  timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT estabelecimento_unico       CHECK (id = 1),
              CONSTRAINT estabelecimento_nome        CHECK (length(btrim(nome)) >= 2),
              CONSTRAINT estabelecimento_whatsapp    CHECK (whatsapp IS NULL OR whatsapp ~ '^\+[1-9][0-9]{7,14}$'),
              CONSTRAINT estabelecimento_grade       CHECK (grade_minutos IN (5, 10, 15, 20, 30, 60)),
              CONSTRAINT estabelecimento_antecedencia CHECK (antecedencia_minima_minutos BETWEEN 0 AND 10080),
              CONSTRAINT estabelecimento_horizonte   CHECK (horizonte_dias BETWEEN 1 AND 365)
            );

            -- O fuso decide o "dia local" e o horario de parede do
            -- expediente: nome invalido quebraria toda a agenda. A conversao
            -- falha (22023) se o PostgreSQL nao conhecer o fuso.
            CREATE OR REPLACE FUNCTION cleison_validar_fuso() RETURNS trigger
              LANGUAGE plpgsql AS $$
            BEGIN
              PERFORM now() AT TIME ZONE NEW.fuso_horario;
              RETURN NEW;
            EXCEPTION WHEN invalid_parameter_value THEN
              RAISE EXCEPTION '[estabelecimento_fuso_valido] Fuso horario desconhecido: %', NEW.fuso_horario
                USING ERRCODE = 'check_violation', CONSTRAINT = 'estabelecimento_fuso_valido';
            END $$;

            CREATE TRIGGER estabelecimento_fuso_valido
              BEFORE INSERT OR UPDATE OF fuso_horario ON estabelecimento
              FOR EACH ROW EXECUTE FUNCTION cleison_validar_fuso();

            CREATE TABLE profissionais (
              id             bigserial PRIMARY KEY,
              -- Login opcional: um profissional pode existir sem acesso ao
              -- painel, e um usuario (recepcao) sem ser profissional.
              user_id        bigint UNIQUE REFERENCES users (id) ON DELETE RESTRICT,
              nome_exibicao  varchar(80) NOT NULL,
              ativo          boolean NOT NULL DEFAULT true,
              ordem          smallint NOT NULL DEFAULT 0,
              created_at     timestamptz NOT NULL DEFAULT now(),
              updated_at     timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT profissionais_nome CHECK (length(btrim(nome_exibicao)) >= 2)
            );

            CREATE TABLE servicos (
              id                bigserial PRIMARY KEY,
              codigo            varchar(40) NOT NULL UNIQUE,
              nome              varchar(80) NOT NULL,
              descricao         varchar(300),
              preco_centavos    integer  NOT NULL,
              duracao_minutos   integer  NOT NULL,
              -- Define o que entra na "contagem de cortes" dos relatorios.
              -- Barba e pezinho NAO contam por padrao; combo corte+barba
              -- conta como UM corte. Ver docs/DECISOES-PENDENTES.md.
              conta_como_corte  boolean  NOT NULL DEFAULT false,
              permite_barbearia boolean  NOT NULL DEFAULT true,
              permite_domicilio boolean  NOT NULL DEFAULT true,
              ativo             boolean  NOT NULL DEFAULT true,
              ordem             smallint NOT NULL DEFAULT 0,
              created_at        timestamptz NOT NULL DEFAULT now(),
              updated_at        timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT servicos_codigo     CHECK (codigo ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
              CONSTRAINT servicos_nome       CHECK (length(btrim(nome)) >= 2),
              CONSTRAINT servicos_preco      CHECK (preco_centavos >= 0),
              CONSTRAINT servicos_duracao    CHECK (duracao_minutos > 0 AND duracao_minutos <= 720),
              CONSTRAINT servicos_modalidade CHECK (permite_barbearia OR permite_domicilio)
            );

            -- Quais servicos cada profissional executa. Nao ha preco por
            -- profissional na V1: o preco e do servico.
            CREATE TABLE profissional_servico (
              profissional_id bigint NOT NULL REFERENCES profissionais (id) ON DELETE CASCADE,
              servico_id      bigint NOT NULL REFERENCES servicos (id) ON DELETE CASCADE,
              created_at      timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (profissional_id, servico_id)
            );
            CREATE INDEX profissional_servico_por_servico ON profissional_servico (servico_id);

            CREATE TABLE regioes_atendimento (
              id                   bigserial PRIMARY KEY,
              codigo               varchar(40) NOT NULL UNIQUE,
              nome                 varchar(80) NOT NULL,
              -- Tempo de ida (reservado tambem na volta) em minutos.
              deslocamento_minutos integer NOT NULL,
              taxa_centavos        integer NOT NULL,
              ativo                boolean NOT NULL DEFAULT true,
              ordem                smallint NOT NULL DEFAULT 0,
              created_at           timestamptz NOT NULL DEFAULT now(),
              updated_at           timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT regioes_codigo       CHECK (codigo ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
              CONSTRAINT regioes_nome         CHECK (length(btrim(nome)) >= 2),
              CONSTRAINT regioes_deslocamento CHECK (deslocamento_minutos BETWEEN 0 AND 240),
              CONSTRAINT regioes_taxa         CHECK (taxa_centavos >= 0)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS regioes_atendimento;
            DROP TABLE IF EXISTS profissional_servico;
            DROP TABLE IF EXISTS servicos;
            DROP TABLE IF EXISTS profissionais;
            DROP TABLE IF EXISTS estabelecimento;
            DROP FUNCTION IF EXISTS cleison_validar_fuso();
            -- btree_gist fica: extensao e do banco, nao desta migration, e
            -- remove-la poderia quebrar outro objeto que dependa dela.
        SQL);
    }
};
