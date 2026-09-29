<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;
use Throwable;

/**
 * Migration 2026_09_29_000100 num banco que JA TEM uma ocupacao fantasma
 * (gravada antes da correcao), no banco de teste descartavel:
 *   1. volta o schema para antes da correcao e reproduz a brecha com o
 *      papel da aplicacao;
 *   2. a migration aborta dizendo quantas ha, sem mudar privilegios nem
 *      funcoes;
 *   3. depois da limpeza (pelo dono), aplica e fecha a escrita.
 */
class MigracaoEscritaDiretaTest extends TestCase
{
    use DadosDeAgenda;

    private const MIGRACAO = '2026_09_29_000100_fechar_escrita_direta_na_agenda';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => self::CONEXAO_DONO]);
        $this->limparTabelasDoDominio();
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate', ['--force' => true, '--database' => self::CONEXAO_DONO]);
        $this->limparTabelasDoDominio();
        parent::tearDown();
    }

    private function aplicada(): bool
    {
        return DB::table('migrations')->where('migration', self::MIGRACAO)->exists();
    }

    private function appEscreveEmOcupacoes(): bool
    {
        return (bool) DB::scalar("SELECT has_table_privilege(current_user, 'public.ocupacoes_agenda', 'INSERT')");
    }

    public function test_banco_com_ocupacao_fantasma_nao_migra_ate_ser_limpo(): void
    {
        // 1. Schema anterior + brecha reproduzida pela aplicacao.
        while ($this->aplicada()) {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--database' => self::CONEXAO_DONO]));
        }
        $this->assertTrue($this->appEscreveEmOcupacoes());

        $prof = $this->novoProfissional('Ze');
        $id = DB::transaction(fn () => $this->novoAgendamento('10:00', '10:30', ['profissional_id' => $prof]));
        DB::table('agendamentos')->where('id', $id)->update(['estado' => 'cancelado', 'cancelado_em' => now()]);
        DB::table('ocupacoes_agenda')->insert([
            'profissional_id' => $prof, 'agendamento_id' => $id,
            'periodo' => DB::raw("(SELECT periodo_ocupado FROM agendamentos WHERE id = {$id})"),
        ]);
        $this->assertSame(1, DB::table('ocupacoes_agenda')->count(), 'a brecha antiga deixou a ocupacao fantasma');

        // 2. A migration aborta e nao muda nada.
        try {
            Artisan::call('migrate', ['--force' => true, '--database' => self::CONEXAO_DONO]);
            $this->fail('A migration aplicou com uma ocupacao fantasma no banco.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('[migracao_ocupacoes_fantasma] 1 ocupacao(oes)', $e->getMessage());
        }
        $this->assertFalse($this->aplicada(), 'migration registrada apesar do erro');
        $this->assertTrue($this->appEscreveEmOcupacoes(), 'mudanca parcial de privilegios');
        $this->assertSame(1, DB::table('ocupacoes_agenda')->count(), 'a migration apagou dados');

        // 3. Limpeza manual pelo dono (a ocupacao e de um cancelado, nao e
        //    protegida) e a migration passa.
        $this->conexaoDono()->table('ocupacoes_agenda')->where('agendamento_id', $id)->delete();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--database' => self::CONEXAO_DONO]));
        $this->assertTrue($this->aplicada());
        $this->assertFalse($this->appEscreveEmOcupacoes());
    }
}
