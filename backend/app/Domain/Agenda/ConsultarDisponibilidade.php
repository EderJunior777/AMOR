<?php

namespace App\Domain\Agenda;

use App\Enums\Modalidade;
use App\Models\Estabelecimento;
use App\Models\ExcecaoExpediente;
use App\Models\ExpedienteSemanal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Horarios de INICIO livres de UM dia (docs/ESPEC-RESERVA.md, secao 8). So
 * leitura: nao grava nada e nao trava nada.
 *
 * Usa as mesmas regras da reserva, sem copia: CatalogoDaReserva (V5 a V7),
 * CalculoDeReserva (grade, periodo ocupado com deslocamento), antecedencia
 * (V3) e JanelasDeExpediente (V8, sobre o periodo OCUPADO arredondado).
 * Subtrai as ocupacoes do profissional (agendamentos que seguram horario e
 * bloqueios) com UMA consulta para o dia: qualquer sobreposicao com o
 * periodo ocupado candidato tira o horario. Horario que nao existe (ou e
 * ambiguo) na virada do horario de verao e pulado, como a reserva o recusaria.
 *
 * E so uma sugestao: a garantia contra dois clientes no mesmo horario e a
 * constraint de exclusao do banco no INSERT (V9). A resposta nunca leva
 * nome, id nem motivo de bloqueio: so "HH:MM".
 *
 * Chamada por: GET /api/v1/disponibilidade (DisponibilidadeController).
 */
final class ConsultarDisponibilidade
{
    /**
     * Data passada (antes de hoje, no fuso) e recusada como "antecedencia":
     * o mesmo codigo que a reserva daria para qualquer horario que ja passou.
     * A lista vazia ficaria indistinguivel de "dia sem expediente".
     *
     * @param  list<int>  $servicos
     * @return list<string> "HH:MM" em ordem crescente
     *
     * @throws ReservaRecusada data_invalida, antecedencia, alem_do_horizonte, servico_indisponivel,
     *                         profissional_indisponivel, domicilio_indisponivel, agenda_indisponivel
     */
    public function executar(string $data, array $servicos, int $profissionalId, Modalidade $modalidade, ?int $regiaoId): array
    {
        $estabelecimento = Estabelecimento::atual() ?? throw ReservaRecusada::por('agenda_indisponivel');
        $fuso = $estabelecimento->fuso_horario;
        $grade = $estabelecimento->grade_minutos;
        $agora = CarbonImmutable::now();

        $this->exigirDataValida($data, $fuso, $grade);
        if ($data < $agora->setTimezone($fuso)->format('Y-m-d')) {
            throw ReservaRecusada::por('antecedencia');
        }
        CalculoDeReserva::exigirHorizonte($data, $agora, $fuso, $estabelecimento->horizonte_dias);

        $catalogo = CatalogoDaReserva::ler($this->pedidoDeConsulta($data, $servicos, $profissionalId, $modalidade, $regiaoId), $estabelecimento, travar: false);
        $janelas = $this->janelas($profissionalId, $data, $fuso);

        // Candidatos: todo horario da grade que cabe no expediente e respeita a antecedencia.
        $candidatos = [];
        for ($minuto = 0; $minuto < 24 * 60; $minuto += $grade) {
            $hora = sprintf('%02d:%02d', intdiv($minuto, 60), $minuto % 60);
            try {
                $inicio = CalculoDeReserva::instante($data, $hora, $fuso, $grade);
            } catch (ReservaRecusada $e) {
                if ($e->codigo === 'hora_inexistente') {
                    continue;
                }
                throw $e;
            }
            try {
                CalculoDeReserva::exigirAntecedencia($inicio, $agora, $estabelecimento->antecedencia_minima_minutos);
            } catch (ReservaRecusada) {
                continue;
            }
            $reserva = CalculoDeReserva::calcular($inicio, $fuso, $grade, $catalogo->servicos, $catalogo->regiao);
            if (JanelasDeExpediente::cabe($reserva->inicioOcupado, $reserva->fimOcupado, $janelas)) {
                $candidatos[$hora] = $reserva;
            }
        }
        if ($candidatos === []) {
            return [];
        }

        $ocupacoes = $this->ocupacoes(
            $profissionalId,
            min(array_map(fn (ReservaCalculada $r) => $r->inicioOcupado, $candidatos)),
            max(array_map(fn (ReservaCalculada $r) => $r->fimOcupado, $candidatos)),
        );

        $livres = [];
        foreach ($candidatos as $hora => $reserva) {
            $inicio = $reserva->inicioOcupado->getTimestamp();
            $fim = $reserva->fimOcupado->getTimestamp();
            $bate = false;
            foreach ($ocupacoes as [$ocupadoDe, $ocupadoAte]) {
                if ($ocupadoDe < $fim && $inicio < $ocupadoAte) {
                    $bate = true;
                    break;
                }
            }
            if (! $bate) {
                $livres[] = (string) $hora;
            }
        }

        return $livres;
    }

    /** V1 para o dia inteiro: a data existe no calendario (meia-noite inexistente nao invalida a data). */
    private function exigirDataValida(string $data, string $fuso, int $grade): void
    {
        try {
            CalculoDeReserva::instante($data, '00:00', $fuso, $grade);
        } catch (ReservaRecusada $e) {
            if ($e->codigo !== 'hora_inexistente') {
                throw $e;
            }
        }
    }

    /**
     * O catalogo so le o que o cliente escolhe. Nome, telefone, hora e
     * logradouro sao marcadores: nao entram em nenhum calculo nem em nenhuma
     * gravacao desta consulta (que e so leitura).
     *
     * @param  list<int>  $servicos
     */
    private function pedidoDeConsulta(string $data, array $servicos, int $profissionalId, Modalidade $modalidade, ?int $regiaoId): PedidoDeReserva
    {
        return new PedidoDeReserva(
            servicos: $servicos,
            profissionalId: $profissionalId,
            data: $data,
            hora: '00:00',
            modalidade: $modalidade,
            clienteNome: 'Consulta',
            clienteTelefone: '+5511900000000',
            regiaoId: $regiaoId,
            logradouro: 'Consulta de disponibilidade',
        );
    }

    /** @return list<Janela> */
    private function janelas(int $profissionalId, string $data, string $fuso): array
    {
        return JanelasDeExpediente::doDia(
            $data,
            $fuso,
            ExpedienteSemanal::query()->where('profissional_id', $profissionalId)->get()
                ->map(fn (ExpedienteSemanal $e) => [
                    'dia_semana' => $e->dia_semana, 'hora_inicio' => $e->hora_inicio, 'hora_fim' => $e->hora_fim,
                ])->all(),
            ExcecaoExpediente::query()->where('profissional_id', $profissionalId)
                ->where('data', $data)->get()
                ->map(fn (ExcecaoExpediente $e) => [
                    'data' => $e->data->format('Y-m-d'), 'hora_inicio' => $e->hora_inicio, 'hora_fim' => $e->hora_fim,
                ])->all(),
        );
    }

    /**
     * Ocupacoes do profissional que tocam [$de, $ate): so os limites, em
     * segundos Unix. Nenhuma coluna de origem (agendamento ou bloqueio) sai do banco.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function ocupacoes(int $profissionalId, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        return DB::table('ocupacoes_agenda')
            ->where('profissional_id', $profissionalId)
            ->whereRaw("periodo && tstzrange(?::timestamptz, ?::timestamptz, '[)')", [
                $de->utc()->format('Y-m-d H:i:s').'+00',
                $ate->utc()->format('Y-m-d H:i:s').'+00',
            ])
            ->selectRaw('extract(epoch from lower(periodo))::bigint as de, extract(epoch from upper(periodo))::bigint as ate')
            ->get()
            ->map(fn ($linha) => [(int) $linha->de, (int) $linha->ate])
            ->all();
    }
}
