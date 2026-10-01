<?php

namespace App\Domain\Painel;

/**
 * Link wa.me com a mensagem PRONTA ao cliente (confirmada ou recusada). Sem
 * API e sem envio automatico: o barbeiro abre o WhatsApp e toca em enviar.
 *
 * O telefone vai so em digitos (E.164 sem o "+"). O motivo da recusa e INTERNO
 * e nunca entra na mensagem. Sem telefone (cliente anonimizado): sem link.
 */
final class MensagemParaCliente
{
    public const CONFIRMADO = 'confirmado';

    public const RECUSADO = 'recusado';

    public const CANCELADO = 'cancelado';

    /** @param  array<string, mixed>  $reserva  ApresentacaoDaReserva::paraTela() */
    public static function link(array $reserva, string $tipo): ?string
    {
        $telefone = $reserva['telefone'] ?? null;
        if (! is_string($telefone) || $telefone === '') {
            return null;
        }
        $digitos = (string) preg_replace('/\D/', '', $telefone);
        if ($digitos === '') {
            return null;
        }

        return 'https://wa.me/'.$digitos.'?text='.rawurlencode(self::texto($reserva, $tipo));
    }

    /** @param  array<string, mixed>  $reserva */
    public static function texto(array $reserva, string $tipo): string
    {
        $quando = "{$reserva['dia']}, às {$reserva['hora']}";

        return match ($tipo) {
            self::CONFIRMADO => "Olá, {$reserva['primeiroNome']}! Seu horário está confirmado: {$reserva['servicos']}, {$quando}. Até lá!",
            self::RECUSADO => "Olá, {$reserva['primeiroNome']}. Não consegui atender seu pedido de {$reserva['servicos']} ({$quando}). Quer escolher outro horário?",
            self::CANCELADO => "Olá, {$reserva['primeiroNome']}. Precisei cancelar seu horário de {$reserva['servicos']} ({$quando}). Desculpe o transtorno! Quer escolher outro horário?",
        };
    }
}
