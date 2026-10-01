<?php

namespace Tests\Feature\Painel;

use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * Troca da propria senha (etapa 3, Fase 2): obrigatoria com senha temporaria,
 * politica minima de 12 caracteres com letras e numeros, senha atual conferida
 * (com limite de tentativas), outras sessoes derrubadas, sessao regenerada,
 * auditoria sem senha.
 */
class TrocaDeSenhaTest extends TestCase
{
    use BancoDeTeste;

    private const ATUAL = 'SenhaAtual12345';

    private const NOVA = 'NovaSenhaForte2026';

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
    private function logado(array $estado = []): User
    {
        $usuario = User::factory()->state($estado + [
            'password' => self::ATUAL,
            'senha_temporaria' => false,
            'name' => 'Fulano Silva Santos',
            'email' => 'fulano.silva@exemplo.com',
        ])->create();
        $this->actingAs($usuario);

        return $usuario;
    }

    private function trocar(string $atual = self::ATUAL, string $nova = self::NOVA, ?string $confirmacao = null): TestResponse
    {
        return $this->post('/painel/conta/senha', [
            'senha_atual' => $atual,
            'senha_nova' => $nova,
            'senha_nova_confirmation' => $confirmacao ?? $nova,
        ]);
    }

    public function test_a_tela_de_troca_abre_e_explica_a_senha_temporaria(): void
    {
        $this->logado(['senha_temporaria' => true]);

        $resposta = $this->get('/painel/conta/senha');

        $resposta->assertOk();
        $resposta->assertSee('troque a senha temporária');
        $html = $resposta->getContent();
        $this->assertStringContainsString('autocomplete="new-password"', $html);
        $this->assertStringContainsString('name="senha_nova_confirmation"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
    }

    public function test_a_tela_de_conta_mostra_quem_entrou_e_tem_o_botao_de_sair(): void
    {
        $this->logado();

        $html = $this->get('/painel/conta/senha')->getContent();

        $this->assertStringContainsString('Fulano Silva Santos', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*action="\/painel\/sair"[^>]*>.*?name="_token".*?Sair/s', $html, 'sair e um POST com CSRF');
        $this->post('/painel/sair')->assertRedirect('/painel/entrar');
        $this->assertGuest();
    }

    public function test_quem_esta_com_senha_temporaria_tambem_ve_o_botao_de_sair(): void
    {
        $this->logado(['senha_temporaria' => true]);

        $this->get('/painel/conta/senha')->assertSee('action="/painel/sair"', false);
    }

    public function test_troca_com_senha_temporaria_libera_o_painel_e_derruba_as_outras_sessoes(): void
    {
        $usuario = $this->logado(['senha_temporaria' => true]);
        $outro = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'sessao-velha-1', 'user_id' => $usuario->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'sessao-velha-2', 'user_id' => $usuario->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'sessao-de-outro', 'user_id' => $outro->id, 'payload' => 'x', 'last_activity' => time()],
        ]);
        $tokenAntes = $usuario->remember_token;
        $this->get('/painel')->assertRedirect('/painel/conta/senha');
        $idAntes = session()->getId();

        $this->trocar()->assertRedirect('/painel')->assertSessionHas('sucesso', 'Senha alterada.');

        $depois = $usuario->fresh();
        $this->assertTrue(Hash::check(self::NOVA, $depois->password));
        $this->assertFalse(Hash::check(self::ATUAL, $depois->password));
        $this->assertFalse((bool) $depois->senha_temporaria);
        $this->assertNotSame($tokenAntes, $depois->remember_token);
        $this->assertNotSame($idAntes, session()->getId(), 'sessao nova depois de trocar a senha');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $usuario->id)->count(), 'as outras sessoes do usuario caem');
        $this->assertSame(1, DB::table('sessions')->where('user_id', $outro->id)->count(), 'as dos outros ficam');

        $this->get('/painel')->assertOk();
    }

    public function test_quem_ja_tem_senha_definitiva_tambem_pode_trocar(): void
    {
        $usuario = $this->logado();

        $this->trocar()->assertRedirect('/painel');

        $this->assertTrue(Hash::check(self::NOVA, $usuario->fresh()->password));
    }

    public function test_a_troca_e_auditada_sem_senha_e_sem_ip(): void
    {
        $usuario = $this->logado(['senha_temporaria' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->trocar();

        $linhas = DB::table('auditoria_acessos')->get();
        $this->assertCount(1, $linhas);
        $this->assertSame('senha_trocada', $linhas[0]->evento);
        $this->assertSame('sucesso', $linhas[0]->resultado);
        $this->assertSame($usuario->id, (int) $linhas[0]->usuario_id);
        $this->assertNull($linhas[0]->autor_id);

        $tudo = json_encode($linhas).implode("\n", $this->logs);
        foreach ([self::ATUAL, self::NOVA, '203.0.113.9'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $tudo);
        }
    }

    public function test_senha_atual_errada_nao_troca_nada(): void
    {
        $usuario = $this->logado(['senha_temporaria' => true]);
        $hashAntes = $usuario->password;

        $this->trocar('errada-123456')
            ->assertRedirect('/painel/conta/senha')
            ->assertSessionHas('erro', 'Senha atual incorreta.');

        $depois = $usuario->fresh();
        $this->assertSame($hashAntes, $depois->password);
        $this->assertTrue((bool) $depois->senha_temporaria);
        $this->assertSame(0, DB::table('auditoria_acessos')->count());
    }

    public function test_cinco_senhas_atuais_erradas_bloqueiam_a_troca_ate_com_a_certa(): void
    {
        $usuario = $this->logado();

        for ($i = 0; $i < 5; $i++) {
            $this->trocar("errada-{$i}-123456")->assertRedirect('/painel/conta/senha');
        }

        $bloqueada = $this->trocar();
        $bloqueada->assertStatus(429);
        $bloqueada->assertSee('Muitas tentativas');
        $this->assertTrue(Hash::check(self::ATUAL, $usuario->fresh()->password), 'nada mudou');
    }

    /** @return array<string, array{0: string, 1: ?string, 2: string}> */
    public static function senhasNovasRecusadas(): array
    {
        return [
            'curta (11)' => ['Abcdefgh123', null, 'pelo menos 12 caracteres'],
            'so numeros' => ['123456789012345', null, 'precisa ter letras'],
            'so letras' => ['abcdefghijklmnop', null, 'precisa ter números'],
            'igual a atual' => [self::ATUAL, null, 'diferente da atual'],
            'confirmacao diferente' => [self::NOVA, 'OutraSenhaForte2026', 'confirmação não confere'],
            'acima de 72 bytes (o bcrypt ignoraria o resto)' => [str_repeat('a1', 36).'b', null, 'no máximo 72'],
            'vazia' => ['', null, 'pelo menos 12 caracteres'],
            'senha comum' => ['senha1234567', null, 'menos previsível'],
            'senha comum em ingles' => ['Password2026X', null, 'menos previsível'],
            'teclado' => ['qwertyuiop12', null, 'menos previsível'],
            'poucos caracteres diferentes' => ['aaaaaaaaaaa1', null, 'menos previsível'],
            'contem o e-mail' => ['Fulano.Silva2026!', null, 'menos previsível'],
            'contem o nome' => ['MinhaSantos2026x', null, 'menos previsível'],
            'nome do estabelecimento' => ['Barbearia123456', null, 'menos previsível'],
        ];
    }

    public function test_72_bytes_e_o_maximo_aceito(): void
    {
        $usuario = $this->logado();
        $nova = 'Zz9'.str_repeat('xK7q', 17).'m'; // 72 caracteres

        $this->assertSame(72, strlen($nova));
        $this->trocar(self::ATUAL, $nova)->assertRedirect('/painel');
        $this->assertTrue(Hash::check($nova, $usuario->fresh()->password));
    }

    public function test_acentos_contam_em_bytes_para_o_limite_do_bcrypt(): void
    {
        $this->logado();
        $nova = str_repeat('ção1', 19); // 76 bytes, 76/… caracteres: passa de 72 bytes

        $resposta = $this->trocar(self::ATUAL, $nova);

        $this->assertGreaterThan(72, strlen($nova));
        $resposta->assertRedirect('/painel/conta/senha')->assertSessionHasErrors('senha_nova');
    }

    public function test_errar_a_nova_senha_nao_gasta_as_tentativas_da_senha_atual(): void
    {
        $this->logado();

        for ($i = 0; $i < 8; $i++) {
            $this->trocar(self::ATUAL, 'curta1')->assertRedirect('/painel/conta/senha');
        }

        $this->trocar()->assertRedirect('/painel')->assertSessionHas('sucesso');
    }

    #[DataProvider('senhasNovasRecusadas')]
    public function test_a_politica_de_senha_recusa_e_nao_troca_nada(string $nova, ?string $confirmacao, string $mensagem): void
    {
        $usuario = $this->logado(['senha_temporaria' => true]);
        $hashAntes = $usuario->password;

        $resposta = $this->trocar(self::ATUAL, $nova, $confirmacao);

        $resposta->assertRedirect('/painel/conta/senha');
        $resposta->assertSessionHasErrors('senha_nova');
        $erros = implode(' ', session('errors')->get('senha_nova'));
        $this->assertStringContainsString($mensagem, $erros);
        $this->assertSame($hashAntes, $usuario->fresh()->password);
        $this->assertSame(0, DB::table('auditoria_acessos')->count());
        $this->assertNull(session()->getOldInput('senha_nova'), 'a senha digitada nunca volta para o formulario');
    }

    public function test_campos_do_tipo_errado_falham_sem_erro_de_servidor(): void
    {
        $usuario = $this->logado();
        $hashAntes = $usuario->password;

        $this->post('/painel/conta/senha', ['senha_atual' => ['x'], 'senha_nova' => ['y'], 'senha_nova_confirmation' => ['z']])
            ->assertRedirect('/painel/conta/senha');
        $this->post('/painel/conta/senha', [])->assertRedirect('/painel/conta/senha');

        $this->assertSame($hashAntes, $usuario->fresh()->password);
    }

    public function test_sem_login_nao_troca_senha(): void
    {
        $this->post('/painel/conta/senha', ['senha_atual' => 'x', 'senha_nova' => 'y'])->assertRedirect('/painel/entrar');
    }

    public function test_usuario_desativado_nao_troca_senha(): void
    {
        $usuario = $this->logado();
        DB::table('users')->where('id', $usuario->id)->update(['ativo' => false]);
        $this->app['auth']->forgetGuards();

        $this->trocar()->assertRedirect('/painel/entrar');

        $this->assertTrue(Hash::check(self::ATUAL, $usuario->fresh()->password));
    }

    public function test_com_senha_temporaria_so_a_troca_e_o_sair_estao_liberados(): void
    {
        $this->logado(['senha_temporaria' => true]);

        $this->get('/painel')->assertRedirect('/painel/conta/senha');
        $this->get('/painel/conta/senha')->assertOk();
        $this->post('/painel/sair')->assertRedirect('/painel/entrar');
    }
}
