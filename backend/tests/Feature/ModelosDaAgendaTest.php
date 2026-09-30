<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\AgendamentoEvento;
use App\Models\BloqueioAgenda;
use App\Models\Cliente;
use App\Models\EnderecoCliente;
use App\Models\ExcecaoExpediente;
use App\Models\Profissional;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * Models da etapa 2 (docs/ESPEC-RESERVA.md, secao 7): relacoes, $fillable
 * minimo e o historico somente leitura.
 */
class ModelosDaAgendaTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    public function test_cliente_tem_enderecos_e_o_endereco_aponta_para_o_cliente(): void
    {
        $cliente = Cliente::query()->findOrFail($this->novoCliente());
        $regiao = $this->novaRegiao();

        $endereco = $cliente->enderecos()->create([
            'regiao_id' => $regiao,
            'logradouro' => 'Rua das Flores, 10',
            'complemento' => 'ap 2',
            'referencia' => 'perto da praca',
        ]);

        $this->assertSame($cliente->id, $endereco->cliente_id);
        $this->assertTrue($endereco->cliente->is($cliente));
        $this->assertSame([$endereco->id], $cliente->enderecos()->pluck('id')->all());
    }

    public function test_profissional_tem_excecoes_e_a_excecao_aponta_para_o_profissional(): void
    {
        $profissional = Profissional::query()->findOrFail($this->novoProfissional());

        $excecao = $profissional->excecoes()->create([
            'data' => '2026-10-04',
            'hora_inicio' => '09:00',
            'hora_fim' => '13:00',
            'motivo' => 'Domingo aberto',
        ]);

        $this->assertTrue($excecao->profissional->is($profissional));
        $this->assertSame('2026-10-04', $excecao->fresh()->data->toDateString());
        $this->assertSame([$excecao->id], $profissional->excecoes()->pluck('id')->all());
    }

    public function test_vinculo_profissional_servico_nos_dois_sentidos(): void
    {
        $profissional = Profissional::query()->findOrFail($this->novoProfissional());
        $servico = Servico::query()->findOrFail($this->novoServico());

        $profissional->servicos()->attach($servico->id);

        $this->assertSame([$servico->id], $profissional->servicos()->pluck('servicos.id')->all());
        $this->assertSame([$profissional->id], $servico->profissionais()->pluck('profissionais.id')->all());
    }

    public function test_bloqueio_ocupa_a_agenda_e_cancelar_libera_com_ator_operador(): void
    {
        $profissional = $this->novoProfissional();
        $operador = User::factory()->create();

        $bloqueio = BloqueioAgenda::query()->create([
            'profissional_id' => $profissional,
            'tipo' => 'folga',
            'inicio' => $this->em('08:00'),
            'fim' => $this->em('12:00'),
            'motivo' => 'Consulta medica',
        ]);

        $this->assertTrue($bloqueio->profissional->is(Profissional::query()->findOrFail($profissional)));
        $this->assertTrue($bloqueio->ativo());
        $this->assertCount(1, $this->ocupacoes($profissional));

        $bloqueio->cancelar($operador);

        $this->assertFalse($bloqueio->fresh()->ativo());
        $this->assertNotNull($bloqueio->fresh()->cancelado_em);
        $this->assertSame([], $this->ocupacoes($profissional));
    }

    public function test_cancelar_bloqueio_ja_cancelado_nao_muda_nada(): void
    {
        $bloqueio = BloqueioAgenda::query()->create([
            'profissional_id' => $this->novoProfissional(),
            'tipo' => 'compromisso',
            'inicio' => $this->em('08:00'),
            'fim' => $this->em('09:00'),
        ]);
        $operador = User::factory()->create();
        $bloqueio->cancelar($operador);
        $primeiro = $bloqueio->fresh()->cancelado_em;

        $bloqueio->fresh()->cancelar($operador);

        $this->assertEquals($primeiro, $bloqueio->fresh()->cancelado_em);
    }

    public function test_agendamento_tem_eventos_somente_leitura(): void
    {
        $agendamento = Agendamento::query()->findOrFail($this->novoAgendamento('10:00', '10:30'));

        $eventos = $agendamento->eventos;

        $this->assertCount(1, $eventos);
        $this->assertInstanceOf(AgendamentoEvento::class, $eventos[0]);
        $this->assertSame('criado', $eventos[0]->tipo);
        $this->assertIsArray($eventos[0]->dados);
        $this->assertTrue($eventos[0]->agendamento->is($agendamento));
    }

    public function test_evento_nao_e_salvo_nem_apagado_pela_aplicacao(): void
    {
        $evento = AgendamentoEvento::query()->where(
            'agendamento_id', $this->novoAgendamento('10:00', '10:30')
        )->firstOrFail();

        foreach ([
            'save' => fn () => $evento->save(),
            'alterar' => fn () => $evento->forceFill(['tipo' => 'remarcado'])->save(),
            'delete' => fn () => $evento->delete(),
            'novo' => fn () => (new AgendamentoEvento)->save(),
        ] as $nome => $acao) {
            try {
                $acao();
                $this->fail("{$nome} deveria lancar LogicException");
            } catch (LogicException) {
                // esperado
            }
        }

        $this->assertSame(1, DB::table('agendamento_eventos')->where('id', $evento->id)->count());
        $this->assertSame([], (new AgendamentoEvento)->getFillable());
    }

    public function test_fillable_minimo_dos_novos_models(): void
    {
        $this->assertSame(
            ['profissional_id', 'tipo', 'inicio', 'fim', 'motivo'],
            (new BloqueioAgenda)->getFillable()
        );
        $this->assertSame(
            ['regiao_id', 'logradouro', 'complemento', 'referencia'],
            (new EnderecoCliente)->getFillable()
        );
        $this->assertSame(
            ['profissional_id', 'data', 'hora_inicio', 'hora_fim', 'motivo'],
            (new ExcecaoExpediente)->getFillable()
        );
    }
}
