<?php

namespace App\Http\Resources;

use App\Enums\Modalidade;
use App\Models\Agendamento;
use App\Models\AgendamentoItem;
use App\Models\Estabelecimento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reserva como o SITE a ve (docs/ESPEC-RESERVA.md, secao 6). Lista branca:
 * nada de ids internos (nenhuma chave "id" ou "*_id"), endereco, telefone
 * ou nome do cliente, nem chave e hash de idempotencia. Servicos e precos
 * vem do SNAPSHOT dos itens, nao do catalogo de hoje.
 *
 * Espera o Agendamento com "itens" e "profissional" ja carregados (o
 * dominio entrega assim; lazy loading e proibido fora de producao).
 *
 * @mixin Agendamento
 */
class ReservaResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fuso = Estabelecimento::atual()?->fuso_horario ?? config('app.timezone');
        $inicio = $this->inicio_servico->setTimezone($fuso);
        $fim = $this->fim_servico->setTimezone($fuso);
        $domicilio = $this->modalidade === Modalidade::Domicilio;
        $servicos = $this->itens;

        return [
            'codigo' => $this->codigo_publico,
            'estado' => $this->estado->value,
            'data' => $inicio->format('Y-m-d'),
            'hora' => $inicio->format('H:i'),
            'inicio' => $inicio->toIso8601String(),
            'fim' => $fim->toIso8601String(),
            'modalidade' => $this->modalidade->value,
            'profissional' => ['nome_exibicao' => $this->profissional->nome_exibicao],
            'servicos' => $servicos->map(fn (AgendamentoItem $item) => [
                'nome' => $item->servico_nome,
                'preco_centavos' => $item->preco_centavos,
                'duracao_minutos' => $item->duracao_minutos,
            ])->values()->all(),
            'taxa_deslocamento_centavos' => $this->taxa_deslocamento_centavos,
            'total_centavos' => $servicos->sum('preco_centavos') + $this->taxa_deslocamento_centavos,
            'regiao_nome' => $domicilio ? $this->regiao_nome : null,
        ];
    }
}
