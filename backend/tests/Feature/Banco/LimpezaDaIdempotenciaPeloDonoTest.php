<?php

namespace Tests\Feature\Banco;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * Fase 6: a excecao da limpeza no trigger de imutabilidade do encerrado e
 * ESTREITA. Mesmo para o dono (que a funcao cleison_limpar_idempotencia
 * usa), so passa a troca exata: chave_idempotencia e hash_requisicao indo a
 * NULL juntos, nada mais. Dados COMMITADOS: a conexao do dono nao
 * enxergaria uma transacao de teste aberta.
 */
class LimpezaDaIdempotenciaPeloDonoTest extends TestCase
{
    use DadosDeAgenda;

    private const CHAVE = 'chave-dono-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => self::CONEXAO_DONO]);
        $this->limparTabelasDoDominio();
    }

    protected function tearDown(): void
    {
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    private function canceladoComChave(): int
    {
        // Agendamento e itens na MESMA transacao (conferencia adiada no COMMIT).
        return DB::transaction(function () {
            $id = $this->novoAgendamento('08:00', '08:30', [
                'chave_idempotencia' => self::CHAVE,
                'hash_requisicao' => str_repeat('ab', 32),
                'observacao_cliente' => 'chego cedo',
            ]);
            DB::table('agendamentos')->where('id', $id)->update(['estado' => 'cancelado', 'cancelado_em' => now()]);

            return $id;
        });
    }

    /** @param callable(Connection): mixed $acao */
    private function assertRecusadoPelaImutabilidade(Connection $conexao, callable $acao): void
    {
        try {
            $conexao->transaction(fn () => $acao($conexao));
            $this->fail('O banco aceitou o que agendamentos_encerrado_imutavel deveria impedir.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('agendamentos_encerrado_imutavel', $e->getMessage());
            $this->assertSame('23514', $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }

    public function test_dono_passa_so_chave_e_hash_indo_a_nulo_juntos(): void
    {
        $id = $this->canceladoComChave();
        $dono = $this->conexaoDono();

        // Mais uma coluna junto (fora das duas excecoes): recusado.
        $this->assertRecusadoPelaImutabilidade($dono, fn (Connection $c) => $c->table('agendamentos')->where('id', $id)
            ->update(['chave_idempotencia' => null, 'hash_requisicao' => null, 'motivo_cancelamento' => 'outro motivo']));
        // Trocar so o hash por outro valor: recusado.
        $this->assertRecusadoPelaImutabilidade($dono, fn (Connection $c) => $c->table('agendamentos')->where('id', $id)
            ->update(['hash_requisicao' => str_repeat('cd', 32)]));
        // Trocar a chave por outra (em vez de anular): recusado.
        $this->assertRecusadoPelaImutabilidade($dono, fn (Connection $c) => $c->table('agendamentos')->where('id', $id)
            ->update(['chave_idempotencia' => 'outra-chave-0123456789', 'hash_requisicao' => str_repeat('cd', 32)]));

        // A troca exata: passa.
        $dono->table('agendamentos')->where('id', $id)->update(['chave_idempotencia' => null, 'hash_requisicao' => null]);

        $linha = DB::table('agendamentos')->where('id', $id)->first();
        $this->assertNull($linha->chave_idempotencia);
        $this->assertNull($linha->hash_requisicao);
        $this->assertSame('chego cedo', $linha->observacao_cliente);
    }

    public function test_a_aplicacao_nao_passa_nem_a_troca_exata(): void
    {
        $id = $this->canceladoComChave();

        $this->assertRecusadoPelaImutabilidade(DB::connection(), fn (Connection $c) => $c->table('agendamentos')->where('id', $id)
            ->update(['chave_idempotencia' => null, 'hash_requisicao' => null]));
        $this->assertSame(self::CHAVE, DB::table('agendamentos')->where('id', $id)->value('chave_idempotencia'));
    }

    public function test_a_excecao_da_anonimizacao_continua_valendo(): void
    {
        $id = $this->canceladoComChave();

        $this->conexaoDono()->table('agendamentos')->where('id', $id)->update([
            'observacao_cliente' => null, 'motivo_cancelamento' => null,
            'chave_idempotencia' => null, 'hash_requisicao' => null,
        ]);

        $this->assertNull(DB::table('agendamentos')->where('id', $id)->value('observacao_cliente'));
    }
}
