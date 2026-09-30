<?php

namespace Tests\Feature\Banco;

use App\Enums\Ator;
use App\Models\User;
use App\Support\AutoriaInvalida;
use App\Support\TransacaoAuditada;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\CenarioDeAnonimizacao;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * Decisao E2, opcao (a): o motivo do encaixe do operador vai para
 * agendamento_eventos.dados.motivo, gravado pelo trigger de historico a
 * partir de cleison.motivo (definido pela TransacaoAuditada, so para
 * operador). E texto livre: a anonimizacao remove.
 */
class MotivoDoEncaixeTest extends TestCase
{
    use BancoDeTeste, CenarioDeAnonimizacao, DadosDeAgenda;

    private function dadosDoEvento(int $agendamento, string $tipo): array
    {
        return json_decode((string) DB::table('agendamento_eventos')
            ->where('agendamento_id', $agendamento)->where('tipo', $tipo)->orderByDesc('id')->value('dados'), true);
    }

    public function test_encaixe_do_operador_grava_o_motivo_no_evento_criado(): void
    {
        $operador = User::factory()->create();

        $id = TransacaoAuditada::executar(Ator::Operador, $operador,
            fn () => $this->novoAgendamento('07:00', '07:30', ['origem' => 'presencial']),
            motivo: 'Cliente fiel, antes de abrir');

        $dados = $this->dadosDoEvento($id, 'criado');
        $this->assertSame('Cliente fiel, antes de abrir', $dados['motivo']);
        $this->assertSame('presencial', $dados['origem']);
    }

    public function test_sem_motivo_o_evento_nao_tem_a_chave(): void
    {
        $operador = User::factory()->create();

        $id = TransacaoAuditada::executar(Ator::Operador, $operador,
            fn () => $this->novoAgendamento('10:00', '10:30', ['origem' => 'presencial']));

        $this->assertArrayNotHasKey('motivo', $this->dadosDoEvento($id, 'criado'));
    }

    public function test_remarcacao_com_motivo_grava_no_evento_remarcado(): void
    {
        $operador = User::factory()->create();
        $id = $this->novoAgendamento('10:00', '10:30', ['origem' => 'presencial']);

        TransacaoAuditada::executar(Ator::Operador, $operador, fn () => DB::table('agendamentos')->where('id', $id)->update([
            'inicio_servico' => $this->em('21:00'), 'fim_servico' => $this->em('21:30'),
            'inicio_ocupado' => $this->em('21:00'), 'fim_ocupado' => $this->em('21:30'),
        ]), motivo: 'Encaixe depois do expediente');

        $this->assertSame('Encaixe depois do expediente', $this->dadosDoEvento($id, 'remarcado')['motivo']);
    }

    public function test_motivo_nao_vaza_para_a_proxima_transacao(): void
    {
        $operador = User::factory()->create();
        TransacaoAuditada::executar(Ator::Operador, $operador, fn () => null, motivo: 'So desta vez');

        $id = TransacaoAuditada::executar(Ator::Operador, $operador,
            fn () => $this->novoAgendamento('10:00', '10:30', ['origem' => 'presencial']));

        $this->assertArrayNotHasKey('motivo', $this->dadosDoEvento($id, 'criado'));
        $this->assertSame('', (string) DB::scalar("SELECT current_setting('cleison.motivo', true)"));
    }

    public function test_motivo_so_para_operador(): void
    {
        foreach ([Ator::Cliente, Ator::Sistema] as $ator) {
            try {
                TransacaoAuditada::executar($ator, null, fn () => null, motivo: 'qualquer');
                $this->fail("{$ator->value} nao deveria aceitar motivo");
            } catch (AutoriaInvalida) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_anonimizacao_remove_o_motivo_do_encaixe(): void
    {
        $operador = User::factory()->create();
        $cliente = $this->novoCliente('Zebedeu Quixabeira', '+5511987651234');
        $id = TransacaoAuditada::executar(Ator::Operador, $operador,
            fn () => $this->novoAgendamento('07:00', '07:30', ['origem' => 'presencial', 'cliente_id' => $cliente]),
            motivo: 'Zebedeu pediu antes das 8');
        DB::table('agendamentos')->where('id', $id)->update(['estado' => 'cancelado', 'cancelado_em' => now()]);

        $this->anonimizar($cliente, $this->proprietario()->id);

        $dados = $this->dadosDoEvento($id, 'criado');
        $this->assertArrayNotHasKey('motivo', $dados);
        $this->assertSame('presencial', $dados['origem'], 'o resto do historico fica');
        $this->assertSame(0, DB::table('agendamento_eventos')->where('agendamento_id', $id)
            ->whereRaw("dados::text ILIKE '%Zebedeu%'")->count());
    }
}
