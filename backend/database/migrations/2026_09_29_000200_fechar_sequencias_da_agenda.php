<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Complemento da 2026_09_29_000100: sem INSERT em ocupacoes_agenda e
 * agendamento_eventos, a aplicacao nao precisa das sequencias dessas
 * tabelas. Com USAGE/UPDATE ela so conseguiria queimar ids (nextval,
 * setval). Quem gera os ids e o dono, pelos triggers SECURITY DEFINER.
 *
 * REVOKE ALL de todo papel (inclusive PUBLIC) que nao seja o dono da
 * sequencia, como na 000100.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE
              r record;
            BEGIN
              FOR r IN
                SELECT DISTINCT c.oid::regclass AS sequencia, a.grantee
                  FROM pg_catalog.pg_class c
                 CROSS JOIN LATERAL pg_catalog.aclexplode(c.relacl) a
                 WHERE c.oid IN (pg_catalog.pg_get_serial_sequence('public.ocupacoes_agenda', 'id')::regclass,
                                 pg_catalog.pg_get_serial_sequence('public.agendamento_eventos', 'id')::regclass)
                   AND a.grantee <> c.relowner
              LOOP
                EXECUTE pg_catalog.format('REVOKE ALL ON SEQUENCE %s FROM %s', r.sequencia,
                  CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE pg_catalog.quote_ident(pg_catalog.pg_get_userbyid(r.grantee)) END);
              END LOOP;
            END $$;
        SQL);
    }

    /**
     * Devolve USAGE/SELECT (o que ALTER DEFAULT PRIVILEGES dava) SO ao
     * papel da aplicacao configurado, nao a qualquer papel que exista.
     */
    public function down(): void
    {
        $papel = (string) config('database.connections.pgsql.username');
        if ($papel === '') {
            return;
        }

        $papelSql = DB::scalar('SELECT pg_catalog.quote_ident(?)', [$papel]);
        foreach (['ocupacoes_agenda', 'agendamento_eventos'] as $tabela) {
            $sequencia = DB::scalar('SELECT pg_catalog.pg_get_serial_sequence(?, ?)', ["public.{$tabela}", 'id']);
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$sequencia} TO {$papelSql}");
        }
    }
};
