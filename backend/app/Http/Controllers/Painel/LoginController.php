<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditoriaDeAcesso;
use App\Support\HashIsca;
use App\Support\LimiteDeLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Login do painel (etapa 3, Fase 2).
 *
 * Contra enumeracao de contas: e-mail inexistente, senha errada e usuario
 * inativo dao a MESMA resposta, e a senha e SEMPRE conferida (contra o hash
 * de isca quando nao ha usuario), para o tempo ser parecido. O bloqueio por
 * excesso de falhas tambem e igual para e-mail que existe e que nao existe.
 * Nada de IP, senha ou e-mail de usuario conhecido vai para o banco ou o log.
 */
final class LoginController extends Controller
{
    private const MENSAGEM_FALHA = 'E-mail ou senha incorretos.';

    private const MENSAGEM_BLOQUEIO = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';

    /** bcrypt so usa 72 bytes; acima disso e abuso (nem confere o resto). */
    private const MAXIMO_DA_SENHA = 200;

    public function formulario(): View
    {
        return view('painel.entrar');
    }

    public function entrar(Request $request): RedirectResponse|Response
    {
        $bruto = $request->input('email');
        $senha = $request->input('senha');
        $senha = is_string($senha) ? $senha : '';
        $emailLongoDemais = is_string($bruto) && mb_strlen(trim($bruto)) > 254;
        $email = is_string($bruto) && ! $emailLongoDemais ? LimiteDeLogin::normalizarEmail($bruto) : '';
        $ip = (string) $request->ip();

        $usuario = $email === '' ? null : User::query()->whereRaw('lower(email) = ?', [$email])->first();

        // A tentativa e CONTADA antes de conferir a senha (bcrypt demora; em
        // paralelo, so as primeiras N passariam a conferir).
        $espera = LimiteDeLogin::espera($email, $ip) ?? LimiteDeLogin::contar($email, $ip);
        if ($espera !== null) {
            // Uma vez por janela: a trilha e imutavel e nao cresce com um flood.
            if (LimiteDeLogin::primeiroBloqueio($email, $ip, $espera)) {
                AuditoriaDeAcesso::acesso('login', 'bloqueado', $usuario?->getKey(), $email);
            }

            return response()
                ->view('painel.entrar', ['erro' => self::MENSAGEM_BLOQUEIO], 429)
                ->header('Retry-After', (string) $espera);
        }

        // Sempre uma verificacao de senha, haja usuario ou nao.
        $confere = Hash::check(substr($senha, 0, self::MAXIMO_DA_SENHA), (string) ($usuario?->password ?? HashIsca::obter()));

        if ($usuario === null || ! $usuario->ativo || ! $confere || strlen($senha) > self::MAXIMO_DA_SENHA) {
            AuditoriaDeAcesso::acesso('login', 'falha', $usuario?->getKey(), $email);

            return redirect()->route('painel.entrar')
                ->with('erro', self::MENSAGEM_FALHA)
                ->withInput(['email' => $email]);
        }

        LimiteDeLogin::loginCerto($email, $ip);
        Auth::login($usuario);
        $request->session()->regenerate();
        // Hash com custo antigo (rounds mudou depois): refeito agora, com a senha que acabou de conferir.
        $atualizacoes = ['ultimo_acesso_em' => now()];
        if (Hash::needsRehash((string) $usuario->password)) {
            $atualizacoes['password'] = $senha;
        }
        $usuario->forceFill($atualizacoes)->save();
        AuditoriaDeAcesso::acesso('login', 'sucesso', (int) $usuario->getKey());

        return $usuario->senha_temporaria
            ? redirect()->route('painel.conta.senha')
            : redirect()->intended('/painel');
    }

    public function sair(Request $request): RedirectResponse
    {
        AuditoriaDeAcesso::acesso('logout', 'logout', (int) Auth::id());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('painel.entrar');
    }
}
