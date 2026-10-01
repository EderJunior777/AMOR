<?php

namespace App\Domain\Painel;

use App\Enums\EstadoAgendamento;
use App\Enums\Modalidade;
use App\Models\Agendamento;
use Carbon\CarbonImmutable;

/**
 * A reserva como o painel a mostra: texto pronto, no fuso do estabelecimento
 * e em portugues. Tudo vem de relacoes JA carregadas (ConsultasDoPainel).
 * Cliente anonimizado (sem telefone) e tratado: sem telefone, sem botao.
 */
final class ApresentacaoDaReserva
{
    public function __construct(
        private readonly Agendamento $reserva,
        private readonly string $fuso,
        private readonly CarbonImmutable $agora,
        private readonly int $expiraEmHoras,
    ) {}

    /** @return array<string, mixed> o que as views usam */
    public function paraTela(): array
    {
        return [
            'id' => (int) $this->reserva->getKey(),
            'codigo' => (string) $this->reserva->codigo_publico,
            'estado' => $this->reserva->estado->value,
            'estadoRotulo' => $this->reserva->estado->rotulo(),
            'nome' => $this->nome(),
            'primeiroNome' => $this->primeiroNome(),
            'telefone' => $this->telefoneE164(),
            'telefoneFormatado' => $this->telefoneFormatado(),
            'servicos' => $this->servicos(),
            'duracao' => self::minutosEmTexto($this->duracaoEmMinutos()),
            'dia' => $this->dia(),
            'hora' => $this->hora(),
            'local' => $this->reserva->modalidade === Modalidade::Domicilio ? 'Na casa do cliente' : 'Na barbearia',
            'domicilio' => $this->reserva->modalidade === Modalidade::Domicilio,
            'endereco' => $this->reserva->endereco_texto,
            'regiao' => $this->reserva->regiao_nome,
            'observacao' => $this->reserva->observacao_cliente,
            'total' => $this->total(),
            'expira' => $this->reserva->estado === EstadoAgendamento::Solicitado ? $this->expira() : null,
        ];
    }

    public function nome(): string
    {
        $nome = trim((string) $this->reserva->cliente?->nome);

        return $nome === '' ? 'Cliente' : $nome;
    }

    public function primeiroNome(): string
    {
        return explode(' ', $this->nome())[0];
    }

    /** E.164 (+5511987651234) ou nulo (cliente anonimizado). */
    public function telefoneE164(): ?string
    {
        $telefone = $this->reserva->cliente?->telefone;

        return $telefone === null || $telefone === '' ? null : (string) $telefone;
    }

    /** (11) 98765-1234 para numero brasileiro; qualquer outro, como esta. */
    public function telefoneFormatado(): ?string
    {
        $e164 = $this->telefoneE164();
        if ($e164 === null) {
            return null;
        }
        if (preg_match('/^\+55(\d{2})(9?\d{4})(\d{4})$/', $e164, $m) === 1) {
            return "({$m[1]}) {$m[2]}-{$m[3]}";
        }

        return $e164;
    }

    public function servicos(): string
    {
        return $this->reserva->itens->pluck('servico_nome')->implode(' + ');
    }

    public function duracaoEmMinutos(): int
    {
        return (int) $this->reserva->itens->sum('duracao_minutos');
    }

    public function dia(): string
    {
        return $this->inicio()->locale('pt_BR')->translatedFormat('l, d/m');
    }

    public function hora(): string
    {
        return $this->inicio()->format('H:i');
    }

    /** Preco dos itens mais a taxa de deslocamento, em reais ("R$ 40,00"). */
    public function total(): string
    {
        $centavos = (int) $this->reserva->itens->sum('preco_centavos') + (int) $this->reserva->taxa_deslocamento_centavos;

        return 'R$ '.number_format($centavos / 100, 2, ',', '.');
    }

    /**
     * "expira em 10 h 30 min": o que vier primeiro entre o prazo do pedido
     * (criacao + horas configuradas) e a hora marcada.
     */
    public function expira(): string
    {
        $limite = min(
            $this->reserva->created_at->toImmutable()->addHours($this->expiraEmHoras)->getTimestamp(),
            $this->reserva->inicio_servico->getTimestamp(),
        );
        $minutos = intdiv($limite - $this->agora->getTimestamp(), 60);

        return $minutos <= 0 ? 'vai expirar a qualquer momento' : 'expira em '.self::minutosEmTexto($minutos);
    }

    /** 30 -> "30 min", 60 -> "1 h", 90 -> "1 h 30 min". */
    public static function minutosEmTexto(int $minutos): string
    {
        if ($minutos < 60) {
            return "{$minutos} min";
        }
        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return $resto === 0 ? "{$horas} h" : "{$horas} h {$resto} min";
    }

    private function inicio(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->reserva->inicio_servico)->setTimezone($this->fuso);
    }
}
