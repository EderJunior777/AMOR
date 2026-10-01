<?php

namespace Tests\Feature\Painel;

use App\Enums\PapelUsuario;
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
 * Menu "Equipe" do painel (etapa 3, Fase 3): so o PROPRIETARIO ativo lista,
 * cria barbeiro e recepcao (senha temporaria mostrada UMA vez, na resposta, sem
 * ir para sessao, log nem auditoria), desativa (derruba as sessoes), reativa e
 * redefine senha. Autorizacao no servidor (policy) em TODA rota.
 */
class EquipeTest extends TestCase
{
    use BancoDeTeste;

    private User $dono;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dono = User::factory()->proprietario()->state(['name' => 'Dona Maria Proprietaria', 'email' => 'dona@exemplo.com'])->create();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function profissional(string $nome = 'Ze do Corte', bool $ativo = true): int
    {
        return (int) DB::table('profissionais')->insertGetId(['nome_exibicao' => $nome, 'ativo' => $ativo]);
    }

    /** @param array<string, mixed> $campos */
    private function criar(array $campos = []): TestResponse
    {
        return $this->post('/painel/equipe/criar', $campos + [
            'nome' => 'Fulano de Tal', 'email' => 'fulano@exemplo.com', 'papel' => 'recepcao',
        ]);
    }

    private function senhaMostrada(TestResponse $resposta): string
    {
        $this->assertSame(1, preg_match('/id="senha-temporaria"[^>]*>\s*([A-Za-z0-9]{16})\s*</', $resposta->getContent(), $m), 'a senha temporaria nao apareceu na tela');

        return $m[1];
    }

    // -------------------------------------------------------------- acesso

    public function test_sem_login_nada_da_equipe_funciona(): void
    {
        $alvo = User::factory()->create();

        $this->get('/painel/equipe')->assertRedirect('/painel/entrar');
        $this->criar()->assertRedirect('/painel/entrar');
        foreach (['desativar', 'reativar', 'redefinir-senha'] as $rota) {
            $this->post("/painel/equipe/{$rota}", ['usuario' => $alvo->id])->assertRedirect('/painel/entrar');
        }
        $this->assertSame(0, DB::table('auditoria_acessos')->count());
    }

    /** @return array<string, array{0: callable(self): User}> */
    public static function quemNaoGere(): array
    {
        return [
            'barbeiro' => [fn (self $t) => User::factory()->create()],
            'recepcao' => [fn (self $t) => User::factory()->state(['papel' => PapelUsuario::Recepcao])->create()],
        ];
    }

    /** @param callable(self): User $montar */
    #[DataProvider('quemNaoGere')]
    public function test_so_o_proprietario_acessa_a_equipe_em_qualquer_rota(callable $montar): void
    {
        $intruso = $montar($this);
        $alvo = User::factory()->state(['papel' => PapelUsuario::Recepcao])->create();
        $this->actingAs($intruso);

        $this->get('/painel/equipe')->assertForbidden();
        $this->criar()->assertForbidden();
        foreach (['desativar', 'reativar', 'redefinir-senha'] as $rota) {
            $this->post("/painel/equipe/{$rota}", ['usuario' => $alvo->id])->assertForbidden();
        }

        $this->assertTrue($alvo->fresh()->ativo);
        $this->assertSame(0, User::query()->where('email', 'fulano@exemplo.com')->count(), 'ninguem foi criado');
        $this->assertSame(0, DB::table('auditoria_acessos')->count());
    }

    public function test_proprietario_inativo_nao_gere_a_equipe(): void
    {
        $inativo = User::factory()->proprietario()->state(['ativo' => false])->create();

        $this->actingAs($inativo)->get('/painel/equipe')->assertRedirect('/painel/entrar');
    }

    public function test_so_o_proprietario_ve_o_menu_equipe(): void
    {
        $this->actingAs($this->dono)->get('/painel')->assertSee('href="/painel/equipe"', false);
        $this->actingAs(User::factory()->create())->get('/painel')->assertDontSee('/painel/equipe');
        $this->actingAs(User::factory()->state(['papel' => PapelUsuario::Recepcao])->create())->get('/painel')->assertDontSee('/painel/equipe');
    }

    // ---------------------------------------------------------------- lista

    public function test_a_lista_mostra_a_equipe_o_papel_o_estado_e_o_profissional(): void
    {
        $profissional = $this->profissional('Ze do Corte');
        $barbeiro = User::factory()->state(['name' => 'Barbeiro Ze', 'email' => 'ze@exemplo.com'])->create();
        DB::table('profissionais')->where('id', $profissional)->update(['user_id' => $barbeiro->id]);
        User::factory()->state(['name' => 'Recepcionista Ana', 'email' => 'ana@exemplo.com', 'papel' => PapelUsuario::Recepcao, 'ativo' => false])->create();

        $resposta = $this->actingAs($this->dono)->get('/painel/equipe');

        $resposta->assertOk();
        foreach (['Dona Maria Proprietaria', 'Barbeiro Ze', 'ze@exemplo.com', 'Recepcionista Ana', 'Ze do Corte'] as $texto) {
            $resposta->assertSee($texto);
        }
        $resposta->assertSee('Barbeiro');
        $resposta->assertSee('Recepção');
        $resposta->assertSee('Desativado');
        $resposta->assertDontSee($this->dono->fresh()->password, false);
        $resposta->assertDontSee('remember_token');
    }

    public function test_proprietario_aparece_sem_botoes_de_acao_e_os_outros_com(): void
    {
        $ativo = User::factory()->state(['name' => 'Ativo Fulano'])->create();
        $inativo = User::factory()->state(['name' => 'Inativo Beltrano', 'ativo' => false])->create();

        $html = $this->actingAs($this->dono)->get('/painel/equipe')->getContent();

        $this->assertStringNotContainsString('name="usuario" value="'.$this->dono->id.'"', $html, 'ninguem age no proprietario');
        $this->assertStringContainsString('name="usuario" value="'.$ativo->id.'"', $html);
        $this->assertStringContainsString('/painel/equipe/desativar', $html);
        $this->assertStringContainsString('/painel/equipe/redefinir-senha', $html);
        $this->assertStringContainsString('/painel/equipe/reativar', $html);
        $this->assertStringContainsString('name="usuario" value="'.$inativo->id.'"', $html);
    }

    public function test_o_formulario_de_criar_lista_so_profissionais_ativos_e_sem_acesso(): void
    {
        $this->profissional('Livre e Ativo');
        $this->profissional('Parado Inativo', ativo: false);
        $comAcesso = $this->profissional('Ja Tem Acesso');
        DB::table('profissionais')->where('id', $comAcesso)->update(['user_id' => User::factory()->create()->id]);

        $html = $this->actingAs($this->dono)->get('/painel/equipe')->getContent();
        $bloco = substr($html, (int) strpos($html, 'name="profissional_id"'));
        $bloco = substr($bloco, 0, (int) strpos($bloco, '</select>'));

        $this->assertStringContainsString('Livre e Ativo', $bloco);
        $this->assertStringNotContainsString('Parado Inativo', $bloco);
        $this->assertStringNotContainsString('Ja Tem Acesso', $bloco);
    }

    // ---------------------------------------------------------------- criar

    public function test_criar_barbeiro_mostra_a_senha_temporaria_uma_unica_vez_e_nao_a_guarda(): void
    {
        $profissional = $this->profissional();

        $resposta = $this->actingAs($this->dono)->criar([
            'nome' => 'Zé do Corte', 'email' => 'ZE@Exemplo.com', 'papel' => 'barbeiro', 'profissional_id' => $profissional,
        ]);

        $resposta->assertOk();
        $senha = $this->senhaMostrada($resposta);
        $usuario = User::query()->where('email', 'ze@exemplo.com')->firstOrFail();
        $this->assertSame(PapelUsuario::Barbeiro, $usuario->papel);
        $this->assertTrue((bool) $usuario->senha_temporaria);
        $this->assertTrue(Hash::check($senha, $usuario->password), 'o banco guarda so o hash');
        $this->assertSame($usuario->id, (int) DB::table('profissionais')->where('id', $profissional)->value('user_id'));

        $resposta->assertSee('Zé do Corte');
        $resposta->assertSee('Senha temporária');
        $resposta->assertSee('não aparece de novo');
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));

        // Nunca fora da tela desta resposta: nem sessao, nem proxima pagina, nem log, nem auditoria.
        $this->assertStringNotContainsString($senha, json_encode(session()->all()));
        $this->get('/painel/equipe')->assertDontSee($senha);
        $this->assertStringNotContainsString($senha, implode("\n", $this->logs));
        $this->assertStringNotContainsString($senha, json_encode(DB::table('auditoria_acessos')->get()));
    }

    public function test_a_tela_da_senha_tem_copiar_com_alternativa_sem_https_e_texto_selecionavel(): void
    {
        $resposta = $this->actingAs($this->dono)->criar();
        $html = $resposta->getContent();

        $this->assertStringContainsString('data-copiar="#senha-temporaria"', $html);
        $this->assertStringContainsString('data-copiar-aviso="#aviso-da-copia"', $html);
        $this->assertStringContainsString('id="aviso-da-copia"', $html);
        $this->assertStringContainsString('class="copiavel"', $html, 'o texto e selecionavel com um toque (alternativa sem HTTPS)');
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
    }

    public function test_criar_recepcao_e_o_novo_usuario_entra_e_e_obrigado_a_trocar_a_senha(): void
    {
        $resposta = $this->actingAs($this->dono)->criar(['nome' => 'Ana Recepcao', 'email' => 'ana@exemplo.com', 'papel' => 'recepcao']);
        $senha = $this->senhaMostrada($resposta);

        $this->post('/painel/sair');
        $this->post('/painel/entrar', ['email' => 'ana@exemplo.com', 'senha' => $senha])->assertRedirect('/painel/conta/senha');
        $this->get('/painel')->assertRedirect('/painel/conta/senha');
    }

    public function test_criar_audita_quem_criou_a_partir_da_sessao_e_nao_do_corpo(): void
    {
        $outro = User::factory()->proprietario()->create();

        $this->actingAs($this->dono)->criar(['autor_id' => $outro->id, 'usuario_id' => $outro->id, 'ativo' => '0']);

        $linha = DB::table('auditoria_acessos')->first();
        $this->assertSame('usuario_criado', $linha->evento);
        $this->assertSame($this->dono->id, (int) $linha->autor_id);
        $this->assertTrue((bool) User::query()->where('email', 'fulano@exemplo.com')->value('ativo'), 'o corpo nao define "ativo"');
    }

    public function test_criar_com_papel_proprietario_e_recusado(): void
    {
        $this->actingAs($this->dono)->criar(['papel' => 'proprietario'])
            ->assertRedirect('/painel/equipe')
            ->assertSessionHas('erro', 'O papel precisa ser barbeiro ou recepção.');

        $this->assertSame(0, User::query()->where('email', 'fulano@exemplo.com')->count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function criacoesRecusadas(): array
    {
        return [
            'e-mail em uso' => [['email' => 'dona@exemplo.com'], 'Este e-mail já está em uso.'],
            'e-mail invalido' => [['email' => 'sem-arroba'], 'Informe um e-mail válido.'],
            'nome curto' => [['nome' => 'A'], 'Informe o nome, com 2 a 120 caracteres.'],
            'barbeiro sem profissional' => [['papel' => 'barbeiro'], 'Escolha o profissional que este barbeiro atende.'],
            'papel invalido' => [['papel' => 'gerente'], 'O papel precisa ser barbeiro ou recepção.'],
            'recepcao com profissional' => [['papel' => 'recepcao', 'profissional_id' => 'PROFISSIONAL'], 'Só o barbeiro tem profissional vinculado.'],
            'profissional inexistente' => [['papel' => 'barbeiro', 'profissional_id' => 999999], 'Profissional não encontrado.'],
        ];
    }

    /** @param array<string, mixed> $campos */
    #[DataProvider('criacoesRecusadas')]
    public function test_criacao_invalida_volta_com_mensagem_clara_e_sem_gravar(array $campos, string $mensagem): void
    {
        $profissional = $this->profissional();
        $campos = array_map(fn ($v) => $v === 'PROFISSIONAL' ? $profissional : $v, $campos);

        $this->actingAs($this->dono)->criar($campos)
            ->assertRedirect('/painel/equipe')
            ->assertSessionHas('erro', $mensagem);

        $this->assertSame(1, DB::table('users')->count(), 'so o proprietario existe');
        $this->assertSame(0, DB::table('auditoria_acessos')->count());
    }

    public function test_campos_do_tipo_errado_nao_derrubam_a_criacao(): void
    {
        $this->actingAs($this->dono);

        foreach ([[], ['nome' => ['a'], 'email' => ['b'], 'papel' => ['c']], ['nome' => 'Fulano', 'email' => 'f@exemplo.com', 'papel' => 'barbeiro', 'profissional_id' => ['x']]] as $corpo) {
            $this->post('/painel/equipe/criar', $corpo)->assertRedirect('/painel/equipe')->assertSessionHas('erro');
        }
        $this->assertSame(1, DB::table('users')->count());
    }

    // ----------------------------------------------------- desativar e afins

    public function test_desativar_derruba_as_sessoes_e_impede_o_login(): void
    {
        $alvo = User::factory()->state(['password' => 'SenhaSegura12345'])->create();
        DB::table('sessions')->insert(['id' => 'sessao-do-alvo', 'user_id' => $alvo->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($this->dono)->post('/painel/equipe/desativar', ['usuario' => $alvo->id])
            ->assertRedirect('/painel/equipe')
            ->assertSessionHas('sucesso', 'Usuário desativado.');

        $this->assertFalse($alvo->fresh()->ativo);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $alvo->id)->count());
        $this->post('/painel/sair');
        $this->post('/painel/entrar', ['email' => $alvo->email, 'senha' => 'SenhaSegura12345'])->assertRedirect('/painel/entrar');
        $this->assertGuest();
    }

    public function test_reativar_e_redefinir_mostram_senha_temporaria_nova_so_uma_vez(): void
    {
        $inativo = User::factory()->state(['ativo' => false, 'password' => 'SenhaAntiga123456'])->create();
        $ativo = User::factory()->state(['password' => 'SenhaAntiga123456'])->create();
        $this->actingAs($this->dono);

        $reativado = $this->post('/painel/equipe/reativar', ['usuario' => $inativo->id]);
        $senha1 = $this->senhaMostrada($reativado);
        $redefinido = $this->post('/painel/equipe/redefinir-senha', ['usuario' => $ativo->id]);
        $senha2 = $this->senhaMostrada($redefinido);

        $this->assertTrue($inativo->fresh()->ativo);
        $this->assertTrue(Hash::check($senha1, $inativo->fresh()->password));
        $this->assertFalse(Hash::check('SenhaAntiga123456', $inativo->fresh()->password), 'a senha antiga nao volta');
        $this->assertTrue(Hash::check($senha2, $ativo->fresh()->password));
        $this->assertTrue((bool) $ativo->fresh()->senha_temporaria);
        $this->get('/painel/equipe')->assertDontSee($senha1)->assertDontSee($senha2);
        foreach ([$senha1, $senha2] as $senha) {
            $this->assertStringNotContainsString($senha, implode("\n", $this->logs));
            $this->assertStringNotContainsString($senha, json_encode(DB::table('auditoria_acessos')->get()));
        }
    }

    public function test_acao_em_proprietario_em_si_mesmo_ou_em_inexistente_e_recusada_com_mensagem(): void
    {
        $outroDono = User::factory()->proprietario()->create();
        $this->actingAs($this->dono);

        foreach (['desativar', 'reativar', 'redefinir-senha'] as $rota) {
            foreach ([$this->dono->id, $outroDono->id] as $id) {
                $this->post("/painel/equipe/{$rota}", ['usuario' => $id])
                    ->assertRedirect('/painel/equipe')
                    ->assertSessionHas('erro', 'Este usuário não pode ser alterado por aqui.');
            }
            $this->post("/painel/equipe/{$rota}", ['usuario' => 999999])
                ->assertRedirect('/painel/equipe')
                ->assertSessionHas('erro', 'Usuário não encontrado.');
        }

        $this->assertTrue($this->dono->fresh()->ativo);
        $this->assertTrue($outroDono->fresh()->ativo);
    }

    public function test_acao_com_usuario_do_tipo_errado_ou_ausente_nao_derruba(): void
    {
        $this->actingAs($this->dono);

        foreach (['desativar', 'reativar', 'redefinir-senha'] as $rota) {
            foreach ([[], ['usuario' => ''], ['usuario' => ['1']], ['usuario' => 'abc'], ['usuario' => '1 OR 1=1']] as $corpo) {
                $this->post("/painel/equipe/{$rota}", $corpo)->assertRedirect('/painel/equipe')->assertSessionHas('erro');
            }
        }
    }

    public function test_desativar_quem_ja_esta_inativo_e_reativar_quem_ja_esta_ativo_dao_mensagem(): void
    {
        $inativo = User::factory()->state(['ativo' => false])->create();
        $ativo = User::factory()->create();
        $this->actingAs($this->dono);

        $this->post('/painel/equipe/desativar', ['usuario' => $inativo->id])->assertSessionHas('erro', 'Este usuário já está desativado.');
        $this->post('/painel/equipe/reativar', ['usuario' => $ativo->id])->assertSessionHas('erro', 'Este usuário já está ativo.');
    }

    // ---------------------------------------------------------------- higiene

    public function test_as_telas_sao_mobile_first_sem_inline_e_sem_cache(): void
    {
        $this->actingAs($this->dono);
        User::factory()->create();

        foreach ([$this->get('/painel/equipe'), $this->criar()] as $resposta) {
            $html = $resposta->getContent();
            $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1"', $html);
            $this->assertStringContainsString('lang="pt-BR"', $html);
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html);
            $this->assertDoesNotMatchRegularExpression('/<style/i', $html);
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
            $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        }
    }

    public function test_nome_e_email_nunca_vao_para_o_log(): void
    {
        $this->actingAs($this->dono)->criar(['nome' => 'Pessoa Sigilosa', 'email' => 'sigilosa@exemplo.com']);
        $this->get('/painel/equipe');

        $log = implode("\n", $this->logs);
        $this->assertStringNotContainsString('Sigilosa', $log);
        $this->assertStringNotContainsString('sigilosa@exemplo.com', $log);
    }
}
