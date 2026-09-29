<?php

namespace Tests\Feature\Banco;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * Escrita "por fora" em ocupacoes_agenda e agendamento_eventos, pelos dois
 * papeis do banco:
 *   - aplicacao: nao tem INSERT/UPDATE/DELETE (42501);
 *   - dono: tem, mas os triggers barram (defesa em profundidade para um
 *     script de manutencao errado).
 *
 * Regressao da brecha reproduzida na revisao externa: com o papel da
 * aplicacao, um INSERT direto criava uma ocupacao apontando para um
 * agendamento CANCELADO (a FK composta aceita, porque o cancelado mantem
 * periodo_ocupado), bloqueando o horario sem ninguem marcado.
 *
 * Dados COMMITADOS (como no ConcorrenciaTest): a conexao do dono e outra
 * sessao e nao enxergaria dados de uma transacao de teste aberta.
 */
class EscritaDiretaNaAgendaTest extends TestCase
{
    use DadosDeAgenda;

    private int $ze;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => self::CONEXAO_DONO]);
        $this->limparTabelasDoDominio();
        $this->ze = $this->novoProfissional('Ze');
    }

    protected function tearDown(): void
    {
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    private function agendar(string $inicio = '10:00', string $fim = '10:30'): int
    {
        return DB::transaction(fn () => $this->novoAgendamento($inicio, $fim, ['profissional_id' => $this->ze]));
    }

    private function cancelar(int $agendamento): void
    {
        DB::table('agendamentos')->where('id', $agendamento)->update(['estado' => 'cancelado', 'cancelado_em' => now()]);
    }

    private function bloquear(string $inicio, string $fim): int
    {
        return DB::table('bloqueios_agenda')->insertGetId([
            'profissional_id' => $this->ze, 'tipo' => 'compromisso',
            'inicio' => $this->em($inicio), 'fim' => $this->em($fim),
        ]);
    }

    /** Linha de ocupacao "fantasma" coerente com a FK composta do pai. */
    private function ocupacaoPara(string $coluna, int $id, string $tabelaPai, string $colunaPeriodo): array
    {
        return [
            'profissional_id' => $this->ze,
            $coluna => $id,
            'periodo' => DB::raw("(SELECT {$colunaPeriodo} FROM {$tabelaPai} WHERE id = {$id})"),
        ];
    }

    /** @param callable(Connection): mixed $acao */
    private function assertRecusado(Connection $conexao, string $regra, string $sqlstate, callable $acao): void
    {
        try {
            $conexao->transaction(function () use ($conexao, $acao) {
                $acao($conexao);
                $conexao->statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertStringContainsString($regra, $e->getMessage(), "Recusado, mas por outra regra:\n".$e->getMessage());
            $this->assertSame($sqlstate, $e->errorInfo[0] ?? null, "SQLSTATE inesperado:\n".$e->getMessage());

            return;
        }

        $this->fail("O banco aceitou uma escrita que {$regra} deveria impedir.");
    }

    public function test_ocupacao_fantasma_de_agendamento_cancelado_e_recusada(): void
    {
        $id = $this->agendar();
        $this->cancelar($id);
        $fantasma = $this->ocupacaoPara('agendamento_id', $id, 'agendamentos', 'periodo_ocupado');

        $this->assertRecusado(DB::connection(), 'ocupacoes_agenda', '42501',
            fn (Connection $c) => $c->table('ocupacoes_agenda')->insert($fantasma));
        $this->assertRecusado($this->conexaoDono(), 'ocupacoes_pai_ativo', '23514',
            fn (Connection $c) => $c->table('ocupacoes_agenda')->insert($fantasma));

        // O horario continua livre.
        $this->assertSame(0, DB::table('ocupacoes_agenda')->count());
        $this->agendar();
        $this->assertSame(1, DB::table('ocupacoes_agenda')->count());
    }

    public function test_ocupacao_fantasma_de_bloqueio_cancelado_e_recusada(): void
    {
        $bloqueio = $this->bloquear('14:00', '15:00');
        DB::table('bloqueios_agenda')->where('id', $bloqueio)->update(['cancelado_em' => now()]);
        $fantasma = $this->ocupacaoPara('bloqueio_id', $bloqueio, 'bloqueios_agenda', 'periodo');

        $this->assertRecusado(DB::connection(), 'ocupacoes_agenda', '42501',
            fn (Connection $c) => $c->table('ocupacoes_agenda')->insert($fantasma));
        $this->assertRecusado($this->conexaoDono(), 'ocupacoes_pai_ativo', '23514',
            fn (Connection $c) => $c->table('ocupacoes_agenda')->insert($fantasma));

        $this->assertSame(0, DB::table('ocupacoes_agenda')->count());
    }

    public function test_dono_nao_religa_ocupacao_a_um_pai_encerrado(): void
    {
        $ativo = $this->agendar('10:00', '10:30');
        $cancelado = $this->agendar('11:00', '11:30');
        $this->cancelar($cancelado);

        $this->assertRecusado($this->conexaoDono(), 'ocupacoes_pai_ativo', '23514',
            fn (Connection $c) => $c->table('ocupacoes_agenda')->where('agendamento_id', $ativo)
                ->update(['agendamento_id' => $cancelado]));
    }

    public function test_dono_continua_barrado_pelos_triggers_da_ocupacao(): void
    {
        $id = $this->agendar();
        $dono = $this->conexaoDono();

        $this->assertRecusado($dono, 'ocupacoes_protegidas', '23514',
            fn (Connection $c) => $c->table('ocupacoes_agenda')->where('agendamento_id', $id)->delete());
        $this->assertRecusado($dono, 'ocupacoes_do_agendamento', '23503',
            fn (Connection $c) => $c->table('ocupacoes_agenda')->where('agendamento_id', $id)
                ->update(['periodo' => DB::raw("tstzrange('2026-10-01 18:00-03', '2026-10-01 18:30-03', '[)')")]));
        $this->assertRecusado($dono, 'ocupacoes_uma_origem', '23514',
            fn (Connection $c) => $c->table('ocupacoes_agenda')->insert([
                'profissional_id' => $this->ze,
                'periodo' => DB::raw("tstzrange('2026-10-01 18:00-03', '2026-10-01 18:30-03', '[)')"),
            ]));

        $this->assertSame(1, DB::table('ocupacoes_agenda')->where('agendamento_id', $id)->count());
    }

    public function test_dono_nao_altera_nem_apaga_historico(): void
    {
        $id = $this->agendar();
        $evento = DB::table('agendamento_eventos')->where('agendamento_id', $id)->value('id');
        $dono = $this->conexaoDono();

        $this->assertRecusado($dono, 'agendamento_eventos_somente_insercao', '23514',
            fn (Connection $c) => $c->table('agendamento_eventos')->where('id', $evento)->update(['estado_novo' => 'concluido']));
        $this->assertRecusado($dono, 'agendamento_eventos_somente_insercao', '23514',
            fn (Connection $c) => $c->table('agendamento_eventos')->where('id', $evento)->delete());
    }
}
