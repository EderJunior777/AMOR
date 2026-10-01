<?php

namespace Tests\Feature\Painel;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * O prefixo __Host- e o atributo Secure do cookie de sessao sao configuraveis
 * por variavel de ambiente (SESSION_PREFIXO_HOST e SESSION_SECURE_COOKIE), os
 * DOIS LIGADOS POR PADRAO. Ambos exigem HTTPS: so o atalho "ligar-para-celular"
 * (teste pelo iPhone em http://IP-do-computador, APP_ENV=local) desliga os
 * dois. A trava de boot, em producao, exige os dois (TravaDeProducaoTest).
 */
class CookieDeSessaoConfiguravelTest extends TestCase
{
    use BancoDeTeste;

    /**
     * config/session.php avaliado num processo PHP limpo, so com as variaveis
     * dadas (sem .env, sem o ambiente do PHPUnit): o que um servidor sem
     * nenhuma dessas variaveis, ou com elas, realmente configura.
     *
     * @param  array<string, string>  $variaveis
     * @return array{cookie: string, secure: bool, path: string, domain: ?string}
     */
    private function configDaSessao(array $variaveis): array
    {
        $raiz = dirname(__DIR__, 3);
        // Barras normais e var_export: no Windows, "\vendor" dentro de aspas duplas viraria um caractere de controle.
        $caminho = fn (string $arquivo) => var_export(str_replace('\\', '/', $raiz).'/'.$arquivo, true);
        // config/session.php chama storage_path(), que precisa de um container com storagePath().
        $modelo = <<<'PHP'
            require __AUTOLOAD__;
            Illuminate\Container\Container::setInstance(new class extends Illuminate\Container\Container {
                public function storagePath($path = '') { return sys_get_temp_dir().'/'.$path; }
            });
            $c = require __CONFIG__;
            echo json_encode(['cookie' => $c['cookie'], 'secure' => $c['secure'], 'path' => $c['path'], 'domain' => $c['domain']]);
            PHP;
        $codigo = str_replace(['__AUTOLOAD__', '__CONFIG__'], [$caminho('vendor/autoload.php'), $caminho('config/session.php')], $modelo);

        $processo = proc_open(
            [PHP_BINARY, '-r', $codigo],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $raiz,
            $variaveis + ['PATH' => (string) getenv('PATH'), 'SystemRoot' => (string) getenv('SystemRoot')],
        );
        $saida = (string) stream_get_contents($pipes[1]);
        $erro = (string) stream_get_contents($pipes[2]);
        proc_close($processo);

        $this->assertNotSame('', $saida, 'config/session.php nao carregou: '.$erro);

        return json_decode($saida, true, flags: JSON_THROW_ON_ERROR);
    }

    private function cookieDaSessao(TestResponse $resposta): Cookie
    {
        foreach ($resposta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }
        $this->fail('A resposta nao definiu o cookie de sessao.');
    }

    // ---------------------------------------------------- o que a config entrega

    public function test_sem_nenhuma_variavel_o_prefixo_e_o_secure_ficam_ligados(): void
    {
        $config = $this->configDaSessao([]);

        $this->assertSame('__Host-cleison-sessao', $config['cookie']);
        $this->assertTrue($config['secure']);
        $this->assertSame('/', $config['path'], '__Host- exige Path=/');
        $this->assertNull($config['domain'], '__Host- exige cookie sem Domain');
    }

    public function test_ligados_por_padrao_tambem_em_app_env_local(): void
    {
        $config = $this->configDaSessao(['APP_ENV' => 'local']);

        $this->assertSame('__Host-cleison-sessao', $config['cookie']);
        $this->assertTrue($config['secure']);
    }

    public function test_modo_celular_desliga_os_dois_so_com_as_variaveis(): void
    {
        $config = $this->configDaSessao([
            'APP_ENV' => 'local',
            'SESSION_PREFIXO_HOST' => 'false',
            'SESSION_SECURE_COOKIE' => 'false',
        ]);

        $this->assertSame('cleison-sessao', $config['cookie'], 'sem __Host-: o navegador aceita o cookie em http');
        $this->assertFalse($config['secure']);
    }

    public function test_cada_variavel_vale_sozinha(): void
    {
        $soSemPrefixo = $this->configDaSessao(['SESSION_PREFIXO_HOST' => 'false']);
        $this->assertSame('cleison-sessao', $soSemPrefixo['cookie']);
        $this->assertTrue($soSemPrefixo['secure']);

        $soSemSecure = $this->configDaSessao(['SESSION_SECURE_COOKIE' => 'false']);
        $this->assertSame('__Host-cleison-sessao', $soSemSecure['cookie']);
        $this->assertFalse($soSemSecure['secure']);
    }

    public function test_valores_explicitamente_ligados_e_textos_estranhos(): void
    {
        $ligado = $this->configDaSessao(['SESSION_PREFIXO_HOST' => 'true', 'SESSION_SECURE_COOKIE' => 'true']);
        $this->assertSame('__Host-cleison-sessao', $ligado['cookie']);
        $this->assertTrue($ligado['secure']);

        // Texto que nao e booleano nao desliga a seguranca: o prefixo continua.
        $estranho = $this->configDaSessao(['SESSION_PREFIXO_HOST' => 'talvez']);
        $this->assertSame('__Host-cleison-sessao', $estranho['cookie']);
    }

    // ---------------------------------------------- o que o navegador recebe

    public function test_cenario_padrao_sobre_https_cookie_host_secure_http_only(): void
    {
        config(['session.cookie' => '__Host-cleison-sessao', 'session.secure' => true]);

        $cookie = $this->cookieDaSessao($this->get('https://painel.exemplo.test/painel/entrar'));

        $this->assertSame('__Host-cleison-sessao', $cookie->getName());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    public function test_cenario_celular_sobre_http_cookie_sem_prefixo_nem_secure_mas_http_only(): void
    {
        config(['session.cookie' => 'cleison-sessao', 'session.secure' => false]);
        $usuario = User::factory()->state(['password' => 'SenhaSegura12345', 'senha_temporaria' => false])->create();

        $entrada = $this->get('http://192.168.0.10:8000/painel/entrar');
        $cookie = $this->cookieDaSessao($entrada);

        $this->assertSame('cleison-sessao', $cookie->getName());
        $this->assertFalse($cookie->isSecure(), 'em http o cookie Secure nao seria guardado pelo Safari');
        $this->assertTrue($cookie->isHttpOnly(), 'HttpOnly nao depende de HTTPS e continua ligado');
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));

        // O fluxo do iPhone: entrar e continuar logado nas paginas seguintes.
        $this->post('http://192.168.0.10:8000/painel/entrar', ['email' => $usuario->email, 'senha' => 'SenhaSegura12345'])
            ->assertRedirect('/painel');
        $this->get('http://192.168.0.10:8000/painel')->assertOk();
    }
}
