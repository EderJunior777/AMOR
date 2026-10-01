<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Escrita na trilha auditoria_acessos (so insercao; o banco recusa o resto).
 *
 * NUNCA grava senha nem IP: a tabela nem tem coluna para isso. A data e a hora
 * vem do banco (now()). Chamar DENTRO da transacao da acao auditada, para a
 * trilha e a acao nascerem (ou falharem) juntas.
 */
final class AuditoriaDeAcesso
{
    /** Gestao da equipe: quem (autor) fez o que (evento) em quem (usuario). */
    public static function gestao(string $evento, int $autorId, int $usuarioId): void
    {
        DB::table('auditoria_acessos')->insert([
            'evento' => $evento,
            'resultado' => 'sucesso',
            'autor_id' => $autorId,
            'usuario_id' => $usuarioId,
        ]);
    }
}
