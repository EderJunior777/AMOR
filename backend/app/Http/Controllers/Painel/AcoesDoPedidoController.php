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
 * Confirmar e recusar um pedido.
 *
 * O ator e SEMPRE o usuario da sessao (nunca o corpo da requisicao). O codigo
 * da reserva vai por POST (nunca na URL). A reserva e buscada DENTRO do escopo
 * do usuario: a de outro profissional responde igual a uma que nao existe. Em
 * seguida a policy confere de novo, e o dominio confere uma terceira vez,
 * travado, com o filtro de profissional na mesma consulta. Estado que mudou
 * (outra pessoa agiu, o cliente cancelou, expirou) vira mensagem clara.
 */
final class AcoesDoPedidoController extends Controller
{
    private const MENSAGENS = [
        'reserva_nao_encontrada' => 'Este pedido não está mais disponível. A lista foi atualizada.',
        'estado_nao_permite' => 'Outra pessoa já agiu neste pedido, o cliente cancelou ou ele expirou. A lista foi atualizada.',
        'motivo_da_recusa_obrigatorio' => 'Escreva o motivo da recusa.',
        'motivo_muito_longo' => 'O motivo pode ter no máximo 300 caracteres.',
    ];

    private const MENSAGEM_PADRAO = 'Não foi possível concluir agora. Atualize a lista e tente de novo.';

    public function __construct(
        private readonly ConsultasDoPainel $consultas,
        private readonly ReservarHorario $reservas,
    ) {}

    public function confirmar(Request $request): RedirectResponse
    {
        return $this->agir($request, 'confirmar', MensagemParaCliente::CONFIRMADO,
            fn (string $codigo, $usuario, ?int $profissional) => $this->reservas->confirmar($codigo, $usuario, $profissional));
    }

    public function recusar(Request $request): RedirectResponse
    {
        $motivo = $request->input('motivo');
        $motivo = is_string($motivo) ? $motivo : null;

        return $this->agir($request, 'recusar', MensagemParaCliente::RECUSADO,
            fn (string $codigo, $usuario, ?int $profissional) => $this->reservas->recusar($codigo, $usuario, $motivo, $profissional));
    }

    private function agir(Request $request, string $habilidade, string $tipo, Closure $acao): RedirectResponse
    {
        $usuario = $request->user();
        $codigo = $request->input('reserva');
        $codigo = is_string($codigo) ? trim($codigo) : '';

        $reserva = $codigo === '' ? null : $this->consultas->reserva($usuario, $codigo);
        if ($reserva === null) {
            return $this->voltar('erro', self::MENSAGENS['reserva_nao_encontrada']);
        }
        Gate::authorize($habilidade, $reserva);

        try {
            $acao($codigo, $usuario, EscopoDoPainel::de($usuario)->profissionalId());
        } catch (ReservaRecusada $e) {
            return $this->voltar('erro', self::MENSAGENS[$e->codigo] ?? self::MENSAGEM_PADRAO);
        }

        return redirect('/painel')->with('acao', ['codigo' => $codigo, 'tipo' => $tipo]);
    }

    private function voltar(string $chave, string $mensagem): RedirectResponse
    {
        return redirect('/painel')->with($chave, $mensagem);
    }
}
