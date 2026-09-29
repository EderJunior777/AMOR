<?php

namespace Tests\Feature;

use App\Enums\PapelUsuario;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\CenarioDeAnonimizacao;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * cleison:anonimizar-inativos (docs/LGPD-ANONIMIZACAO.md, secao 5). O prazo
 * (D5) e decisao do responsavel + juridico: sem configuracao, nada acontece.
 */
class AnonimizarInativosTest extends TestCase
{
    use BancoDeTeste, CenarioDeAnonimizacao, DadosDeAgenda;

    private function clienteCadastradoHa(int $meses, string $nome): int
    {
        $id = $this->novoCliente($nome);
        DB::table('clientes')->where('id', $id)->update(['created_at' => now()->subMonths($meses)]);

        return $id;
    }

    private function agendamentoHa(int $meses, int $cliente, string $estadoFinal): void
    {
        $inicio = now()->subMonths($meses)->startOfHour();
        $id = DB::transaction(fn () => $this->novoAgendamento('10:00', '10:30', [
            'cliente_id' => $cliente,
            'inicio_servico' => $inicio->toIso8601String(),
            'fim_servico' => $inicio->copy()->addMinutes(30)->toIso8601String(),
        ]));
        if ($estadoFinal !== 'confirmado') {
            DB::table('agendamentos')->where('id', $id)->update(['estado' => $estadoFinal]);
        }
    }

    private function nenhumAnonimizado(): void
    {
        $this->assertSame(0, DB::table('anonimizacoes')->count());
        $this->assertSame(0, DB::table('clientes')->whereNotNull('anonimizado_em')->count());
    }

    public function test_sem_prazo_configurado_nao_anonimiza_ninguem(): void
    {
        $this->clienteCadastradoHa(24, 'Cliente Antigo');
        $dono = $this->proprietario()->id;

        foreach ([[null, $dono], ['0', $dono], ['seis', $dono], ['6', null], ['6', 'abc']] as [$meses, $responsavel]) {
            config(['cleison.retencao.meses' => $meses, 'cleison.retencao.responsavel_id' => $responsavel]);

            $this->artisan('cleison:anonimizar-inativos')->expectsOutputToContain('Ninguem foi anonimizado.')->assertFailed();
        }

        $this->nenhumAnonimizado();
    }

    public function test_responsavel_precisa_ser_proprietario_ativo(): void
    {
        $this->clienteCadastradoHa(24, 'Cliente Antigo');

        foreach ([
            User::factory()->create(['papel' => PapelUsuario::Barbeiro])->id,
            User::factory()->proprietario()->create(['ativo' => false])->id,
        ] as $responsavel) {
            config(['cleison.retencao.meses' => '6', 'cleison.retencao.responsavel_id' => (string) $responsavel]);
            $this->artisan('cleison:anonimizar-inativos')->assertFailed();
        }

        $this->nenhumAnonimizado();
    }

    public function test_anonimiza_so_quem_passou_do_prazo_sem_nada_em_aberto(): void
    {
        $dono = $this->proprietario();
        config(['cleison.retencao.meses' => '6', 'cleison.retencao.responsavel_id' => (string) $dono->id]);

        $antigoInativo = $this->clienteCadastradoHa(24, 'Antigo Inativo');
        $this->agendamentoHa(8, $antigoInativo, 'concluido');
        $antigoSemAgendamento = $this->clienteCadastradoHa(24, 'Antigo Sem Agendamento');

        $recente = $this->clienteCadastradoHa(24, 'Voltou Recentemente');
        $this->agendamentoHa(8, $recente, 'concluido');
        $this->agendamentoHa(1, $recente, 'concluido');
        $emAberto = $this->clienteCadastradoHa(24, 'Antigo Com Confirmado Esquecido');
        $this->agendamentoHa(8, $emAberto, 'confirmado');
        $novo = $this->clienteCadastradoHa(1, 'Novo Sem Agendamento');

        $this->artisan('cleison:anonimizar-inativos')
            ->expectsOutput('Retencao (6 meses): 2 cliente(s) anonimizado(s), 0 recusado(s).')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$antigoInativo, $antigoSemAgendamento],
            DB::table('clientes')->whereNotNull('anonimizado_em')->pluck('id')->all());
        foreach ([$recente, $emAberto, $novo] as $mantido) {
            $this->assertNotSame('Cliente anonimizado', DB::table('clientes')->where('id', $mantido)->value('nome'));
        }
        $this->assertEquals(
            [['origem' => 'retencao', 'usuario_id' => $dono->id, 'protocolo' => null]],
            DB::table('anonimizacoes')->distinct()->get(['origem', 'usuario_id', 'protocolo'])->map(fn ($l) => (array) $l)->all(),
        );

        // De novo: ninguem novo, nada duplicado.
        $this->artisan('cleison:anonimizar-inativos')
            ->expectsOutput('Retencao (6 meses): 0 cliente(s) anonimizado(s), 0 recusado(s).')
            ->assertSuccessful();
        $this->assertSame(2, DB::table('anonimizacoes')->count());
    }

    public function test_agendamento_diario_so_liga_com_prazo_valido(): void
    {
        $evento = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'cleison:anonimizar-inativos'));
        $this->assertNotNull($evento);

        foreach ([[null, false], ['', false], ['0', false], ['seis', false], ['6', true]] as [$meses, $liga]) {
            config(['cleison.retencao.meses' => $meses]);
            $this->assertSame($liga, $evento->filtersPass($this->app), 'meses='.var_export($meses, true));
        }
    }
}
