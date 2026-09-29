<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * /up confere o banco de verdade. Fora do ar: 503 com so "Banco de dados
 * indisponível"; a mensagem de conexao do PDO (host, porta, usuario) fica
 * fora da resposta e do log, inclusive com APP_DEBUG ligado.
 */
class SaudeTest extends TestCase
{
    private const HOST = '127.0.0.1';

    private const PORTA = '59999';

    private const USUARIO = 'usuario_saude_x';

    private function derrubarBanco(): void
    {
        config([
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.host' => self::HOST,
            'database.connections.pgsql.port' => self::PORTA,
            'database.connections.pgsql.username' => self::USUARIO,
        ]);
        DB::purge('pgsql');
    }

    private function assertSemDetalheDeConexao(string $conteudo): void
    {
        foreach (['SQLSTATE', self::HOST, self::PORTA, self::USUARIO, 'connection', 'password'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $conteudo);
        }
    }

    public function test_banco_no_ar_responde_up(): void
    {
        $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
    }

    public function test_banco_fora_do_ar_mostra_so_indisponivel(): void
    {
        $this->derrubarBanco();

        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);

            foreach ([$this->getJson('/up'), $this->get('/up')] as $resposta) {
                $resposta->assertStatus(503)
                    ->assertExactJson(['status' => 'down', 'mensagem' => 'Banco de dados indisponível']);
                $this->assertSemDetalheDeConexao((string) $resposta->getContent());
            }
        }
    }

    public function test_banco_fora_do_ar_registra_o_erro_traduzido(): void
    {
        Log::spy();
        $this->derrubarBanco();

        $this->getJson('/up')->assertStatus(503);

        Log::shouldHaveReceived('log')->once()->withArgs(function (string $nivel, string $mensagem, array $contexto) {
            $this->assertSame('error', $nivel);
            $this->assertSame('Erro de banco', $mensagem);
            $this->assertMatchesRegularExpression('/^08/', (string) $contexto['sqlstate']);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $contexto['correlacao']);
            $this->assertSemDetalheDeConexao(json_encode($contexto));

            return true;
        });
    }

    public function test_up_nao_passa_pela_sessao(): void
    {
        $this->get('/up')->assertCookieMissing(config('session.cookie'));
    }
}
