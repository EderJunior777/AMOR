<?php

namespace Tests\Feature\Painel;

use App\Models\User;
use Illuminate\Hashing\HashManager;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * Login, logout e protecoes (etapa 3, Fase 2): mensagem igual e verificacao de
 * senha sempre executada (tempo parecido), limite por e-mail e por IP no cache
 * (o IP nunca vai para o banco nem para o log), auditoria sem senha, sessao
 * regenerada, usuario inativo barrado.
 */
class LoginTest extends TestCase
{
    use BancoDeTeste;

    private const SENHA = 'SenhaSegura12345';

    private const IP = '203.0.113.7';

    private const MENSAGEM_FALHA = 'E-mail ou senha incorretos.';

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    /** @param array<string, mixed> $estado */
    private function usuario(array $estado = []): User
    {
        return User::factory()->state($estado + ['password' => self::SENHA, 'senha_temporaria' => false])->create();
    }

    private function entrar(string $email, string $senha = self::SENHA, string $ip = self::IP): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/painel/entrar', ['email' => $email, 'senha' => $senha]);
    }

    /** @return list<object> */
    private function auditoria(): array
    {
        return DB::table('auditoria_acessos')->orderBy('id')->get()->all();
    }

    // -------------------------------------------------------------- a tela

    public function test_a_tela_de_login_abre_sem_script_nem_estilo_inline(): void
    {
        $resposta = $this->get('/painel/entrar');

        $resposta->assertOk();
        $html = $resposta->getContent();
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="senha"', $html);
        $this->assertStringContainsString('name="_token"', $html, 'CSRF em todo POST');
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('lang="pt-BR"', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html, 'nada de script inline');
        $this->assertDoesNotMatchRegularExpression('/<style/i', $html, 'nada de <style>');
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'nada de style=""');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, 'nada de onclick= e afins');
    }

    public function test_sem_login_o_painel_manda_para_a_tela_de_entrada(): void
    {
        $this->get('/painel')->assertRedirect('/painel/entrar');
        $this->get('/painel/conta/senha')->assertRedirect('/painel/entrar');
        $this->post('/painel/sair')->assertRedirect('/painel/entrar');
        $this->assertGuest();
    }

    public function test_quem_ja_entrou_nao_ve_a_tela_de_login(): void
    {
        $this->actingAs($this->usuario());

        $this->get('/painel/entrar')->assertRedirect('/painel');
    }

    // ------------------------------------------------------------- sucesso

    public function test_login_certo_entra_regenera_a_sessao_e_audita(): void
    {
        $usuario = $this->usuario();

        $this->get('/painel/entrar');
        $antes = session()->getId();

        $this->entrar(strtoupper($usuario->email))->assertRedirect('/painel');

        $this->assertAuthenticatedAs($usuario);
        $this->assertNotSame($antes, session()->getId(), 'sessao nova no login (fixacao de sessao)');
        $this->assertNotNull($usuario->fresh()->ultimo_acesso_em);

        $linhas = $this->auditoria();
        $this->assertCount(1, $linhas);
        $this->assertSame('login', $linhas[0]->evento);
        $this->assertSame('sucesso', $linhas[0]->resultado);
        $this->assertSame($usuario->id, (int) $linhas[0]->usuario_id);
        $this->assertNull($linhas[0]->email_tentado);
        $this->assertNull($linhas[0]->autor_id);
    }

    public function test_com_senha_temporaria_o_login_leva_direto_a_troca(): void
    {
        $usuario = $this->usuario(['senha_temporaria' => true]);

        $this->entrar($usuario->email)->assertRedirect('/painel/conta/senha');

        $this->assertAuthenticatedAs($usuario);
        $this->get('/painel')->assertRedirect('/painel/conta/senha');
        $this->get('/painel/conta/senha')->assertOk();
    }

    // -------------------------------------------------------------- falhas

    public function test_senha_errada_nao_entra_e_audita_com_o_id_do_usuario(): void
    {
        $usuario = $this->usuario();

        $this->entrar($usuario->email, 'senha-errada-123')
            ->assertRedirect('/painel/entrar')
            ->assertSessionHas('erro', self::MENSAGEM_FALHA);

        $this->assertGuest();
        $linha = $this->auditoria()[0];
        $this->assertSame('login', $linha->evento);
        $this->assertSame('falha', $linha->resultado);
        $this->assertSame($usuario->id, (int) $linha->usuario_id);
        $this->assertNull($linha->email_tentado, 'com usuario conhecido vale o id, nunca o e-mail');
    }

    public function test_email_inexistente_tem_a_mesma_resposta_e_audita_o_email_normalizado(): void
    {
        $existente = $this->usuario();

        $semUsuario = $this->entrar('  Fantasma@Exemplo.COM ');
        $comUsuario = $this->entrar($existente->email, 'senha-errada-123');

        $this->assertSame($comUsuario->getStatusCode(), $semUsuario->getStatusCode());
        $this->assertSame($comUsuario->headers->get('Location'), $semUsuario->headers->get('Location'));
        $semUsuario->assertSessionHas('erro', self::MENSAGEM_FALHA);
        $comUsuario->assertSessionHas('erro', self::MENSAGEM_FALHA);

        $linhas = $this->auditoria();
        $this->assertSame('fantasma@exemplo.com', $linhas[0]->email_tentado);
        $this->assertNull($linhas[0]->usuario_id);
    }

    public function test_texto_que_nao_e_email_nunca_vai_para_a_auditoria(): void
    {
        // Alguem digitou a senha no campo de e-mail.
        $this->entrar('MinhaSenhaSecreta123');
        $this->entrar('p@ssw0rd.2024');
        $this->entrar(str_repeat('a', 300).'@exemplo.com');

        foreach ($this->auditoria() as $linha) {
            $this->assertSame('falha', $linha->resultado);
            $this->assertNull($linha->email_tentado);
        }
        $this->assertCount(3, $this->auditoria());
    }

    public function test_usuario_inativo_nao_entra_nem_com_a_senha_certa(): void
    {
        $usuario = $this->usuario(['ativo' => false]);

        $this->entrar($usuario->email)
            ->assertRedirect('/painel/entrar')
            ->assertSessionHas('erro', self::MENSAGEM_FALHA);

        $this->assertGuest();
        $linha = $this->auditoria()[0];
        $this->assertSame('falha', $linha->resultado);
        $this->assertSame($usuario->id, (int) $linha->usuario_id);
    }

    public function test_a_senha_e_sempre_verificada_mesmo_sem_usuario(): void
    {
        $existente = $this->usuario();
        $inativo = $this->usuario(['ativo' => false]);
        $contador = new class($this->app) extends HashManager
        {
            public int $verificacoes = 0;

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->verificacoes++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        Hash::swap($contador);

        $this->entrar('fantasma@exemplo.com', 'qualquer-senha-12');
        $this->assertSame(1, $contador->verificacoes, 'e-mail inexistente: ainda gasta uma verificacao (tempo parecido)');

        $this->entrar($existente->email, 'senha-errada-123');
        $this->assertSame(2, $contador->verificacoes, 'senha errada: uma verificacao');

        $this->entrar($inativo->email);
        $this->assertSame(3, $contador->verificacoes, 'inativo: uma verificacao');
    }

    public function test_senha_gigante_falha_sem_erro_e_sem_custo(): void
    {
        $usuario = $this->usuario();

        $this->entrar($usuario->email, str_repeat('x', 5000))
            ->assertRedirect('/painel/entrar')
            ->assertSessionHas('erro', self::MENSAGEM_FALHA);

        $this->assertGuest();
    }

    public function test_campos_ausentes_ou_do_tipo_errado_falham_como_qualquer_outra_tentativa(): void
    {
        foreach ([[], ['email' => 'a@exemplo.com'], ['senha' => 'x'], ['email' => ['a'], 'senha' => ['b']]] as $corpo) {
            $this->withServerVariables(['REMOTE_ADDR' => self::IP])->post('/painel/entrar', $corpo)
                ->assertRedirect('/painel/entrar')
                ->assertSessionHas('erro', self::MENSAGEM_FALHA);
        }
        $this->assertGuest();
    }

    // -------------------------------------------------------- limites

    public function test_cinco_falhas_no_mesmo_email_bloqueiam_ate_com_a_senha_certa(): void
    {
        $usuario = $this->usuario();

        for ($i = 0; $i < 5; $i++) {
            $this->entrar($usuario->email, "errada-{$i}-123456")->assertRedirect('/painel/entrar');
        }

        $bloqueado = $this->entrar($usuario->email);
        $bloqueado->assertStatus(429);
        $bloqueado->assertSee('Muitas tentativas');
        $this->assertGreaterThan(0, (int) $bloqueado->headers->get('Retry-After'));
        $this->assertGuest();

        $ultima = array_values(array_filter($this->auditoria(), fn ($l) => $l->resultado === 'bloqueado'));
        $this->assertCount(1, $ultima);
        $this->assertSame($usuario->id, (int) $ultima[0]->usuario_id);
    }

    public function test_quem_ataca_um_email_de_outro_ip_nao_tranca_o_dono(): void
    {
        $dono = $this->usuario();

        for ($i = 0; $i < 5; $i++) {
            $this->entrar($dono->email, "errada-{$i}-123456", '198.51.100.1'); // o atacante
        }

        $this->entrar($dono->email, self::SENHA, '198.51.100.1')->assertStatus(429);
        $this->assertGuest();

        // O limite e por e-mail + IP: o dono, do IP dele, entra normalmente.
        $this->entrar($dono->email, self::SENHA, '198.51.100.50')->assertRedirect('/painel');
        $this->assertAuthenticatedAs($dono);
    }

    public function test_o_teto_por_email_somando_os_ips_bloqueia_todos_e_e_igual_para_email_inexistente(): void
    {
        config(['cleison.painel.login_max_falhas_por_email_total' => 10]);
        $usuario = $this->usuario();

        foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
            for ($i = 0; $i < 5; $i++) {
                $this->entrar($usuario->email, "errada-{$i}-123456", $ip);
                $this->entrar('fantasma@exemplo.com', "errada-{$i}-123456", $ip);
            }
        }

        $comUsuario = $this->entrar($usuario->email, self::SENHA, '198.51.100.99');
        $semUsuario = $this->entrar('fantasma@exemplo.com', self::SENHA, '198.51.100.98');

        $this->assertSame(429, $comUsuario->getStatusCode());
        $this->assertSame($comUsuario->getStatusCode(), $semUsuario->getStatusCode(), 'sem pista de que o e-mail existe');
        $comUsuario->assertSee('Muitas tentativas');
        $semUsuario->assertSee('Muitas tentativas');
    }

    public function test_o_bloqueio_e_auditado_uma_vez_por_janela_e_nao_a_cada_tentativa(): void
    {
        $usuario = $this->usuario();
        for ($i = 0; $i < 5; $i++) {
            $this->entrar($usuario->email, "errada-{$i}-123456");
        }

        for ($i = 0; $i < 8; $i++) {
            $this->entrar($usuario->email)->assertStatus(429);
        }

        $bloqueios = array_filter($this->auditoria(), fn ($l) => $l->resultado === 'bloqueado');
        $this->assertCount(1, $bloqueios, 'um flood de tentativas bloqueadas nao enche a trilha imutavel');
    }

    public function test_a_tela_de_login_tem_limite_por_ip_antes_de_abrir_sessao(): void
    {
        config(['cleison.painel.entrar_por_minuto' => 3, 'session.driver' => 'database']);

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.77'])->get('/painel/entrar')->assertOk();
        }
        $barrada = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.77'])->get('/painel/entrar');

        $barrada->assertStatus(429);
        $barrada->assertSee('Muitas tentativas');
        $this->assertNotNull($barrada->headers->get('Content-Security-Policy'), 'a pagina 429 tambem leva os cabecalhos do painel');
        $this->assertSame([], $barrada->headers->getCookies(), 'a requisicao barrada nao abre nem renova sessao (sem cookie)');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.78'])->get('/painel/entrar')->assertOk();
    }

    public function test_e_mail_com_unicode_ou_fora_do_ascii_nao_derruba_o_login_nem_vai_para_a_trilha(): void
    {
        foreach (["fulano\u{2003}@exemplo.com", 'ÉLIO@exemplo.com', 'fulano@exémplo.com', "fulano@exemplo.com\n"] as $email) {
            $this->entrar($email)->assertRedirect('/painel/entrar')->assertSessionHas('erro', self::MENSAGEM_FALHA);
        }

        $linhas = $this->auditoria();
        $this->assertCount(4, $linhas);
        foreach ($linhas as $linha) {
            $this->assertSame('falha', $linha->resultado);
        }
        $this->assertSame(['fulano@exemplo.com'], array_values(array_filter(array_column($linhas, 'email_tentado'))), 'so o e-mail ASCII valido (o \n final e aparado)');
    }

    public function test_o_hash_antigo_e_refeito_no_login_com_o_custo_atual(): void
    {
        $usuario = $this->usuario();
        $antigo = $usuario->fresh()->password;
        Hash::driver('bcrypt')->setRounds(5); // o custo subiu depois que a senha foi criada

        $this->entrar($usuario->email)->assertRedirect('/painel');

        $novo = $usuario->fresh()->password;
        $this->assertNotSame($antigo, $novo);
        $this->assertTrue(Hash::check(self::SENHA, $novo), 'a mesma senha continua valendo');
        $this->assertStringContainsString('$05$', $novo, 'bcrypt com o custo novo');
    }

    public function test_vinte_falhas_do_mesmo_ip_bloqueiam_o_ip_e_nao_os_outros(): void
    {
        $usuario = $this->usuario();

        for ($i = 0; $i < 20; $i++) {
            $this->entrar("alvo{$i}@exemplo.com", 'qualquer-senha-12', '192.0.2.50');
        }

        $this->entrar($usuario->email, self::SENHA, '192.0.2.50')->assertStatus(429);
        $this->assertGuest();

        $this->entrar($usuario->email, self::SENHA, '192.0.2.51')->assertRedirect('/painel');
    }

    public function test_vinte_e_cinco_logins_certos_seguidos_do_mesmo_ip_nao_bloqueiam(): void
    {
        // Os limites contam so falhas: o login certo devolve a tentativa no
        // contador do IP (teto 20) e no do e-mail (teto 30).
        $usuario = $this->usuario();

        for ($i = 0; $i < 25; $i++) {
            $this->entrar($usuario->email, self::SENHA, '192.0.2.60')->assertRedirect('/painel');
            $this->post('/painel/sair');
        }

        $this->entrar($usuario->email, self::SENHA, '192.0.2.60')->assertRedirect('/painel');
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_falhas_entre_logins_certos_bloqueiam_o_ip_nos_mesmos_vinte(): void
    {
        $usuario = $this->usuario();

        for ($i = 0; $i < 20; $i++) {
            $this->entrar("alvo{$i}@exemplo.com", 'qualquer-senha-12', '192.0.2.61');
            if ($i % 5 === 4 && $i < 19) { // logins certos depois da 5a, 10a e 15a falhas
                $this->entrar($usuario->email, self::SENHA, '192.0.2.61')->assertRedirect('/painel');
                $this->post('/painel/sair');
            }
        }

        $this->entrar($usuario->email, self::SENHA, '192.0.2.61')->assertStatus(429);
        $this->assertGuest();
    }

    public function test_login_certo_zera_as_falhas_do_email(): void
    {
        $usuario = $this->usuario();

        for ($i = 0; $i < 4; $i++) {
            $this->entrar($usuario->email, "errada-{$i}-123456");
        }
        $this->entrar($usuario->email)->assertRedirect('/painel');
        $this->post('/painel/sair');

        for ($i = 0; $i < 4; $i++) {
            $this->entrar($usuario->email, "outra-{$i}-123456")->assertRedirect('/painel/entrar');
        }
        $this->entrar($usuario->email)->assertRedirect('/painel');
    }

    public function test_o_ip_nunca_vai_para_o_banco_nem_para_o_log_nem_a_senha_nem_o_email(): void
    {
        $usuario = $this->usuario();

        $this->entrar($usuario->email, 'senha-errada-123');
        $this->entrar('fantasma@exemplo.com', 'outra-senha-1234');
        $this->entrar($usuario->email);
        for ($i = 0; $i < 6; $i++) {
            $this->entrar($usuario->email, "errada-{$i}-123456");
        }

        $tudo = json_encode($this->auditoria());
        $log = implode("\n", $this->logs);
        foreach ([self::IP, '203.0.113'] as $ip) {
            $this->assertStringNotContainsString($ip, $tudo);
            $this->assertStringNotContainsString($ip, $log);
        }
        foreach ([self::SENHA, 'senha-errada-123', 'outra-senha-1234'] as $senha) {
            $this->assertStringNotContainsString($senha, $tudo);
            $this->assertStringNotContainsString($senha, $log);
        }
        $this->assertStringNotContainsString($usuario->email, $tudo.$log, 'e-mail de usuario conhecido nunca e gravado');
    }

    // -------------------------------------------------------------- sair

    public function test_sair_encerra_a_sessao_audita_e_fecha_o_painel(): void
    {
        $usuario = $this->usuario();
        $this->entrar($usuario->email);
        $idDaSessao = session()->getId();

        $this->post('/painel/sair')->assertRedirect('/painel/entrar');

        $this->assertGuest();
        $this->assertNotSame($idDaSessao, session()->getId());
        $this->get('/painel')->assertRedirect('/painel/entrar');

        $logout = array_values(array_filter($this->auditoria(), fn ($l) => $l->evento === 'logout'));
        $this->assertCount(1, $logout);
        $this->assertSame('logout', $logout[0]->resultado);
        $this->assertSame($usuario->id, (int) $logout[0]->usuario_id);
    }

    public function test_sair_por_get_nao_existe(): void
    {
        $this->actingAs($this->usuario());

        $this->get('/painel/sair')->assertStatus(405);
        $this->assertAuthenticated();
    }

    public function test_usuario_desativado_com_sessao_aberta_e_barrado_na_proxima_requisicao(): void
    {
        $usuario = $this->usuario();
        $this->entrar($usuario->email);
        $this->get('/painel')->assertOk();

        DB::table('users')->where('id', $usuario->id)->update(['ativo' => false]);
        // Requisicao nova de verdade: o guard do teste guardaria o usuario em memoria.
        $this->app['auth']->forgetGuards();

        $this->get('/painel')->assertRedirect('/painel/entrar');
        $this->assertGuest();
    }
}
