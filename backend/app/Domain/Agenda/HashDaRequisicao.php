<?php

namespace App\Domain\Agenda;

use LogicException;

/**
 * HMAC-SHA256 do pedido canonico (docs/ESPEC-RESERVA.md, secao 4; D6).
 * A chave e derivada do APP_KEY por HKDF; chaves anteriores (rotacao) so
 * servem para conferir, nunca para gerar.
 */
final readonly class HashDaRequisicao
{
    /** @var list<string> bytes das chaves: a atual primeiro, depois as anteriores */
    private array $chaves;

    /** @param list<string> $chavesAnteriores */
    public function __construct(string $appKey, array $chavesAnteriores = [])
    {
        $chaves = [];
        foreach ([$appKey, ...array_values($chavesAnteriores)] as $chave) {
            $chaves[] = self::bytes((string) $chave);
        }
        $this->chaves = $chaves;
    }

    public static function daAplicacao(): self
    {
        return new self(
            (string) config('app.key'),
            array_values(array_filter((array) config('app.previous_keys', []), fn ($k) => $k !== null && $k !== '')),
        );
    }

    public function calcular(PedidoDeReserva $pedido): string
    {
        return self::hmac($pedido, $this->chaves[0]);
    }

    public function confere(PedidoDeReserva $pedido, string $hash): bool
    {
        $confere = false;
        foreach ($this->chaves as $chave) {
            // percorre todas, sem sair no primeiro acerto
            $confere = hash_equals(self::hmac($pedido, $chave), $hash) || $confere;
        }

        return $confere;
    }

    private static function hmac(PedidoDeReserva $pedido, string $chaveBruta): string
    {
        $json = json_encode(
            self::ordenar($pedido->canonico()),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return hash_hmac('sha256', $json, hash_hkdf('sha256', $chaveBruta, 32, 'cleison.idempotencia'));
    }

    /** ksort recursivo so em mapas; listas mantem a ordem. */
    private static function ordenar(array $valor): array
    {
        foreach ($valor as $k => $v) {
            if (is_array($v)) {
                $valor[$k] = self::ordenar($v);
            }
        }
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }

    private static function bytes(string $chave): string
    {
        if ($chave === '') {
            throw new LogicException('Chave de idempotencia vazia.');
        }
        if (str_starts_with($chave, 'base64:')) {
            $bytes = base64_decode(substr($chave, 7), true);
            if ($bytes === false || $bytes === '') {
                throw new LogicException('Chave base64 invalida.');
            }

            return $bytes;
        }

        return $chave;
    }
}
