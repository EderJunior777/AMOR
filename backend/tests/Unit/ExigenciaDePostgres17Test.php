<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * No PG 16, um instante 'infinity' falha com 22008 (aritmetica de
 * timestamp/intervalo infinito) ANTES de chegar a constraint
 * agendamentos_instantes_finitos. O esquema depende do PG 17+, entao a
 * migration 2026_09_29_000100 recusa servidores mais antigos.
 */
class ExigenciaDePostgres17Test extends TestCase
{
    private function migracao(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_09_29_000100_fechar_escrita_direta_na_agenda.php';
    }

    public function test_recusa_postgresql_16(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PostgreSQL 17');

        $this->migracao()->exigirPostgres17(160004);
    }

    public function test_aceita_17_e_18(): void
    {
        $this->migracao()->exigirPostgres17(170000);
        $this->migracao()->exigirPostgres17(180006);
        $this->addToAssertionCount(2);
    }
}
