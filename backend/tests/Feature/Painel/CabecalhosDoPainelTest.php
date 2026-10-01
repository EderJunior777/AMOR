<?php

namespace Tests\Feature\Painel;

use Exception;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * Cabecalhos de seguranca de TODA resposta sob /painel (etapa 3, Fase 2):
 * CSP estrita (sem script nem estilo inline), anti-frame, nosniff,
 * Referrer-Policy e no-store. Valem tambem nas respostas de ERRO (404, 405,
 * 500), que o Laravel monta fora do pipeline de middleware global.
 */
class CabecalhosDoPainelTest extends TestCase
{
    use BancoDeTeste;

    /** @return array<string, string> */
    private function cabecalhosEsperados(): array
    {
        return [
            'x-frame-options' => 'DENY',
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'no-referrer',
            'cross-origin-opener-policy' => 'same-origin',
            'cross-origin-resource-policy' => 'same-origin',
        ];
    }

    private function exigirCabecalhos(TestResponse $resposta, string $quem): void
    {
        foreach ($this->cabecalhosEsperados() as $nome => $valor) {
            $this->assertSame($valor, $resposta->headers->get($nome), "{$quem}: {$nome}");
        }

        $cache = (string) $resposta->headers->get('cache-control');
        foreach (['no-store', 'private', 'max-age=0'] as $parte) {
            $this->assertStringContainsString($parte, $cache, "{$quem}: cache-control precisa de {$parte}");
        }
        $this->assertSame('no-cache', $resposta->headers->get('pragma'), "{$quem}: pragma");

        $csp = (string) $resposta->headers->get('content-security-policy');
        $diretivas = [];
        foreach (explode(';', $csp) as $parte) {
            $parte = trim($parte);
            if ($parte !== '') {
                [$nome, $valores] = array_pad(explode(' ', $parte, 2), 2, '');
                $diretivas[$nome] = $valores;
            }
        }
        $this->assertSame("'none'", $diretivas['default-src'] ?? null, "{$quem}: default-src");
        $this->assertSame("'self'", $diretivas['script-src'] ?? null, "{$quem}: script-src sem unsafe-inline/eval");
        $this->assertSame("'self'", $diretivas['style-src'] ?? null, "{$quem}: style-src sem unsafe-inline");
        $this->assertSame("'self'", $diretivas['connect-src'] ?? null, "{$quem}: connect-src");
        $this->assertSame("'self'", $diretivas['form-action'] ?? null, "{$quem}: form-action");
        $this->assertSame("'none'", $diretivas['base-uri'] ?? null, "{$quem}: base-uri");
        $this->assertSame("'none'", $diretivas['frame-ancestors'] ?? null, "{$quem}: frame-ancestors");
        $this->assertSame("'none'", $diretivas['object-src'] ?? null, "{$quem}: object-src");
        $this->assertStringNotContainsString('unsafe', $csp, "{$quem}: nada de unsafe-*");
        $this->assertStringNotContainsString('*', $csp, "{$quem}: nada de curinga");

        $permissoes = (string) $resposta->headers->get('permissions-policy');
        foreach (['camera=()', 'microphone=()', 'geolocation=()'] as $parte) {
            $this->assertStringContainsString($parte, $permissoes, "{$quem}: permissions-policy");
        }
    }

    public function test_rota_inexistente_sob_painel_tem_os_cabecalhos(): void
    {
        $this->exigirCabecalhos($this->get('/painel/nao-existe'), '404');
        $this->exigirCabecalhos($this->get('/painel'), '/painel sem rota');
    }

    public function test_metodo_nao_permitido_e_erro_de_servidor_tem_os_cabecalhos(): void
    {
        // Producao: a TravaDeProducao exige APP_DEBUG=false (a pagina de
        // depuracao mostraria a mensagem da excecao).
        config(['app.debug' => false]);
        Route::get('/painel/so-get', fn () => 'ok');
        Route::get('/painel/explode', fn () => throw new Exception('segredo-interno-123'));

        $this->exigirCabecalhos($this->post('/painel/so-get'), '405');

        $erro = $this->get('/painel/explode');
        $erro->assertStatus(500);
        $this->exigirCabecalhos($erro, '500');
        $this->assertStringNotContainsString('segredo-interno-123', $erro->getContent(), 'sem detalhe interno na tela');
    }

    public function test_resposta_normal_sob_painel_tem_os_cabecalhos(): void
    {
        Route::get('/painel/qualquer-pagina', fn () => response('<!doctype html><title>x</title>'));

        $this->exigirCabecalhos($this->get('/painel/qualquer-pagina'), '200');
    }

    public function test_redirecionamento_sob_painel_tem_os_cabecalhos(): void
    {
        Route::get('/painel/vai', fn () => redirect('/painel/volta'));

        $this->exigirCabecalhos($this->get('/painel/vai'), '302');
    }

    public function test_o_prefixo_exato_e_respeitado(): void
    {
        Route::get('/painelzinho', fn () => 'fora do painel');

        $resposta = $this->get('/painelzinho');

        $this->assertNull($resposta->headers->get('content-security-policy'), 'so /painel e /painel/*');
    }

    public function test_fora_do_painel_a_api_e_a_saude_ficam_como_antes(): void
    {
        $this->assertNull($this->get('/up')->headers->get('content-security-policy'));
        $this->assertNull($this->getJson('/api/v1/regioes')->headers->get('content-security-policy'));
    }
}
