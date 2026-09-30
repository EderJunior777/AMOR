<?php

namespace App\Http\Requests\Api;

use App\Support\Telefone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base dos FormRequests da API publica v1. So valida FORMATO (docs/
 * ESPEC-RESERVA.md, 2.1); regra comercial (V1 a V8) e do dominio. Campo que
 * nao esta nas regras e ignorado: os controllers leem so validated().
 *
 * As mensagens sao fixas e nunca trazem o valor enviado (a resposta de
 * validacao nao pode ecoar telefone, codigo nem texto do cliente).
 */
abstract class RequisicaoDaApi extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Campo obrigatorio.',
            'required_if' => 'Campo obrigatorio para esta modalidade.',
            'string' => 'Texto invalido.',
            'array' => 'Lista invalida.',
            'integer' => 'Numero inteiro invalido.',
            'min' => 'Valor abaixo do minimo permitido (:min).',
            'max' => 'Valor acima do maximo permitido (:max).',
            'distinct' => 'Valor repetido.',
            'in' => 'Valor nao permitido.',
            'regex' => 'Formato invalido.',
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function regrasDeServicos(): array
    {
        return [
            'servicos' => ['bail', 'required', 'array', 'min:1', 'max:3'],
            'servicos.*' => ['bail', 'required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function regrasDeDataEHora(bool $hora): array
    {
        $regras = ['data' => ['bail', 'required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/']];
        if ($hora) {
            $regras['hora'] = ['bail', 'required', 'string', 'regex:/\A\d{2}:\d{2}\z/'];
        }

        return $regras;
    }

    /** @return array<string, list<mixed>> */
    protected function regrasDeCodigoETelefone(): array
    {
        // Sem checar o formato do uuid nem do telefone: codigo mal formado, inexistente
        // e telefone errado precisam dar a MESMA resposta (reserva_nao_encontrada).
        return [
            'codigo' => ['bail', 'required', 'string', 'max:100'],
            'telefone' => ['bail', 'required', 'string', 'max:40'],
        ];
    }

    /** Telefone que Telefone::normalizar aceita. */
    protected function telefoneValido(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falhar): void {
            if (! is_string($valor) || Telefone::normalizar($valor) === null) {
                $falhar('Telefone invalido.');
            }
        };
    }
}
