<?php

namespace Tests\Feature\Banco;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\CenarioDeAnonimizacao;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * A excecao dos triggers de imutabilidade vale para o dono, mas so para a
 * troca exata do dado pessoal; e duas anonimizacoes simultaneas entram em
 * fila. Dados COMMITADOS (como no EscritaDiretaNaAgendaTest): a conexao do
 * dono e a segunda conexao nao enxergariam uma transacao de teste aberta.
 */
class AnonimizacaoPeloDonoTest extends TestCase
{
    use CenarioDeAnonimizacao, DadosDeAgenda;

    private const TROCA_EXATA = [
        'endereco_texto' => '[anonimizado]', 'observacao_cliente' => null, 'motivo_cancelamento' => null,
        'chave_idempotencia' => null, 'hash_requisicao' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => self::CONEXAO_DONO]);
        $this->limparTabelasDoDominio();
        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('pgsql_b');
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    /** @param callable(Connection): mixed $acao */
    private function assertRecusado(Connection $conexao, string $regra, callable $acao): void
    {
        try {
            $conexao->transaction(fn () => $acao($conexao));
            $this->fail("O banco aceitou o que {$regra} deveria impedir.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($regra, $e->getMessage(), "Recusado, mas por outra regra:\n".$e->getMessage());
            $this->assertSame('23514', $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }

    public function test_dono_so_passa_a_troca_exata_do_dado_pessoal(): void
    {
        $c = $this->clienteComHistorico();
        $outroProfissional = $this->novoProfissional('Outro');
        $outroCliente = $this->novoCliente('Outro Cliente');
        $dono = $this->conexaoDono();

        $desvios = [
            'profissional' => ['profissional_id' => $outroProfissional],
            'cliente' => ['cliente_id' => $outroCliente],
            'taxa' => ['taxa_deslocamento_centavos' => 2500],
            'origem' => ['origem' => 'whatsapp'],
            'horario' => ['inicio_servico' => $this->em('12:00'), 'fim_servico' => $this->em('12:30'),
                'inicio_ocupado' => $this->em('11:30'), 'fim_ocupado' => $this->em('13:00')],
            'dado pessoal com outro valor' => ['endereco_texto' => 'Outro endereco qualquer'],
        ];
        foreach ($desvios as $desvio) {
            // $desvio primeiro: no "+" do PHP vale a chave da esquerda.
            $this->assertRecusado($dono, 'agendamentos_encerrado_imutavel',
                fn (Connection $d) => $d->table('agendamentos')->where('id', $c['domicilio'])->update($desvio + self::TROCA_EXATA));
        }
        $this->assertRecusado($dono, 'agendamento_itens_encerrado_imutavel',
            fn (Connection $d) => $d->table('agendamento_itens')->where('agendamento_id', $c['domicilio'])->update(['preco_centavos' => 1]));

        // A troca exata passa para o dono (e so para ele: AnonimizacaoTest).
        $this->assertSame(1, $dono->table('agendamentos')->where('id', $c['domicilio'])->update(self::TROCA_EXATA));
    }

    public function test_dono_so_tira_texto_livre_do_historico_e_nunca_apaga(): void
    {
        $c = $this->clienteComHistorico();
        $dono = $this->conexaoDono();
        $cancelamento = $dono->table('agendamento_eventos')
            ->where('agendamento_id', $c['cancelado'])->where('tipo', 'estado_alterado')->value('id');
        $anonimo = DB::raw('cleison_evento_dados_anonimos(dados)');

        $this->assertRecusado($dono, 'agendamento_eventos_somente_insercao',
            fn (Connection $d) => $d->table('agendamento_eventos')->where('id', $cancelamento)->update(['dados' => $anonimo, 'ator' => 'cliente']));
        $this->assertRecusado($dono, 'agendamento_eventos_somente_insercao',
            fn (Connection $d) => $d->table('agendamento_eventos')->where('id', $cancelamento)->update(['dados' => '{"motivo": "outro texto"}']));
        $this->assertRecusado($dono, 'agendamento_eventos_somente_insercao',
            fn (Connection $d) => $d->table('agendamento_eventos')->where('id', $cancelamento)->delete());

        $this->assertSame(1, $dono->table('agendamento_eventos')->where('id', $cancelamento)->update(['dados' => $anonimo]));
        $this->assertSame('{}', $dono->table('agendamento_eventos')->where('id', $cancelamento)->value('dados'));
    }

    public function test_registro_de_anonimizacao_nao_muda_nem_some_nem_para_o_dono(): void
    {
        $cliente = $this->novoCliente('Sem Historico');
        $this->anonimizar($cliente, $this->proprietario()->id);
        $dono = $this->conexaoDono();

        $this->assertRecusado($dono, 'anonimizacoes_somente_insercao',
            fn (Connection $d) => $d->table('anonimizacoes')->update(['protocolo' => 'OUTRO']));
        $this->assertRecusado($dono, 'anonimizacoes_somente_insercao',
            fn (Connection $d) => $d->table('anonimizacoes')->delete());
        $this->assertRecusado($dono, 'clientes_anonimizado_imutavel',
            fn (Connection $d) => $d->table('clientes')->where('id', $cliente)->update(['anonimizado_em' => null]));
    }

    public function test_chamadas_simultaneas_entram_em_fila_e_so_uma_registra(): void
    {
        $c = $this->clienteComHistorico();
        $usuario = $this->proprietario()->id;
        $a = DB::connection();
        $b = DB::connection('pgsql_b');
        $chamar = fn (Connection $conexao) => $conexao->scalar(
            'SELECT public.cleison_anonimizar_cliente(?, ?, ?, ?)', [$c['cliente'], $usuario, 'pedido_titular', 'P-1']);

        $a->beginTransaction();
        try {
            $this->assertNotNull($chamar($a)); // segura o cliente (FOR UPDATE)

            // B espera pela trava de A (aqui, ate o lock_timeout).
            $b->statement("SET lock_timeout = '300ms'");
            try {
                $chamar($b);
                $this->fail('A segunda chamada nao esperou a primeira.');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0] ?? null, $e->getMessage()); // lock_not_available
            }

            $a->commit();
        } finally {
            if ($a->transactionLevel() > 0) {
                $a->rollBack();
            }
        }

        $this->assertNull($chamar($b)); // depois do COMMIT: ja anonimizado
        $this->assertSame(1, DB::table('anonimizacoes')->where('cliente_id', $c['cliente'])->count());
    }
}
