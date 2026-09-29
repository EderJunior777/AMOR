<?php

namespace App\Enums;

/**
 * Por que o cliente foi anonimizado (anonimizacoes.origem, CHECK
 * anonimizacoes_origem). docs/LGPD-ANONIMIZACAO.md.
 */
enum OrigemAnonimizacao: string
{
    case PedidoTitular = 'pedido_titular';
    case Retencao = 'retencao';
}
