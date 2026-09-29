<?php

namespace Tests\Feature\Banco;

use App\Support\AlvoDescartavel;
use App\Support\AlvoNaoAutorizado;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Achado A da revisao externa: operacoes destrutivas dos testes (e o
 * migrate:fresh/reset/rollback/db:wipe em geral) so podem atingir o banco
 * descartavel comprovado.
 *
 * Alvos usados aqui, e SO eles:
 *   - cleison_teste: banco de testes, com a marca "cleison:descartavel:<uuid>";
 *   - cleison_isca_teste: ISCA descartavel, termina em _teste mas NAO tem a
 *     marca; guarda so a tabela "sentinela". Se um comando destrutivo o
 *     atingisse, a sentinela sumiria ou apareceriam tabelas/migrations.
 * O banco de desenvolvimento nunca e alvo.
 *
 * Prova de que o comando destrutivo NAO rodou: depois de cada recusa, a isca
 * continua com exatamente 1 tabela (sentinela, 1 linha) e sem "migrations";
 * no banco de teste, a tabela sentinela_trava criada antes continua la.
 */
class TravaDeAlvoTest extends TestCase
{
    private const ISCA = 'cleison_isca_teste';

    protected function setUp(): void
    {
        parent::setUp();
        $this->conexaoDono()->statement('CREATE TABLE IF NOT EXISTS sentinela_trava (id int)');
        $this->assertIscaIntacta();
    }

    protected function tearDown(): void
    {
        $this->conexaoDono()->statement('DROP TABLE IF EXISTS sentinela_trava');
        foreach (['trava_a', 'trava_b', 'isca_leitura'] as $c) {
            DB::purge($c);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------ apoio

    private function conexao(string $nome, string $base, array $mudancas): string
    {
        config(["database.connections.{$nome}" => array_merge(config("database.connections.{$base}"), $mudancas)]);
        DB::purge($nome);

        return $nome;
    }

    private function url(string $base, string $banco): string
    {
        $c = config("database.connections.{$base}");

        return sprintf('pgsql://%s:%s@%s:%s/%s', rawurlencode($c['username']), rawurlencode((string) $c['password']), $c['host'], $c['port'], $banco);
    }

    private function senhas(): array
    {
        return array_filter([
            (string) config('database.connections.pgsql.password'),
            (string) config('database.connections.pgsql_migracao.password'),
        ]);
    }

    private function assertIscaIntacta(): void
    {
        $isca = DB::connection($this->conexao('isca_leitura', 'pgsql_migracao', ['database' => self::ISCA, 'url' => null]));
        $tabelas = $isca->table('pg_tables')->where('schemaname', 'public')->pluck('tablename')->all();

        $this->assertSame(['sentinela'], $tabelas, 'a isca foi alterada: um comando destrutivo atingiu o alvo errado');
        $this->assertSame(1, $isca->table('sentinela')->count());
        DB::purge('isca_leitura');
    }

    private function assertSentinelaDoTesteIntacta(): void
    {
        $this->assertNotNull(DB::scalar("SELECT to_regclass('public.sentinela_trava')"),
            'a sentinela do banco de teste sumiu: um migrate:fresh rodou');
    }

    private function assertRecusa(string $trecho, callable $acao): void
    {
        try {
            $acao();
            $this->fail("Deveria recusar ({$trecho}).");
        } catch (AlvoNaoAutorizado $e) {
            $this->assertStringContainsString($trecho, $e->getMessage());
            foreach ($this->senhas() as $senha) {
                $this->assertStringNotContainsString($senha, $e->getMessage(), 'mensagem de erro expos senha');
            }
        }
    }

    /** Roda um processo filho isolado, com variaveis de ambiente proprias. */
    private function processo(array $comando, array $variaveis): array
    {
        $ambiente = getenv();
        foreach (['DB_URL', 'DB_MIGRACAO_URL', 'APP_CONFIG_CACHE'] as $herdada) {
            unset($ambiente[$herdada]);
        }
        $ambiente = array_merge($ambiente, $variaveis);

        $p = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, base_path(), $ambiente);
        $saida = stream_get_contents($tubos[1]).stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        $codigo = proc_close($p);

        foreach ($this->senhas() as $senha) {
            $this->assertStringNotContainsString($senha, $saida, 'saida do processo expos senha');
        }

        return [$codigo, $saida];
    }

    private function phpunitFilho(array $variaveis): array
    {
        return $this->processo([PHP_BINARY, base_path('vendor/bin/phpunit'), '--filter', 'EsquemaTest', '--no-progress'], $variaveis);
    }

    // ------------------------------------------- verificacao no processo

    public function test_aplicacao_e_migrations_no_mesmo_banco_marcado_com_papeis_diferentes(): void
    {
        $ids = AlvoDescartavel::exigir($this->app['db'], ['pgsql', 'pgsql_migracao']);

        $this->assertSame(['cleison_teste'], array_values(array_unique(array_map(fn ($i) => $i->banco, $ids))));
        $this->assertNotSame($ids['pgsql']->usuario, $ids['pgsql_migracao']->usuario);
        $this->assertMatchesRegularExpression('/^cleison:descartavel:[0-9a-f]{32}$/', $ids['pgsql']->marca);
    }

    public function test_migrations_apontando_para_outro_banco_e_recusado(): void
    {
        $mig = $this->conexao('trava_b', 'pgsql_migracao', ['database' => self::ISCA]);

        $this->assertRecusa('cleison_isca_teste nao tem a marca', fn () => AlvoDescartavel::exigir($this->app['db'], ['pgsql', $mig]));
    }

    public function test_url_das_migrations_sobrescreve_o_banco_declarado(): void
    {
        // O campo "database" diz cleison_teste; a URL leva para a isca.
        $mig = $this->conexao('trava_b', 'pgsql_migracao', ['database' => 'cleison_teste', 'url' => $this->url('pgsql_migracao', self::ISCA)]);
        $this->assertSame('cleison_teste', config("database.connections.{$mig}.database"));

        $this->assertRecusa('cleison_isca_teste', fn () => AlvoDescartavel::exigir($this->app['db'], ['pgsql', $mig]));
    }

    public function test_url_da_aplicacao_sobrescreve_a_configuracao(): void
    {
        $app = $this->conexao('trava_a', 'pgsql', ['url' => $this->url('pgsql', self::ISCA)]);

        $this->assertRecusa('cleison_isca_teste', fn () => AlvoDescartavel::exigir($this->app['db'], [$app, 'pgsql_migracao']));
    }

    public function test_driver_nao_permitido(): void
    {
        config(['database.connections.trava_a' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $this->assertRecusa('driver nao permitido (sqlite)', fn () => AlvoDescartavel::exigir($this->app['db'], ['trava_a', 'pgsql_migracao']));
    }

    public function test_configuracao_insuficiente_ou_desconhecida(): void
    {
        $this->assertRecusa('Conexao desconhecida', fn () => AlvoDescartavel::exigir($this->app['db'], ['nao_existe']));
        $this->assertRecusa('Nenhuma conexao', fn () => AlvoDescartavel::exigir($this->app['db'], []));

        $sem = $this->conexao('trava_b', 'pgsql_migracao', ['username' => null, 'password' => null]);
        $this->assertRecusa('nao foi possivel verificar', fn () => AlvoDescartavel::exigir($this->app['db'], ['pgsql', $sem]));
    }

    public function test_conexao_com_pooling_direto_e_recusada(): void
    {
        // db:wipe usaria "<nome>::direct", que a trava nao verificaria. O
        // "direct" aponta para a isca; nada chega a ser executado.
        $pool = $this->conexao('trava_b', 'pgsql_migracao', ['direct' => ['database' => self::ISCA]]);

        $this->assertRecusa('conexao direta (pooling)', fn () => AlvoDescartavel::exigir($this->app['db'], ['pgsql', $pool]));
        $this->assertRecusa('conexao direta (pooling)', fn () => Artisan::call('db:wipe', ['--database' => $pool, '--force' => true]));
        $this->assertIscaIntacta();
        $this->assertSentinelaDoTesteIntacta();
    }

    public function test_falha_de_conexao_aborta_sem_alternativa(): void
    {
        $morta = $this->conexao('trava_b', 'pgsql_migracao', ['port' => 1]);

        $this->assertRecusa('nao foi possivel verificar', fn () => AlvoDescartavel::exigir($this->app['db'], ['pgsql', $morta]));
    }

    public function test_banco_sem_sufixo_de_teste_e_recusado(): void
    {
        // Banco de sistema, so leitura do catalogo.
        $sistema = $this->conexao('trava_b', 'pgsql_migracao', ['database' => 'postgres']);

        $this->assertRecusa('nao e de teste', fn () => AlvoDescartavel::exigir($this->app['db'], [$sistema]));
    }

    // ------------------------------- comandos destrutivos interceptados

    public function test_comandos_destrutivos_contra_alvo_nao_comprovado_nao_executam(): void
    {
        $isca = $this->conexao('trava_b', 'pgsql_migracao', ['database' => self::ISCA]);

        foreach (AlvoDescartavel::COMANDOS_DESTRUTIVOS as $comando) {
            $this->assertRecusa('cleison_isca_teste nao tem a marca',
                fn () => Artisan::call($comando, ['--database' => $isca, '--force' => true]));
            $this->assertIscaIntacta();
        }
    }

    // ---------------------------------- processos isolados (ambiente)

    public function test_artisan_com_url_de_migracao_herdada_para_a_isca(): void
    {
        [$codigo, $saida] = $this->processo(
            [PHP_BINARY, base_path('artisan'), 'migrate:fresh', '--database=pgsql_migracao', '--force', '--no-ansi'],
            ['APP_ENV' => 'testing', 'DB_MIGRACAO_URL' => $this->url('pgsql_migracao', self::ISCA)]
        );

        $this->assertNotSame(0, $codigo, $saida);
        $this->assertStringContainsString('cleison_isca_teste nao tem a marca', $saida);
        $this->assertIscaIntacta();
        $this->assertSentinelaDoTesteIntacta();
    }

    public function test_testes_com_banco_herdado_do_ambiente(): void
    {
        // Variavel do ambiente vence o <env> do phpunit.xml (sem force).
        [$codigo, $saida] = $this->phpunitFilho(['DB_DATABASE' => self::ISCA]);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('cleison_isca_teste nao tem a marca', $saida);
        $this->assertIscaIntacta();
    }

    public function test_testes_com_url_da_aplicacao_herdada(): void
    {
        [$codigo, $saida] = $this->phpunitFilho(['DB_URL' => $this->url('pgsql', self::ISCA)]);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('cleison_isca_teste nao tem a marca', $saida);
        $this->assertIscaIntacta();
    }

    public function test_testes_com_url_de_migracao_herdada(): void
    {
        [$codigo, $saida] = $this->phpunitFilho(['DB_MIGRACAO_URL' => $this->url('pgsql_migracao', self::ISCA)]);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('Conexao pgsql_migracao: o banco cleison_isca_teste nao tem a marca', $saida);
        $this->assertIscaIntacta();
        $this->assertSentinelaDoTesteIntacta();
    }

    public function test_testes_com_o_mesmo_usuario_nas_duas_conexoes(): void
    {
        [$codigo, $saida] = $this->phpunitFilho([
            'DB_MIGRACAO_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_MIGRACAO_PASSWORD' => (string) config('database.connections.pgsql.password'),
        ]);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('mesmo usuario', $saida);
        $this->assertSentinelaDoTesteIntacta();
    }

    public function test_testes_fora_do_ambiente_testing(): void
    {
        // APP_ENV=local carregaria o .env de desenvolvimento; para que nem
        // em caso de falha o alvo fosse valioso, o banco tambem vai para a isca.
        [$codigo, $saida] = $this->phpunitFilho(['APP_ENV' => 'local', 'DB_DATABASE' => self::ISCA]);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('APP_ENV=testing', $saida);
        $this->assertIscaIntacta();
    }

    public function test_testes_com_configuracao_em_cache(): void
    {
        // Relativo a base_path(): no Windows o Laravel so reconhece "/" e "\"
        // como inicio de caminho absoluto em APP_CONFIG_CACHE.
        $relativo = 'storage/framework/testing/config-trava-'.bin2hex(random_bytes(4)).'.php';
        $cache = base_path($relativo);

        try {
            // Cache gerado apontando para a isca, como uma configuracao velha.
            [$c1, $s1] = $this->processo(
                [PHP_BINARY, base_path('artisan'), 'config:cache', '--no-ansi'],
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $relativo, 'DB_DATABASE' => self::ISCA]
            );
            $this->assertSame(0, $c1, $s1);
            $this->assertFileExists($cache);

            [$codigo, $saida] = $this->phpunitFilho(['APP_CONFIG_CACHE' => $relativo]);

            $this->assertNotSame(0, $codigo);
            $this->assertStringContainsString('Configuracao em cache', $saida);
            $this->assertIscaIntacta();
        } finally {
            @unlink($cache); // contem credenciais de teste
        }
        $this->assertFileDoesNotExist($cache);
    }
}
