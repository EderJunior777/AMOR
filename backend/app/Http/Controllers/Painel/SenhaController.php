<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\User;
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
 * A senha atual e conferida primeiro, com limite de tentativas por usuario no
 * cache (quem tem a sessao de outra pessoa nao descobre a senha dela por aqui).
 * A tentativa e CONTADA antes de conferir (a conferencia demora; em paralelo,
 * so as primeiras N conferem) e zerada quando a senha atual confere, para
 * errar a senha NOVA nao gastar tentativas.
 *
 * Politica da senha nova: 12 a 72 bytes (o bcrypt ignora o que passa de 72),
 * com letras e numeros, pelo menos 6 caracteres diferentes, diferente da atual,
 * sem ser uma senha comum nem conter o e-mail ou o nome do usuario.
 * Trocar derruba as OUTRAS sessoes do usuario, troca o remember_token e a
 * sessao atual, e audita sem senha. A senha digitada nunca volta para o
 * formulario nem vai para log.
 */
final class SenhaController extends Controller
{
    private const MENSAGEM_ATUAL_ERRADA = 'Senha atual incorreta.';

    private const MENSAGEM_BLOQUEIO = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';

    private const MAXIMO_DA_ATUAL = 200;

    private const MAXIMO_DA_NOVA_EM_BYTES = 72;

    /** Palavras (so letras, sem numeros nem simbolos) que, sozinhas ou com ate 2 letras a mais, sao previsiveis. */
    private const PALAVRAS_COMUNS = [
        'senha', 'password', 'passwd', 'qwerty', 'qwertyuiop', 'asdfghjkl', 'abcdefgh', 'abcdefghijkl',
        'barbearia', 'barbeiro', 'cleison', 'admin', 'administrador', 'mudar', 'trocar', 'bemvindo',
        'brasil', 'futebol', 'corinthians', 'flamengo', 'palmeiras', 'iloveyou', 'letmein', 'welcome',
    ];

    public function formulario(Request $request): View
    {
        return view('painel.conta-senha', ['temporaria' => (bool) $request->user()->senha_temporaria]);
    }

    public function salvar(Request $request): RedirectResponse|Response
    {
        $usuario = $request->user();
        $chave = ChaveDeLimite::de('painel-senha-usuario', (string) $usuario->getKey());
        $maximo = LimiteDeLogin::inteiro('troca_senha_max_falhas');

        if (RateLimiter::tooManyAttempts($chave, $maximo)
            || RateLimiter::hit($chave, LimiteDeLogin::inteiro('login_janela_minutos') * 60) > $maximo) {
            return response()
                ->view('painel.conta-senha', ['temporaria' => (bool) $usuario->senha_temporaria, 'erro' => self::MENSAGEM_BLOQUEIO], 429)
                ->header('Retry-After', (string) max(1, RateLimiter::availableIn($chave)));
        }

        $atual = $this->texto($request, 'senha_atual');
        if (strlen($atual) > self::MAXIMO_DA_ATUAL || ! Hash::check($atual, (string) $usuario->password)) {
            return redirect()->route('painel.conta.senha')->with('erro', self::MENSAGEM_ATUAL_ERRADA);
        }
        // A senha atual conferiu: errar a senha NOVA daqui em diante nao gasta tentativas.
        RateLimiter::clear($chave);

        $nova = $this->texto($request, 'senha_nova');
        $erros = $this->errosDaSenhaNova($nova, $this->texto($request, 'senha_nova_confirmation'), $atual, $usuario);
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

        return redirect('/painel')->with('sucesso', 'Senha alterada.');
    }

    private function texto(Request $request, string $campo): string
    {
        $valor = $request->input($campo);

        return is_string($valor) ? $valor : '';
    }

    /** @return list<string> */
    private function errosDaSenhaNova(string $nova, string $confirmacao, string $atual, User $usuario): array
    {
        $erros = [];

        if (mb_strlen($nova) < 12) {
            $erros[] = 'A senha nova precisa ter pelo menos 12 caracteres.';
        }
        if (strlen($nova) > self::MAXIMO_DA_NOVA_EM_BYTES) {
            $erros[] = 'A senha nova pode ter no máximo 72 caracteres (acentos contam mais de um).';
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
        if ($erros === [] && $this->previsivel($nova, $usuario)) {
            $erros[] = 'Escolha uma senha menos previsível (evite seu nome, seu e-mail e palavras comuns).';
        }

        return $erros;
    }

    private function previsivel(string $nova, User $usuario): bool
    {
        $baixa = mb_strtolower($nova);

        if (count(array_unique(mb_str_split($baixa))) < 6) {
            return true;
        }

        $soLetras = (string) preg_replace('/[^\p{L}]+/u', '', $baixa);
        foreach (self::PALAVRAS_COMUNS as $palavra) {
            if (str_contains($soLetras, $palavra) && mb_strlen($soLetras) - mb_strlen($palavra) <= 2) {
                return true;
            }
        }

        $alfanumerica = (string) preg_replace('/[^a-z0-9]+/', '', $baixa);
        $local = (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) strstr((string) $usuario->email, '@', true)));
        if (strlen($local) >= 4 && str_contains($alfanumerica, $local)) {
            return true;
        }

        foreach (preg_split('/\s+/u', mb_strtolower((string) $usuario->name)) ?: [] as $parte) {
            $parte = (string) preg_replace('/[^\p{L}]+/u', '', $parte);
            if (mb_strlen($parte) >= 4 && str_contains($baixa, $parte)) {
                return true;
            }
        }

        return false;
    }
}
