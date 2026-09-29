<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;
use Throwable;

/**
 * Compatibilidade da migration 2026_09_25_000100 (achado B) com um banco que
 * JA TEM dados, no banco de teste descartavel:
 *   1. volta o schema para antes da correcao;
 *   2. grava dados validos e reproduz a lacuna antiga (A fica sem item);
 *   3. a migration aborta listando o agendamento inconsistente, sem alterar nada;
 *   4. depois da correcao manual (item devolvido), aplica e preserva tudo.
 */
class MigracaoVinculoDosItensTest extends TestCase
{
    use DadosDeAgenda;

    private const MIGRACAO = '2026_09_25_000100_proteger_vinculo_dos_itens';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limparTabelasDoDominio();
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    private function aplicada(): bool
    {
        return DB::table('migrations')->where('migration', self::MIGRACAO)->exists();
    }

    private function travaDoVinculoExiste(): bool
    {
        return DB::table('pg_trigger')->where('tgname', 'agendamento_itens_vinculo_imutavel')->exists();
    }

    private function contagens(): array
    {
        return [
            'agendamentos' => DB::table('agendamentos')->count(),
            'itens' => DB::table('agendamento_itens')->count(),
            'eventos' => DB::table('agendamento_eventos')->count(),
            'ocupacoes' => DB::table('ocupacoes_agenda')->count(),
        ];
    }

    private function mover(int $item, int $para, int $ordem): void
    {
        DB::table('agendamento_itens')->where('id', $item)->update(['agendamento_id' => $para, 'ordem' => $ordem]);
    }

    private function ajustarFim(int $agendamento, string $fim): void
    {
        DB::table('agendamentos')->where('id', $agendamento)->update(['fim_servico' => $this->em($fim), 'fim_ocupado' => $this->em($fim)]);
    }

    public function test_banco_existente_com_dados_validos_e_invalidos(): void
    {
        // 1. Schema como estava antes da correcao.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--database' => 'pgsql_migracao']));
        $this->assertFalse($this->aplicada());
        $this->assertFalse($this->travaDoVinculoExiste());

        // 2. Dados validos + reproducao da lacuna com a funcao antiga.
        [$a, $b, $c] = DB::transaction(function () {
            $prof = $this->novoProfissional('Ze');

            return [
                $this->novoAgendamento('10:00', '10:30', ['profissional_id' => $prof]),
                $this->novoAgendamento('11:00', '11:30', ['profissional_id' => $prof]),
                $this->novoAgendamento('16:00', '17:00', ['profissional_id' => $prof], [
                    ['duracao_minutos' => 30], ['duracao_minutos' => 30, 'servico_nome' => 'Barba'],
                ]),
            ];
        });
        $itemA = (int) DB::table('agendamento_itens')->where('agendamento_id', $a)->value('id');

        DB::transaction(function () use ($itemA, $b) {
            $this->ajustarFim($b, '12:00');
            $this->mover($itemA, $b, 2);
        });
        $this->assertSame(0, DB::table('agendamento_itens')->where('agendamento_id', $a)->count(),
            'a versao antiga deixou A sem servico (lacuna reproduzida)');

        // 3. A migration encontra o registro inconsistente e nao muda nada.
        $antes = $this->contagens();
        try {
            Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
            $this->fail('A migration aplicou sobre dados inconsistentes.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('[migracao_itens_inconsistentes] 1 agendamento(s)', $e->getMessage());
            $this->assertStringContainsString("(ids: {$a})", $e->getMessage());
        }
        $this->assertFalse($this->aplicada(), 'migration registrada apesar do erro');
        $this->assertFalse($this->travaDoVinculoExiste(), 'mudanca parcial de schema');
        $this->assertSame($antes, $this->contagens(), 'a migration apagou ou criou dados');
        $this->assertTrue(DB::table('agendamentos')->where('id', $a)->exists(), 'o agendamento inconsistente foi apagado');

        // 4. Correcao manual (devolver o item, sem apagar historico) e nova tentativa.
        DB::transaction(function () use ($itemA, $a, $b) {
            $this->mover($itemA, $a, 1);
            $this->ajustarFim($b, '11:30');
        });
        $antes = $this->contagens();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']));
        $this->assertTrue($this->aplicada());
        $this->assertTrue($this->travaDoVinculoExiste());
        $this->assertSame($antes, $this->contagens(), 'dados preservados pela migration');
        $this->assertSame(2, DB::table('agendamento_itens')->where('agendamento_id', $c)->count());

        // E a lacuna fechou.
        try {
            DB::transaction(function () use ($itemA, $b) {
                $this->ajustarFim($b, '12:00');
                $this->mover($itemA, $b, 2);
            });
            $this->fail('Transferencia de item aceita depois da migration.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('agendamento_itens_vinculo_imutavel', $e->getMessage());
        }
        $this->assertSame($antes, $this->contagens());
    }
}
