<?php

namespace Tests\Feature\Banco;

use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

class ClientesTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    public function test_telefone_em_e164_e_unico(): void
    {
        $this->novoCliente('Joao', '+5511987654321');

        $this->assertBancoRecusa('clientes_telefone', fn () => $this->novoCliente('Pedro', '(11) 98765-4321'));
        $this->assertBancoRecusa('clientes_telefone_unico', fn () => $this->novoCliente('Outro Joao', '+5511987654321'));
    }

    public function test_varios_clientes_sem_telefone_sao_permitidos(): void
    {
        // Atendimento presencial de quem nao quis dar telefone.
        $this->novoCliente('Cliente de balcao');
        $this->novoCliente('Outro de balcao');

        $this->assertSame(2, DB::table('clientes')->whereNull('telefone')->count());
    }

    public function test_nome_obrigatorio(): void
    {
        $this->assertBancoRecusa('clientes_nome', fn () => $this->novoCliente(' '));
    }

    public function test_endereco_precisa_ser_do_proprio_cliente(): void
    {
        $joao = $this->novoCliente('Joao', '+5511987654321');
        $ana = $this->novoCliente('Ana', '+5511911112222');
        $regiao = $this->novaRegiao();
        $enderecoDaAna = DB::table('enderecos_cliente')->insertGetId(['cliente_id' => $ana, 'logradouro' => 'Rua da Ana, 10']);

        $this->assertBancoRecusa('agendamentos_endereco_do_cliente', fn () => $this->novoAgendamento('10:00', '10:30', [
            'cliente_id' => $joao,
            'modalidade' => 'domicilio',
            'endereco_cliente_id' => $enderecoDaAna,
            'endereco_texto' => 'Rua da Ana, 10',
            'regiao_id' => $regiao,
            'regiao_nome' => 'Zona Sul',
        ]));
    }

    public function test_cliente_com_historico_nao_pode_ser_apagado(): void
    {
        $joao = $this->novoCliente('Joao', '+5511987654321');
        $this->novoAgendamento('10:00', '10:30', ['cliente_id' => $joao]);

        $this->assertBancoRecusa('agendamentos_cliente_id_fkey', fn () => DB::table('clientes')->where('id', $joao)->delete());
    }
}
