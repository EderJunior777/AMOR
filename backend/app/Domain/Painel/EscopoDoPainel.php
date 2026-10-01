<?php

namespace App\Domain\Painel;

use App\Enums\PapelUsuario;
use App\Models\Profissional;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * O que o usuario ENXERGA no painel (as policies decidem o que ele pode FAZER;
 * os dois agem juntos, e o dominio confere de novo, travado, na hora de agir).
 *
 *   - proprietario e recepcao: todas as reservas;
 *   - barbeiro: so as do PROPRIO profissional (profissionais.user_id);
 *   - barbeiro sem profissional vinculado, ou usuario inativo: NADA (falha fechada).
 */
final class EscopoDoPainel
{
    private function __construct(
        private readonly bool $semAcesso,
        private readonly ?int $profissionalId,
    ) {}

    public static function de(User $usuario): self
    {
        if (! $usuario->ativo) {
            return new self(semAcesso: true, profissionalId: null);
        }

        return match ($usuario->papel) {
            PapelUsuario::Proprietario, PapelUsuario::Recepcao => new self(semAcesso: false, profissionalId: null),
            PapelUsuario::Barbeiro => self::doBarbeiro($usuario),
        };
    }

    /** O profissional do barbeiro: filtro das acoes no dominio. Nulo para quem ve tudo (e para quem nao ve nada). */
    public function profissionalId(): ?int
    {
        return $this->profissionalId;
    }

    public function semAcesso(): bool
    {
        return $this->semAcesso;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $consulta  consulta de agendamentos
     * @return Builder<T>
     */
    public function restringir(Builder $consulta): Builder
    {
        if ($this->semAcesso) {
            return $consulta->whereRaw('false');
        }

        return $this->profissionalId === null ? $consulta : $consulta->where('profissional_id', $this->profissionalId);
    }

    private static function doBarbeiro(User $usuario): self
    {
        $id = Profissional::query()->where('user_id', $usuario->getKey())->value('id');

        return $id === null
            ? new self(semAcesso: true, profissionalId: null)
            : new self(semAcesso: false, profissionalId: (int) $id);
    }
}
