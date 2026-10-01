<?php

namespace App\Support;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Sessao no banco SEM IP e SEM user-agent.
 *
 * O handler padrao do Laravel grava ip_address e user_agent de toda sessao
 * (inclusive a anonima, criada so por abrir a tela de login). Pela decisao da
 * etapa 3 o IP nunca vai para o banco (so vive no cache, em chave HMAC, no
 * limite de tentativas). A migration 2026_10_01_000200 remove as colunas; este
 * handler garante que nada tente grava-las. user_id continua: e por ele que
 * desativar um usuario derruba as sessoes dele.
 */
final class SessaoSemIp extends DatabaseSessionHandler
{
    /** @param  array<string, mixed>  $payload */
    protected function addRequestInformation(&$payload)
    {
        return $this;
    }
}
