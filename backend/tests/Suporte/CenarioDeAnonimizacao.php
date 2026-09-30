<?php

namespace Tests\Suporte;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cliente com historico completo e dado pessoal facil de procurar (valores
 * ficticios e incomuns, para a varredura nao achar coincidencia): endereco,
 * agendamento em domicilio remarcado e concluido, cancelado com motivo,
 * nao_compareceu, idempotencia preenchida e observacoes.
 *
 * Exige DadosDeAgenda na classe de teste.
 */
trait CenarioDeAnonimizacao
{
    protected const HASH_DO_PEDIDO = '7e5a1f0c4b2d9e8a6c3f1b0d5e7a9c2b4d6f8a0c1e3b5d7f9a2c4e6b8d0f1a3c';

    /** Tudo que identifica o cliente do cenario. Nada disso pode sobrar. */
    protected function dadosPessoais(): array
    {
        return ['Zebedeu', 'Quixabeira', '987651234', 'Xiquexique', 'Zeta 77', 'Verdejante', 'Kappa', self::HASH_DO_PEDIDO];
    }

    protected function proprietario(): User
    {
        return User::factory()->proprietario()->create();
    }

    protected function anonimizar(int $cliente, ?int $usuario, string $origem = 'pedido_titular', ?string $protocolo = 'LGPD-2026/001'): mixed
    {
        return DB::scalar('SELECT public.cleison_anonimizar_cliente(?, ?, ?, ?)', [$cliente, $usuario, $origem, $protocolo]);
    }

    /**
     * @return array{cliente: int, profissional: int, endereco: int, domicilio: int, cancelado: int, faltou: int}
     */
    protected function clienteComHistorico(): array
    {
        $profissional = $this->novoProfissional('Profissional do Cenario');
        $regiao = $this->novaRegiao(['nome' => 'Zona Norte']);
        $cliente = $this->novoCliente('Zebedeu Quixabeira', '+5511987651234');
        DB::table('clientes')->where('id', $cliente)->update(['observacoes' => 'Prefere tesoura Kappa']);

        $endereco = DB::table('enderecos_cliente')->insertGetId([
            'cliente_id' => $cliente,
            'regiao_id' => $regiao,
            'logradouro' => 'Rua Xiquexique 4321',
            'complemento' => 'Bloco Zeta 77',
            'referencia' => 'Portao Verdejante',
        ]);

        // Domicilio: criado, remarcado (+1h) e concluido.
        // Agendamento + itens na mesma transacao: os itens sao conferidos no
        // COMMIT (funciona com dados commitados e dentro do RefreshDatabase).
        $domicilio = DB::transaction(fn () => $this->novoAgendamento('10:00', '10:30', [
            'profissional_id' => $profissional,
            'cliente_id' => $cliente,
            'modalidade' => 'domicilio',
            'endereco_cliente_id' => $endereco,
            'endereco_texto' => 'Rua Xiquexique 4321, Bloco Zeta 77',
            'regiao_id' => $regiao,
            'regiao_nome' => 'Zona Norte',
            'deslocamento_minutos' => 30,
            'taxa_deslocamento_centavos' => 2000,
            'inicio_ocupado' => $this->em('09:30'),
            'fim_ocupado' => $this->em('11:00'),
            'observacao_cliente' => 'Zebedeu pediu Kappa',
            'chave_idempotencia' => 'pedido-zebedeu-1',
            'hash_requisicao' => self::HASH_DO_PEDIDO,
        ]));
        DB::table('agendamentos')->where('id', $domicilio)->update([
            'inicio_servico' => $this->em('11:00'), 'fim_servico' => $this->em('11:30'),
            'inicio_ocupado' => $this->em('10:30'), 'fim_ocupado' => $this->em('12:00'),
        ]);
        DB::table('agendamentos')->where('id', $domicilio)->update(['estado' => 'concluido']);

        $cancelado = DB::transaction(fn () => $this->novoAgendamento('14:00', '14:30', [
            'profissional_id' => $profissional, 'cliente_id' => $cliente, 'observacao_cliente' => 'Zebedeu vai de Kappa',
        ]));
        DB::table('agendamentos')->where('id', $cancelado)->update([
            'estado' => 'cancelado', 'cancelado_em' => now(), 'motivo_cancelamento' => 'Zebedeu viajou, ligar 11987651234',
        ]);

        $faltou = DB::transaction(fn () => $this->novoAgendamento('16:00', '16:30', ['profissional_id' => $profissional, 'cliente_id' => $cliente]));
        DB::table('agendamentos')->where('id', $faltou)->update(['estado' => 'nao_compareceu']);

        return compact('cliente', 'profissional', 'endereco', 'domicilio', 'cancelado', 'faltou');
    }
}
