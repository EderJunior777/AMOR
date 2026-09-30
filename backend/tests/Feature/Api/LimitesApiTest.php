<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use ReflectionProperty;

/**
 * Rate limiters da API (AppServiceProvider, config cleison.api.limites).
 * Cada teste baixa UM limite e deixa os outros altos, para provar qual
 * limitador disparou.
 */
class LimitesApiTest extends ApiTestCase
{
    private const CODIGO = '11111111-2222-4333-8444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cleison.api.limites' => [
            'geral_por_minuto' => 1000,
            'criar_por_minuto_ip' => 1000,
            'criar_por_hora_telefone' => 1000,
            'reserva_por_minuto_ip_codigo' => 1000,
            'reserva_por_hora_ip' => 1000,
            'global_por_minuto_por_rota' => 1000,
        ]]);
        $this->segredos[] = self::CODIGO;
    }

    private function limitar(string $qual, int $valor): void
    {
        config(["cleison.api.limites.{$qual}" => $valor]);
    }

    private function assert429(TestResponse $r): void
    {
        $r->assertStatus(429)->assertJsonPath('codigo', 'muitas_tentativas');
        $this->assertSame(['mensagem', 'codigo'], array_keys($r->json()));
        $this->assertGreaterThanOrEqual(1, (int) $r->headers->get('Retry-After'));
    }

    private function consultar(string $codigo = self::CODIGO, string $telefone = self::TELEFONE): TestResponse
    {
        return $this->postJson('/api/v1/reservas/consultar', ['codigo' => $codigo, 'telefone' => $telefone]);
    }

    public function test_limite_geral_por_ip_vale_para_todas_as_rotas(): void
    {
        $this->limitar('geral_por_minuto', 2);

        $this->getJson('/api/v1/servicos')->assertOk();
        $this->getJson('/api/v1/regioes')->assertOk();
        $this->assert429($this->getJson('/api/v1/servicos'));
        $this->assert429($this->getJson('/api/v1/profissionais?servicos[]=1'));
        $this->assert429($this->consultar());
        $this->assert429($this->reservar($this->corpo()));
    }

    public function test_criar_reserva_limitada_por_ip(): void
    {
        $this->limitar('criar_por_minuto_ip', 2);

        $this->reservar($this->corpo(['hora' => '10:00']), 'chave-a-0123456789abcdef')->assertCreated();
        $this->reservar($this->corpo(['hora' => '10:30', 'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]), 'chave-b-0123456789abcdef')->assertCreated();
        $this->assert429($this->reservar($this->corpo(['hora' => '11:00']), 'chave-c-0123456789abcdef'));
        // O limite de criar nao afeta a leitura.
        $this->getJson('/api/v1/servicos')->assertOk();
    }

    public function test_criar_reserva_limitada_por_telefone_normalizado(): void
    {
        $this->limitar('criar_por_hora_telefone', 2);

        $this->reservar($this->corpo(['hora' => '10:00']), 'chave-a-0123456789abcdef')->assertCreated();
        $this->reservar($this->corpo(['hora' => '10:30', 'cliente' => ['nome' => 'Quixabeira', 'telefone' => '(11) 98765-1234']]), 'chave-b-0123456789abcdef')->assertCreated();
        // A terceira, com outra grafia do MESMO telefone, cai no limite.
        $this->assert429($this->reservar($this->corpo(['hora' => '11:00', 'cliente' => ['nome' => 'Quixabeira', 'telefone' => '11987651234']]), 'chave-c-0123456789abcdef'));
        // Outro telefone, mesmo IP: passa.
        $this->reservar($this->corpo(['hora' => '11:00', 'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]), 'chave-d-0123456789abcdef')->assertCreated();
        $this->segredos[] = '11912345678';
    }

    /**
     * Achado #2 da Fase 5: quem sabe o telefone de alguem nao esgota o limite
     * dessa pessoa com pedidos invalidos. O limite por telefone so conta
     * reserva CRIADA; recusa de formato (FormRequest) ou de regra (dominio)
     * nao consome o limite de ninguem.
     */
    public function test_dez_pedidos_invalidos_com_o_telefone_de_alguem_nao_impedem_essa_pessoa_de_reservar(): void
    {
        $this->limitar('criar_por_hora_telefone', 2);

        foreach (range(1, 10) as $i) {
            $invalido = match ($i % 4) {
                0 => $this->corpo(['hora' => '25:00']),                // formato: FormRequest
                1 => $this->corpo(['hora' => '10:10']),                // fora da grade: dominio
                2 => $this->corpo(['data' => '2026-10-04']),           // passado: dominio
                3 => $this->corpo(['servicos' => [999999]]),           // servico inexistente: dominio
            };
            $this->reservar($invalido, sprintf('chave-invalida-%08d', $i))->assertStatus(422);
        }

        $this->reservar($this->corpo(), 'chave-da-vitima-0000001')->assertCreated();
    }

    public function test_so_reserva_criada_consome_o_limite_do_telefone(): void
    {
        $this->limitar('criar_por_hora_telefone', 2);

        $this->reservar($this->corpo(['hora' => '10:00']), 'chave-a-0123456789abcdef')->assertCreated();
        // Repeticao idempotente e horario ocupado (409) nao contam.
        foreach (range(1, 3) as $i) {
            $this->reservar($this->corpo(['hora' => '10:00']), 'chave-a-0123456789abcdef')->assertOk();
        }
        $this->reservar($this->corpo(['hora' => '10:00']), 'chave-b-0123456789abcdef')->assertStatus(409);

        $this->reservar($this->corpo(['hora' => '11:00']), 'chave-c-0123456789abcdef')->assertCreated();
        $this->assert429($this->reservar($this->corpo(['hora' => '14:00']), 'chave-d-0123456789abcdef'));
    }

    /**
     * Revisao final: no limite do telefone, o REENVIO da mesma tentativa
     * (mesma chave, mesmo corpo; ex.: resposta perdida) recebe a reserva que
     * ja existe (200), nao 429. Pedido novo continua barrado.
     */
    public function test_no_limite_do_telefone_o_reenvio_idempotente_ainda_recebe_a_reserva(): void
    {
        $this->limitar('criar_por_hora_telefone', 1);

        $criada = $this->reservar($this->corpo(['hora' => '10:00']), 'chave-a-0123456789abcdef')->assertCreated();

        $repetida = $this->reservar($this->corpo(['hora' => '10:00']), 'chave-a-0123456789abcdef')->assertOk();
        $this->assertSame($criada->json('codigo'), $repetida->json('codigo'));

        $this->assert429($this->reservar($this->corpo(['hora' => '11:00']), 'chave-b-0123456789abcdef'));
        // Mesma chave com outro corpo, no limite: segue o contrato da idempotencia.
        $this->reservar($this->corpo(['hora' => '11:00']), 'chave-a-0123456789abcdef')
            ->assertStatus(422)->assertJsonPath('codigo', 'idempotencia_conflito');
    }

    public function test_por_ip_as_tentativas_invalidas_continuam_contando(): void
    {
        $this->limitar('criar_por_minuto_ip', 2);

        $this->reservar($this->corpo(['hora' => '10:10']), 'chave-a-0123456789abcdef')->assertStatus(422);
        $this->reservar($this->corpo(['hora' => '25:00']), 'chave-b-0123456789abcdef')->assertStatus(422);
        $this->assert429($this->reservar($this->corpo(['hora' => '10:00']), 'chave-c-0123456789abcdef'));
    }

    public function test_consultar_cancelar_e_remarcar_limitados_por_ip_e_codigo(): void
    {
        $this->limitar('reserva_por_minuto_ip_codigo', 3);

        foreach (['consultar', 'cancelar', 'remarcar'] as $rota) {
            $corpo = ['codigo' => self::CODIGO, 'telefone' => self::TELEFONE, 'data' => '2026-10-08', 'hora' => '10:00'];
            $this->postJson('/api/v1/reservas/'.$rota, $corpo)->assertStatus(422)->assertJsonPath('codigo', 'reserva_nao_encontrada');
            // O limite e por IP + codigo, somado entre as tres rotas.
        }
        $this->assert429($this->consultar());
        $this->assert429($this->postJson('/api/v1/reservas/cancelar', ['codigo' => self::CODIGO, 'telefone' => self::TELEFONE]));
        // Outro codigo, mesmo IP: segue livre.
        $this->consultar('99999999-2222-4333-8444-555555555555')->assertStatus(422)->assertJsonPath('codigo', 'reserva_nao_encontrada');
    }

    public function test_consultar_limitado_por_ip_na_hora_mesmo_variando_o_codigo(): void
    {
        $this->limitar('reserva_por_hora_ip', 3);

        for ($i = 1; $i <= 3; $i++) {
            $this->consultar("0000000{$i}-2222-4333-8444-555555555555")->assertStatus(422);
        }
        $this->assert429($this->consultar('00000009-2222-4333-8444-555555555555'));
        $this->assert429($this->postJson('/api/v1/reservas/cancelar', ['codigo' => self::CODIGO, 'telefone' => self::TELEFONE]));
        // Criar e ler nao dividem esse limite.
        $this->reservar($this->corpo(), 'chave-a-0123456789abcdef')->assertCreated();
    }

    public function test_o_429_de_erro_nao_vaza_o_codigo_nem_o_telefone_enviados(): void
    {
        $this->limitar('reserva_por_minuto_ip_codigo', 1);
        $this->consultar();
        $r = $this->consultar();

        $this->assert429($r);
        $this->assertStringNotContainsString(self::CODIGO, $r->getContent());
        $this->assertStringNotContainsString('98765', $r->getContent());
    }

    public function test_chaves_de_limite_com_telefone_ou_codigo_nao_levam_o_valor_cru(): void
    {
        $this->reservar($this->corpo(), self::CHAVE)->assertCreated();
        $this->consultar();
        $this->postJson('/api/v1/reservas/cancelar', ['codigo' => self::CODIGO, 'telefone' => '(11) 91234-5678']);
        $this->segredos[] = '11912345678';

        $armazenado = new ReflectionProperty(Cache::store()->getStore(), 'storage');
        $chaves = array_keys($armazenado->getValue(Cache::store()->getStore()));
        $this->assertNotEmpty($chaves);
        $texto = implode("\n", $chaves);

        foreach ([self::CODIGO, '5555555555', '11987651234', '987651234', '5511987651234', '11912345678', '912345678'] as $cru) {
            $this->assertStringNotContainsString($cru, $texto);
        }
        $this->assertStringNotContainsString('127.0.0.1', $texto);
    }

    public function test_chave_do_limite_por_telefone_nao_e_o_hash_simples_do_telefone(): void
    {
        $this->reservar($this->corpo(), self::CHAVE)->assertCreated();

        $armazenado = new ReflectionProperty(Cache::store()->getStore(), 'storage');
        $texto = implode("\n", array_keys($armazenado->getValue(Cache::store()->getStore())));

        // Sem a chave do servidor, ninguem reconstroi a chave a partir do telefone.
        foreach (['md5', 'sha1', 'sha256'] as $algoritmo) {
            $this->assertStringNotContainsString(hash($algoritmo, '+5511987651234'), $texto);
            $this->assertStringNotContainsString(hash($algoritmo, self::TELEFONE), $texto);
        }
    }

    public function test_limites_tem_padroes_conservadores_e_vem_de_config(): void
    {
        $padrao = require base_path('config/cleison.php');
        $limites = $padrao['api']['limites'];

        $this->assertSame(60, $limites['geral_por_minuto']);
        $this->assertSame(10, $limites['criar_por_minuto_ip']);
        $this->assertSame(5, $limites['criar_por_hora_telefone']);
        $this->assertSame(5, $limites['reserva_por_minuto_ip_codigo']);
        $this->assertSame(30, $limites['reserva_por_hora_ip']);
    }
}
