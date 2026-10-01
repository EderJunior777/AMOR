<?php

namespace App\Domain\Painel;

use App\Enums\EstadoAgendamento;
use App\Models\Agendamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Leituras do painel, SEMPRE pelo escopo do usuario (EscopoDoPainel) e com
 * tudo carregado de uma vez (cliente, itens, profissional): com o modo estrito
 * do Eloquent, carga preguicosa seria erro. Somente leitura.
 */
final class ConsultasDoPainel
{
    /** Teto de cartoes por tela (a lista inteira cabe numa tela de celular rolando). */
    private const LIMITE = 100;

    /** Pedidos (solicitados), do mais antigo ao mais novo. @return Collection<int, Agendamento> */
    public function pedidos(User $usuario): Collection
    {
        return $this->solicitados($usuario)->orderBy('created_at')->orderBy('id')->limit(self::LIMITE)->get();
    }

    /**
     * O que a atualizacao automatica precisa saber: quantos pedidos ha e quais
     * (ids internos, sem dado pessoal e sem codigo). @return array{total: int, ids: list<int>}
     */
    public function resumoDePedidos(User $usuario): array
    {
        $consulta = fn () => EscopoDoPainel::de($usuario)->restringir(Agendamento::query())->where('estado', EstadoAgendamento::Solicitado);

        return [
            'total' => $consulta()->count(),
            'ids' => $consulta()->orderBy('id')->limit(self::LIMITE)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /** Uma reserva pelo codigo publico, DENTRO do escopo do usuario; nula se nao existir ou for de outro profissional. */
    public function reserva(User $usuario, string $codigo): ?Agendamento
    {
        if (! Str::isUuid($codigo)) {
            return null;
        }

        return $this->base($usuario)->where('codigo_publico', $codigo)->first();
    }

    /** @return Builder<Agendamento> */
    private function solicitados(User $usuario): Builder
    {
        return $this->base($usuario)->where('estado', EstadoAgendamento::Solicitado);
    }

    /** @return Builder<Agendamento> */
    private function base(User $usuario): Builder
    {
        return EscopoDoPainel::de($usuario)->restringir(Agendamento::query()->with(['cliente', 'itens', 'profissional']));
    }
}
