<?php

namespace Tests\Unit;

use App\Support\ConfiguracaoInsegura;
use App\Support\TravaDeProducao;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TravaDeProducaoTest extends TestCase
{
    /** Configuracao de producao correta; cada teste estraga um item. */
    private function configSegura(array $trocas = []): Repository
    {
        $config = new Repository([
            'app' => ['debug' => false, 'key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'proxies_confiaveis' => ['10.0.0.0/8']],
            'session' => ['secure' => true],
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => ['driver' => 'pgsql', 'url' => null, 'sslmode' => 'verify-full', 'password' => 'app'],
                    'pgsql_migracao' => ['driver' => 'pgsql', 'url' => null, 'sslmode' => 'verify-full', 'password' => ''],
                ],
            ],
        ]);
        foreach ($trocas as $chave => $valor) {
            $config->set($chave, $valor);
        }

        return $config;
    }

    private function verificar(Repository $config, ?string $senhaMigracaoNoAmbiente = null, string $ambiente = 'production', bool $servindoHttp = true): void
    {
        TravaDeProducao::verificar($ambiente, $servindoHttp, $config, $senhaMigracaoNoAmbiente);
    }

    public function test_configuracao_segura_sobe(): void
    {
        $this->verificar($this->configSegura());
        $this->verificar($this->configSegura(['database.connections.pgsql.sslmode' => 'require']));
        $this->verificar($this->configSegura(['database.connections.pgsql.sslmode' => 'verify-ca']));
        $this->addToAssertionCount(3);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function configuracoesInseguras(): array
    {
        return [
            'debug ligado' => [['app.debug' => true], 'APP_DEBUG'],
            'cookie sem Secure' => [['session.secure' => false], 'SESSION_SECURE_COOKIE'],
            'cookie secure nulo' => [['session.secure' => null], 'SESSION_SECURE_COOKIE'],
            'sslmode prefer' => [['database.connections.pgsql.sslmode' => 'prefer'], 'DB_SSLMODE'],
            'sslmode disable' => [['database.connections.pgsql.sslmode' => 'disable'], 'DB_SSLMODE'],
            'sslmode ausente' => [['database.connections.pgsql.sslmode' => null], 'DB_SSLMODE'],
            'sslmode rebaixado pela URL' => [['database.connections.pgsql.url' => 'pgsql://u:p@db:5432/cleison?sslmode=disable'], 'DB_SSLMODE'],
            'conexao padrao nao e pgsql' => [['database.default' => 'outra', 'database.connections.outra' => ['driver' => 'sqlite']], 'DB_SSLMODE'],
            'senha de migracao no config' => [['database.connections.pgsql_migracao.password' => 'dono'], 'DB_MIGRACAO_PASSWORD'],
            'senha de migracao na URL' => [['database.connections.pgsql_migracao.url' => 'pgsql://cleison:dono@db/cleison'], 'DB_MIGRACAO_PASSWORD'],
            'APP_KEY vazia' => [['app.key' => ''], 'APP_KEY'],
            'APP_KEY nula' => [['app.key' => null], 'APP_KEY'],
            'proxy curinga' => [['app.proxies_confiaveis' => ['*']], 'TRUSTED_PROXIES'],
            'proxy curinga duplo' => [['app.proxies_confiaveis' => ['10.0.0.1', '**']], 'TRUSTED_PROXIES'],
            'proxy /0 ipv4' => [['app.proxies_confiaveis' => ['0.0.0.0/0']], 'TRUSTED_PROXIES'],
            'proxy /0 ipv6' => [['app.proxies_confiaveis' => ['10.0.0.1', '::/0']], 'TRUSTED_PROXIES'],
            'proxy metade da internet' => [['app.proxies_confiaveis' => ['0.0.0.0/1', '128.0.0.0/1']], 'TRUSTED_PROXIES'],
        ];
    }

    #[DataProvider('configuracoesInseguras')]
    public function test_recusa_configuracao_insegura(array $trocas, string $nomeEsperado): void
    {
        try {
            $this->verificar($this->configSegura($trocas));
            $this->fail('A trava deixou subir com configuracao insegura.');
        } catch (ConfiguracaoInsegura $e) {
            $this->assertStringContainsString($nomeEsperado, $e->getMessage());
        }
    }

    public function test_senha_de_migracao_no_ambiente_do_processo_e_recusada(): void
    {
        $this->expectException(ConfiguracaoInsegura::class);
        $this->expectExceptionMessage('DB_MIGRACAO_PASSWORD');

        $this->verificar($this->configSegura(), senhaMigracaoNoAmbiente: 'dono');
    }

    public function test_mensagem_lista_todos_os_problemas_sem_vazar_valores(): void
    {
        $config = $this->configSegura([
            'app.debug' => true,
            'app.key' => '',
            'database.connections.pgsql.url' => 'pgsql://cleison_app:segredo-da-app@db.interno:5432/cleison?sslmode=prefer',
            'database.connections.pgsql_migracao.password' => 'segredo-do-dono',
        ]);

        try {
            $this->verificar($config);
            $this->fail('Deveria recusar.');
        } catch (ConfiguracaoInsegura $e) {
            foreach (['APP_DEBUG', 'APP_KEY', 'DB_SSLMODE', 'DB_MIGRACAO_PASSWORD'] as $nome) {
                $this->assertStringContainsString($nome, $e->getMessage());
            }
            foreach (['segredo-da-app', 'segredo-do-dono', 'db.interno', 'prefer'] as $valor) {
                $this->assertStringNotContainsString($valor, $e->getMessage());
            }
        }
    }

    public function test_so_vale_em_producao_atendendo_http(): void
    {
        $insegura = $this->configSegura(['app.debug' => true, 'database.connections.pgsql_migracao.password' => 'dono']);

        // Pipeline de deploy (migrate, config:cache...) e desenvolvimento.
        $this->verificar($insegura, ambiente: 'production', servindoHttp: false);
        $this->verificar($insegura, ambiente: 'local', servindoHttp: true);
        $this->verificar($insegura, ambiente: 'testing', servindoHttp: true);
        $this->addToAssertionCount(3);
    }

    public function test_comandos_que_atendem_http_contam_como_web(): void
    {
        $this->assertTrue(TravaDeProducao::servindoHttp(console: false, comando: null));
        $this->assertTrue(TravaDeProducao::servindoHttp(console: true, comando: 'serve'));
        $this->assertTrue(TravaDeProducao::servindoHttp(console: true, comando: 'octane:start'));
        $this->assertFalse(TravaDeProducao::servindoHttp(console: true, comando: 'migrate'));
        $this->assertFalse(TravaDeProducao::servindoHttp(console: true, comando: 'config:cache'));
        $this->assertFalse(TravaDeProducao::servindoHttp(console: true, comando: 'queue:work'));
        $this->assertFalse(TravaDeProducao::servindoHttp(console: true, comando: null));
    }
}
