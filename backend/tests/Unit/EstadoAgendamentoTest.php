<?php

namespace Tests\Unit;

use App\Enums\EstadoAgendamento as E;
use PHPUnit\Framework\TestCase;

/** O contrato em si. A concordancia com o banco esta em ContratoDeEstadosTest. */
class EstadoAgendamentoTest extends TestCase
{
    public function test_encerrados_nao_tem_saida(): void
    {
        foreach ([E::Concluido, E::Cancelado, E::NaoCompareceu] as $estado) {
            $this->assertTrue($estado->encerrado(), $estado->value);
            $this->assertSame([], $estado->transicoesPermitidas());
        }
        foreach ([E::Solicitado, E::Confirmado, E::EmAtendimento] as $estado) {
            $this->assertFalse($estado->encerrado(), $estado->value);
        }
    }

    public function test_so_cancelado_e_falta_liberam_a_agenda(): void
    {
        $liberam = array_values(array_filter(E::cases(), fn (E $e) => ! $e->ocupaAgenda()));
        $this->assertSame([E::Cancelado, E::NaoCompareceu], $liberam);
    }

    public function test_nao_se_nasce_cancelado_nem_faltoso(): void
    {
        // Nenhum estado encerrado e inicial.
        foreach (E::iniciais() as $estado) {
            $this->assertFalse($estado->encerrado(), $estado->value);
        }
        $this->assertSame([E::Solicitado, E::Confirmado, E::EmAtendimento], E::iniciais());
    }

    public function test_solicitacao_precisa_ser_confirmada_antes_de_atender(): void
    {
        $this->assertFalse(E::Solicitado->podeIrPara(E::EmAtendimento));
        $this->assertFalse(E::Solicitado->podeIrPara(E::Concluido));
        $this->assertTrue(E::Solicitado->podeIrPara(E::Confirmado));
    }

    public function test_nenhum_estado_volta_para_solicitado(): void
    {
        foreach (E::cases() as $estado) {
            $this->assertFalse($estado->podeIrPara(E::Solicitado), $estado->value);
        }
    }
}
