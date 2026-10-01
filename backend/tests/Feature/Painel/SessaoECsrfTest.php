<?php

namespace Tests\Feature\Painel;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * Cookie de sessao (HttpOnly, SameSite, Secure sobre HTTPS), CSRF em TODO POST
 * do painel e paginas de erro sem estilo nem script inline (a CSP estrita as
 * deixaria sem formatacao).
 */
class SessaoECsrfTest extends TestCase
{
    use BancoDeTeste;

    private function cookieDaSessao(TestResponse $resposta): Cookie
    {
        foreach ($resposta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }
        $this->fail('A resposta nao definiu o cookie de sessao.');
    }

    /** Liga a conferencia de CSRF, que o framework pula quando o ambiente e "testing". */
    private function comCsrfLigado(): void
    {
        $this->app['env'] = 'local';
    }

    public function test_o_cookie_de_sessao_e_http_only_same_site_e_cifrado(): void
    {
        $cookie = $this->cookieDaSessao($this->get('/painel/entrar'));

        $this->assertTrue($cookie->isHttpOnly(), 'HttpOnly: JavaScript nao le a sessao');
        $this->assertContains(strtolower((string) $cookie->getSameSite()), ['lax', 'strict'], 'SameSite');
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain(), 'sem Domain: nao vale para subdominios');
        $this->assertTrue(config('session.encrypt'), 'sessao cifrada');
        $this->assertLessThanOrEqual(120, config('session.lifetime'), 'sessao ociosa expira em ate 2 horas');
    }

    public function test_sobre_https_o_cookie_de_sessao_e_secure(): void
    {
        config(['session.secure' => true]);

        $cookie = $this->cookieDaSessao($this->get('https://painel.exemplo.test/painel/entrar'));

        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    /** @return array<string, array{0: string}> */
    public static function postsDoPainel(): array
    {
        return [
            'entrar' => ['/painel/entrar'],
            'sair' => ['/painel/sair'],
            'trocar senha' => ['/painel/conta/senha'],
        ];
    }

    #[DataProvider('postsDoPainel')]
    public function test_todo_post_do_painel_exige_o_token_csrf(string $rota): void
    {
        $this->comCsrfLigado();
        $this->actingAs(User::factory()->create());

        $this->post($rota)->assertStatus(419);
        $this->withSession(['_token' => 'token-certo'])->post($rota, ['_token' => 'token-errado'])->assertStatus(419);
        $this->withSession(['_token' => 'token-certo'])
            ->withHeader('X-CSRF-TOKEN', 'token-errado')->post($rota)->assertStatus(419);
    }

    public function test_com_o_token_certo_o_post_passa_da_barreira_do_csrf(): void
    {
        $this->comCsrfLigado();

        $resposta = $this->withSession(['_token' => 'token-certo'])
            ->post('/painel/entrar', ['_token' => 'token-certo', 'email' => 'a@exemplo.com', 'senha' => 'qualquer-senha-12']);

        $resposta->assertRedirect('/painel/entrar');
        $resposta->assertSessionHas('erro');
    }

    public function test_o_post_de_sair_por_get_nao_contorna_o_csrf(): void
    {
        $this->comCsrfLigado();
        $this->actingAs(User::factory()->create());

        $this->get('/painel/sair')->assertStatus(405);
        $this->assertAuthenticated();
    }

    public function test_a_pagina_419_e_em_portugues_e_sem_estilo_nem_script_inline(): void
    {
        $this->comCsrfLigado();

        $resposta = $this->post('/painel/entrar');

        $resposta->assertStatus(419);
        $resposta->assertSee('Sua página expirou');
        $this->exigirPaginaDeErroLimpa($resposta);
        $this->assertSame("'self'", $this->diretiva($resposta, 'style-src'));
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function paginasDeErro(): array
    {
        return [
            'nao encontrada' => ['/painel/nao-existe', 404, 'Página não encontrada'],
            'metodo nao permitido' => ['/painel/so-get', 405, 'Ação não permitida'],
            'erro do servidor' => ['/painel/explode', 500, 'Algo deu errado'],
        ];
    }

    #[DataProvider('paginasDeErro')]
    public function test_as_paginas_de_erro_do_painel_sao_limpas_e_em_portugues(string $caminho, int $status, string $texto): void
    {
        config(['app.debug' => false]);
        Route::get('/painel/so-get', fn () => 'ok');
        Route::get('/painel/explode', fn () => throw new Exception('segredo-interno-123'));

        $resposta = $caminho === '/painel/so-get' ? $this->post($caminho) : $this->get($caminho);

        $resposta->assertStatus($status);
        $resposta->assertSee($texto);
        $this->exigirPaginaDeErroLimpa($resposta);
        $this->assertStringNotContainsString('segredo-interno-123', $resposta->getContent());
    }

    private function exigirPaginaDeErroLimpa(TestResponse $resposta): void
    {
        $html = $resposta->getContent();
        $this->assertStringContainsString('lang="pt-BR"', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html, 'nada de script inline');
        $this->assertDoesNotMatchRegularExpression('/<style/i', $html, 'nada de <style>');
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'nada de style=""');
        $this->assertStringContainsString('href="/painel/painel.css"', $html);
    }

    private function diretiva(TestResponse $resposta, string $nome): ?string
    {
        foreach (explode(';', (string) $resposta->headers->get('content-security-policy')) as $parte) {
            $parte = trim($parte);
            if (str_starts_with($parte, $nome.' ')) {
                return substr($parte, strlen($nome) + 1);
            }
        }

        return null;
    }
}
