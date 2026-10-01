<?php

namespace App\Http\Controllers\Painel;

use App\Domain\Painel\ApresentacaoDaReserva;
use App\Domain\Painel\ConsultasDoPainel;
use App\Domain\Painel\MensagemParaCliente;
use App\Models\Agendamento;
use App\Models\Estabelecimento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Montagem das reservas para as telas do painel: texto pronto no fuso do
 * estabelecimento, e o aviso ao cliente (WhatsApp) depois de uma acao.
 */
trait ComReservasNaTela
{
    /**
     * @param  Collection<int, Agendamento>  $reservas
     * @return list<array<string, mixed>>
     */
    private function apresentar(Collection $reservas): array
    {
        $fuso = Estabelecimento::atual()?->fuso_horario ?? 'America/Sao_Paulo';
        $agora = CarbonImmutable::now();
        $horas = max(1, (int) config('cleison.reservas.solicitado_expira_horas', 12));

        return $reservas->map(fn (Agendamento $r) => (new ApresentacaoDaReserva($r, $fuso, $agora, $horas))->paraTela())->values()->all();
    }

    /**
     * O resultado da ultima acao do proprio usuario (flash de UMA requisicao):
     * so o codigo vai na sessao, e a reserva e relida DENTRO do escopo dele (um
     * codigo plantado na sessao de outro usuario nao mostra nada). O botao do
     * WhatsApp so existe se o estado confere com o que foi feito.
     *
     * @return array{tipo: string, reserva: array<string, mixed>, link: ?string}|null
     */
    private function resultadoDaAcao(Request $request, User $usuario, ConsultasDoPainel $consultas): ?array
    {
        $acao = $request->session()->get('acao');
        if (! is_array($acao) || ! is_string($acao['codigo'] ?? null) || ! in_array($acao['tipo'] ?? null, [MensagemParaCliente::CONFIRMADO, MensagemParaCliente::RECUSADO], true)) {
            return null;
        }

        $reserva = $consultas->reserva($usuario, $acao['codigo']);
        $estadoEsperado = $acao['tipo'] === MensagemParaCliente::CONFIRMADO ? 'confirmado' : 'cancelado';
        if ($reserva === null || $reserva->estado->value !== $estadoEsperado) {
            return null;
        }

        $tela = $this->apresentar(collect([$reserva]))[0];

        return ['tipo' => $acao['tipo'], 'reserva' => $tela, 'link' => MensagemParaCliente::link($tela, $acao['tipo'])];
    }
}
