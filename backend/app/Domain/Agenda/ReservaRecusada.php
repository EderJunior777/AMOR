<?php

namespace App\Domain\Agenda;

use DomainException;
use LogicException;

/**
 * Recusa de regra de negocio, com codigo ESTAVEL (o site e testes dependem
 * dele) e mensagem para o cliente. Nunca leva id, nome de outro cliente nem
 * detalhe interno. A API responde 422 com {mensagem, codigo}.
 */
final class ReservaRecusada extends DomainException
{
    public const MENSAGENS = [
        'data_invalida' => 'Data invalida.',
        'fora_da_grade' => 'Escolha um horario da lista.',
        'hora_inexistente' => 'Este horario nao existe nesta data (mudanca de horario de verao).',
        'antecedencia' => 'Este horario ja nao pode ser reservado. Escolha outro mais adiante.',
        'alem_do_horizonte' => 'A agenda para esta data ainda nao esta aberta.',
        'servico_indisponivel' => 'Um dos servicos escolhidos nao esta disponivel.',
        'profissional_indisponivel' => 'Este profissional nao atende o que foi escolhido.',
        'domicilio_indisponivel' => 'Atendimento a domicilio indisponivel para esta regiao ou endereco.',
        'fora_do_expediente' => 'Horario fora do expediente.',
        'idempotencia_conflito' => 'Esta solicitacao ja foi usada com outros dados. Recarregue a pagina e tente de novo.',
        'remarcacao_exige_novo_pedido' => 'Reserva ja confirmada nao pode ser remarcada pelo site. Cancele e faca uma nova reserva.',
        'reserva_nao_encontrada' => 'Reserva nao encontrada. Confira o codigo e o telefone.',
        'fora_do_prazo' => 'O prazo para alterar esta reserva pelo site ja passou. Fale com a barbearia.',
        'motivo_obrigatorio' => 'Informe o motivo do encaixe.',
        'motivo_muito_longo' => 'O motivo pode ter no maximo trezentos caracteres.',
        'estado_nao_permite' => 'Esta reserva nao pode ser alterada no estado atual.',
        'agenda_indisponivel' => 'A agenda esta indisponivel no momento. Tente novamente mais tarde.',
    ];

    private function __construct(public readonly string $codigo)
    {
        parent::__construct(self::MENSAGENS[$codigo]);
    }

    public static function por(string $codigo): self
    {
        if (! isset(self::MENSAGENS[$codigo])) {
            throw new LogicException("Codigo de recusa desconhecido: {$codigo}");
        }

        return new self($codigo);
    }
}
