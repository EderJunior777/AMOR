<?php

namespace Tests;

use App\Support\AlvoDescartavel;
use App\Support\AlvoNaoAutorizado;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** Conexao do papel dono (migrations e limpeza dos testes). */
    public const CONEXAO_DONO = 'pgsql_migracao';

    /**
     * Trava de seguranca, antes de qualquer migrate:fresh do RefreshDatabase
     * e de qualquer TRUNCATE/ALTER dos testes. Verifica as DUAS conexoes que
     * os testes usam (aplicacao e dono) pela conexao efetiva, no servidor
     * (App\Support\AlvoDescartavel), e recusa:
     *   - configuracao em cache (ignoraria .env.testing e phpunit.xml);
     *   - ambiente diferente de "testing";
     *   - alvo sem marca de descartavel, driver diferente de pgsql, bancos
     *     diferentes entre as conexoes, falha de conexao;
     *   - aplicacao e dono com o MESMO usuario (os testes precisam exercitar
     *     o papel sem DDL).
     * Qualquer recusa aborta a execucao antes de tocar no banco.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if ($app->configurationIsCached()) {
            throw new AlvoNaoAutorizado('Configuracao em cache: rode "php artisan config:clear" antes dos testes.');
        }
        if (! $app->environment('testing')) {
            throw new AlvoNaoAutorizado("Testes exigem APP_ENV=testing (atual: {$app->environment()}).");
        }

        $identidades = AlvoDescartavel::exigir($app['db'], [$app['config']->get('database.default'), self::CONEXAO_DONO]);

        $usuarios = array_unique(array_map(fn ($i) => $i->usuario, $identidades));
        if (count($usuarios) !== count($identidades)) {
            throw new AlvoNaoAutorizado('Aplicacao e migrations usam o mesmo usuario do banco; os testes exigem papeis separados.');
        }

        return $app;
    }

    /**
     * Unico caminho para os testes usarem o papel dono (TRUNCATE, ALTER
     * TABLE...): revalida o alvo na hora e so entao devolve a conexao.
     */
    protected function conexaoDono(): Connection
    {
        AlvoDescartavel::exigir($this->app['db'], [config('database.default'), self::CONEXAO_DONO]);

        return DB::connection(self::CONEXAO_DONO);
    }

    /** Esvazia as tabelas do dominio (testes com COMMIT real). */
    protected function limparTabelasDoDominio(): void
    {
        $this->conexaoDono()->statement(
            'TRUNCATE ocupacoes_agenda, agendamento_eventos, agendamento_itens, agendamentos, bloqueios_agenda, '
            .'enderecos_cliente, clientes, profissional_servico, expedientes_semanais, excecoes_expediente, '
            .'servicos, regioes_atendimento, profissionais, estabelecimento, users RESTART IDENTITY CASCADE'
        );
    }

    /**
     * Executa $acao dentro de um savepoint e exige que o banco a recuse
     * pela regra $constraint: o nome tem que aparecer na mensagem E o
     * SQLSTATE tem que ser o daquele tipo de regra. Sem $sqlstate explicito,
     * ele e deduzido do catalogo (pg_constraint.contype); nomes que nao
     * estao no catalogo sao regras de trigger, que levantam check_violation.
     * O savepoint desfaz so a tentativa: a transacao do teste segue valida.
     */
    protected function assertBancoRecusa(string $constraint, callable $acao, ?string $sqlstate = null): void
    {
        $esperados = $sqlstate !== null ? [$sqlstate] : $this->sqlstatesDaRegra($constraint);
        $this->assertBancoRecusaCom($constraint, $acao, $esperados);
    }

    /** @return list<string> */
    private function sqlstatesDaRegra(string $constraint): array
    {
        $tipo = DB::table('pg_constraint')->where('conname', $constraint)->value('contype');

        return match ($tipo) {
            'c' => ['23514'],            // check_violation
            'x' => ['23P01'],            // exclusion_violation
            'u', 'p' => ['23505'],       // unique_violation
            'f' => ['23503', '23001'],   // foreign_key_violation / restrict_violation (DELETE)
            null => ['23514'],           // trigger do projeto (RAISE ... check_violation)
            default => $this->fail("Tipo de constraint inesperado para {$constraint}: {$tipo}"),
        };
    }

    /** @param list<string> $sqlstates */
    private function assertBancoRecusaCom(string $constraint, callable $acao, array $sqlstates): void
    {
        try {
            DB::transaction(function () use ($acao) {
                $acao();
                // Dispara agora as conferencias adiadas para o COMMIT.
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                $constraint,
                $e->getMessage(),
                "O banco recusou, mas por outra regra:\n".$e->getMessage()
            );
            $this->assertContains($e->errorInfo[0] ?? null, $sqlstates,
                "Recusado por {$constraint}, mas com SQLSTATE inesperado:\n".$e->getMessage());

            return;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }
        }

        $this->fail("O banco aceitou uma operacao que a regra {$constraint} deveria impedir.");
    }
}
