<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\RepetirEmConflito;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Repeticao por deadlock/serializacao (docs/ESPEC-RESERVA.md, secao 5), com
 * QueryException REAL do PostgreSQL (RAISE com o SQLSTATE), sem transacao
 * de teste: cada erro acontece em autocommit.
 */
class RepetirEmConflitoTest extends TestCase
{
    /** Levanta de verdade o SQLSTATE $estado pelo PostgreSQL. */
    private function erroReal(string $estado): QueryException
    {
        try {
            DB::select("DO \$\$ BEGIN RAISE EXCEPTION 'erro de teste' USING ERRCODE = '{$estado}'; END \$\$");
        } catch (QueryException $e) {
            return $e;
        }
        $this->fail('o banco nao levantou o erro esperado');
    }

    public function test_deadlock_duas_vezes_e_sucesso_na_terceira(): void
    {
        $tentativas = 0;

        $resultado = (new RepetirEmConflito(esperar: false))->executar(function () use (&$tentativas) {
            $tentativas++;
            if ($tentativas < 3) {
                throw $this->erroReal('40P01');
            }

            return 'gravou';
        });

        $this->assertSame('gravou', $resultado);
        $this->assertSame(3, $tentativas);
    }

    public function test_serializacao_40001_tambem_repete(): void
    {
        $tentativas = 0;

        (new RepetirEmConflito(esperar: false))->executar(function () use (&$tentativas) {
            if (++$tentativas < 2) {
                throw $this->erroReal('40001');
            }
        });

        $this->assertSame(2, $tentativas);
    }

    public function test_sempre_deadlock_relanca_a_ultima_depois_de_tres_tentativas(): void
    {
        $tentativas = 0;

        try {
            (new RepetirEmConflito(esperar: false))->executar(function () use (&$tentativas) {
                $tentativas++;
                throw $this->erroReal('40P01');
            });
            $this->fail('deveria relancar');
        } catch (QueryException $e) {
            $this->assertSame('40P01', $e->errorInfo[0]);
        }

        $this->assertSame(3, $tentativas);
    }

    public function test_outros_erros_nao_repetem(): void
    {
        foreach (['23P01', '23505', '23514'] as $estado) {
            $tentativas = 0;
            try {
                (new RepetirEmConflito(esperar: false))->executar(function () use (&$tentativas, $estado) {
                    $tentativas++;
                    throw $this->erroReal($estado);
                });
                $this->fail('deveria relancar');
            } catch (QueryException $e) {
                $this->assertSame($estado, $e->errorInfo[0]);
            }
            $this->assertSame(1, $tentativas, "SQLSTATE {$estado} nao pode repetir");
        }

        $this->expectException(RuntimeException::class);
        (new RepetirEmConflito(esperar: false))->executar(fn () => throw new RuntimeException('bug'));
    }

    public function test_cada_nova_tentativa_e_logada_sem_dado_pessoal(): void
    {
        Log::spy();

        try {
            (new RepetirEmConflito(esperar: false))->executar(fn () => throw $this->erroReal('40P01'));
        } catch (QueryException) {
            // esperado
        }

        Log::shouldHaveReceived('warning')->twice();
        Log::shouldHaveReceived('warning')->with(\Mockery::type('string'), ['sqlstate' => '40P01', 'tentativa' => 2])->once();
        Log::shouldHaveReceived('warning')->with(\Mockery::type('string'), ['sqlstate' => '40P01', 'tentativa' => 3])->once();
    }

    public function test_a_espera_e_curta_e_aleatoria_e_pode_ser_ligada(): void
    {
        $inicio = hrtime(true);
        $tentativas = 0;

        (new RepetirEmConflito)->executar(function () use (&$tentativas) {
            if (++$tentativas < 3) {
                throw $this->erroReal('40P01');
            }
        });

        $ms = (hrtime(true) - $inicio) / 1e6;
        $this->assertGreaterThanOrEqual(20, $ms, 'duas esperas de 10 a 50 ms');
        $this->assertLessThan(1500, $ms);
    }

    public function test_maximo_de_tentativas_invalido_e_erro_de_programacao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RepetirEmConflito(maximoDeTentativas: 0);
    }
}
