<?php

namespace App\Domain\Equipe;

use App\Models\User;
use JsonSerializable;
use LogicException;

/**
 * Resultado de criar, reativar ou redefinir um acesso: o usuario e a senha
 * temporaria EM TEXTO, que existe so aqui, para ser mostrada UMA vez na tela
 * (o banco guarda so o hash).
 *
 * Nao vaza por descuido: print_r/var_dump, json_encode e serialize (cache,
 * sessao, fila) nao mostram a senha. Quem mostra a senha o faz de proposito,
 * lendo $senhaTemporaria.
 */
final class AcessoCriado implements JsonSerializable
{
    public function __construct(
        public readonly User $usuario,
        #[\SensitiveParameter] public readonly string $senhaTemporaria,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['usuario_id' => $this->usuario->getKey(), 'senhaTemporaria' => '***'];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['usuario_id' => $this->usuario->getKey()];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('A senha temporária não pode ser serializada.');
    }
}
