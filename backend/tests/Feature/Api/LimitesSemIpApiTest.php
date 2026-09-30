<?php

namespace Tests\Feature\Api;

/**
 * API_LIMITE_POR_IP=false (config cleison.api.limites_por_ip): para producao
 * atras de proxy SEM TRUSTED_PROXIES certo, onde todo cliente teria o IP do
 * proxy e o limite por IP viraria um limite global. Desligado, nao ha limite
 * por IP; ficam o por telefone (criacao) e o por codigo (reserva existente).
 */
class LimitesSemIpApiTest extends ApiTestCase
{
    private const CODIGO = '11111111-2222-4333-8444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cleison.api.limites_por_ip' => false,
            'cleison.api.limites' => [
                'geral_por_minuto' => 2,
                'criar_por_minuto_ip' => 2,
                'criar_por_hora_telefone' => 2,
                'reserva_por_minuto_ip_codigo' => 2,
                'reserva_por_hora_ip' => 2,
                'global_por_minuto_por_rota' => 1000,
            ],
        ]);
        $this->segredos[] = self::CODIGO;
    }

    private function consultarDe(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/reservas/consultar', ['codigo' => self::CODIGO, 'telefone' => self::TELEFONE]);
    }

    public function test_por_padrao_o_limite_por_ip_e_ligado(): void
    {
        $arquivo = require config_path('cleison.php');

        $this->assertTrue($arquivo['api']['limites_por_ip']);
    }

    public function test_desligado_nao_ha_limite_geral_por_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->getJson('/api/v1/servicos')->assertOk();
        }
    }

    /**
     * Achado #3 da Fase 5: sem limite por IP, fica sempre um limite global
     * alto POR ROTA (todos os clientes juntos), para o catalogo e a
     * disponibilidade nao ficarem sem freio nenhum.
     */
    public function test_desligado_fica_um_limite_global_por_rota_de_qualquer_ip(): void
    {
        config(['cleison.api.limites.global_por_minuto_por_rota' => 2]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->getJson('/api/v1/servicos')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/api/v1/servicos')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->getJson('/api/v1/servicos')
            ->assertStatus(429)->assertJsonPath('codigo', 'muitas_tentativas');

        // Outra rota tem o proprio limite.
        $this->getJson('/api/v1/regioes')->assertOk();
    }

    public function test_limite_global_por_rota_tem_padrao_alto(): void
    {
        $arquivo = require config_path('cleison.php');

        $this->assertSame(600, $arquivo['api']['limites']['global_por_minuto_por_rota']);
    }

    public function test_desligado_a_criacao_ainda_limita_por_telefone(): void
    {
        $this->reservar($this->corpo(), 'chave-aaaaaaaaaaaaaaaa1')->assertStatus(201);
        $this->reservar($this->corpo(['hora' => '11:00']), 'chave-aaaaaaaaaaaaaaaa2')->assertStatus(201);
        $this->reservar($this->corpo(['hora' => '14:00']), 'chave-aaaaaaaaaaaaaaaa3')
            ->assertStatus(429)->assertJsonPath('codigo', 'muitas_tentativas');
    }

    public function test_desligado_a_reserva_existente_ainda_limita_por_codigo_de_qualquer_ip(): void
    {
        $this->consultarDe('203.0.113.1')->assertStatus(422);
        $this->consultarDe('198.51.100.7')->assertStatus(422);
        $this->consultarDe('192.0.2.99')->assertStatus(429)->assertJsonPath('codigo', 'muitas_tentativas');
    }
}
