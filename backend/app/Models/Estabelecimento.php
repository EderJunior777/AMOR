<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuracoes do (unico) estabelecimento. A tabela aceita uma linha so
 * (id = 1, CHECK no banco).
 */
class Estabelecimento extends Model
{
    public const ID = 1;

    protected $table = 'estabelecimento';

    public $incrementing = false;

    protected $fillable = [
        'nome', 'fuso_horario', 'whatsapp', 'endereco', 'grade_minutos',
        'antecedencia_minima_minutos', 'horizonte_dias', 'domicilio_ativo',
    ];
    // dados_demonstracao fica fora: distingue o seed de exemplo do
    // estabelecimento real e so e marcado explicitamente (forceFill).

    protected $attributes = ['id' => self::ID];

    protected function casts(): array
    {
        return [
            'grade_minutos' => 'integer',
            'antecedencia_minima_minutos' => 'integer',
            'horizonte_dias' => 'integer',
            'domicilio_ativo' => 'boolean',
            'dados_demonstracao' => 'boolean',
        ];
    }

    public static function atual(): ?self
    {
        return static::query()->find(self::ID);
    }
}
