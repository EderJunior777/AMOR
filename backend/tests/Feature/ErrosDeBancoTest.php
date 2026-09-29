<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Erros do PostgreSQL viram respostas HTTP por SQLSTATE, sem a mensagem do
 * banco na resposta e sem os parametros da query no log (PII).
 * Excecoes simuladas com o formato real do PostgreSQL/PDO.
 */
class ErrosDeBancoTest extends TestCase
{
    /** Valores pessoais (ficticios) que estariam nos bindings/DETAIL: nao podem sair. */
    private const PII = ['Fulano da Silva', '+5511999990000', 'Rua Secreta 42'];

    private function pdo(string $sqlstate, string $mensagemPg): PDOException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlstate}]: erro: 7 {$mensagemPg}");
        $pdo->errorInfo = [$sqlstate, 7, $mensagemPg];

        return $pdo;
    }

    private function queryException(string $sqlstate, string $mensagemPg): QueryException
    {
        return new QueryException(
            'pgsql',
            'insert into clientes (nome, telefone, observacoes) values (?, ?, ?)',
            self::PII,
            $this->pdo($sqlstate, $mensagemPg),
        );
    }

    private function lancarEmRota(Throwable $e): TestResponse
    {
        Route::get('/_teste/erro-banco', fn () => throw $e);

        return $this->getJson('/_teste/erro-banco');
    }

    private function assertSemVazamento(string $conteudo): void
    {
        foreach ([...self::PII, 'DETAIL', 'SQLSTATE', 'insert into', 'ocupacoes_', 'agendamento 42'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $conteudo);
        }
    }

    /** @return array<string, array{string, string, int, string, string}> */
    public static function casos(): array
    {
        return [
            'exclusao (horario ocupado)' => ['23P01',
                "ERROR:  conflicting key value violates exclusion constraint \"ocupacoes_sem_sobreposicao\"\nDETAIL:  Key (profissional_id, periodo)=(3, [\"2026-10-01 13:00:00+00\",\"2026-10-01 13:30:00+00\")) conflicts with existing key.",
                409, 'horario_indisponivel', 'Horario indisponivel.'],
            'unica conhecida' => ['23505',
                "ERROR:  duplicate key value violates unique constraint \"clientes_telefone_unico\"\nDETAIL:  Key (telefone)=(+5511999990000) already exists.",
                422, 'dados_invalidos', 'Ja existe um cliente com este telefone.'],
            'check de trigger conhecido' => ['23514',
                'ERROR:  [agendamentos_transicao_estado] Transicao de concluido para cancelado nao permitida (agendamento 42)',
                422, 'dados_invalidos', 'Mudanca de estado nao permitida.'],
            'anonimizacao sem proprietario' => ['23514',
                'ERROR:  [anonimizacao_exige_proprietario] Anonimizacao exige usuario proprietario ativo',
                422, 'dados_invalidos', 'So um proprietario ativo pode anonimizar clientes.'],
            'check desconhecido' => ['23514',
                "ERROR:  new row for relation \"x\" violates check constraint \"regra_nova\"\nDETAIL:  Failing row contains (Fulano da Silva).",
                422, 'dados_invalidos', 'Os dados nao atendem as regras da agenda.'],
            'fk' => ['23503',
                "ERROR:  insert or update on table \"agendamentos\" violates foreign key constraint \"agendamentos_cliente_id_fkey\"\nDETAIL:  Key (cliente_id)=(42) is not present.",
                422, 'dados_invalidos', 'Referencia invalida ou registro em uso.'],
            'deadlock' => ['40P01', 'ERROR:  deadlock detected', 503, 'tente_novamente', 'Muitas operacoes ao mesmo tempo. Tente novamente em instantes.'],
            'serializacao' => ['40001', 'ERROR:  could not serialize access', 503, 'tente_novamente', 'Muitas operacoes ao mesmo tempo. Tente novamente em instantes.'],
            'nao mapeado' => ['42P01', 'ERROR:  relation "clientes_x" does not exist', 500, 'erro_interno', 'Erro interno.'],
        ];
    }

    #[DataProvider('casos')]
    public function test_query_exception_vira_resposta_sem_vazar(string $sqlstate, string $pg, int $status, string $codigo, string $mensagem): void
    {
        $resposta = $this->lancarEmRota($this->queryException($sqlstate, $pg));

        $resposta->assertStatus($status)->assertJson(['codigo' => $codigo, 'mensagem' => $mensagem]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $resposta->json('correlacao'));
        $this->assertSemVazamento((string) $resposta->getContent());

        if ($status === 503) {
            $resposta->assertHeader('Retry-After', '1');
        }
    }

    /** O COMMIT lanca PDOException crua (conferencia adiada), nao QueryException. */
    public function test_pdo_exception_crua_do_commit_tambem_e_traduzida(): void
    {
        $resposta = $this->lancarEmRota($this->pdo('23514',
            'ERROR:  [agendamentos_com_servico] Agendamento 42 sem nenhum servico'));

        $resposta->assertStatus(422)->assertJson(['mensagem' => 'O agendamento precisa de pelo menos um servico.']);
        $this->assertSemVazamento((string) $resposta->getContent());
    }

    public function test_log_tem_sqlstate_constraint_e_correlacao_sem_pii(): void
    {
        Log::spy();

        $resposta = $this->lancarEmRota($this->queryException('23505',
            "ERROR:  duplicate key value violates unique constraint \"clientes_telefone_unico\"\nDETAIL:  Key (telefone)=(+5511999990000) already exists."));

        Log::shouldHaveReceived('log')->once()->withArgs(function (string $nivel, string $mensagem, array $contexto) use ($resposta) {
            $this->assertSame('warning', $nivel);
            $this->assertSame('23505', $contexto['sqlstate']);
            $this->assertSame('clientes_telefone_unico', $contexto['constraint']);
            $this->assertSame($resposta->json('correlacao'), $contexto['correlacao']);
            $this->assertSemVazamento($mensagem.json_encode($contexto));

            return true;
        });
        // O log padrao do Laravel (mensagem com SQL e bindings) nao roda.
        Log::shouldNotHaveReceived('error');
    }

    /** Defesa extra: a mensagem da QueryException ja nao interpola os bindings. */
    public function test_conexoes_pgsql_mascaram_bindings_na_mensagem(): void
    {
        foreach (['pgsql', 'pgsql_migracao'] as $conexao) {
            $this->assertTrue(config("database.connections.{$conexao}.mask_bindings_in_exception_messages"), $conexao);
        }
    }
}
