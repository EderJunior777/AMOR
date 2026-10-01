<?php

namespace App\Http\Controllers\Painel;

use App\Domain\Equipe\AcessoCriado;
use App\Domain\Equipe\EquipeRecusada;
use App\Domain\Equipe\GerenciarEquipe;
use App\Enums\PapelUsuario;
use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use App\Models\Profissional;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Menu "Equipe" (so o proprietario ativo): listar, criar barbeiro e recepcao,
 * desativar, reativar e redefinir senha.
 *
 * O autor e SEMPRE o usuario da sessao. A senha temporaria existe so na
 * resposta que a cria (view renderizada direto no POST, no-store): nao vai para
 * a sessao, a auditoria, o log nem para a proxima tela. O usuario-alvo vai por
 * POST (nunca na URL); proprietario e o proprio autor nunca sao alvo (policy
 * aqui e GerenciarEquipe no dominio, que confere de novo, no banco).
 */
final class EquipeController extends Controller
{
    public function __construct(private readonly GerenciarEquipe $equipe) {}

    public function index(Request $request): View
    {
        Gate::authorize('gerirEquipe', User::class);

        $fuso = Estabelecimento::atual()?->fuso_horario ?? 'America/Sao_Paulo';

        $pessoas = User::query()->with('profissional')->orderByRaw("papel = 'proprietario' DESC")->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => (int) $u->getKey(),
                'nome' => $u->name,
                'email' => $u->email,
                'papel' => $u->papel->rotulo(),
                'ehProprietario' => $u->papel === PapelUsuario::Proprietario,
                'ativo' => (bool) $u->ativo,
                'profissional' => $u->profissional?->nome_exibicao,
                'senhaTemporaria' => (bool) $u->senha_temporaria,
                'ultimoAcesso' => $u->ultimo_acesso_em !== null
                    ? CarbonImmutable::instance($u->ultimo_acesso_em)->setTimezone($fuso)->format('d/m/Y H:i')
                    : null,
            ])->all();

        $livres = Profissional::query()->whereNull('user_id')->where('ativo', true)->orderBy('nome_exibicao')->get(['id', 'nome_exibicao']);

        return view('painel.equipe', ['pessoas' => $pessoas, 'profissionaisLivres' => $livres]);
    }

    public function criar(Request $request): View|RedirectResponse
    {
        Gate::authorize('gerirEquipe', User::class);

        return $this->executar(function (User $autor) use ($request) {
            $papel = PapelUsuario::tryFrom($this->texto($request, 'papel')) ?? throw EquipeRecusada::por('papel_invalido');

            return $this->equipe->criarUsuario(
                $autor,
                $this->texto($request, 'nome'),
                $this->texto($request, 'email'),
                $papel,
                $this->inteiro($request->input('profissional_id')),
            );
        }, $request, 'Acesso criado');
    }

    public function desativar(Request $request): View|RedirectResponse
    {
        Gate::authorize('gerirEquipe', User::class);

        return $this->executar(function (User $autor) use ($request) {
            $this->equipe->desativar($autor, $this->alvo($request, $autor));

            return null;
        }, $request, '', 'Usuário desativado.');
    }

    public function reativar(Request $request): View|RedirectResponse
    {
        Gate::authorize('gerirEquipe', User::class);

        return $this->executar(fn (User $autor) => $this->equipe->reativar($autor, $this->alvo($request, $autor)), $request, 'Acesso reativado');
    }

    public function redefinirSenha(Request $request): View|RedirectResponse
    {
        Gate::authorize('gerirEquipe', User::class);

        return $this->executar(fn (User $autor) => $this->equipe->redefinirSenha($autor, $this->alvo($request, $autor)), $request, 'Senha redefinida');
    }

    /**
     * @param  Closure(User): ?AcessoCriado  $acao
     */
    private function executar(Closure $acao, Request $request, string $titulo, ?string $sucesso = null): View|RedirectResponse
    {
        try {
            $acesso = $acao($request->user());
        } catch (EquipeRecusada $e) {
            return redirect('/painel/equipe')->with('erro', $e->getMessage());
        }

        if ($acesso === null) {
            return redirect('/painel/equipe')->with('sucesso', $sucesso);
        }

        // A senha vai SO nesta resposta (nada de redirecionamento: a sessao nao a guarda).
        return view('painel.equipe-acesso', [
            'titulo' => $titulo,
            'nome' => $acesso->usuario->name,
            'email' => $acesso->usuario->email,
            'papel' => $acesso->usuario->papel->rotulo(),
            'senha' => $acesso->senhaTemporaria,
        ]);
    }

    /** O id do alvo (POST). Policy "gerir": proprietario e o proprio autor nao sao alvo (mensagem, nao erro cru). */
    private function alvo(Request $request, User $autor): int
    {
        $id = $this->inteiro($request->input('usuario')) ?? throw EquipeRecusada::por('usuario_nao_encontrado');

        $alvo = User::query()->find($id);
        if ($alvo !== null && ! Gate::forUser($autor)->allows('gerir', $alvo)) {
            throw EquipeRecusada::por('alvo_protegido');
        }

        return $id;
    }

    private function texto(Request $request, string $campo): string
    {
        $valor = $request->input($campo);

        return is_string($valor) ? $valor : '';
    }

    private function inteiro(mixed $valor): ?int
    {
        if (! is_string($valor) && ! is_int($valor)) {
            return null;
        }
        $inteiro = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $inteiro === false ? null : $inteiro;
    }
}
