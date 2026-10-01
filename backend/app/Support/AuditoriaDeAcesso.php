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
    /**
     * Login, logout ou troca de senha. NUNCA grava senha nem IP.
     *
     * $emailTentado so e gravado quando NAO ha usuario (falha ou bloqueio de
     * login com e-mail que nao pertence a ninguem) E tem formato de e-mail:
     * quem digitou a senha no campo de e-mail nao a deixa na trilha. Com
     * usuario conhecido vale usuario_id, nunca o e-mail.
     */
    public static function acesso(string $evento, string $resultado, ?int $usuarioId, ?string $emailTentado = null): void
    {
        $email = null;
        if ($usuarioId === null && $emailTentado !== null) {
            $normalizado = mb_strtolower(trim($emailTentado));
            if (strlen($normalizado) <= 254 && preg_match('/^[^@\s]+@[a-z0-9.-]+\.[a-z]{2,}$/', $normalizado) === 1) {
                $email = $normalizado;
            }
        }

        DB::table('auditoria_acessos')->insert([
            'evento' => $evento,
            'resultado' => $resultado,
            'usuario_id' => $usuarioId,
            'email_tentado' => $email,
        ]);
    }

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
