<?php

namespace Tests\Feature\Api;

use Illuminate\Database\QueryException;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route as Rotas;

/** Propriedades transversais da API v1: sem estado, sem CORS, erros sempre JSON e sem detalhe interno. */
class ContratoDaApiTest extends ApiTestCase
{
    /** @return list<Route> */
    private function rotasDaApi(): array
    {
        return array_values(array_filter(
            Rotas::getRoutes()->getRoutes(),
            fn (Route $r) => str_starts_with($r->uri(), 'api/v1/'),
        ));
    }

    public function test_as_oito_rotas_existem_sob_api_v1(): void
    {
        $rotas = array_map(fn (Route $r) => implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri(), $this->rotasDaApi());
        sort($rotas);

        $this->assertSame([
            'GET api/v1/disponibilidade',
            'GET api/v1/profissionais',
            'GET api/v1/regioes',
            'GET api/v1/servicos',
            'POST api/v1/reservas',
            'POST api/v1/reservas/cancelar',
            'POST api/v1/reservas/consultar',
            'POST api/v1/reservas/remarcar',
        ], $rotas);
    }

    public function test_nenhuma_rota_usa_sessao_cookie_ou_csrf_e_todas_tem_limite_geral(): void
    {
        foreach ($this->rotasDaApi() as $rota) {
            $middlewares = array_map(fn ($m) => is_string($m) ? $m : get_debug_type($m), $rota->gatherMiddleware());
            foreach ($middlewares as $m) {
                $this->assertStringNotContainsString('Session', $m, $rota->uri());
                $this->assertStringNotContainsString('Cookie', $m, $rota->uri());
                $this->assertStringNotContainsString('Csrf', $m, $rota->uri());
            }
            $this->assertNotContains(StartSession::class, $middlewares);
            $this->assertContains('throttle:api-geral', $middlewares, $rota->uri());
        }
    }

    public function test_respostas_nao_criam_sessao_nem_cookie(): void
    {
        $respostas = [
            $this->getJson('/api/v1/servicos'),
            $this->reservar($this->corpo()),
            $this->postJson('/api/v1/reservas/consultar', ['codigo' => 'x', 'telefone' => 'y']),
            $this->reservar([], null),
        ];
        foreach ($respostas as $r) {
            $this->assertSame([], $r->headers->getCookies());
            $this->assertFalse($r->headers->has('Set-Cookie'));
        }
    }

    public function test_nao_ha_cors_aberto(): void
    {
        $this->assertFileDoesNotExist(config_path('cors.php'));

        $r = $this->getJson('/api/v1/servicos', ['Origin' => 'https://site-de-terceiro.example']);
        $r->assertOk();
        $this->assertFalse($r->headers->has('Access-Control-Allow-Origin'));

        $pre = $this->call('OPTIONS', '/api/v1/reservas', [], [], [], [
            'HTTP_ORIGIN' => 'https://site-de-terceiro.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertFalse($pre->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($pre->headers->has('Access-Control-Allow-Methods'));
    }

    public function test_rota_inexistente_e_metodo_errado_respondem_json_sem_detalhe(): void
    {
        $r = $this->getJson('/api/v1/nada')->assertStatus(404);
        $this->assertSame(['mensagem', 'codigo'], array_keys($r->json()));
        $this->assertSame('nao_encontrado', $r->json('codigo'));

        $r = $this->postJson('/api/v1/servicos')->assertStatus(405);
        $this->assertSame('metodo_nao_permitido', $r->json('codigo'));
        $this->assertSame(['mensagem', 'codigo'], array_keys($r->json()));
    }

    public function test_erro_inesperado_de_banco_vira_500_generico_em_json(): void
    {
        Rotas::middleware('api')->get('api/v1/teste-erro', function () {
            $pdo = new \PDOException('SQLSTATE[XX000]: segredo Quixabeira 5511987651234');
            $pdo->errorInfo = ['XX000', 7, 'segredo Quixabeira 5511987651234'];

            throw new QueryException('pgsql', 'select * from clientes where telefone = ?', ['5511987651234'], $pdo);
        });

        $r = $this->getJson('/api/v1/teste-erro')->assertStatus(500);

        $this->assertSame('erro_interno', $r->json('codigo'));
        foreach (['SQLSTATE', 'clientes', 'Quixabeira', '5511987651234', 'select'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $r->getContent());
        }
    }

    public function test_recusa_de_dominio_nao_vai_para_o_log(): void
    {
        $this->reservar($this->corpo(['hora' => '03:00']))->assertStatus(422);
        $this->getJson('/api/v1/nada');

        $this->assertSame([], array_filter($this->logs, fn ($l) => str_contains($l, 'ReservaRecusada')));
    }

    public function test_corpo_que_nao_e_json_valido_ou_e_vazio_responde_422_json(): void
    {
        $r = $this->call('POST', '/api/v1/reservas', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => self::CHAVE, 'HTTP_ACCEPT' => 'application/json'], '{quebrado');
        $r->assertStatus(422);
        $this->assertSame('dados_invalidos', $r->json('codigo'));
    }
}
