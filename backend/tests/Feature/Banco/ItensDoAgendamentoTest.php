<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * Achado B da revisao externa: nenhuma alteracao de itens pode deixar um
 * agendamento persistido sem servico ou com duracao inconsistente.
 *
 * COMMIT de verdade (sem RefreshDatabase): as conferencias adiadas so
 * disparam no COMMIT. Cada rejeicao e seguida da prova de que agendamentos,
 * itens e ocupacoes continuam exatamente como antes.
 */
class ItensDoAgendamentoTest extends TestCase
{
    use DadosDeAgenda;

    private int $a;

    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limparTabelasDoDominio();

        // Criacao legitima de agendamento + itens na mesma transacao.
        [$this->a, $this->b] = DB::transaction(function () {
            $prof = $this->novoProfissional('Ze');

            return [
                $this->novoAgendamento('10:00', '10:30', ['profissional_id' => $prof]),
                $this->novoAgendamento('11:00', '11:30', ['profissional_id' => $prof]),
            ];
        });
    }

    protected function tearDown(): void
    {
        $this->conexaoDono()->statement('ALTER TABLE agendamento_itens ENABLE TRIGGER agendamento_itens_vinculo_imutavel');
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    private function retrato(): array
    {
        DB::statement("SET TIME ZONE 'UTC'");

        return [
            'agendamentos' => DB::table('agendamentos')->orderBy('id')->get(['id', 'estado', 'inicio_servico', 'fim_servico', 'inicio_ocupado', 'fim_ocupado'])->toArray(),
            'itens' => DB::table('agendamento_itens')->orderBy('id')->get(['id', 'agendamento_id', 'ordem', 'duracao_minutos', 'preco_centavos', 'servico_nome'])->toArray(),
            'ocupacoes' => DB::table('ocupacoes_agenda')->orderBy('id')->get(['agendamento_id', 'periodo'])->toArray(),
        ];
    }

    /**
     * Executa $acao numa transacao que DEVE ser recusada com $regra. Informa
     * se a recusa veio no COMMIT (regra adiada) ou num comando (imediata).
     */
    private function assertRecusadoEm(string $momento, string $regra, callable $acao): void
    {
        $antes = $this->retrato();
        $chegouAoCommit = false;

        try {
            DB::transaction(function () use ($acao, &$chegouAoCommit) {
                $acao();
                $chegouAoCommit = true;
            });
            $this->fail("Transacao aceita; esperava recusa por {$regra}.");
        } catch (PDOException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertStringContainsString($regra, $e->getMessage());
        }

        $this->assertSame($momento === 'commit', $chegouAoCommit,
            $momento === 'commit' ? 'a regra deveria barrar so no COMMIT' : 'a regra deveria barrar ja no comando');
        $this->assertEquals($antes, $this->retrato(), 'a transacao recusada alterou dados');
        $this->assertSame(0, DB::transactionLevel());
    }

    private function itemDe(int $agendamento): int
    {
        return (int) DB::table('agendamento_itens')->where('agendamento_id', $agendamento)->orderBy('ordem')->value('id');
    }

    public function test_criacao_legitima_na_mesma_transacao(): void
    {
        $this->assertSame(1, DB::table('agendamento_itens')->where('agendamento_id', $this->a)->count());
        $this->assertSame(2, DB::table('ocupacoes_agenda')->count());
    }

    public function test_nao_move_o_unico_item_de_a_para_b_mesmo_com_b_ajustado(): void
    {
        $this->assertRecusadoEm('comando', 'agendamento_itens_vinculo_imutavel', function () {
            DB::table('agendamentos')->where('id', $this->b)->update(['fim_servico' => $this->em('12:00'), 'fim_ocupado' => $this->em('12:00')]);
            DB::table('agendamento_itens')->where('id', $this->itemDe($this->a))->update(['agendamento_id' => $this->b, 'ordem' => 2]);
        });
    }

    public function test_nao_move_um_entre_varios_itens(): void
    {
        $c = DB::transaction(fn () => $this->novoAgendamento('14:00', '15:00', [], [
            ['duracao_minutos' => 30, 'servico_nome' => 'Corte'],
            ['duracao_minutos' => 30, 'servico_nome' => 'Barba'],
        ]));
        $segundo = (int) DB::table('agendamento_itens')->where('agendamento_id', $c)->where('ordem', 2)->value('id');

        $this->assertRecusadoEm('comando', 'agendamento_itens_vinculo_imutavel', function () use ($c, $segundo) {
            // A e B "ajustados" para continuarem coerentes sozinhos.
            DB::table('agendamentos')->where('id', $c)->update(['fim_servico' => $this->em('14:30'), 'fim_ocupado' => $this->em('14:30')]);
            DB::table('agendamentos')->where('id', $this->b)->update(['fim_servico' => $this->em('12:00'), 'fim_ocupado' => $this->em('12:00')]);
            DB::table('agendamento_itens')->where('id', $segundo)->update(['agendamento_id' => $this->b, 'ordem' => 2]);
        });
    }

    public function test_segunda_camada_revalida_a_origem_no_commit(): void
    {
        // Desliga so a trava do vinculo (papel dono) para provar que a
        // conferencia adiada, sozinha, agora tambem olha o agendamento de origem.
        $this->conexaoDono()->statement('ALTER TABLE agendamento_itens DISABLE TRIGGER agendamento_itens_vinculo_imutavel');

        $this->assertRecusadoEm('commit', "Agendamento {$this->a} sem nenhum servico", function () {
            DB::table('agendamentos')->where('id', $this->b)->update(['fim_servico' => $this->em('12:00'), 'fim_ocupado' => $this->em('12:00')]);
            DB::table('agendamento_itens')->where('id', $this->itemDe($this->a))->update(['agendamento_id' => $this->b, 'ordem' => 2]);
        });
    }

    public function test_alterar_item_no_mesmo_agendamento_e_permitido(): void
    {
        $item = $this->itemDe($this->a);

        DB::transaction(function () use ($item) {
            DB::table('agendamento_itens')->where('id', $item)->update(['servico_nome' => 'Degrade', 'preco_centavos' => 5000, 'duracao_minutos' => 60]);
            DB::table('agendamentos')->where('id', $this->a)->update(['fim_servico' => $this->em('11:00'), 'fim_ocupado' => $this->em('11:00')]);
        });

        $this->assertSame(5000, DB::table('agendamento_itens')->where('id', $item)->value('preco_centavos'));
    }

    public function test_apagar_o_unico_item_falha_no_commit(): void
    {
        $this->assertRecusadoEm('commit', 'agendamentos_com_servico',
            fn () => DB::table('agendamento_itens')->where('id', $this->itemDe($this->a))->delete());
    }

    public function test_duracao_inconsistente_falha_no_commit(): void
    {
        $this->assertRecusadoEm('commit', 'agendamentos_duracao_dos_itens',
            fn () => DB::table('agendamento_itens')->where('id', $this->itemDe($this->a))->update(['duracao_minutos' => 60]));
    }

    public function test_itens_de_encerrado_continuam_protegidos(): void
    {
        DB::table('agendamentos')->where('id', $this->a)->update(['estado' => 'concluido']);

        $this->assertRecusadoEm('comando', 'agendamento_itens_encerrado_imutavel',
            fn () => DB::table('agendamento_itens')->where('id', $this->itemDe($this->a))->update(['preco_centavos' => 0]));
    }

    public function test_remarcacao_sem_transferir_itens(): void
    {
        $item = $this->itemDe($this->a);

        DB::transaction(fn () => DB::table('agendamentos')->where('id', $this->a)->update([
            'inicio_servico' => $this->em('15:00'), 'fim_servico' => $this->em('15:30'),
            'inicio_ocupado' => $this->em('15:00'), 'fim_ocupado' => $this->em('15:30'),
        ]));

        $this->assertSame($this->a, (int) DB::table('agendamento_itens')->where('id', $item)->value('agendamento_id'));
        $this->assertSame(1, DB::table('ocupacoes_agenda')->where('agendamento_id', $this->a)
            ->whereRaw("periodo = tstzrange(?::timestamptz, ?::timestamptz, '[)')", [$this->em('15:00'), $this->em('15:30')])->count());
    }
}
