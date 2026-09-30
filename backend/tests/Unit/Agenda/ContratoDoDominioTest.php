<?php

namespace Tests\Unit\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\PedidoDeReserva;
use App\Domain\Agenda\ReservaRecusada;
use App\Enums\Ator;
use App\Enums\EstadoAgendamento;
use App\Enums\Modalidade;
use App\Enums\OrigemAgendamento;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Tipos compartilhados do dominio da agenda (docs/ESPEC-RESERVA.md, secao 6). */
class ContratoDoDominioTest extends TestCase
{
    public function test_canal_define_origem_estado_inicial_e_ator(): void
    {
        $this->assertSame(OrigemAgendamento::Site, Canal::Site->origem());
        $this->assertSame(EstadoAgendamento::Solicitado, Canal::Site->estadoInicial());
        $this->assertSame(Ator::Cliente, Canal::Site->ator());
        $this->assertFalse(Canal::Site->ehOperador());

        foreach ([Canal::Whatsapp, Canal::Presencial] as $canal) {
            $this->assertSame($canal->value, $canal->origem()->value);
            $this->assertSame(EstadoAgendamento::Confirmado, $canal->estadoInicial());
            $this->assertSame(Ator::Operador, $canal->ator());
            $this->assertTrue($canal->ehOperador());
        }
    }

    private function dados(array $extra = []): array
    {
        return $extra + [
            'servicos' => [3, 1],
            'profissional_id' => 7,
            'data' => '2026-10-05',
            'hora' => '14:30',
            'modalidade' => 'barbearia',
            'cliente' => ['nome' => '  Joao   da  Silva ', 'telefone' => '(11) 98765-4321'],
            'observacao' => "  chego\n cedo  ",
        ];
    }

    public function test_pedido_normaliza_texto_e_telefone(): void
    {
        $pedido = PedidoDeReserva::deDados($this->dados(), 'chave-0123456789abcdef');

        $this->assertSame([3, 1], $pedido->servicos, 'a ordem dos servicos e a ordem dos itens');
        $this->assertSame(7, $pedido->profissionalId);
        $this->assertSame(Modalidade::Barbearia, $pedido->modalidade);
        $this->assertSame('Joao da Silva', $pedido->clienteNome);
        $this->assertSame('+5511987654321', $pedido->clienteTelefone);
        $this->assertSame('chego cedo', $pedido->observacao);
        $this->assertSame('chave-0123456789abcdef', $pedido->chaveIdempotencia);
    }

    public function test_pedido_na_barbearia_descarta_regiao_e_endereco(): void
    {
        $pedido = PedidoDeReserva::deDados($this->dados([
            'regiao_id' => 2,
            'endereco' => ['logradouro' => 'Rua A, 100'],
        ]));

        $this->assertNull($pedido->regiaoId);
        $this->assertNull($pedido->logradouro);
    }

    public function test_pedido_a_domicilio_guarda_regiao_e_endereco(): void
    {
        $pedido = PedidoDeReserva::deDados($this->dados([
            'modalidade' => 'domicilio',
            'regiao_id' => '2',
            'endereco' => ['logradouro' => ' Rua A,  100 ', 'complemento' => '', 'referencia' => 'portao azul'],
        ]));

        $this->assertSame(2, $pedido->regiaoId);
        $this->assertSame('Rua A, 100', $pedido->logradouro);
        $this->assertNull($pedido->complemento, 'texto vazio vira nulo');
        $this->assertSame('portao azul', $pedido->referencia);
    }

    public function test_campos_fora_da_lista_nao_existem_no_pedido(): void
    {
        $pedido = PedidoDeReserva::deDados($this->dados([
            'preco_centavos' => 1,
            'estado' => 'concluido',
            'origem' => 'presencial',
            'cliente_id' => 99,
            'codigo_publico' => '00000000-0000-0000-0000-000000000000',
            'fuso' => 'UTC',
        ]));

        $propriedades = array_keys(get_object_vars($pedido));
        foreach (['preco_centavos', 'estado', 'origem', 'cliente_id', 'codigo_publico', 'fuso'] as $proibido) {
            $this->assertNotContains($proibido, $propriedades);
        }
        $this->assertStringNotContainsString('preco', json_encode($pedido->canonico()));
    }

    public function test_canonico_tem_chaves_ordenadas_e_nao_leva_a_chave_de_idempotencia(): void
    {
        $canonico = PedidoDeReserva::deDados($this->dados(), 'chave-0123456789abcdef')->canonico();

        $this->assertArrayNotHasKey('chave_idempotencia', $canonico);
        $chaves = array_keys($canonico);
        $ordenadas = $chaves;
        sort($ordenadas);
        $this->assertSame($ordenadas, $chaves);
        $this->assertSame(['nome', 'telefone'], array_keys($canonico['cliente']));
        $this->assertSame('+5511987654321', $canonico['cliente']['telefone']);
    }

    public function test_pedido_sem_servico_repetido_nem_vazio_e_com_telefone_valido(): void
    {
        foreach ([
            ['servicos' => []],
            ['servicos' => [1, 1]],
            ['servicos' => [0]],
            ['cliente' => ['nome' => 'Joao', 'telefone' => '123']],
        ] as $invalido) {
            try {
                PedidoDeReserva::deDados($this->dados($invalido));
                $this->fail('deveria recusar: '.json_encode($invalido));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_recusa_tem_codigo_estavel_e_mensagem_sem_detalhe_interno(): void
    {
        $e = ReservaRecusada::por('fora_do_expediente');

        $this->assertSame('fora_do_expediente', $e->codigo);
        $this->assertSame(ReservaRecusada::MENSAGENS['fora_do_expediente'], $e->getMessage());

        foreach ([
            'data_invalida', 'fora_da_grade', 'hora_inexistente', 'antecedencia', 'alem_do_horizonte',
            'servico_indisponivel', 'profissional_indisponivel', 'domicilio_indisponivel',
            'fora_do_expediente', 'idempotencia_conflito', 'remarcacao_exige_novo_pedido',
            'reserva_nao_encontrada', 'fora_do_prazo', 'motivo_obrigatorio', 'motivo_muito_longo', 'estado_nao_permite', 'agenda_indisponivel',
        ] as $codigo) {
            $this->assertArrayHasKey($codigo, ReservaRecusada::MENSAGENS);
        }
    }

    public function test_codigo_desconhecido_e_erro_de_programacao(): void
    {
        $this->expectException(LogicException::class);

        ReservaRecusada::por('inventado');
    }
}
