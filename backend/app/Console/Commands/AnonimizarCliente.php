<?php

namespace App\Console\Commands;

use App\Enums\OrigemAnonimizacao;
use App\Enums\PapelUsuario;
use App\Models\User;
use App\Support\Anonimizacao;
use Illuminate\Console\Command;

/**
 * Atende a um pedido de eliminacao do titular (LGPD) ate o painel da
 * etapa 3 existir. docs/LGPD-ANONIMIZACAO.md, secao 6.
 *
 * Mostra so contagens (nunca nome, telefone ou endereco), pede que se
 * digite o id do cliente para confirmar e so entao chama a funcao do banco,
 * que e quem confere o proprietario e as demais regras.
 */
class AnonimizarCliente extends Command
{
    protected $signature = 'cleison:anonimizar-cliente
        {cliente : Id do cliente}
        {--usuario= : Id do proprietario que executa o pedido}
        {--protocolo= : Protocolo do pedido (letras, numeros e . _ / -, ate 40)}
        {--forcar : Dispensa a confirmacao (obrigatorio sem terminal interativo)}
        {--simular : So mostra o que seria afetado; nao altera nada}';

    protected $description = 'Anonimiza um cliente (pedido do titular, LGPD), mantendo o historico da agenda.';

    public function handle(): int
    {
        $clienteId = $this->inteiroPositivo($this->argument('cliente'));
        $usuarioId = $this->inteiroPositivo($this->option('usuario'));
        $protocolo = trim((string) $this->option('protocolo'));

        if ($clienteId === null) {
            $this->error('Informe o id do cliente (numero inteiro).');

            return self::FAILURE;
        }
        if ($usuarioId === null) {
            $this->error('Informe --usuario=<id do proprietario que executa o pedido>.');

            return self::FAILURE;
        }
        if (preg_match(Anonimizacao::FORMATO_PROTOCOLO, $protocolo) !== 1) {
            $this->error('Informe --protocolo (letras, numeros e . _ / -, ate 40 caracteres).');

            return self::FAILURE;
        }

        // Aviso antecipado; quem garante e a funcao do banco.
        $proprietario = User::query()->find($usuarioId);
        if ($proprietario === null || ! $proprietario->ativo || $proprietario->papel !== PapelUsuario::Proprietario) {
            $this->error("O usuario {$usuarioId} nao e um proprietario ativo. So proprietario pode anonimizar clientes.");

            return self::FAILURE;
        }

        $previa = Anonimizacao::previa($clienteId);
        if ($previa === null) {
            $this->error("Cliente {$clienteId} nao encontrado.");

            return self::FAILURE;
        }
        if ($previa['anonimizado']) {
            $this->info("Cliente {$clienteId} ja esta anonimizado. Nada a fazer.");

            return self::SUCCESS;
        }

        $this->line("Cliente {$clienteId}: o que sera anonimizado (o historico da agenda continua)");
        $this->table(['Registro', 'Quantidade'], [
            ['Enderecos', $previa['enderecos']],
            ['Agendamentos', $previa['agendamentos']],
            ['Eventos do historico', $previa['eventos']],
        ]);

        if ($previa['em_aberto'] > 0) {
            $this->error("O cliente tem {$previa['em_aberto']} agendamento(s) em aberto. Cancele ou conclua antes de anonimizar.");

            return self::FAILURE;
        }

        if ($this->option('simular')) {
            $this->info('Simulacao: nada foi alterado.');

            return self::SUCCESS;
        }

        if (! $this->option('forcar')) {
            if (! $this->input->isInteractive()) {
                $this->error('Sem terminal interativo, confirme com --forcar.');

                return self::FAILURE;
            }
            $digitado = trim((string) $this->ask('Isto nao pode ser desfeito. Digite o id do cliente para confirmar'));
            if ($digitado !== (string) $clienteId) {
                $this->error('Confirmacao nao confere. Nada foi alterado.');

                return self::FAILURE;
            }
        }

        $registro = Anonimizacao::executar($clienteId, $proprietario, OrigemAnonimizacao::PedidoTitular, $protocolo);

        if ($registro === null) {
            $this->info("Cliente {$clienteId} ja estava anonimizado. Nada a fazer.");

            return self::SUCCESS;
        }

        $this->info("Cliente {$clienteId} anonimizado (registro {$registro}, protocolo {$protocolo}).");

        return self::SUCCESS;
    }

    private function inteiroPositivo(mixed $valor): ?int
    {
        $inteiro = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $inteiro === false ? null : $inteiro;
    }
}
