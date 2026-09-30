<?php

namespace App\Domain\Agenda;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Calculo puro da reserva (docs/ESPEC-RESERVA.md, 2.3 e V1 a V4). Sem banco e
 * sem relogio: o "agora" e sempre parametro.
 */
final class CalculoDeReserva
{
    /**
     * V1 e V2: data e hora de parede no fuso -> instante UTC. Recusa, nunca ajusta.
     */
    public static function instante(string $data, string $hora, string $fuso, int $gradeMinutos): CarbonImmutable
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $data, $d) !== 1
            || ! checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            throw ReservaRecusada::por('data_invalida');
        }
        if (preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $hora, $h) !== 1
            || $gradeMinutos < 1
            || (((int) $h[1]) * 60 + (int) $h[2]) % $gradeMinutos !== 0) {
            throw ReservaRecusada::por('fora_da_grade');
        }

        $tz = new DateTimeZone($fuso);
        $parede = (new DateTimeImmutable("{$data} {$hora}:00", new DateTimeZone('UTC')))->getTimestamp();

        // Offsets que o fuso teve entre ontem e amanha da data pedida.
        $offsets = [$tz->getOffset(new DateTimeImmutable('@'.($parede - 86400)))];
        foreach ($tz->getTransitions($parede - 2 * 86400, $parede + 2 * 86400) as $t) {
            $offsets[] = $t['offset'];
        }

        $candidatos = [];
        foreach (array_unique($offsets) as $offset) {
            $instante = $parede - $offset;
            if ($tz->getOffset(new DateTimeImmutable('@'.$instante)) === $offset) {
                $candidatos[] = $instante;
            }
        }
        if (count($candidatos) !== 1) {
            throw ReservaRecusada::por('hora_inexistente');
        }

        return CarbonImmutable::createFromTimestampUTC($candidatos[0])->utc();
    }

    /**
     * @param  list<SnapshotServico>  $servicos
     */
    public static function calcular(
        CarbonImmutable $inicioServico,
        string $fuso,
        int $gradeMinutos,
        array $servicos,
        ?SnapshotRegiao $regiao,
    ): ReservaCalculada {
        $duracao = array_sum(array_map(fn (SnapshotServico $s) => $s->duracaoMinutos, $servicos));
        $preco = array_sum(array_map(fn (SnapshotServico $s) => $s->precoCentavos, $servicos));
        $deslocamento = $regiao?->deslocamentoMinutos ?? 0;
        $taxa = $regiao?->taxaCentavos ?? 0;

        $inicio = $inicioServico->utc();
        $fim = $inicio->addMinutes($duracao);

        $alvoInicio = $inicio->subMinutes($deslocamento);
        $alvoFim = $fim->addMinutes($deslocamento);

        $inicioOcupado = $alvoInicio->subMinutes(self::minutosDoDia($alvoInicio, $fuso) % $gradeMinutos);
        $sobra = self::minutosDoDia($alvoFim, $fuso) % $gradeMinutos;
        $fimOcupado = $sobra === 0 ? $alvoFim : $alvoFim->addMinutes($gradeMinutos - $sobra);

        return new ReservaCalculada(
            itens: array_values($servicos),
            duracaoMinutos: $duracao,
            inicioServico: $inicio,
            fimServico: $fim,
            inicioOcupado: $inicioOcupado,
            fimOcupado: $fimOcupado,
            deslocamentoMinutos: $deslocamento,
            taxaCentavos: $taxa,
            regiaoNome: $regiao?->nome,
            totalCentavos: $preco + $taxa,
        );
    }

    /** V3: o inicio do servico precisa estar a pelo menos $minutos de agora (igual e permitido). */
    public static function exigirAntecedencia(CarbonImmutable $inicioServico, CarbonImmutable $agora, int $minutos): void
    {
        if ($inicioServico->lt($agora->addMinutes($minutos))) {
            throw ReservaRecusada::por('antecedencia');
        }
    }

    /** V4: a data (AAAA-MM-DD) nao passa de hoje local + $dias. */
    public static function exigirHorizonte(string $data, CarbonImmutable $agora, string $fuso, int $dias): void
    {
        $limite = $agora->setTimezone($fuso)->startOfDay()->addDays($dias)->format('Y-m-d');
        if ($data > $limite) {
            throw ReservaRecusada::por('alem_do_horizonte');
        }
    }

    private static function minutosDoDia(CarbonImmutable $instante, string $fuso): int
    {
        $local = $instante->setTimezone($fuso);

        return ((int) $local->format('H')) * 60 + (int) $local->format('i');
    }
}
