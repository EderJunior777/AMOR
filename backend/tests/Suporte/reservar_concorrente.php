<?php

/*
 * Processo filho usado por ConcorrenciaTest: tenta ocupar a agenda numa
 * conexao PROPRIA, como um segundo servidor/worker faria. Nao carrega o
 * Laravel (sobe rapido e nao herda estado do teste).
 *
 * Sincronizacao sem relogio: o processo pai segura pg_advisory_lock(portao)
 * EXCLUSIVO. Cada filho conecta e fica bloqueado em
 * pg_advisory_lock_shared(portao). O pai confere em pg_locks que todos os
 * filhos estao esperando e so entao solta o lock: todos sao liberados juntos.
 *
 * Uso: php reservar_concorrente.php <agendamento|bloqueio> <profissional> <cliente> <inicio> <fim> <portao>
 * Saida (stdout, uma linha JSON):
 *   {"ok":true,"id":N,"repeticoes_por_deadlock":K}
 *   {"ok":false,"sqlstate":"23P01","erro":"...","repeticoes_por_deadlock":K}
 */

function ocupar(PDO $pdo, string $tipo, string $profissional, string $cliente, string $inicio, string $fim): array
{
    try {
        $pdo->beginTransaction();

        if ($tipo === 'bloqueio') {
            $stmt = $pdo->prepare(
                "INSERT INTO bloqueios_agenda (profissional_id, tipo, inicio, fim, motivo)
                 VALUES (?, 'compromisso', ?, ?, 'teste de concorrencia') RETURNING id"
            );
            $stmt->execute([$profissional, $inicio, $fim]);
            $id = (int) $stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO agendamentos (profissional_id, cliente_id, estado, origem, modalidade,
                                           inicio_servico, fim_servico, inicio_ocupado, fim_ocupado)
                 VALUES (?, ?, 'solicitado', 'site', 'barbearia', ?, ?, ?, ?) RETURNING id"
            );
            $stmt->execute([$profissional, $cliente, $inicio, $fim, $inicio, $fim]);
            $id = (int) $stmt->fetchColumn();

            $pdo->prepare(
                'INSERT INTO agendamento_itens (agendamento_id, servico_id, servico_nome, preco_centavos, duracao_minutos, conta_como_corte)
                 SELECT a.id, s.id, s.nome, s.preco_centavos,
                        extract(epoch FROM a.fim_servico - a.inicio_servico)::int / 60, s.conta_como_corte
                   FROM agendamentos a, (SELECT * FROM servicos ORDER BY id LIMIT 1) s
                  WHERE a.id = ?'
            )->execute([$id]);
        }

        $pdo->commit();

        return ['ok' => true, 'id' => $id];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'sqlstate' => $e->errorInfo[0] ?? (string) $e->getCode(), 'erro' => $e->getMessage()];
    }
}

[, $tipo, $profissional, $cliente, $inicio, $fim, $portao] = $argv;

$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('CLEISON_PG_HOST'), getenv('CLEISON_PG_PORT'), getenv('CLEISON_PG_DB')),
    getenv('CLEISON_PG_USER'),
    getenv('CLEISON_PG_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("SET lock_timeout = '20s'");
$pdo->exec("SET application_name = 'cleison_teste_concorrencia'");

// Portao: bloqueia aqui ate o pai soltar o lock exclusivo.
$pdo->prepare('SELECT pg_advisory_lock_shared(?)')->execute([(int) $portao]);
$pdo->prepare('SELECT pg_advisory_unlock_shared(?)')->execute([(int) $portao]);

// 40P01/40001: transacao abortada pelo PostgreSQL sem gravar nada; o
// correto e repetir. Na repeticao vem o conflito definitivo (23P01) ou o
// sucesso. E o que o servico da etapa 2 fara (docs/ARQUITETURA.md).
$deadlocks = 0;
for ($tentativa = 1; ; $tentativa++) {
    $resultado = ocupar($pdo, $tipo, $profissional, $cliente, $inicio, $fim);
    $repetivel = ! $resultado['ok'] && in_array($resultado['sqlstate'], ['40P01', '40001'], true);
    if (! $repetivel || $tentativa === 5) {
        break;
    }
    $deadlocks++;
    usleep(random_int(1000, 20000));
}

echo json_encode($resultado + ['repeticoes_por_deadlock' => $deadlocks]), PHP_EOL;
