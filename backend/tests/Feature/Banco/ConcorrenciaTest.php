<?php

namespace Tests\Feature\Banco;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * Concorrencia de verdade: dados COMMITADOS, conexoes separadas e
 * processos PHP separados batendo no PostgreSQL ao mesmo tempo. Por isso
 * esta classe nao usa RefreshDatabase (que embrulha tudo numa transacao
 * so) e limpa as tabelas do banco de TESTE antes e depois de cada caso.
 *
 * Os processos filhos sao sincronizados por um advisory lock (portao)
 * controlado por este processo, conferido em pg_locks: nada de "esperar
 * N segundos e torcer".
 */
class ConcorrenciaTest extends TestCase
{
    use DadosDeAgenda;

    private const TABELAS = 'ocupacoes_agenda, agendamento_eventos, agendamento_itens, agendamentos, '
        .'bloqueios_agenda, enderecos_cliente, clientes, profissional_servico, expedientes_semanais, '
        .'excecoes_expediente, servicos, regioes_atendimento, profissionais, estabelecimento, users';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true, '--database' => 'pgsql_migracao']);
        $this->limpar();
        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        $this->servico = $this->novoServico(); // usado pelos processos filhos
    }

    private int $servico;

    /** Profissional que atende o servico dos processos (E1: o site exige o vinculo). */
    private function profissionalDoSite(string $nome = 'Profissional Teste'): int
    {
        $id = $this->novoProfissional($nome);
        DB::table('profissional_servico')->insert(['profissional_id' => $id, 'servico_id' => $this->servico]);

        return $id;
    }

    protected function tearDown(): void
    {
        $this->religarFila();
        DB::purge('pgsql_b');
        $this->limpar();
        parent::tearDown();
    }

    /** TRUNCATE exige o papel dono: a aplicacao nao pode (PrivilegiosTest). */
    private function limpar(): void
    {
        $this->conexaoDono()->statement('TRUNCATE '.self::TABELAS.' RESTART IDENTITY CASCADE');
    }

    private function religarFila(): void
    {
        $this->conexaoDono()->statement('ALTER TABLE ocupacoes_agenda ENABLE TRIGGER ocupacoes_fila_por_profissional');
    }

    /** Tenta reservar na conexao dada. null = conseguiu; senao o SQLSTATE. */
    private function tentarEm(Connection $conexao, int $profissional, int $cliente, string $inicio, string $fim): ?string
    {
        try {
            $conexao->transaction(function () use ($conexao, $profissional, $cliente, $inicio, $fim) {
                $id = $conexao->table('agendamentos')->insertGetId([
                    'profissional_id' => $profissional, 'cliente_id' => $cliente,
                    'estado' => 'solicitado', 'origem' => 'site', 'modalidade' => 'barbearia',
                    'inicio_servico' => $this->em($inicio), 'fim_servico' => $this->em($fim),
                    'inicio_ocupado' => $this->em($inicio), 'fim_ocupado' => $this->em($fim),
                ]);
                $conexao->insert(
                    'INSERT INTO agendamento_itens (agendamento_id, servico_id, servico_nome, preco_centavos, duracao_minutos, conta_como_corte)
                     SELECT ?, id, nome, preco_centavos, duracao_minutos, conta_como_corte FROM servicos ORDER BY id LIMIT 1',
                    [$id]
                );
            });

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? 'desconhecido';
        }
    }

    /**
     * Invariantes do que ficou GRAVADO: todo compromisso ativo tem
     * ocupacao, nenhum inativo tem, e nenhum profissional tem ocupacoes
     * sobrepostas.
     */
    private function assertAgendaConsistente(): void
    {
        $this->assertSame(0, (int) DB::scalar(
            'SELECT count(*) FROM agendamentos a
              WHERE cleison_estado_ocupa_agenda(a.estado)
                AND NOT EXISTS (SELECT 1 FROM ocupacoes_agenda o WHERE o.agendamento_id = a.id)'
        ), 'agendamento ativo sem ocupacao');
        $this->assertSame(0, (int) DB::scalar(
            'SELECT count(*) FROM ocupacoes_agenda o JOIN agendamentos a ON a.id = o.agendamento_id
              WHERE NOT cleison_estado_ocupa_agenda(a.estado)'
        ), 'ocupacao de agendamento inativo');
        $this->assertSame(0, (int) DB::scalar(
            'SELECT count(*) FROM ocupacoes_agenda o JOIN bloqueios_agenda b ON b.id = o.bloqueio_id WHERE b.cancelado_em IS NOT NULL'
        ), 'ocupacao de bloqueio cancelado');
        $this->assertSame(0, (int) DB::scalar(
            'SELECT count(*) FROM ocupacoes_agenda x JOIN ocupacoes_agenda y
                ON x.profissional_id = y.profissional_id AND x.id < y.id AND x.periodo && y.periodo'
        ), 'ocupacoes sobrepostas gravadas');
        $this->assertSame(0, (int) DB::scalar(
            'SELECT count(*) FROM agendamentos a WHERE NOT EXISTS (SELECT 1 FROM agendamento_itens i WHERE i.agendamento_id = a.id)'
        ), 'agendamento sem itens');
    }

    public function test_segunda_conexao_espera_a_primeira_e_perde_quando_ela_confirma(): void
    {
        $ze = $this->profissionalDoSite('Ze');
        $joao = $this->novoCliente('Joao', '+5511987654321');
        $ana = $this->novoCliente('Ana', '+5511911112222');
        $a = DB::connection();
        $b = DB::connection('pgsql_b');

        // A reservou 10:00 e ainda nao confirmou a transacao.
        $a->beginTransaction();
        $this->novoAgendamento('10:00', '10:30', ['profissional_id' => $ze, 'cliente_id' => $joao, 'estado' => 'solicitado']);

        // B tenta 10:15-10:45 no mesmo instante: nao "passa por nao ver" a
        // linha de A. Fica esperando A decidir (aqui, estoura o lock_timeout).
        $b->statement("SET lock_timeout = '300ms'");
        $this->assertSame('55P03', $this->tentarEm($b, $ze, $ana, '10:15', '10:45'),
            'B deveria esperar a transacao de A, e nao gravar por cima.');

        $a->commit();

        // A confirmou: B leva conflito de exclusao.
        $this->assertSame('23P01', $this->tentarEm($b, $ze, $ana, '10:15', '10:45'));
        $this->assertSame(1, DB::table('agendamentos')->where('profissional_id', $ze)->count());
        $this->assertSame(1, DB::table('ocupacoes_agenda')->where('profissional_id', $ze)->count());
        $this->assertAgendaConsistente();
    }

    public function test_se_a_primeira_desiste_o_horario_fica_livre(): void
    {
        $ze = $this->profissionalDoSite('Ze');
        $joao = $this->novoCliente('Joao', '+5511987654321');
        $ana = $this->novoCliente('Ana', '+5511911112222');

        DB::beginTransaction();
        $this->novoAgendamento('10:00', '10:30', ['profissional_id' => $ze, 'cliente_id' => $joao, 'estado' => 'solicitado']);
        DB::rollBack();

        $this->assertNull($this->tentarEm(DB::connection('pgsql_b'), $ze, $ana, '10:00', '10:30'));
        $this->assertAgendaConsistente();
    }

    public function test_corrida_entre_processos_pelo_mesmo_horario(): void
    {
        $ze = $this->profissionalDoSite('Ze');
        $tentativas = [];
        foreach (range(1, 10) as $i) {
            $tentativas[] = ['agendamento', $ze, $this->novoCliente("Cliente {$i}", sprintf('+55119000000%02d', $i)), '14:00', '14:30'];
        }

        $resultados = $this->dispararProcessos($tentativas);

        $ganharam = array_values(array_filter($resultados, fn ($r) => $r['ok']));
        $perderam = array_values(array_filter($resultados, fn ($r) => ! $r['ok']));

        $this->assertCount(1, $ganharam, json_encode($resultados));
        $this->assertCount(9, $perderam);
        foreach ($perderam as $r) {
            $this->assertSame('23P01', $r['sqlstate'], $r['erro'] ?? '');
        }

        $this->assertSame(1, DB::table('agendamentos')->count());
        $this->assertSame(1, DB::table('agendamento_itens')->count(), 'nenhum item orfao das tentativas que perderam');
        $this->assertSame(1, DB::table('ocupacoes_agenda')->count());
        $this->assertAgendaConsistente();

        // Com a fila por profissional ninguem precisa repetir por deadlock.
        $this->assertSame(0, array_sum(array_column($resultados, 'repeticoes_por_deadlock')), json_encode($resultados));
    }

    public function test_so_a_constraint_de_exclusao_ja_impede_reserva_dupla(): void
    {
        // Desliga a fila (papel dono) para provar que a GARANTIA e da
        // constraint de exclusao; a fila so troca deadlock por espera.
        $this->conexaoDono()->statement('ALTER TABLE ocupacoes_agenda DISABLE TRIGGER ocupacoes_fila_por_profissional');

        $ze = $this->profissionalDoSite('Ze');
        $tentativas = [];
        foreach (range(1, 8) as $i) {
            $tentativas[] = ['agendamento', $ze, $this->novoCliente("Cliente {$i}", sprintf('+55119000001%02d', $i)), '14:00', '14:30'];
        }

        $resultados = $this->dispararProcessos($tentativas);

        $this->assertCount(1, array_filter($resultados, fn ($r) => $r['ok']), json_encode($resultados));
        foreach (array_filter($resultados, fn ($r) => ! $r['ok']) as $r) {
            // Sem a fila, um perdedor pode esgotar as repeticoes em deadlock;
            // mesmo assim nada foi gravado por ele.
            $this->assertContains($r['sqlstate'], ['23P01', '40P01'], $r['erro'] ?? '');
        }
        $this->assertSame(1, DB::table('agendamentos')->count());
        $this->assertSame(1, DB::table('ocupacoes_agenda')->count());
        $this->assertAgendaConsistente();

        // Evidencia opcional (CLEISON_RELATORIO_CONCORRENCIA=1); fora disso
        // nao escreve nada, para nao poluir a saida do runner.
        if (getenv('CLEISON_RELATORIO_CONCORRENCIA')) {
            fwrite(STDERR, sprintf(
                "\n[concorrencia sem fila] 8 processos, 1 gravou; repeticoes por deadlock: %d; perdedores terminados em 40P01: %d\n",
                array_sum(array_column($resultados, 'repeticoes_por_deadlock')),
                count(array_filter($resultados, fn ($r) => ! $r['ok'] && $r['sqlstate'] === '40P01'))
            ));
        }
    }

    public function test_bloqueio_e_agendamentos_disputando_o_mesmo_periodo(): void
    {
        $ze = $this->profissionalDoSite('Ze');
        $tentativas = [['bloqueio', $ze, 0, '14:00', '15:00']];
        foreach (range(1, 5) as $i) {
            $tentativas[] = ['agendamento', $ze, $this->novoCliente("Cliente {$i}", sprintf('+55119000002%02d', $i)), '14:30', '15:00'];
        }

        $resultados = $this->dispararProcessos($tentativas);

        $this->assertCount(1, array_filter($resultados, fn ($r) => $r['ok']), json_encode($resultados));
        foreach (array_filter($resultados, fn ($r) => ! $r['ok']) as $r) {
            $this->assertSame('23P01', $r['sqlstate'], $r['erro'] ?? '');
        }
        $this->assertSame(1, DB::table('ocupacoes_agenda')->count());
        $this->assertSame(
            1,
            DB::table('bloqueios_agenda')->count() + DB::table('agendamentos')->count(),
            'so um dos lados gravou'
        );
        $this->assertAgendaConsistente();
    }

    public function test_em_paralelo_horarios_encostados_e_profissionais_diferentes_passam(): void
    {
        $ze = $this->profissionalDoSite('Ze');
        $beto = $this->profissionalDoSite('Beto');
        $cliente = $this->novoCliente('Joao', '+5511987654321');

        $tentativas = [];
        foreach ([$ze, $beto] as $prof) {
            foreach ([['10:00', '10:30'], ['10:30', '11:00'], ['11:00', '11:30']] as [$ini, $fim]) {
                $tentativas[] = ['agendamento', $prof, $cliente, $ini, $fim];
            }
        }

        $resultados = $this->dispararProcessos($tentativas);

        $this->assertSame(array_fill(0, 6, true), array_column($resultados, 'ok'), json_encode($resultados));
        $this->assertSame(6, DB::table('ocupacoes_agenda')->count());
        $this->assertAgendaConsistente();
    }

    public function test_agendamento_sem_servico_e_barrado_no_commit_de_verdade(): void
    {
        $ze = $this->profissionalDoSite();
        $cliente = $this->novoCliente();

        try {
            DB::transaction(fn () => DB::table('agendamentos')->insert([
                'profissional_id' => $ze, 'cliente_id' => $cliente,
                'estado' => 'solicitado', 'origem' => 'site', 'modalidade' => 'barbearia',
                'inicio_servico' => $this->em('10:00'), 'fim_servico' => $this->em('10:30'),
                'inicio_ocupado' => $this->em('10:00'), 'fim_ocupado' => $this->em('10:30'),
            ]));
            $this->fail('COMMIT de agendamento sem servico foi aceito.');
        } catch (PDOException $e) {
            // Erro de constraint adiada surge no COMMIT como PDOException
            // "crua" (o Laravel nao embrulha em QueryException ali).
            $this->assertSame('23514', $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertStringContainsString('agendamentos_com_servico', $e->getMessage());
        }

        $this->assertSame(0, DB::table('agendamentos')->count());
        $this->assertSame(0, DB::table('ocupacoes_agenda')->count(), 'a ocupacao criada pelo trigger foi desfeita junto');
    }

    /**
     * Sobe um processo PHP por tentativa e so os libera quando TODOS estao
     * conectados e bloqueados no portao (conferido em pg_locks).
     *
     * @param  list<array{string,int,int,string,string}>  $tentativas  [tipo, profissional, cliente, inicio, fim]
     * @return list<array{ok:bool,id?:int,sqlstate?:string,erro?:string,repeticoes_por_deadlock:int}>
     */
    private function dispararProcessos(array $tentativas): array
    {
        $cfg = config('database.connections.pgsql');
        $ambiente = array_merge(getenv(), [
            'CLEISON_PG_HOST' => (string) $cfg['host'],
            'CLEISON_PG_PORT' => (string) $cfg['port'],
            'CLEISON_PG_DB' => (string) $cfg['database'],
            'CLEISON_PG_USER' => (string) $cfg['username'],
            'CLEISON_PG_PASS' => (string) $cfg['password'],
        ]);
        $script = base_path('tests/Suporte/reservar_concorrente.php');
        $portao = random_int(1000, 2_000_000_000);
        $quem = DB::connection('pgsql_b');
        $quem->select('SELECT pg_advisory_lock(?)', [$portao]);

        $processos = [];
        try {
            foreach ($tentativas as [$tipo, $prof, $cliente, $inicio, $fim]) {
                $comando = [PHP_BINARY, $script, $tipo, (string) $prof, (string) $cliente, $this->em($inicio), $this->em($fim), (string) $portao];
                $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, base_path(), $ambiente);
                $this->assertIsResource($processo);
                $processos[] = [$processo, $tubos];
            }

            // Espera (com limite) ate todos os filhos estarem na fila do portao.
            $limite = microtime(true) + 60;
            do {
                $esperando = (int) DB::scalar(
                    "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 0 AND objid = ? AND NOT granted",
                    [$portao]
                );
                if ($esperando === count($tentativas)) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $limite);

            $this->assertSame(count($tentativas), $esperando, 'nem todos os processos chegaram ao portao');
        } finally {
            $quem->select('SELECT pg_advisory_unlock(?)', [$portao]);
        }

        $resultados = [];
        foreach ($processos as [$processo, $tubos]) {
            $saida = stream_get_contents($tubos[1]);
            $erro = stream_get_contents($tubos[2]);
            fclose($tubos[1]);
            fclose($tubos[2]);
            proc_close($processo);
            $resultados[] = json_decode(trim((string) $saida), true)
                ?? ['ok' => false, 'sqlstate' => 'SAIDA_INVALIDA', 'erro' => $saida.$erro, 'repeticoes_por_deadlock' => 0];
        }

        return $resultados;
    }
}
