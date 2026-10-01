<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Support\AuditoriaDeAcesso;
use App\Support\ChaveDeLimite;
use App\Support\LimiteDeLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Troca da PROPRIA senha (obrigatoria com senha temporaria).
 *
 * A senha atual e conferida primeiro, com limite de tentativas por usuario
 * (no cache; quem tem a sessao de outra pessoa nao descobre a senha dela por
 * aqui). Politica: 12 a 128 caracteres, com letras e numeros, diferente da
 * atual. Trocar derruba as OUTRAS sessoes do usuario, troca o remember_token
 * e a sessao atual, e audita sem senha. A senha digitada nunca volta para o
 * formulario nem vai para log.
 */
final class SenhaController extends Controller
{
    private const MENSAGEM_ATUAL_ERRADA = 'Senha atual incorreta.';

    private const MENSAGEM_BLOQUEIO = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';

    private const MAXIMO_DA_ATUAL = 200;

    public function formulario(Request $request): View
    {
        return view('painel.conta-senha', ['temporaria' => (bool) $request->user()->senha_temporaria]);
    }

    public function salvar(Request $request): RedirectResponse|Response
    {
        $usuario = $request->user();
        $chave = ChaveDeLimite::de('painel-senha-usuario', (string) $usuario->getKey());
        $janela = LimiteDeLogin::inteiro('login_janela_minutos') * 60;

        if (RateLimiter::tooManyAttempts($chave, LimiteDeLogin::inteiro('troca_senha_max_falhas'))) {
            return response()
                ->view('painel.conta-senha', ['temporaria' => (bool) $usuario->senha_temporaria, 'erro' => self::MENSAGEM_BLOQUEIO], 429)
                ->header('Retry-After', (string) max(1, RateLimiter::availableIn($chave)));
        }

        $atual = $this->texto($request, 'senha_atual');
        if (strlen($atual) > self::MAXIMO_DA_ATUAL || ! Hash::check($atual, (string) $usuario->password)) {
            RateLimiter::hit($chave, $janela);

            return redirect()->route('painel.conta.senha')->with('erro', self::MENSAGEM_ATUAL_ERRADA);
        }

        $nova = $this->texto($request, 'senha_nova');
        $erros = $this->errosDaSenhaNova($nova, $this->texto($request, 'senha_nova_confirmation'), $atual);
        if ($erros !== []) {
            return redirect()->route('painel.conta.senha')->withErrors(['senha_nova' => $erros]);
        }

        DB::transaction(function () use ($usuario, $nova, $request) {
            // password: cast "hashed" grava so o hash.
            $usuario->forceFill(['password' => $nova, 'senha_temporaria' => false, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')
                ->where('user_id', $usuario->getKey())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
            AuditoriaDeAcesso::acesso('senha_trocada', 'sucesso', (int) $usuario->getKey());
        });

        $request->session()->regenerate();
        RateLimiter::clear($chave);

        return redirect('/painel')->with('sucesso', 'Senha alterada.');
    }

    private function texto(Request $request, string $campo): string
    {
        $valor = $request->input($campo);

        return is_string($valor) ? $valor : '';
    }

    /** @return list<string> */
    private function errosDaSenhaNova(string $nova, string $confirmacao, string $atual): array
    {
        $tamanho = mb_strlen($nova);
        $erros = [];

        if ($tamanho < 12) {
            $erros[] = 'A senha nova precisa ter pelo menos 12 caracteres.';
        }
        if ($tamanho > 128) {
            $erros[] = 'A senha nova pode ter no máximo 128 caracteres.';
        }
        if (preg_match('/\p{L}/u', $nova) !== 1) {
            $erros[] = 'A senha nova precisa ter letras.';
        }
        if (preg_match('/\p{N}/u', $nova) !== 1) {
            $erros[] = 'A senha nova precisa ter números.';
        }
        if ($nova === $atual) {
            $erros[] = 'A senha nova precisa ser diferente da atual.';
        }
        if ($nova !== $confirmacao) {
            $erros[] = 'A confirmação não confere com a senha nova.';
        }

        return $erros;
    }
}
