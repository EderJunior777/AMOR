<?php

namespace App\Domain\Agenda;

use App\Enums\Modalidade;
use App\Support\Telefone;
use InvalidArgumentException;

/**
 * O que o cliente (ou o operador) PEDE, e nada mais (docs/ESPEC-RESERVA.md,
 * 2.1). Preco, duracao, periodo ocupado, estado, origem, cliente_id,
 * codigo_publico e fuso nao existem aqui: sao calculados no servidor. Campo
 * a mais em $dados e ignorado.
 *
 * Normalizado na construcao (texto sem espacos extras, telefone em E.164,
 * endereco so em domicilio): o hash de idempotencia e a gravacao usam
 * exatamente os mesmos valores.
 */
final readonly class PedidoDeReserva
{
    /** @var list<int> ordem dos servicos = ordem dos itens */
    public array $servicos;

    public string $clienteNome;

    public string $clienteTelefone;

    public ?int $regiaoId;

    public ?string $logradouro;

    public ?string $complemento;

    public ?string $referencia;

    public ?string $observacao;

    public ?string $motivoEncaixe;

    /** @param list<int> $servicos */
    public function __construct(
        array $servicos,
        public int $profissionalId,
        public string $data,
        public string $hora,
        public Modalidade $modalidade,
        string $clienteNome,
        string $clienteTelefone,
        ?int $regiaoId = null,
        ?string $logradouro = null,
        ?string $complemento = null,
        ?string $referencia = null,
        ?string $observacao = null,
        public ?string $chaveIdempotencia = null,
        ?string $motivoEncaixe = null,
    ) {
        $servicos = array_map('intval', array_values($servicos));
        if ($servicos === [] || count(array_unique($servicos)) !== count($servicos) || min($servicos) < 1) {
            throw new InvalidArgumentException('Lista de servicos vazia, repetida ou com id invalido.');
        }
        $telefone = Telefone::normalizar($clienteTelefone);
        if ($telefone === null) {
            throw new InvalidArgumentException('Telefone invalido.');
        }

        $domicilio = $modalidade === Modalidade::Domicilio;

        $this->servicos = $servicos;
        $this->clienteNome = (string) self::texto($clienteNome);
        $this->clienteTelefone = $telefone;
        $this->regiaoId = $domicilio ? $regiaoId : null;
        $this->logradouro = $domicilio ? self::texto($logradouro) : null;
        $this->complemento = $domicilio ? self::texto($complemento) : null;
        $this->referencia = $domicilio ? self::texto($referencia) : null;
        $this->observacao = self::texto($observacao);
        $this->motivoEncaixe = self::texto($motivoEncaixe);
    }

    /**
     * A partir do validated() do FormRequest (ou de um array equivalente do
     * painel). So as chaves da secao 2.1 sao lidas.
     */
    public static function deDados(array $dados, ?string $chaveIdempotencia = null, ?string $motivoEncaixe = null): self
    {
        $endereco = is_array($dados['endereco'] ?? null) ? $dados['endereco'] : [];
        $cliente = is_array($dados['cliente'] ?? null) ? $dados['cliente'] : [];

        return new self(
            servicos: array_values((array) ($dados['servicos'] ?? [])),
            profissionalId: (int) ($dados['profissional_id'] ?? 0),
            data: (string) ($dados['data'] ?? ''),
            hora: (string) ($dados['hora'] ?? ''),
            modalidade: Modalidade::from((string) ($dados['modalidade'] ?? '')),
            clienteNome: (string) ($cliente['nome'] ?? ''),
            clienteTelefone: (string) ($cliente['telefone'] ?? ''),
            regiaoId: isset($dados['regiao_id']) ? (int) $dados['regiao_id'] : null,
            logradouro: $endereco['logradouro'] ?? null,
            complemento: $endereco['complemento'] ?? null,
            referencia: $endereco['referencia'] ?? null,
            observacao: $dados['observacao'] ?? null,
            chaveIdempotencia: $chaveIdempotencia,
            motivoEncaixe: $motivoEncaixe,
        );
    }

    /**
     * Forma canonica para o hash de idempotencia (secao 4): chaves ordenadas
     * em todos os niveis, sem a propria chave de idempotencia.
     *
     * @return array<string, mixed>
     */
    public function canonico(): array
    {
        $dados = [
            'cliente' => ['nome' => $this->clienteNome, 'telefone' => $this->clienteTelefone],
            'data' => $this->data,
            'endereco' => $this->modalidade === Modalidade::Domicilio ? [
                'complemento' => $this->complemento,
                'logradouro' => $this->logradouro,
                'referencia' => $this->referencia,
            ] : null,
            'hora' => $this->hora,
            'modalidade' => $this->modalidade->value,
            'motivo_encaixe' => $this->motivoEncaixe,
            'observacao' => $this->observacao,
            'profissional_id' => $this->profissionalId,
            'regiao_id' => $this->regiaoId,
            'servicos' => $this->servicos,
        ];
        ksort($dados);

        return $dados;
    }

    private static function texto(mixed $valor): ?string
    {
        $limpo = trim((string) preg_replace('/\s+/u', ' ', (string) $valor));

        return $limpo === '' ? null : $limpo;
    }
}
