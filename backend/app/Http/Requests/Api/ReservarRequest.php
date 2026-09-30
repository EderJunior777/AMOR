<?php

namespace App\Http\Requests\Api;

use Closure;

/**
 * POST /api/v1/reservas (docs/ESPEC-RESERVA.md, 2.1). So formato. O
 * cabecalho Idempotency-Key entra na validacao como "idempotency_key"
 * (o cabecalho vence qualquer campo de mesmo nome no corpo).
 */
class ReservarRequest extends RequisicaoDaApi
{
    protected function prepareForValidation(): void
    {
        $chave = $this->header('Idempotency-Key');
        $this->merge(['idempotency_key' => is_string($chave) ? $chave : null]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $domicilio = ['exclude_unless:modalidade,domicilio', 'required_if:modalidade,domicilio'];

        return $this->regrasDeDataEHora(hora: true) + $this->regrasDeServicos() + [
            'idempotency_key' => ['bail', 'required', 'string', 'min:16', 'max:100', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'profissional_id' => ['bail', 'required', 'integer', 'min:1'],
            'modalidade' => ['bail', 'required', 'string', 'in:barbearia,domicilio'],
            'regiao_id' => [...$domicilio, 'bail', 'integer', 'min:1'],
            'endereco' => ['exclude_unless:modalidade,domicilio', 'nullable', 'array'],
            'endereco.logradouro' => [...$domicilio, 'bail', 'string', 'max:200', self::SEM_CONTROLE],
            'endereco.complemento' => ['exclude_unless:modalidade,domicilio', 'nullable', 'string', 'max:100', self::SEM_CONTROLE],
            'endereco.referencia' => ['exclude_unless:modalidade,domicilio', 'nullable', 'string', 'max:200', self::SEM_CONTROLE],
            'cliente' => ['bail', 'required', 'array'],
            'cliente.nome' => ['bail', 'required', 'string', 'min:2', 'max:120', self::SEM_CONTROLE, $this->nomeComDoisCaracteres()],
            'cliente.telefone' => ['bail', 'required', 'string', 'max:40', $this->telefoneValido()],
            'observacao' => ['nullable', 'string', 'max:300', self::SEM_CONTROLE],
        ];
    }

    /** O nome que o dominio grava (espacos colapsados) ainda tem 2 caracteres. */
    private function nomeComDoisCaracteres(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falhar): void {
            $limpo = trim((string) preg_replace('/\s+/u', ' ', (string) $valor));
            if (mb_strlen($limpo) < 2) {
                $falhar('Valor abaixo do minimo permitido (2).');
            }
        };
    }
}
