<?php

namespace App\Http\Controllers\Painel;

use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\ReservarHorario;
use App\Domain\Painel\ConsultasDoPainel;
use App\Domain\Painel\EscopoDoPainel;
use App\Domain\Painel\MensagemParaCliente;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Iniciar atendimento, concluir, nao compareceu e cancelar (com motivo).
 *
 * Mesmas garantias das acoes de pedido: o ator e SEMPRE o usuario da sessao, o
 * codigo vai por POST (nunca na URL), a reserva e buscada DENTRO do escopo do
 * usuario (a de outro profissional responde igual a uma que nao existe), a
 * policy confere de novo e o dominio confere uma terceira vez, travado, com o
 * filtro de profissional na mesma consulta.
 */
final class AcoesDaAgendaController extends Controller
{
    private const MENSAGENS = [
        'reserva_nao_encontrada' => 'Esta reserva não está mais disponível. A agenda foi atualizada.',
        'estado_nao_permite' => 'Outra pessoa já mudou esta reserva, ou o estado dela não permite essa ação. A agenda foi atualizada.',
        'motivo_do_cancelamento_obrigatorio' => 'Escreva o motivo do cancelamento.',
        'motivo_muito_longo' => 'O motivo pode ter no máximo 300 caracteres.',
    ];

    private const MENSAGEM_PADRAO = 'Não foi possível concluir agora. Atualize a agenda e tente de novo.';

    public function __construct(
        private readonly ConsultasDoPainel $consultas,
        private readonly ReservarHorario $reservas,
    ) {}

    public function iniciar(Request $request): RedirectResponse
    {
        return $this->agir($request, 'iniciar', 'Atendimento iniciado.', null,
            fn (string $codigo, $usuario, ?int $profissional) => $this->reservas->iniciar($codigo, $usuario, $profissional));
    }

    public function concluir(Request $request): RedirectResponse
    {
        return $this->agir($request, 'concluir', 'Atendimento concluído.', null,
            fn (string $codigo, $usuario, ?int $profissional) => $this->reservas->concluir($codigo, $usuario, $profissional));
    }

    public function faltou(Request $request): RedirectResponse
    {
        return $this->agir($request, 'faltou', 'Falta registrada.', null,
            fn (string $codigo, $usuario, ?int $profissional) => $this->reservas->marcarFalta($codigo, $usuario, $profissional));
    }

    public function cancelar(Request $request): RedirectResponse
    {
        $motivo = $request->input('motivo');
        $motivo = is_string($motivo) ? $motivo : null;

        // Cancelar oferece avisar o cliente: o codigo vai no flash (so ele; a reserva e relida no escopo).
        return $this->agir($request, 'cancelar', null, MensagemParaCliente::CANCELADO,
            fn (string $codigo, $usuario, ?int $profissional) => $this->reservas->cancelarComMotivo($codigo, $usuario, $motivo, $profissional));
    }

    private function agir(Request $request, string $habilidade, ?string $sucesso, ?string $avisoAoCliente, Closure $acao): RedirectResponse
    {
        $usuario = $request->user();
        $codigo = $request->input('reserva');
        $codigo = is_string($codigo) ? trim($codigo) : '';

        $reserva = $codigo === '' ? null : $this->consultas->reserva($usuario, $codigo);
        if ($reserva === null) {
            return redirect('/painel/agenda')->with('erro', self::MENSAGENS['reserva_nao_encontrada']);
        }
        Gate::authorize($habilidade, $reserva);

        try {
            $acao($codigo, $usuario, EscopoDoPainel::de($usuario)->profissionalId());
        } catch (ReservaRecusada $e) {
            return redirect('/painel/agenda')->with('erro', self::MENSAGENS[$e->codigo] ?? self::MENSAGEM_PADRAO);
        }

        $resposta = redirect('/painel/agenda');
        if ($sucesso !== null) {
            $resposta->with('sucesso', $sucesso);
        }
        if ($avisoAoCliente !== null) {
            $resposta->with('acao', ['codigo' => $codigo, 'tipo' => $avisoAoCliente]);
        }

        return $resposta;
    }
}
