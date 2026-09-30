<?php

namespace Tests\Unit\Agenda;

use App\Domain\Agenda\Janela;
use App\Domain\Agenda\JanelasDeExpediente;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Janelas de expediente de um dia e regra V8 (docs/ESPEC-RESERVA.md, 3 V8). */
class JanelasDeExpedienteTest extends TestCase
{
    private const FUSO = 'America/Sao_Paulo';

    /** Instante a partir de horario de parede local. */
    private function local(string $data, string $hora, string $fuso = self::FUSO): CarbonImmutable
    {
        return CarbonImmutable::parse($data.' '.$hora, $fuso)->utc();
    }

    private function semanal(int $dia, string $ini, string $fim): array
    {
        return ['dia_semana' => $dia, 'hora_inicio' => $ini, 'hora_fim' => $fim];
    }

    private function excecao(string $data, string $ini, string $fim): array
    {
        return ['data' => $data, 'hora_inicio' => $ini, 'hora_fim' => $fim];
    }

    /** @return list<string> janelas como "inicio|fim" em UTC */
    private function texto(array $janelas): array
    {
        return array_map(
            fn (Janela $j) => $j->inicio->utc()->format('Y-m-d H:i').'|'.$j->fim->utc()->format('Y-m-d H:i'),
            $janelas,
        );
    }

    public function test_janela_contem_usa_intervalo_fechado_no_inicio_e_aberto_no_fim(): void
    {
        $j = new Janela($this->local('2026-10-05', '08:00'), $this->local('2026-10-05', '12:00'));

        $this->assertTrue($j->contem($this->local('2026-10-05', '08:00'), $this->local('2026-10-05', '12:00')));
        $this->assertFalse($j->contem($this->local('2026-10-05', '07:59'), $this->local('2026-10-05', '09:00')));
        $this->assertFalse($j->contem($this->local('2026-10-05', '11:00'), $this->local('2026-10-05', '12:01')));
    }

    public function test_escolhe_janelas_pelo_dia_da_semana_e_ignora_outros_dias(): void
    {
        $semanais = [
            $this->semanal(0, '10:00', '14:00'), // domingo
            $this->semanal(1, '08:00', '18:00'), // segunda
            $this->semanal(2, '09:00', '17:00'),
        ];

        // 2026-10-04 e domingo
        $domingo = JanelasDeExpediente::doDia('2026-10-04', self::FUSO, $semanais, []);
        $this->assertSame(['2026-10-04 13:00|2026-10-04 17:00'], $this->texto($domingo));

        // 2026-10-05 e segunda
        $segunda = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, $semanais, []);
        $this->assertSame(['2026-10-05 11:00|2026-10-05 21:00'], $this->texto($segunda));
    }

    public function test_dia_sem_janela_devolve_lista_vazia(): void
    {
        $semanais = [$this->semanal(1, '08:00', '18:00')];

        $this->assertSame([], JanelasDeExpediente::doDia('2026-10-06', self::FUSO, $semanais, []));
        $this->assertSame([], JanelasDeExpediente::doDia('2026-10-06', self::FUSO, [], []));
    }

    public function test_excecao_da_data_substitui_as_semanais(): void
    {
        $semanais = [$this->semanal(1, '08:00', '18:00')];
        $excecoes = [$this->excecao('2026-10-05', '10:00', '12:00')];

        $janelas = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, $semanais, $excecoes);

        $this->assertSame(['2026-10-05 13:00|2026-10-05 15:00'], $this->texto($janelas));
    }

    public function test_excecao_pode_abrir_um_dia_sem_expediente_semanal(): void
    {
        $excecoes = [$this->excecao('2026-10-06', '10:00', '12:00')];

        $janelas = JanelasDeExpediente::doDia('2026-10-06', self::FUSO, [], $excecoes);

        $this->assertSame(['2026-10-06 13:00|2026-10-06 15:00'], $this->texto($janelas));
    }

    public function test_excecao_de_outra_data_nao_interfere(): void
    {
        $semanais = [$this->semanal(1, '08:00', '18:00')];
        $excecoes = [$this->excecao('2026-10-12', '10:00', '12:00')];

        $janelas = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, $semanais, $excecoes);

        $this->assertSame(['2026-10-05 11:00|2026-10-05 21:00'], $this->texto($janelas));
    }

    public function test_varias_excecoes_da_data_substituem_juntas_e_saem_ordenadas(): void
    {
        $semanais = [$this->semanal(1, '08:00', '18:00')];
        $excecoes = [
            $this->excecao('2026-10-05', '14:00', '16:00'),
            $this->excecao('2026-10-05', '09:00', '11:00'),
        ];

        $janelas = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, $semanais, $excecoes);

        $this->assertSame(
            ['2026-10-05 12:00|2026-10-05 14:00', '2026-10-05 17:00|2026-10-05 19:00'],
            $this->texto($janelas),
        );
    }

    public function test_aceita_horas_com_segundos(): void
    {
        $semanais = [$this->semanal(1, '08:00:00', '12:30:00')];

        $janelas = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, $semanais, []);

        $this->assertSame(['2026-10-05 11:00|2026-10-05 15:30'], $this->texto($janelas));
    }

    public function test_janelas_que_encostam_formam_uma_so(): void
    {
        $semanais = [
            $this->semanal(1, '12:00', '13:00'),
            $this->semanal(1, '08:00', '12:00'),
        ];

        $janelas = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, $semanais, []);

        $this->assertCount(1, $janelas);
        $this->assertSame(['2026-10-05 11:00|2026-10-05 16:00'], $this->texto($janelas));
        $this->assertTrue(JanelasDeExpediente::cabe(
            $this->local('2026-10-05', '11:30'),
            $this->local('2026-10-05', '12:30'),
            $janelas,
        ));
    }

    public function test_janelas_com_buraco_continuam_separadas(): void
    {
        $semanais = [
            $this->semanal(1, '08:00', '12:00'),
            $this->semanal(1, '13:00', '20:00'),
        ];

        $janelas = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, $semanais, []);
        $this->assertCount(2, $janelas);

        $cabe = fn (string $ini, string $fim) => JanelasDeExpediente::cabe(
            $this->local('2026-10-05', $ini),
            $this->local('2026-10-05', $fim),
            $janelas,
        );

        $this->assertFalse($cabe('11:30', '12:30'));
        $this->assertFalse($cabe('12:00', '12:30'));
        $this->assertTrue($cabe('13:00', '13:30'));
        $this->assertTrue($cabe('11:00', '12:00'));
    }

    public function test_bordas_da_janela(): void
    {
        $janelas = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, [$this->semanal(1, '08:00', '20:00')], []);

        $cabe = fn (string $ini, string $fim) => JanelasDeExpediente::cabe(
            $this->local('2026-10-05', $ini),
            $this->local('2026-10-05', $fim),
            $janelas,
        );

        $this->assertTrue($cabe('19:00', '20:00'), 'termina exatamente no fim');
        $this->assertTrue($cabe('08:00', '09:00'), 'comeca exatamente no inicio');
        $this->assertFalse($cabe('20:00', '21:00'), 'comeca no fim');
        $this->assertFalse($cabe('19:30', '20:01'), 'passa 1 minuto do fim');
        $this->assertFalse($cabe('07:59', '09:00'), '1 minuto antes do inicio');
    }

    public function test_cabe_sem_janelas_e_false(): void
    {
        $this->assertFalse(JanelasDeExpediente::cabe(
            $this->local('2026-10-05', '10:00'),
            $this->local('2026-10-05', '11:00'),
            [],
        ));
    }

    public function test_24_horas_vai_ate_a_meia_noite_do_dia_seguinte(): void
    {
        foreach (['24:00', '24:00:00'] as $fimTexto) {
            $janelas = JanelasDeExpediente::doDia(
                '2026-10-05',
                self::FUSO,
                [$this->semanal(1, '20:00', $fimTexto)],
                [],
            );

            $this->assertSame(['2026-10-05 23:00|2026-10-06 03:00'], $this->texto($janelas));
            $this->assertEquals($this->local('2026-10-06', '00:00'), $janelas[0]->fim);
        }
    }

    public function test_periodo_que_atravessa_a_meia_noite_nao_cabe(): void
    {
        $janelas = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, [$this->semanal(1, '20:00', '24:00')], []);

        $this->assertFalse(JanelasDeExpediente::cabe(
            $this->local('2026-10-05', '23:30'),
            $this->local('2026-10-06', '00:30'),
            $janelas,
        ));
        $this->assertTrue(JanelasDeExpediente::cabe(
            $this->local('2026-10-05', '23:30'),
            $this->local('2026-10-06', '00:00'),
            $janelas,
        ));
    }

    public function test_nao_junta_janelas_de_dias_diferentes(): void
    {
        // Terca 00:00-08:00 nao se junta com a janela de segunda que vai ate 24:00.
        $semanais = [$this->semanal(1, '20:00', '24:00'), $this->semanal(2, '00:00', '08:00')];

        $segunda = JanelasDeExpediente::doDia('2026-10-05', self::FUSO, $semanais, []);
        $terca = JanelasDeExpediente::doDia('2026-10-06', self::FUSO, $semanais, []);

        $this->assertFalse(JanelasDeExpediente::cabe(
            $this->local('2026-10-05', '23:30'),
            $this->local('2026-10-06', '00:30'),
            $segunda,
        ));
        $this->assertFalse(JanelasDeExpediente::cabe(
            $this->local('2026-10-05', '23:30'),
            $this->local('2026-10-06', '00:30'),
            $terca,
        ));
    }

    public function test_conversao_de_fuso_na_troca_de_horario_de_new_york(): void
    {
        $semanais = array_map(fn (int $d) => $this->semanal($d, '08:00', '12:00'), range(0, 6));

        // 2026-03-08: o horario de verao comeca as 02:00 local; 08:00 ja e EDT (UTC-4).
        $marco = JanelasDeExpediente::doDia('2026-03-08', 'America/New_York', $semanais, []);
        $this->assertSame(['2026-03-08 12:00|2026-03-08 16:00'], $this->texto($marco));

        // 2026-03-07: ainda EST (UTC-5).
        $antes = JanelasDeExpediente::doDia('2026-03-07', 'America/New_York', $semanais, []);
        $this->assertSame(['2026-03-07 13:00|2026-03-07 17:00'], $this->texto($antes));

        // 2026-11-01: o horario de verao termina as 02:00 local; 08:00 ja e EST (UTC-5).
        $novembro = JanelasDeExpediente::doDia('2026-11-01', 'America/New_York', $semanais, []);
        $this->assertSame(['2026-11-01 13:00|2026-11-01 17:00'], $this->texto($novembro));
    }

    public function test_dia_da_semana_e_o_da_data_local_e_nao_o_do_utc(): void
    {
        // 2026-10-03 e sabado (6). 21:00-24:00 local em Sao Paulo (UTC-3) cai
        // em 00:00Z-03:00Z de domingo, mas o dia da semana continua sendo sabado.
        $semanais = [
            $this->semanal(6, '21:00', '24:00'),
            $this->semanal(0, '08:00', '09:00'),
        ];

        $janelas = JanelasDeExpediente::doDia('2026-10-03', self::FUSO, $semanais, []);

        $this->assertSame(['2026-10-04 00:00|2026-10-04 03:00'], $this->texto($janelas));

        // E o domingo local usa so a janela de domingo.
        $domingo = JanelasDeExpediente::doDia('2026-10-04', self::FUSO, $semanais, []);
        $this->assertSame(['2026-10-04 11:00|2026-10-04 12:00'], $this->texto($domingo));
    }

    public function test_dia_da_semana_em_fuso_a_frente_do_utc(): void
    {
        // Pacifico/Auckland (UTC+13 em outubro): 2026-10-05 e segunda local.
        $semanais = [$this->semanal(1, '00:30', '01:30'), $this->semanal(0, '10:00', '11:00')];

        $janelas = JanelasDeExpediente::doDia('2026-10-05', 'Pacific/Auckland', $semanais, []);

        // 00:30 local NZDT (UTC+13) = 11:30Z do dia anterior.
        $this->assertSame(['2026-10-04 11:30|2026-10-04 12:30'], $this->texto($janelas));
    }
}
