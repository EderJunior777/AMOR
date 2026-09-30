<?php

namespace Tests\Unit\Agenda;

use App\Domain\Agenda\CalculoDeReserva;
use App\Domain\Agenda\ReservaCalculada;
use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\SnapshotRegiao;
use App\Domain\Agenda\SnapshotServico;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Calculo puro da reserva (docs/ESPEC-RESERVA.md, 2.3 e V1 a V4). Sem banco. */
class CalculoDeReservaTest extends TestCase
{
    private const SP = 'America/Sao_Paulo';

    private const NY = 'America/New_York';

    private function exigirRecusa(string $codigo, callable $acao): void
    {
        try {
            $acao();
        } catch (ReservaRecusada $e) {
            $this->assertSame($codigo, $e->codigo);

            return;
        }
        $this->fail("Esperava a recusa {$codigo}.");
    }

    private function servico(int $id, int $preco, int $duracao): SnapshotServico
    {
        return new SnapshotServico($id, "Servico {$id}", $preco, $duracao, true);
    }

    private function utc(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso, 'UTC');
    }

    private function hora(CarbonImmutable $i, string $fuso = self::SP): string
    {
        return $i->setTimezone($fuso)->format('Y-m-d H:i');
    }

    // ---- V1: grade e formato ----

    public function test_grade_aceita_horas_cheias_e_meias_com_grade_30(): void
    {
        $this->assertSame('2026-10-05 13:00:00', CalculoDeReserva::instante('2026-10-05', '10:00', self::SP, 30)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 13:30:00', CalculoDeReserva::instante('2026-10-05', '10:30', self::SP, 30)->format('Y-m-d H:i:s'));
    }

    public function test_quinze_fora_da_grade_30_e_aceito_na_grade_15(): void
    {
        $this->exigirRecusa('fora_da_grade', fn () => CalculoDeReserva::instante('2026-10-05', '10:15', self::SP, 30));
        $this->assertSame('13:15', CalculoDeReserva::instante('2026-10-05', '10:15', self::SP, 15)->format('H:i'));
    }

    public function test_formatos_de_hora_invalidos_sao_fora_da_grade(): void
    {
        foreach (['9:00', '10:00:00', '24:00', 'ab:cd', '', '10:60', ' 10:00', "10:00\n"] as $hora) {
            $this->exigirRecusa('fora_da_grade', fn () => CalculoDeReserva::instante('2026-10-05', $hora, self::SP, 30));
        }
    }

    public function test_datas_invalidas(): void
    {
        foreach (['2027-02-29', '2026-04-31', '2026-1-05', '2026-10-5', '', 'abcd-ef-gh', "2026-10-05\n", '2026-10-05 '] as $data) {
            $this->exigirRecusa('data_invalida', fn () => CalculoDeReserva::instante($data, '10:00', self::SP, 30));
        }
    }

    public function test_bissexto_valido(): void
    {
        $this->assertSame('2028-02-29 13:00', CalculoDeReserva::instante('2028-02-29', '10:00', self::SP, 30)->format('Y-m-d H:i'));
    }

    public function test_conversao_de_sao_paulo_para_utc(): void
    {
        $i = CalculoDeReserva::instante('2026-10-05', '14:30', self::SP, 30);
        $this->assertSame('UTC', $i->getTimezone()->getName());
        $this->assertSame('2026-10-05 17:30:00', $i->format('Y-m-d H:i:s'));
    }

    // ---- V2: horario de verao ----

    public function test_hora_inexistente_no_pulo_do_horario_de_verao(): void
    {
        $this->exigirRecusa('hora_inexistente', fn () => CalculoDeReserva::instante('2026-03-08', '02:30', self::NY, 30));
    }

    public function test_hora_ambigua_na_volta_do_horario_de_verao(): void
    {
        $this->exigirRecusa('hora_inexistente', fn () => CalculoDeReserva::instante('2026-11-01', '01:30', self::NY, 30));
    }

    public function test_horas_fora_da_transicao_usam_o_offset_certo(): void
    {
        // 03:00 em 8/3 ja e EDT (UTC-4); 03:00 em 1/11 ja e EST (UTC-5).
        $this->assertSame('2026-03-08 07:00', CalculoDeReserva::instante('2026-03-08', '03:00', self::NY, 30)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01 08:00', CalculoDeReserva::instante('2026-11-01', '03:00', self::NY, 30)->format('Y-m-d H:i'));
        $this->assertSame('2026-03-08 06:00', CalculoDeReserva::instante('2026-03-08', '01:00', self::NY, 30)->format('Y-m-d H:i'));
    }

    // ---- calculo ----

    public function test_barbearia_ocupado_igual_ao_servico(): void
    {
        $inicio = $this->utc('2026-10-05 13:00');
        $r = CalculoDeReserva::calcular($inicio, self::SP, 30, [$this->servico(1, 5000, 30)], null);

        $this->assertTrue($r->inicioServico->equalTo($inicio));
        $this->assertSame('2026-10-05 13:30', $r->fimServico->format('Y-m-d H:i'));
        $this->assertTrue($r->inicioOcupado->equalTo($r->inicioServico));
        $this->assertTrue($r->fimOcupado->equalTo($r->fimServico));
        $this->assertSame(0, $r->taxaCentavos);
        $this->assertSame(0, $r->deslocamentoMinutos);
        $this->assertNull($r->regiaoNome);
        $this->assertSame(5000, $r->totalCentavos);
    }

    public function test_varios_servicos_somam_duracao_e_preco_na_ordem(): void
    {
        $a = $this->servico(3, 4000, 30);
        $b = $this->servico(1, 2500, 15);
        $c = $this->servico(2, 1000, 45);
        $r = CalculoDeReserva::calcular($this->utc('2026-10-05 13:00'), self::SP, 15, [$a, $b, $c], null);

        $this->assertSame(90, $r->duracaoMinutos);
        $this->assertSame('2026-10-05 14:30', $r->fimServico->format('Y-m-d H:i'));
        $this->assertSame([$a, $b, $c], $r->itens);
        $this->assertSame(7500, $r->totalCentavos);
    }

    public function test_domicilio_arredonda_ocupado_para_fora_na_grade(): void
    {
        // 10:00-10:30 local (13:00Z), deslocamento 20, grade 30.
        $regiao = new SnapshotRegiao(9, 'Centro', 20, 1500);
        $r = CalculoDeReserva::calcular($this->utc('2026-10-05 13:00'), self::SP, 30, [$this->servico(1, 5000, 30)], $regiao);

        $this->assertSame('2026-10-05 09:30', $this->hora($r->inicioOcupado));
        $this->assertSame('2026-10-05 11:00', $this->hora($r->fimOcupado));
        $this->assertSame(20, $r->deslocamentoMinutos);
        $this->assertSame(1500, $r->taxaCentavos);
        $this->assertSame('Centro', $r->regiaoNome);
        $this->assertSame(6500, $r->totalCentavos);
        $this->assertDeslocamentoReservado($r);
    }

    public function test_domicilio_deslocamento_exato_da_grade(): void
    {
        $regiao = new SnapshotRegiao(9, 'Centro', 30, 0);
        $r = CalculoDeReserva::calcular($this->utc('2026-10-05 13:00'), self::SP, 30, [$this->servico(1, 5000, 30)], $regiao);

        $this->assertSame('2026-10-05 09:30', $this->hora($r->inicioOcupado));
        $this->assertSame('2026-10-05 11:00', $this->hora($r->fimOcupado));
        $this->assertDeslocamentoReservado($r);
    }

    public function test_arredondamento_atravessa_a_meia_noite_local(): void
    {
        // 00:00 local do dia 5 = 03:00Z.
        $regiao = new SnapshotRegiao(9, 'Centro', 20, 0);
        $r = CalculoDeReserva::calcular($this->utc('2026-10-05 03:00'), self::SP, 30, [$this->servico(1, 5000, 30)], $regiao);

        $this->assertSame('2026-10-04 23:30', $this->hora($r->inicioOcupado));
        $this->assertSame('2026-10-05 01:00', $this->hora($r->fimOcupado));
        $this->assertDeslocamentoReservado($r);
    }

    public function test_resultado_em_minutos_cheios(): void
    {
        $regiao = new SnapshotRegiao(9, 'Centro', 25, 0);
        $r = CalculoDeReserva::calcular($this->utc('2026-10-05 13:00'), self::SP, 15, [$this->servico(1, 5000, 45)], $regiao);

        foreach ([$r->inicioServico, $r->fimServico, $r->inicioOcupado, $r->fimOcupado] as $i) {
            $this->assertSame(0, (int) $i->format('s'));
            $this->assertSame(0, (int) $i->format('u'));
        }
        $this->assertSame('2026-10-05 09:30', $this->hora($r->inicioOcupado));
        $this->assertSame('2026-10-05 11:15', $this->hora($r->fimOcupado));
        $this->assertLessThanOrEqual(24 * 60, ($r->fimOcupado->getTimestamp() - $r->inicioOcupado->getTimestamp()) / 60);
    }

    private function assertDeslocamentoReservado(ReservaCalculada $r): void
    {
        $this->assertGreaterThanOrEqual($r->deslocamentoMinutos * 60, $r->inicioServico->getTimestamp() - $r->inicioOcupado->getTimestamp());
        $this->assertGreaterThanOrEqual($r->deslocamentoMinutos * 60, $r->fimOcupado->getTimestamp() - $r->fimServico->getTimestamp());
    }

    // ---- V3 antecedencia, V4 horizonte ----

    public function test_antecedencia_no_limite_passa_e_um_minuto_antes_falha(): void
    {
        $agora = $this->utc('2026-10-05 12:00');
        CalculoDeReserva::exigirAntecedencia($this->utc('2026-10-05 13:00'), $agora, 60);
        $this->addToAssertionCount(1);

        $this->exigirRecusa('antecedencia', fn () => CalculoDeReserva::exigirAntecedencia($this->utc('2026-10-05 12:59'), $agora, 60));
    }

    public function test_horizonte_hoje_mais_dias_passa_e_mais_um_falha(): void
    {
        $agora = $this->utc('2026-10-05 15:00');
        CalculoDeReserva::exigirHorizonte('2026-11-04', $agora, self::SP, 30);
        $this->addToAssertionCount(1);

        $this->exigirRecusa('alem_do_horizonte', fn () => CalculoDeReserva::exigirHorizonte('2026-11-05', $agora, self::SP, 30));
    }

    public function test_horizonte_usa_o_dia_local_do_fuso(): void
    {
        // 02:00Z de 5/10 ainda e dia 4 em Sao Paulo: 4 + 1 = dia 5.
        $agora = $this->utc('2026-10-05 02:00');
        CalculoDeReserva::exigirHorizonte('2026-10-05', $agora, self::SP, 1);
        $this->addToAssertionCount(1);

        $this->exigirRecusa('alem_do_horizonte', fn () => CalculoDeReserva::exigirHorizonte('2026-10-06', $agora, self::SP, 1));
    }
}
