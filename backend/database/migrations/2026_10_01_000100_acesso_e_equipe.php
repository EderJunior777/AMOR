<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Etapa 3, Fase 1: acesso e equipe.
 *
 *   users.senha_temporaria   a senha foi gerada pelo proprietario: o painel
 *                            exige a troca no primeiro acesso (Fase 2).
 *   users.ultimo_acesso_em   instante do ultimo login com sucesso.
 *
 *   auditoria_acessos        quem entrou, quem saiu, quem falhou, e a gestao
 *                            da equipe (criar, desativar, reativar, redefinir
 *                            senha). SO insercao para a aplicacao:
 *                              - o trigger recusa UPDATE e DELETE, ate para o
 *                                dono;
 *                              - o papel da aplicacao nao tem UPDATE, DELETE
 *                                nem TRUNCATE e so insere as colunas de
 *                                conteudo (nao escolhe id nem ocorrido_em: a
 *                                data e a hora sao do banco).
 *                            O DONO ainda pode TRUNCATE, DROP e desligar o
 *                            trigger (como em anonimizacoes): isso e protegido
 *                            pela separacao de papeis (so o pipeline de
 *                            deploy tem a senha do dono), nao por esta tabela.
 *
 * Nenhum dado pessoal novo:
 *   - NUNCA ha senha nem IP (o limite por IP existe so no cache);
 *   - email_tentado so existe em falha ou bloqueio de login de um e-mail que
 *     NAO pertence a nenhum usuario (quando ha usuario, vale usuario_id).
 *     A garantia de que uma senha digitada por engano no campo de e-mail nao
 *     chega aqui e da APLICACAO (descarta o campo se o usuario existe ou se
 *     o formato nao for o de e-mail). O CHECK so barra o que claramente nao e
 *     e-mail (sem arroba, com espaco, dominio sem sufixo alfabetico) e nao
 *     separa senha de e-mail pelo formato: texto como "a@b.com" passa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Falha rapido em vez de enfileirar o login atras de uma transacao longa
        // (o ALTER pede ACCESS EXCLUSIVE em users).
        DB::unprepared("SET LOCAL lock_timeout = '5s'");

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.users
              ADD COLUMN senha_temporaria boolean NOT NULL DEFAULT false,
              ADD COLUMN ultimo_acesso_em timestamptz;

            CREATE TABLE public.auditoria_acessos (
              id             bigserial PRIMARY KEY,
              -- Quem entrou/saiu/falhou, ou o alvo de uma acao de gestao.
              usuario_id     bigint REFERENCES public.users (id) ON DELETE RESTRICT,
              -- Quem executou a acao de gestao (proprietario); nulo em acesso.
              autor_id       bigint REFERENCES public.users (id) ON DELETE RESTRICT,
              evento         varchar(30) NOT NULL,
              resultado      varchar(20) NOT NULL,
              email_tentado  varchar(254),
              ocorrido_em    timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT auditoria_acessos_evento CHECK (evento IN (
                'login', 'logout',
                'usuario_criado', 'usuario_desativado', 'usuario_reativado',
                'senha_redefinida', 'senha_trocada')),
              CONSTRAINT auditoria_acessos_resultado CHECK (resultado IN ('sucesso', 'falha', 'bloqueado', 'logout')),
              -- logout e o unico evento com resultado "logout"; so login falha ou bloqueia.
              CONSTRAINT auditoria_acessos_logout_coerente CHECK ((evento = 'logout') = (resultado = 'logout')),
              CONSTRAINT auditoria_acessos_so_login_falha CHECK (resultado NOT IN ('falha', 'bloqueado') OR evento = 'login'),
              -- Gestao sempre diz quem fez e em quem.
              CONSTRAINT auditoria_acessos_gestao_identificada CHECK (
                evento NOT IN ('usuario_criado', 'usuario_desativado', 'usuario_reativado', 'senha_redefinida')
                OR (autor_id IS NOT NULL AND usuario_id IS NOT NULL AND resultado = 'sucesso')),
              -- Acesso nao tem autor.
              CONSTRAINT auditoria_acessos_acesso_sem_autor CHECK (evento NOT IN ('login', 'logout', 'senha_trocada') OR autor_id IS NULL),
              -- E-mail: so em falha ou bloqueio de login sem usuario, em formato de
              -- e-mail ja normalizado (minusculo, sem espacos, sufixo alfabetico).
              CONSTRAINT auditoria_acessos_email_so_na_falha CHECK (
                email_tentado IS NULL
                OR (evento = 'login' AND resultado IN ('falha', 'bloqueado') AND usuario_id IS NULL
                    AND email_tentado ~ '^[^@\s]+@[a-z0-9.-]+\.[a-z]{2,}$'
                    AND email_tentado = lower(btrim(email_tentado)))),
              -- Quem saiu ou trocou a senha e identificado; login com sucesso tambem.
              CONSTRAINT auditoria_acessos_saida_e_troca_identificadas CHECK (
                evento NOT IN ('logout', 'senha_trocada') OR usuario_id IS NOT NULL),
              CONSTRAINT auditoria_acessos_login_sucesso_identificado CHECK (
                NOT (evento = 'login' AND resultado = 'sucesso') OR usuario_id IS NOT NULL)
            );
            CREATE INDEX auditoria_acessos_por_usuario ON public.auditoria_acessos (usuario_id, ocorrido_em);
            CREATE INDEX auditoria_acessos_por_autor ON public.auditoria_acessos (autor_id) WHERE autor_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION public.cleison_auditoria_acessos_somente_insercao() RETURNS trigger
              LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
              RAISE EXCEPTION '[auditoria_acessos_somente_insercao] A auditoria de acessos nao pode ser alterada nem apagada'
                USING ERRCODE = 'check_violation', CONSTRAINT = 'auditoria_acessos_somente_insercao';
            END $$;

            CREATE TRIGGER auditoria_acessos_imutavel
              BEFORE UPDATE OR DELETE ON public.auditoria_acessos
              FOR EACH ROW EXECUTE FUNCTION public.cleison_auditoria_acessos_somente_insercao();

            -- Fecha tudo para qualquer papel que nao seja o dono, inclusive PUBLIC;
            -- logo abaixo a aplicacao recebe so o INSERT das colunas de conteudo.
            DO $$
            DECLARE
              r record;
            BEGIN
              FOR r IN
                SELECT DISTINCT a.grantee
                  FROM pg_catalog.pg_class c
                 CROSS JOIN LATERAL pg_catalog.aclexplode(c.relacl) a
                 WHERE c.oid = 'public.auditoria_acessos'::regclass
                   AND a.grantee <> c.relowner
                   AND a.privilege_type IN ('INSERT', 'UPDATE', 'DELETE', 'TRUNCATE')
              LOOP
                EXECUTE pg_catalog.format('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.auditoria_acessos FROM %s',
                  CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE pg_catalog.quote_ident(pg_catalog.pg_get_userbyid(r.grantee)) END);
              END LOOP;
            END $$;
        SQL);

        // A aplicacao insere (login, logout, falha) e le, nada alem. INSERT por
        // coluna: id (sequencia) e ocorrido_em (now()) nao sao dela. O USAGE da
        // sequencia vem dos default privileges do papel (backend/README.md).
        $papel = (string) config('database.connections.pgsql.username');
        $papelSql = DB::scalar('SELECT pg_catalog.quote_ident(?)', [$papel]);
        DB::statement("GRANT INSERT (usuario_id, autor_id, evento, resultado, email_tentado) ON public.auditoria_acessos TO {$papelSql}");
        DB::statement("GRANT SELECT ON public.auditoria_acessos TO {$papelSql}");
    }

    /**
     * Recusa desfazer com registros: a auditoria e imutavel, e apagar a tabela
     * apagaria a trilha em silencio.
     */
    public function down(): void
    {
        if ((bool) DB::scalar("SELECT to_regclass('public.auditoria_acessos') IS NOT NULL")
            && (int) DB::scalar('SELECT count(*) FROM public.auditoria_acessos') > 0) {
            throw new RuntimeException('Ha registros em auditoria_acessos; desfazer esta migration apagaria a trilha de auditoria.');
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS public.auditoria_acessos;
            DROP FUNCTION IF EXISTS public.cleison_auditoria_acessos_somente_insercao();
            ALTER TABLE public.users
              DROP COLUMN IF EXISTS ultimo_acesso_em,
              DROP COLUMN IF EXISTS senha_temporaria;
        SQL);
    }
};
