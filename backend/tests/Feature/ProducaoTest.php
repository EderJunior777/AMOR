<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\ConfiguracaoInsegura;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ligacao da trava de producao e dos proxies confiaveis no provider real
 * (a logica em si esta em Tests\Unit\TravaDeProducaoTest).
 */
class ProducaoTest extends TestCase
{
    private mixed $argvOriginal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->argvOriginal = $_SERVER['argv'] ?? null;
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argvOriginal;
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function bootComo(string $ambiente, ?string $comando): void
    {
        // Os testes rodam no console; "serve" e um comando que atende HTTP.
        $_SERVER['argv'] = ['artisan', $comando];
        $this->app->detectEnvironment(fn () => $ambiente);
        try {
            $this->app->getProvider(AppServiceProvider::class)->boot();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_provider_recusa_subir_em_producao_com_debug_ligado(): void
    {
        config(['app.debug' => true]);

        $this->expectException(ConfiguracaoInsegura::class);
        $this->bootComo('production', 'serve');
    }

    public function test_provider_deixa_o_pipeline_de_deploy_rodar(): void
    {
        config(['app.debug' => true, 'database.connections.pgsql_migracao.password' => 'dono']);

        $this->bootComo('production', 'migrate');
        $this->addToAssertionCount(1);
    }

    public function test_proxies_confiaveis_vem_da_configuracao(): void
    {
        Route::get('/_teste/ip', fn (Request $r) => $r->ip().'|'.$r->getScheme());

        config(['app.proxies_confiaveis' => ['10.0.0.0/8']]);
        $this->bootComo('testing', null);

        $viaProxy = $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->get('/_teste/ip');
        $this->assertSame('203.0.113.9|https', $viaProxy->getContent());
    }

    public function test_quem_nao_esta_na_lista_nao_forja_ip_nem_esquema(): void
    {
        Route::get('/_teste/ip', fn (Request $r) => $r->ip().'|'.$r->getScheme());

        config(['app.proxies_confiaveis' => ['10.0.0.0/8']]);
        $this->bootComo('testing', null);

        $forjado = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->get('/_teste/ip');
        $this->assertSame('198.51.100.7|http', $forjado->getContent());
    }

    public function test_sem_lista_nenhum_proxy_e_confiavel(): void
    {
        Route::get('/_teste/ip', fn (Request $r) => $r->ip());

        config(['app.proxies_confiaveis' => []]);
        $this->bootComo('testing', null);

        $resposta = $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
            ->get('/_teste/ip');
        $this->assertSame('10.1.2.3', $resposta->getContent());
    }
}
