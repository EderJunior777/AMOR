<?php

namespace App\Domain\Agenda;

use Carbon\CarbonImmutable;

/**
 * Janelas de expediente de um dia (semanais ou excecoes) e a regra V8:
 * o periodo ocupado cabe inteiro em UMA janela (docs/ESPEC-RESERVA.md, 3 V8).
 * Funcoes puras, sem banco.
 */
final class JanelasDeExpediente
{
    /**
     * Janelas do dia local $data, em UTC e ordenadas. Se existir qualquer
     * excecao na data, ela(s) substitui(em) as semanais. Janelas que encostam
     * viram uma so; com buraco (almoco) continuam separadas.
     *
     * @param  list<array{dia_semana:int, hora_inicio:string, hora_fim:string}>  $semanais
     * @param  list<array{data:string, hora_inicio:string, hora_fim:string}>  $excecoes
     * @return list<Janela>
     */
    public static function doDia(string $data, string $fuso, array $semanais, array $excecoes): array
    {
        $doDia = array_values(array_filter($excecoes, fn (array $e) => $e['data'] === $data));

        if ($doDia === []) {
            // dayOfWeek do Carbon: 0 = domingo; da data LOCAL, nao do UTC
            $diaSemana = CarbonImmutable::parse($data.' 00:00:00', $fuso)->dayOfWeek;
            $doDia = array_values(array_filter($semanais, fn (array $s) => (int) $s['dia_semana'] === $diaSemana));
        }

        $pares = [];
        foreach ($doDia as $linha) {
            $pares[] = [
                self::instante($data, $linha['hora_inicio'], $fuso),
                self::instante($data, $linha['hora_fim'], $fuso),
            ];
        }
        usort($pares, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $janelas = [];
        foreach ($pares as [$inicio, $fim]) {
            $ultimo = count($janelas) - 1;
            if ($ultimo >= 0 && $janelas[$ultimo]->fim == $inicio) {
                $janelas[$ultimo] = new Janela($janelas[$ultimo]->inicio, $fim);

                continue;
            }
            $janelas[] = new Janela($inicio, $fim);
        }

        return $janelas;
    }

    /**
     * V8: true sse UMA unica janela contem o periodo inteiro. Nao atravessa
     * almoco nem a meia-noite (janelas sao sempre de um so dia).
     *
     * @param  list<Janela>  $janelas
     */
    public static function cabe(CarbonImmutable $inicioOcupado, CarbonImmutable $fimOcupado, array $janelas): bool
    {
        foreach ($janelas as $janela) {
            if ($janela->contem($inicioOcupado, $fimOcupado)) {
                return true;
            }
        }

        return false;
    }

    /** 'HH:MM' ou 'HH:MM:SS' de parede no fuso; '24:00' = 00:00 do dia seguinte. */
    private static function instante(string $data, string $hora, string $fuso): CarbonImmutable
    {
        if (str_starts_with($hora, '24:')) {
            return CarbonImmutable::parse($data.' 00:00:00', $fuso)->addDay()->utc();
        }

        return CarbonImmutable::parse($data.' '.$hora, $fuso)->utc();
    }
}
