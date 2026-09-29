<?php

namespace App\Enums;

/**
 * Contrato de estados do agendamento.
 *
 * O banco aplica as mesmas regras (funcao cleison_transicao_permitida e
 * trigger agendamentos_validar). Este enum existe para a aplicacao saber
 * de antemao o que oferecer na tela; quem garante e o banco. O teste
 * ContratoDeEstadosTest confere que os dois concordam em todos os pares.
 *
 *   solicitado     -> confirmado | cancelado
 *   confirmado     -> em_atendimento | concluido | cancelado | nao_compareceu
 *   em_atendimento -> concluido | cancelado
 *   concluido, cancelado, nao_compareceu: encerrados (sem saida)
 */
enum EstadoAgendamento: string
{
    case Solicitado = 'solicitado';
    case Confirmado = 'confirmado';
    case EmAtendimento = 'em_atendimento';
    case Concluido = 'concluido';
    case Cancelado = 'cancelado';
    case NaoCompareceu = 'nao_compareceu';

    public function rotulo(): string
    {
        return match ($this) {
            self::Solicitado => 'Aguardando confirmação',
            self::Confirmado => 'Confirmado',
            self::EmAtendimento => 'Em atendimento',
            self::Concluido => 'Concluído',
            self::Cancelado => 'Cancelado',
            self::NaoCompareceu => 'Não compareceu',
        };
    }

    /**
     * Estados em que um agendamento pode ser criado. Atendimento espontaneo
     * ja terminado: cria em EmAtendimento, grava os itens e conclui na mesma
     * transacao (itens de agendamento encerrado sao imutaveis no banco).
     */
    public static function iniciais(): array
    {
        return [self::Solicitado, self::Confirmado, self::EmAtendimento];
    }

    /** @return list<self> */
    public function transicoesPermitidas(): array
    {
        return match ($this) {
            self::Solicitado => [self::Confirmado, self::Cancelado],
            self::Confirmado => [self::EmAtendimento, self::Concluido, self::Cancelado, self::NaoCompareceu],
            self::EmAtendimento => [self::Concluido, self::Cancelado],
            self::Concluido, self::Cancelado, self::NaoCompareceu => [],
        };
    }

    public function podeIrPara(self $destino): bool
    {
        return in_array($destino, $this->transicoesPermitidas(), true);
    }

    /** Segura horario na agenda (tem linha em ocupacoes_agenda). */
    public function ocupaAgenda(): bool
    {
        return in_array($this, [self::Solicitado, self::Confirmado, self::EmAtendimento, self::Concluido], true);
    }

    public function encerrado(): bool
    {
        return $this->transicoesPermitidas() === [];
    }
}
