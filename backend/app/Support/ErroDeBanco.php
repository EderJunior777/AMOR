<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use WeakMap;

/**
 * Traduz erros do PostgreSQL (QueryException e a PDOException crua que o
 * COMMIT lanca nas conferencias adiadas) em respostas HTTP, por SQLSTATE:
 *
 *   23P01                         -> 409 "Horario indisponivel"
 *   23514/23505/23503/23001       -> 422, mensagem pela constraint (tabela abaixo) ou generica
 *   40P01/40001                   -> 503 + Retry-After
 *   demais                        -> 500 generico
 *
 * Nunca devolve a mensagem do banco (os triggers citam ids e valores
 * internos; o DETAIL traz a linha, com nome e telefone). O log leva so
 * SQLSTATE, constraint e um id de correlacao, nunca os parametros da
 * query (PII, LGPD). O mesmo id vai na resposta, para o suporte achar o log.
 */
final class ErroDeBanco
{
    /** Mensagens por constraint/regra de trigger (so as que um usuario consegue disparar). */
    public const MENSAGENS = [
        'clientes_telefone_unico' => 'Ja existe um cliente com este telefone.',
        'clientes_telefone' => 'Telefone invalido.',
        'clientes_nome' => 'Informe o nome do cliente.',
        'users_email_unico' => 'Este e-mail ja esta em uso.',
        'users_email_formato' => 'E-mail invalido.',
        'servicos_codigo_key' => 'Ja existe um servico com este codigo.',
        'regioes_codigo_key' => 'Ja existe uma regiao com este codigo.',
        'agendamentos_chave_idempotencia_unica' => 'Esta solicitacao ja foi registrada com outros dados.',
        'agendamentos_idempotencia_coerente' => 'Solicitacao sem identificacao coerente.',
        'agendamentos_estado_inicial' => 'Agendamento nao pode nascer neste estado.',
        'agendamentos_transicao_estado' => 'Mudanca de estado nao permitida.',
        'agendamentos_encerrado_imutavel' => 'Atendimento encerrado nao pode ser alterado.',
        'agendamento_itens_encerrado_imutavel' => 'Atendimento encerrado nao pode ser alterado.',
        'agendamentos_com_servico' => 'O agendamento precisa de pelo menos um servico.',
        'agendamentos_duracao_dos_itens' => 'A duracao dos servicos nao confere com o horario.',
        'agendamentos_domicilio_completo' => 'Atendimento em domicilio exige endereco e regiao.',
        'agendamentos_barbearia_sem_domicilio' => 'Atendimento na barbearia nao leva endereco nem taxa.',
        'agendamentos_endereco_do_cliente' => 'O endereco nao pertence a este cliente.',
        'agendamentos_cancelamento_coerente' => 'Cancelamento sem data coerente.',
        'agendamentos_servico_intervalo' => 'Horario de termino antes do inicio.',
        'agendamentos_instantes_finitos' => 'Horario invalido.',
        'agendamentos_ocupado_max_24h' => 'O atendimento nao pode passar de 24 horas.',
        'bloqueios_intervalo' => 'O bloqueio termina antes de comecar.',
        'bloqueios_max_90_dias' => 'O bloqueio pode ter no maximo 90 dias.',
        'bloqueios_instantes_finitos' => 'Horario invalido.',
        'expedientes_sem_sobreposicao' => 'Esta janela de expediente se sobrepoe a outra.',
        'excecoes_sem_sobreposicao' => 'Esta janela de excecao se sobrepoe a outra.',
        'estabelecimento_fuso_valido' => 'Fuso horario desconhecido.',
        'anonimizacao_exige_proprietario' => 'So um proprietario ativo pode anonimizar clientes.',
        'anonimizacao_cliente_inexistente' => 'Cliente nao encontrado.',
        'clientes_anonimizacao_com_ativo' => 'O cliente tem agendamento em aberto; cancele ou conclua antes de anonimizar.',
        'clientes_anonimizado_fechado' => 'Este cliente foi anonimizado e nao recebe novos dados.',
        'clientes_anonimizado_imutavel' => 'Cliente anonimizado nao pode ser alterado.',
        'anonimizacoes_protocolo' => 'Protocolo invalido (letras, numeros e . _ / -, ate 40 caracteres).',
        'anonimizacoes_protocolo_do_pedido' => 'Informe o protocolo do pedido.',
    ];

    private const GENERICAS = [
        '23514' => 'Os dados nao atendem as regras da agenda.',
        '23505' => 'Registro duplicado.',
        '23503' => 'Referencia invalida ou registro em uso.',
        '23001' => 'Registro em uso; nao pode ser removido.',
    ];

    /** @var WeakMap<PDOException, string>|null */
    private static ?WeakMap $correlacoes = null;

    public static function sqlstate(PDOException $e): ?string
    {
        $estado = $e->errorInfo[0] ?? null;
        if (is_string($estado) && preg_match('/^[0-9A-Z]{5}$/', $estado)) {
            return $estado;
        }
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $e->getMessage(), $m)) {
            return $m[1];
        }

        return null;
    }

    /** Nome da constraint ou da regra de trigger ("[nome] ..."), se houver. */
    public static function constraint(PDOException $e): ?string
    {
        $texto = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if (preg_match('/\[([a-z][a-z0-9_]*)\]/', $texto, $m)
            || preg_match('/constraint "([a-z][a-z0-9_]*)"/', $texto, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function correlacao(PDOException $e): string
    {
        self::$correlacoes ??= new WeakMap;

        return self::$correlacoes[$e] ??= (string) Str::uuid();
    }

    /** @return array{0: int, 1: string, 2: string} [status, codigo, mensagem] */
    public static function classificar(PDOException $e): array
    {
        $estado = self::sqlstate($e);

        return match (true) {
            $estado === '23P01' => [409, 'horario_indisponivel', 'Horario indisponivel.'],
            isset(self::GENERICAS[$estado]) => [422, 'dados_invalidos', self::MENSAGENS[self::constraint($e)] ?? self::GENERICAS[$estado]],
            in_array($estado, ['40P01', '40001'], true) => [503, 'tente_novamente', 'Muitas operacoes ao mesmo tempo. Tente novamente em instantes.'],
            default => [500, 'erro_interno', 'Erro interno.'],
        };
    }

    public static function resposta(PDOException $e): JsonResponse
    {
        [$status, $codigo, $mensagem] = self::classificar($e);

        $resposta = response()->json([
            'mensagem' => $mensagem,
            'codigo' => $codigo,
            'correlacao' => self::correlacao($e),
        ], $status);

        if ($status === 503) {
            $resposta->headers->set('Retry-After', '1');
        }

        return $resposta;
    }

    /** Log sem mensagem do banco e sem parametros da query. */
    public static function registrar(PDOException $e): void
    {
        [$status] = self::classificar($e);

        Log::log(match (true) {
            $status === 409 => 'info',
            $status === 500 => 'error',
            default => 'warning',
        }, 'Erro de banco', [
            'sqlstate' => self::sqlstate($e),
            'constraint' => self::constraint($e),
            'correlacao' => self::correlacao($e),
            'status' => $status,
            'conexao' => $e instanceof QueryException ? $e->getConnectionName() : null,
        ]);
    }
}
