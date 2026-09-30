<?php

namespace Tests\Feature\Api;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

/**
 * O site chama a API pelo proxy da Netlify: a conexao chega do IP do proxy e o
 * cliente real vem em X-Forwarded-For. O limite por IP so pode usar o IP do
 * CLIENTE quando o proxy e confiavel (TRUSTED_PROXIES); sem isso o
 * X-Forwarded-For e ignorado, senao qualquer um trocaria o cabecalho para
 * fugir do limite.
 *
 * REMOTE_ADDR dos testes: 127.0.0.1 (o "proxy").
 */
class LimitesPorIpAtrasDeProxyTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cleison.api.limites.geral_por_minuto' => 2]);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    private function como(string $encaminhadoPor): TestResponse
    {
        return $this->getJson('/api/v1/servicos', ['X-Forwarded-For' => $encaminhadoPor]);
    }

    public function test_com_proxy_confiavel_cada_cliente_do_xff_tem_o_proprio_limite(): void
    {
        TrustProxies::at(['127.0.0.1']);

        $this->como('203.0.113.10')->assertOk();
        $this->como('203.0.113.10')->assertOk();
        $this->como('203.0.113.10')->assertStatus(429)->assertJsonPath('codigo', 'muitas_tentativas');

        // Outro cliente atras do MESMO proxy: limite independente.
        $this->como('203.0.113.20')->assertOk();
        $this->como('203.0.113.20')->assertOk();
        $this->como('203.0.113.20')->assertStatus(429);

        // O primeiro continua barrado.
        $this->como('203.0.113.10')->assertStatus(429);
    }

    public function test_com_proxy_confiavel_o_ip_do_cliente_e_o_da_ponta_do_xff_e_prefixo_forjado_nao_vale(): void
    {
        TrustProxies::at(['127.0.0.1']);

        // O proxy acrescenta o IP real ao FIM da cadeia; o prefixo e do cliente e nao conta.
        $this->como('9.9.9.1, 203.0.113.10')->assertOk();
        $this->como('9.9.9.2, 203.0.113.10')->assertOk();
        $this->como('9.9.9.3, 203.0.113.10')->assertStatus(429);
    }

    public function test_sem_proxy_confiavel_o_xff_e_ignorado_e_a_chave_e_o_ip_da_conexao(): void
    {
        TrustProxies::flushState();

        $this->como('203.0.113.10')->assertOk();
        $this->como('203.0.113.20')->assertOk();
        // Terceira chamada, de "cliente" diferente pelo cabecalho: mesma conexao, mesmo limite.
        $this->como('203.0.113.30')->assertStatus(429)->assertJsonPath('codigo', 'muitas_tentativas');
    }

    public function test_a_confianca_no_proxy_vale_tambem_para_o_limite_da_criacao_de_reserva(): void
    {
        TrustProxies::at(['127.0.0.1']);
        config(['cleison.api.limites.criar_por_minuto_ip' => 1]);

        $primeiro = ['X-Forwarded-For' => '203.0.113.10', 'Idempotency-Key' => 'chave-a-0123456789abcdef'];
        $this->postJson('/api/v1/reservas', $this->corpo(['hora' => '10:00']), $primeiro)->assertCreated();
        $this->postJson('/api/v1/reservas', $this->corpo(['hora' => '10:30']), ['Idempotency-Key' => 'chave-b-0123456789abcdef'] + $primeiro)->assertStatus(429);

        // Outro cliente atras do proxy, com outro telefone: passa.
        $outro = ['X-Forwarded-For' => '203.0.113.20', 'Idempotency-Key' => 'chave-c-0123456789abcdef'];
        $this->postJson('/api/v1/reservas', $this->corpo(['hora' => '11:00', 'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]), $outro)->assertCreated();
        $this->segredos[] = '11912345678';
    }
}
