<?php

namespace App\Domain\Equipe;

use App\Domain\Agenda\RepetirEmConflito;
use App\Enums\PapelUsuario;
use App\Models\Profissional;
use App\Models\User;
use App\Support\AuditoriaDeAcesso;
use App\Support\ErroDeBanco;
use App\Support\SenhaTemporaria;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Gestao da equipe (etapa 3, Fase 1): o PROPRIETARIO cria barbeiro e recepcao,
 * desativa, reativa e redefine a senha. Uma transacao por acao, repetida em
 * deadlock (RepetirEmConflito), com a trilha em auditoria_acessos gravada nela.
 *
 * Regras:
 *   - o autor precisa ser proprietario ATIVO no banco, relido dentro da
 *     transacao (o papel em memoria nao vale; FOR SHARE segura o autor);
 *   - so se criam e se gerenciam barbeiro e recepcao: proprietario nunca e
 *     alvo (nem o proprio autor), o que tambem impede ficar sem proprietario;
 *   - barbeiro leva um profissional ATIVO e ainda sem acesso; recepcao, nenhum;
 *   - senha temporaria (SenhaTemporaria) com senha_temporaria = true, que
 *     forca a troca no primeiro acesso; mostrada UMA vez (AcessoCriado);
 *   - desativar, reativar e redefinir derrubam as sessoes do alvo e trocam o
 *     remember_token ("lembrar-me" antigo morre junto). Reativar tambem troca
 *     a senha: a antiga nao volta.
 *
 * Nada aqui grava senha, e-mail ou nome em log: erros sao EquipeRecusada, com
 * codigo e mensagem fixos.
 */
final class GerenciarEquipe
{
    private RepetirEmConflito $repetir;

    public function __construct(?RepetirEmConflito $repetir = null)
    {
        $this->repetir = $repetir ?? new RepetirEmConflito;
    }

    /**
     * @throws EquipeRecusada
     */
    public function criarUsuario(User $autor, string $nome, string $email, PapelUsuario $papel, ?int $profissionalId = null): AcessoCriado
    {
        return $this->transacao(function () use ($autor, $nome, $email, $papel, $profissionalId) {
            $this->exigirProprietario($autor);

            if (! in_array($papel, [PapelUsuario::Barbeiro, PapelUsuario::Recepcao], true)) {
                throw EquipeRecusada::por('papel_invalido');
            }
            $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
            if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) {
                throw EquipeRecusada::por('nome_invalido');
            }
            $email = mb_strtolower(trim($email));
            if (strlen($email) > 254 || Validator::make(['e' => $email], ['e' => ['required', 'email:rfc']])->fails()
                || ! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
                throw EquipeRecusada::por('email_invalido');
            }

            $profissional = $this->profissionalDoBarbeiro($papel, $profissionalId);

            $senha = SenhaTemporaria::gerar();
            $usuario = new User(['name' => $nome, 'email' => $email, 'password' => $senha]);
            // papel, ativo e senha_temporaria nao sao atribuiveis em massa.
            $usuario->forceFill(['papel' => $papel, 'ativo' => true, 'senha_temporaria' => true]);
            $this->gravar(fn () => $usuario->save(), 'users_email_unico', 'email_em_uso');

            if ($profissional !== null) {
                $profissional->user_id = $usuario->getKey();
                $this->gravar(fn () => $profissional->save(), 'profissionais_user_id_key', 'profissional_ja_vinculado');
            }

            AuditoriaDeAcesso::gestao('usuario_criado', (int) $autor->getKey(), (int) $usuario->getKey());

            return new AcessoCriado($usuario, $senha);
        });
    }

    /**
     * @throws EquipeRecusada
     */
    public function desativar(User $autor, int $alvoId): User
    {
        return $this->transacao(function () use ($autor, $alvoId) {
            $this->exigirProprietario($autor);
            $alvo = $this->alvo($autor, $alvoId);
            if (! $alvo->ativo) {
                throw EquipeRecusada::por('ja_inativo');
            }

            $alvo->forceFill(['ativo' => false, 'remember_token' => Str::random(60)])->save();
            $this->derrubarSessoes($alvo);
            AuditoriaDeAcesso::gestao('usuario_desativado', (int) $autor->getKey(), (int) $alvo->getKey());

            return $alvo;
        });
    }

    /**
     * Volta a ativo COM senha nova temporaria: a senha antiga nao volta.
     *
     * @throws EquipeRecusada
     */
    public function reativar(User $autor, int $alvoId): AcessoCriado
    {
        return $this->transacao(function () use ($autor, $alvoId) {
            $this->exigirProprietario($autor);
            $alvo = $this->alvo($autor, $alvoId);
            if ($alvo->ativo) {
                throw EquipeRecusada::por('ja_ativo');
            }

            $senha = SenhaTemporaria::gerar();
            $alvo->forceFill(['ativo' => true, 'password' => $senha, 'senha_temporaria' => true, 'remember_token' => Str::random(60)])->save();
            $this->derrubarSessoes($alvo);
            AuditoriaDeAcesso::gestao('usuario_reativado', (int) $autor->getKey(), (int) $alvo->getKey());

            return new AcessoCriado($alvo, $senha);
        });
    }

    /**
     * O proprietario redefine a senha de barbeiro ou recepcao (nao ha "esqueci
     * a senha" por e-mail): senha temporaria nova, sessoes derrubadas.
     *
     * @throws EquipeRecusada
     */
    public function redefinirSenha(User $autor, int $alvoId): AcessoCriado
    {
        return $this->transacao(function () use ($autor, $alvoId) {
            $this->exigirProprietario($autor);
            $alvo = $this->alvo($autor, $alvoId);

            $senha = SenhaTemporaria::gerar();
            $alvo->forceFill(['password' => $senha, 'senha_temporaria' => true, 'remember_token' => Str::random(60)])->save();
            $this->derrubarSessoes($alvo);
            AuditoriaDeAcesso::gestao('senha_redefinida', (int) $autor->getKey(), (int) $alvo->getKey());

            return new AcessoCriado($alvo, $senha);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    private function transacao(Closure $fn): mixed
    {
        return $this->repetir->executar(fn () => DB::transaction($fn));
    }

    /** O autor e proprietario ativo NO BANCO agora; FOR SHARE: ninguem o desativa ate o fim. */
    private function exigirProprietario(User $autor): void
    {
        if (! $autor->exists
            || User::query()->whereKey($autor->getKey())->where('ativo', true)
                ->where('papel', PapelUsuario::Proprietario)->sharedLock()->first(['id']) === null) {
            throw EquipeRecusada::por('sem_permissao');
        }
    }

    /** Alvo travado (FOR UPDATE): so barbeiro ou recepcao, nunca o proprio autor nem outro proprietario. */
    private function alvo(User $autor, int $alvoId): User
    {
        if ($alvoId === (int) $autor->getKey()) {
            throw EquipeRecusada::por('alvo_protegido');
        }
        $alvo = User::query()->whereKey($alvoId)->lockForUpdate()->first()
            ?? throw EquipeRecusada::por('usuario_nao_encontrado');
        if ($alvo->papel === PapelUsuario::Proprietario) {
            throw EquipeRecusada::por('alvo_protegido');
        }

        return $alvo;
    }

    /** Barbeiro: profissional ativo e sem acesso (travado). Recepcao: nenhum. */
    private function profissionalDoBarbeiro(PapelUsuario $papel, ?int $profissionalId): ?Profissional
    {
        if ($papel === PapelUsuario::Recepcao) {
            if ($profissionalId !== null) {
                throw EquipeRecusada::por('profissional_so_para_barbeiro');
            }

            return null;
        }
        if ($profissionalId === null) {
            throw EquipeRecusada::por('profissional_obrigatorio');
        }

        $profissional = Profissional::query()->whereKey($profissionalId)->lockForUpdate()->first()
            ?? throw EquipeRecusada::por('profissional_inexistente');
        if (! $profissional->ativo) {
            throw EquipeRecusada::por('profissional_inativo');
        }
        if ($profissional->user_id !== null) {
            throw EquipeRecusada::por('profissional_ja_vinculado');
        }

        return $profissional;
    }

    /** Grava; a violacao da UNIQUE conhecida vira a recusa (a transacao inteira desfaz). */
    private function gravar(Closure $gravar, string $constraint, string $codigo): void
    {
        try {
            $gravar();
        } catch (QueryException $e) {
            if (ErroDeBanco::sqlstate($e) === '23505' && ErroDeBanco::constraint($e) === $constraint) {
                throw EquipeRecusada::por($codigo);
            }
            throw $e;
        }
    }

    private function derrubarSessoes(User $alvo): void
    {
        DB::table('sessions')->where('user_id', $alvo->getKey())->delete();
    }
}
