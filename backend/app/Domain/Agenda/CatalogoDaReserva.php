<?php

namespace App\Domain\Agenda;

use App\Enums\Modalidade;
use App\Models\Estabelecimento;
use App\Models\Profissional;
use App\Models\RegiaoAtendimento;
use App\Models\Servico;
use Illuminate\Support\Facades\DB;

/**
 * Leitura do catalogo vivo para uma reserva e validacao de V5, V6 e V7
 * (docs/ESPEC-RESERVA.md, secao 3): servicos ativos e permitidos na
 * modalidade, profissional ativo e habilitado em CADA servico, e dominio da
 * regiao a domicilio. Devolve snapshots imutaveis (preco e duracao do dia).
 *
 * Com $travar (dentro da transacao) a leitura e SELECT ... FOR SHARE, na
 * ordem fixa profissional, servicos, vinculos, regiao: desativar um servico
 * ou apagar um vinculo ao mesmo tempo espera o COMMIT da reserva, e a
 * ordem constante evita deadlock entre reservas. Sem $travar e so leitura
 * (pre-validacao, antes da transacao).
 */
final readonly class CatalogoDaReserva
{
    private const LOGRADOURO_MINIMO = 5;

    private const LOGRADOURO_MAXIMO = 200;

    private const COMPLEMENTO_MAXIMO = 100;

    private const REFERENCIA_MAXIMO = 200;

    /**
     * @param  list<SnapshotServico>  $servicos  na ordem pedida
     */
    public function __construct(
        public array $servicos,
        public ?SnapshotRegiao $regiao,
    ) {}

    /** @throws ReservaRecusada servico_indisponivel, profissional_indisponivel ou domicilio_indisponivel */
    public static function ler(PedidoDeReserva $pedido, Estabelecimento $estabelecimento, bool $travar): self
    {
        $com = fn ($consulta) => $travar ? $consulta->sharedLock() : $consulta;
        $domicilio = $pedido->modalidade === Modalidade::Domicilio;

        $profissional = $com(Profissional::query()->whereKey($pedido->profissionalId))->first();
        $servicos = $com(Servico::query()->whereIn('id', $pedido->servicos)->orderBy('id'))->get()->keyBy('id');
        $vinculados = $com(DB::table('profissional_servico')
            ->where('profissional_id', $pedido->profissionalId)
            ->whereIn('servico_id', $pedido->servicos)
            ->orderBy('servico_id'))
            ->pluck('servico_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $regiao = $domicilio && $pedido->regiaoId !== null
            ? $com(RegiaoAtendimento::query()->whereKey($pedido->regiaoId))->first()
            : null;

        // V5: existem, ativos e permitem a modalidade (o operador tambem).
        $snapshots = [];
        foreach ($pedido->servicos as $id) {
            $servico = $servicos->get($id);
            $permite = $servico !== null && ($domicilio ? $servico->permite_domicilio : $servico->permite_barbearia);
            if ($servico === null || ! $servico->ativo || ! $permite) {
                throw ReservaRecusada::por('servico_indisponivel');
            }
            $snapshots[] = new SnapshotServico(
                $servico->id, $servico->nome, $servico->preco_centavos, $servico->duracao_minutos, $servico->conta_como_corte,
            );
        }

        // V6: profissional ativo e com vinculo com CADA servico.
        if ($profissional === null || ! $profissional->ativo
            || array_diff($pedido->servicos, $vinculados) !== []) {
            throw ReservaRecusada::por('profissional_indisponivel');
        }

        if (! $domicilio) {
            return new self($snapshots, null);
        }

        // V7: domicilio ligado, regiao ativa, endereco que cabe no banco.
        if (! $estabelecimento->domicilio_ativo
            || $regiao === null || ! $regiao->ativo
            || ! self::enderecoValido($pedido)) {
            throw ReservaRecusada::por('domicilio_indisponivel');
        }

        return new self($snapshots, new SnapshotRegiao(
            $regiao->id, $regiao->nome, $regiao->deslocamento_minutos, $regiao->taxa_centavos,
        ));
    }

    private static function enderecoValido(PedidoDeReserva $pedido): bool
    {
        $logradouro = (string) $pedido->logradouro;

        return mb_strlen($logradouro) >= self::LOGRADOURO_MINIMO
            && mb_strlen($logradouro) <= self::LOGRADOURO_MAXIMO
            && mb_strlen((string) $pedido->complemento) <= self::COMPLEMENTO_MAXIMO
            && mb_strlen((string) $pedido->referencia) <= self::REFERENCIA_MAXIMO;
    }
}
