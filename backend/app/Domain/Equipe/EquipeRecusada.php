<?php

namespace App\Domain\Equipe;

use DomainException;
use LogicException;

/**
 * Recusa de regra da gestao da equipe, com codigo ESTAVEL e mensagem pronta
 * para a tela. Nunca leva id, e-mail nem senha.
 */
final class EquipeRecusada extends DomainException
{
    public const MENSAGENS = [
        'sem_permissao' => 'Você não tem permissão para gerenciar a equipe.',
        'papel_invalido' => 'O papel precisa ser barbeiro ou recepção.',
        'nome_invalido' => 'Informe o nome, com 2 a 120 caracteres.',
        'email_invalido' => 'Informe um e-mail válido.',
        'email_em_uso' => 'Este e-mail já está em uso.',
        'profissional_obrigatorio' => 'Escolha o profissional que este barbeiro atende.',
        'profissional_so_para_barbeiro' => 'Só o barbeiro tem profissional vinculado.',
        'profissional_inexistente' => 'Profissional não encontrado.',
        'profissional_inativo' => 'Este profissional está inativo.',
        'profissional_ja_vinculado' => 'Este profissional já tem um acesso.',
        'usuario_nao_encontrado' => 'Usuário não encontrado.',
        'alvo_protegido' => 'Este usuário não pode ser alterado por aqui.',
        'ja_inativo' => 'Este usuário já está desativado.',
        'ja_ativo' => 'Este usuário já está ativo.',
    ];

    private function __construct(public readonly string $codigo)
    {
        parent::__construct(self::MENSAGENS[$codigo]);
    }

    public static function por(string $codigo): self
    {
        if (! isset(self::MENSAGENS[$codigo])) {
            throw new LogicException("Codigo de recusa desconhecido: {$codigo}");
        }

        return new self($codigo);
    }
}
