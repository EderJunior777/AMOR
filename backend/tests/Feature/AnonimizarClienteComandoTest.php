<?php

namespace Tests\Feature;

use App\Enums\PapelUsuario;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\CenarioDeAnonimizacao;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/** cleison:anonimizar-cliente (docs/LGPD-ANONIMIZACAO.md, secao 6). */
class AnonimizarClienteComandoTest extends TestCase
{
    use BancoDeTeste, CenarioDeAnonimizacao, DadosDeAgenda;

    private const PERGUNTA = 'Isto nao pode ser desfeito. Digite o id do cliente para confirmar';

    private function nomeAtual(int $cliente): string
    {
        return (string) DB::table('clientes')->where('id', $cliente)->value('nome');
    }

    private function argumentos(int $cliente, int $usuario, array $extra = []): array
    {
        return ['cliente' => $cliente, '--usuario' => $usuario, '--protocolo' => 'LGPD-2026/007'] + $extra;
    }

    public function test_simular_mostra_so_contagens_e_nao_altera_nada(): void
    {
        $c = $this->clienteComHistorico();

        $codigo = Artisan::call('cleison:anonimizar-cliente', $this->argumentos($c['cliente'], $this->proprietario()->id, ['--simular' => true]));
        $saida = Artisan::output();

        $this->assertSame(0, $codigo, $saida);
        $this->assertMatchesRegularExpression('/Enderecos\s*\|\s*1\b/', $saida);
        $this->assertMatchesRegularExpression('/Agendamentos\s*\|\s*3\b/', $saida);
        $this->assertMatchesRegularExpression('/Eventos do historico\s*\|\s*7\b/', $saida);
        $this->assertStringContainsString('Simulacao: nada foi alterado.', $saida);
        foreach ($this->dadosPessoais() as $dado) {
            $this->assertStringNotContainsString($dado, $saida);
        }
        $this->assertSame('Zebedeu Quixabeira', $this->nomeAtual($c['cliente']));
        $this->assertSame(0, DB::table('anonimizacoes')->count());
    }

    public function test_confirmacao_errada_nao_altera_nada(): void
    {
        $c = $this->clienteComHistorico();

        $this->artisan('cleison:anonimizar-cliente', $this->argumentos($c['cliente'], $this->proprietario()->id))
            ->expectsQuestion(self::PERGUNTA, (string) ($c['cliente'] + 1))
            ->expectsOutput('Confirmacao nao confere. Nada foi alterado.')
            ->assertFailed();

        $this->assertSame('Zebedeu Quixabeira', $this->nomeAtual($c['cliente']));
    }

    public function test_confirmacao_com_o_id_anonimiza_e_registra_quem_pediu(): void
    {
        $c = $this->clienteComHistorico();
        $dono = $this->proprietario();

        $this->artisan('cleison:anonimizar-cliente', $this->argumentos($c['cliente'], $dono->id))
            ->expectsQuestion(self::PERGUNTA, (string) $c['cliente'])
            ->assertSuccessful();

        $this->assertSame('Cliente anonimizado', $this->nomeAtual($c['cliente']));
        $registro = DB::table('anonimizacoes')->where('cliente_id', $c['cliente'])->first();
        $this->assertEquals([$dono->id, 'pedido_titular', 'LGPD-2026/007'], [$registro->usuario_id, $registro->origem, $registro->protocolo]);
        // Anonimizar nao gera evento de agendamento.
        $this->assertSame(0, DB::table('agendamento_eventos')->where('usuario_id', $dono->id)->count());
    }

    public function test_sem_terminal_interativo_exige_forcar(): void
    {
        $c = $this->clienteComHistorico();
        $dono = $this->proprietario()->id;

        $this->artisan('cleison:anonimizar-cliente', $this->argumentos($c['cliente'], $dono, ['--no-interaction' => true]))
            ->expectsOutput('Sem terminal interativo, confirme com --forcar.')
            ->assertFailed();
        $this->assertSame('Zebedeu Quixabeira', $this->nomeAtual($c['cliente']));

        $this->artisan('cleison:anonimizar-cliente', $this->argumentos($c['cliente'], $dono, ['--no-interaction' => true, '--forcar' => true]))
            ->assertSuccessful();
        $this->assertSame('Cliente anonimizado', $this->nomeAtual($c['cliente']));
    }

    public function test_exige_usuario_e_protocolo_validos(): void
    {
        $c = $this->clienteComHistorico();
        $dono = $this->proprietario()->id;

        $this->artisan('cleison:anonimizar-cliente', ['cliente' => $c['cliente'], '--protocolo' => 'P-1', '--simular' => true])->assertFailed();
        $this->artisan('cleison:anonimizar-cliente', ['cliente' => $c['cliente'], '--usuario' => $dono, '--simular' => true])->assertFailed();
        $this->artisan('cleison:anonimizar-cliente', ['cliente' => $c['cliente'], '--usuario' => $dono, '--protocolo' => 'LGPD 01', '--simular' => true])->assertFailed();
        $this->artisan('cleison:anonimizar-cliente', ['cliente' => 'abc', '--usuario' => $dono, '--protocolo' => 'P-1', '--simular' => true])->assertFailed();

        $this->assertSame('Zebedeu Quixabeira', $this->nomeAtual($c['cliente']));
    }

    public function test_so_proprietario_ativo(): void
    {
        $c = $this->clienteComHistorico();
        $usuarios = [
            User::factory()->create(['papel' => PapelUsuario::Barbeiro])->id,
            User::factory()->create(['papel' => PapelUsuario::Recepcao])->id,
            User::factory()->proprietario()->create(['ativo' => false])->id,
            999999,
        ];

        foreach ($usuarios as $usuario) {
            $this->artisan('cleison:anonimizar-cliente', $this->argumentos($c['cliente'], $usuario, ['--forcar' => true]))->assertFailed();
        }

        $this->assertSame('Zebedeu Quixabeira', $this->nomeAtual($c['cliente']));
        $this->assertSame(0, DB::table('anonimizacoes')->count());
    }

    public function test_recusa_com_agendamento_em_aberto(): void
    {
        $c = $this->clienteComHistorico();
        DB::transaction(fn () => $this->novoAgendamento('18:00', '18:30', ['cliente_id' => $c['cliente']]));

        $this->artisan('cleison:anonimizar-cliente', $this->argumentos($c['cliente'], $this->proprietario()->id, ['--forcar' => true]))
            ->expectsOutputToContain('agendamento(s) em aberto')
            ->assertFailed();

        $this->assertSame('Zebedeu Quixabeira', $this->nomeAtual($c['cliente']));
    }

    public function test_cliente_ja_anonimizado_ou_inexistente(): void
    {
        $c = $this->clienteComHistorico();
        $dono = $this->proprietario()->id;
        $this->anonimizar($c['cliente'], $dono);

        $this->artisan('cleison:anonimizar-cliente', $this->argumentos($c['cliente'], $dono, ['--forcar' => true]))
            ->expectsOutput("Cliente {$c['cliente']} ja esta anonimizado. Nada a fazer.")
            ->assertSuccessful();
        $this->assertSame(1, DB::table('anonimizacoes')->count());

        $this->artisan('cleison:anonimizar-cliente', $this->argumentos(999999, $dono, ['--forcar' => true]))
            ->expectsOutput('Cliente 999999 nao encontrado.')
            ->assertFailed();
    }
}
