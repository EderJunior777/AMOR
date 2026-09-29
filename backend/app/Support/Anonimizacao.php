<?php

namespace App\Support;

use App\Enums\Ator;
use App\Enums\EstadoAgendamento;
use App\Enums\OrigemAnonimizacao;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lado PHP da anonimizacao (docs/LGPD-ANONIMIZACAO.md). Quem decide e o
 * banco: cleison_anonimizar_cliente confere o proprietario, recusa cliente
 * com agendamento em aberto e e idempotente. Aqui so ha a previa (contagens,
 * nunca dado pessoal) e a chamada dentro de uma TransacaoAuditada.
 */
final class Anonimizacao
{
    /** Mesmo formato do CHECK anonimizacoes_protocolo (so para avisar antes). */
    public const FORMATO_PROTOCOLO = '#^[A-Za-z0-9._/-]{1,40}$#';

    /**
     * Contagens do que a anonimizacao alcanca; null se o cliente nao existe.
     *
     * @return array{anonimizado: bool, enderecos: int, agendamentos: int, eventos: int, em_aberto: int}|null
     */
    public static function previa(int $clienteId): ?array
    {
        $cliente = DB::table('clientes')->where('id', $clienteId)->first(['anonimizado_em']);
        if ($cliente === null) {
            return null;
        }

        $agendamentos = DB::table('agendamentos')->where('cliente_id', $clienteId);
        $emAberto = array_map(
            fn (EstadoAgendamento $e) => $e->value,
            array_filter(EstadoAgendamento::cases(), fn (EstadoAgendamento $e) => ! $e->encerrado()),
        );

        return [
            'anonimizado' => $cliente->anonimizado_em !== null,
            'enderecos' => DB::table('enderecos_cliente')->where('cliente_id', $clienteId)->count(),
            'agendamentos' => (clone $agendamentos)->count(),
            'eventos' => DB::table('agendamento_eventos')
                ->whereIn('agendamento_id', (clone $agendamentos)->select('id'))
                ->count(),
            'em_aberto' => (clone $agendamentos)->whereIn('estado', $emAberto)->count(),
        ];
    }

    /**
     * @return int|null id em anonimizacoes; null se o cliente ja estava anonimizado
     *
     * @throws \PDOException recusas do banco (traduzidas pelo ErroDeBanco)
     */
    public static function executar(int $clienteId, User $proprietario, OrigemAnonimizacao $origem, ?string $protocolo): ?int
    {
        // Pedido do titular: quem executa e o proprietario (operador).
        // Retencao: rotina do sistema, em nome do proprietario responsavel.
        [$ator, $usuario] = $origem === OrigemAnonimizacao::PedidoTitular
            ? [Ator::Operador, $proprietario]
            : [Ator::Sistema, null];

        $id = TransacaoAuditada::executar($ator, $usuario, fn () => DB::scalar(
            'SELECT public.cleison_anonimizar_cliente(?, ?, ?, ?)',
            [$clienteId, $proprietario->getKey(), $origem->value, $protocolo],
        ));

        return $id === null ? null : (int) $id;
    }
}
