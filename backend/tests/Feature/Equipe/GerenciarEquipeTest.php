<?php

namespace Tests\Feature\Equipe;

use App\Domain\Equipe\AcessoCriado;
use App\Domain\Equipe\EquipeRecusada;
use App\Domain\Equipe\GerenciarEquipe;
use App\Enums\Ator;
use App\Enums\PapelUsuario;
use App\Models\User;
use App\Support\AutoriaInvalida;
use App\Support\TransacaoAuditada;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * Fase 1 da etapa 3: o proprietario cria barbeiro e recepcao (senha temporaria
 * mostrada UMA vez), desativa, reativa e redefine senha. Desativar derruba as
 * sessoes. Tudo auditado, sem senha em log nem em banco (so o hash).
 */
class GerenciarEquipeTest extends TestCase
{
    use BancoDeTeste;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    private function equipe(): GerenciarEquipe
    {
        return $this->app->make(GerenciarEquipe::class);
    }

    private function dono(): User
    {
        return User::factory()->proprietario()->create();
    }

    public function profissional(string $nome = 'Ze do Corte', bool $ativo = true): int
    {
        return (int) DB::table('profissionais')->insertGetId(['nome_exibicao' => $nome, 'ativo' => $ativo]);
    }

    private function recusa(string $codigo, callable $acao): EquipeRecusada
    {
        try {
            $acao();
        } catch (EquipeRecusada $e) {
            $this->assertSame($codigo, $e->codigo, $e->getMessage());

            return $e;
        }
        $this->fail("Deveria ter sido recusado com {$codigo}.");
    }

    private function sessao(int $usuarioId, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $usuarioId, 'payload' => 'x', 'last_activity' => time()]);
    }

    /** @return list<object> */
    private function auditoria(): array
    {
        return DB::table('auditoria_acessos')->orderBy('id')->get()->all();
    }

    // ------------------------------------------------------------------ criar

    public function test_cria_barbeiro_vinculado_ao_profissional_com_senha_temporaria(): void
    {
        $dono = $this->dono();
        $profissional = $this->profissional();

        $acesso = $this->equipe()->criarUsuario($dono, '  Zé   do Corte ', '  ZE@Exemplo.com ', PapelUsuario::Barbeiro, $profissional);

        $this->assertInstanceOf(AcessoCriado::class, $acesso);
        $usuario = $acesso->usuario->fresh();
        $this->assertSame('Zé do Corte', $usuario->name, 'espacos normalizados');
        $this->assertSame('ze@exemplo.com', $usuario->email, 'e-mail normalizado');
        $this->assertSame(PapelUsuario::Barbeiro, $usuario->papel);
        $this->assertTrue($usuario->ativo);
        $this->assertTrue((bool) $usuario->senha_temporaria, 'forca a troca no primeiro acesso');
        $this->assertNull($usuario->ultimo_acesso_em);
        $this->assertSame($usuario->id, (int) DB::table('profissionais')->where('id', $profissional)->value('user_id'));

        // A senha mostrada confere com o hash; o banco so tem o hash.
        $this->assertTrue(Hash::check($acesso->senhaTemporaria, $usuario->password));
        $this->assertNotSame($acesso->senhaTemporaria, DB::table('users')->where('id', $usuario->id)->value('password'));
    }

    public function test_cria_recepcao_sem_profissional(): void
    {
        $acesso = $this->equipe()->criarUsuario($this->dono(), 'Maria Recepção', 'maria@exemplo.com', PapelUsuario::Recepcao);

        $this->assertSame(PapelUsuario::Recepcao, $acesso->usuario->fresh()->papel);
        $this->assertSame(0, DB::table('profissionais')->whereNotNull('user_id')->count());
    }

    public function test_a_senha_temporaria_atende_a_politica_e_e_diferente_a_cada_vez(): void
    {
        $dono = $this->dono();
        $vistas = [];

        for ($i = 0; $i < 25; $i++) {
            $senha = $this->equipe()->criarUsuario($dono, 'Pessoa Numero '.$i, "pessoa{$i}@exemplo.com", PapelUsuario::Recepcao)->senhaTemporaria;

            $validacao = Validator::make(['s' => $senha], ['s' => ['required', Password::min(12)->mixedCase()->letters()->numbers()]]);
            $this->assertFalse($validacao->fails(), 'a senha gerada precisa passar na politica (12+, letras e numeros)');
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $senha, 'sem simbolos: nada que atrapalhe ao digitar no celular');
            $vistas[$senha] = true;
        }

        $this->assertCount(25, $vistas);
    }

    public function test_a_senha_temporaria_nunca_vai_para_log_nem_para_a_auditoria_nem_para_dump(): void
    {
        $dono = $this->dono();
        $acesso = $this->equipe()->criarUsuario($dono, 'Sigilo Total', 'sigilo@exemplo.com', PapelUsuario::Recepcao);
        $senha = $acesso->senhaTemporaria;

        $this->assertStringNotContainsString($senha, implode("\n", $this->logs));
        $this->assertStringNotContainsString($senha, json_encode($this->auditoria()));
        $this->assertStringNotContainsString($senha, print_r($acesso, true), 'print_r/var_dump do objeto nao mostra a senha');
        $this->assertStringNotContainsString($senha, var_export($acesso->usuario->getAttributes(), true));

        try {
            serialize($acesso);
            $this->fail('O objeto com a senha nao pode ser serializado (cache, sessao, fila).');
        } catch (LogicException $e) {
            $this->assertStringNotContainsString($senha, $e->getMessage());
        }
    }

    public function test_criar_grava_a_auditoria_sem_dado_pessoal(): void
    {
        $dono = $this->dono();
        $acesso = $this->equipe()->criarUsuario($dono, 'Fulano de Tal', 'fulano@exemplo.com', PapelUsuario::Recepcao);

        $linhas = $this->auditoria();
        $this->assertCount(1, $linhas);
        $this->assertSame('usuario_criado', $linhas[0]->evento);
        $this->assertSame('sucesso', $linhas[0]->resultado);
        $this->assertSame($dono->id, (int) $linhas[0]->autor_id);
        $this->assertSame($acesso->usuario->id, (int) $linhas[0]->usuario_id);
        $this->assertNull($linhas[0]->email_tentado);
        $this->assertStringNotContainsString('fulano', json_encode($linhas));
    }

    /** @return array<string, array{0: string, 1: callable(self): array<string, mixed>}> */
    public static function criacoesRecusadas(): array
    {
        return [
            'papel proprietario' => ['papel_invalido', fn (self $t) => ['papel' => PapelUsuario::Proprietario]],
            'barbeiro sem profissional' => ['profissional_obrigatorio', fn (self $t) => ['papel' => PapelUsuario::Barbeiro, 'profissional' => null]],
            'recepcao com profissional' => ['profissional_so_para_barbeiro', fn (self $t) => ['papel' => PapelUsuario::Recepcao, 'profissional' => $t->profissional('Outro')]],
            'profissional inexistente' => ['profissional_inexistente', fn (self $t) => ['papel' => PapelUsuario::Barbeiro, 'profissional' => 999999]],
            'profissional inativo' => ['profissional_inativo', fn (self $t) => ['papel' => PapelUsuario::Barbeiro, 'profissional' => $t->profissional('Parado', ativo: false)]],
            'nome curto' => ['nome_invalido', fn (self $t) => ['papel' => PapelUsuario::Recepcao, 'nome' => 'A']],
            'nome longo' => ['nome_invalido', fn (self $t) => ['papel' => PapelUsuario::Recepcao, 'nome' => str_repeat('a', 121)]],
            'e-mail sem arroba' => ['email_invalido', fn (self $t) => ['papel' => PapelUsuario::Recepcao, 'email' => 'sem-arroba']],
            'e-mail enorme' => ['email_invalido', fn (self $t) => ['papel' => PapelUsuario::Recepcao, 'email' => str_repeat('a', 250).'@b.co']],
        ];
    }

    /** @param callable(self): array<string, mixed> $montar */
    #[DataProvider('criacoesRecusadas')]
    public function test_recusa_a_criacao_invalida_sem_gravar_nada(string $codigo, callable $montar): void
    {
        $dono = $this->dono();
        $p = $montar($this) + ['nome' => 'Fulano de Tal', 'email' => 'fulano@exemplo.com', 'profissional' => null];

        $this->recusa($codigo, fn () => $this->equipe()->criarUsuario($dono, $p['nome'], $p['email'], $p['papel'], $p['profissional']));

        $this->assertSame(1, DB::table('users')->count(), 'so o proprietario existe');
        $this->assertSame(0, DB::table('auditoria_acessos')->count());
    }

    public function test_email_em_uso_sem_diferenciar_maiusculas(): void
    {
        $dono = $this->dono();
        $this->equipe()->criarUsuario($dono, 'Primeiro Usuario', 'repetido@exemplo.com', PapelUsuario::Recepcao);

        $this->recusa('email_em_uso', fn () => $this->equipe()->criarUsuario($dono, 'Segundo Usuario', 'REPETIDO@Exemplo.COM', PapelUsuario::Recepcao));

        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame(1, DB::table('auditoria_acessos')->count(), 'a tentativa recusada nao e auditada como criacao');
    }

    public function test_profissional_ja_vinculado_a_outro_acesso(): void
    {
        $dono = $this->dono();
        $profissional = $this->profissional();
        $this->equipe()->criarUsuario($dono, 'Primeiro Barbeiro', 'b1@exemplo.com', PapelUsuario::Barbeiro, $profissional);

        $this->recusa('profissional_ja_vinculado', fn () => $this->equipe()->criarUsuario($dono, 'Segundo Barbeiro', 'b2@exemplo.com', PapelUsuario::Barbeiro, $profissional));

        $this->assertSame(2, DB::table('users')->count(), 'o segundo usuario nao ficou pela metade');
    }

    /** @return array<string, array{0: callable(self): User}> */
    public static function autoresSemPermissao(): array
    {
        return [
            'barbeiro' => [fn (self $t) => User::factory()->create()],
            'recepcao' => [fn (self $t) => User::factory()->state(['papel' => PapelUsuario::Recepcao])->create()],
            'proprietario inativo' => [fn (self $t) => User::factory()->proprietario()->state(['ativo' => false])->create()],
            'usuario que nem foi salvo' => [fn (self $t) => User::factory()->proprietario()->make(['id' => 424242])],
        ];
    }

    /** @param callable(self): User $montar */
    #[DataProvider('autoresSemPermissao')]
    public function test_so_proprietario_ativo_gerencia_a_equipe(callable $montar): void
    {
        $autor = $montar($this);
        $alvo = User::factory()->state(['papel' => PapelUsuario::Recepcao])->create();

        $this->recusa('sem_permissao', fn () => $this->equipe()->criarUsuario($autor, 'Fulano de Tal', 'x@exemplo.com', PapelUsuario::Recepcao));
        $this->recusa('sem_permissao', fn () => $this->equipe()->desativar($autor, $alvo->id));
        $this->recusa('sem_permissao', fn () => $this->equipe()->reativar($autor, $alvo->id));
        $this->recusa('sem_permissao', fn () => $this->equipe()->redefinirSenha($autor, $alvo->id));

        $this->assertTrue($alvo->fresh()->ativo);
        $this->assertSame(0, DB::table('auditoria_acessos')->count());
    }

    public function test_o_papel_e_relido_do_banco_nao_da_memoria(): void
    {
        $autor = $this->dono();
        $copiaEmMemoria = $autor->withoutRelations();
        DB::table('users')->where('id', $autor->id)->update(['papel' => 'recepcao']);

        $this->recusa('sem_permissao', fn () => $this->equipe()->criarUsuario($copiaEmMemoria, 'Fulano de Tal', 'x@exemplo.com', PapelUsuario::Recepcao));
    }

    // -------------------------------------------------------------- desativar

    public function test_desativar_derruba_as_sessoes_so_dele_e_troca_o_remember_token(): void
    {
        $dono = $this->dono();
        $alvo = User::factory()->create();
        $outro = User::factory()->create();
        $this->sessao($alvo->id, 'sessao-do-alvo-1');
        $this->sessao($alvo->id, 'sessao-do-alvo-2');
        $this->sessao($outro->id, 'sessao-de-outro');
        $tokenAntes = $alvo->remember_token;

        $this->equipe()->desativar($dono, $alvo->id);

        $this->assertFalse($alvo->fresh()->ativo);
        $this->assertNotSame($tokenAntes, $alvo->fresh()->remember_token, 'lembrar-me antigo morre junto');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $alvo->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $outro->id)->count());

        $linha = $this->auditoria()[0];
        $this->assertSame('usuario_desativado', $linha->evento);
        $this->assertSame($dono->id, (int) $linha->autor_id);
        $this->assertSame($alvo->id, (int) $linha->usuario_id);
    }

    public function test_usuario_desativado_nao_age_mais_como_operador(): void
    {
        $dono = $this->dono();
        $alvo = User::factory()->create();
        $this->equipe()->desativar($dono, $alvo->id);

        $this->expectException(AutoriaInvalida::class);
        TransacaoAuditada::executar(Ator::Operador, $alvo, fn () => null);
    }

    public function test_nao_desativa_a_si_mesmo_nem_outro_proprietario_nem_inexistente(): void
    {
        $dono = $this->dono();
        $outroDono = $this->dono();

        $this->recusa('alvo_protegido', fn () => $this->equipe()->desativar($dono, $dono->id));
        $this->recusa('alvo_protegido', fn () => $this->equipe()->desativar($dono, $outroDono->id));
        $this->recusa('usuario_nao_encontrado', fn () => $this->equipe()->desativar($dono, 999999));

        $this->assertTrue($dono->fresh()->ativo);
        $this->assertTrue($outroDono->fresh()->ativo);
    }

    public function test_desativar_quem_ja_esta_inativo_e_recusado(): void
    {
        $dono = $this->dono();
        $alvo = User::factory()->state(['ativo' => false])->create();

        $this->recusa('ja_inativo', fn () => $this->equipe()->desativar($dono, $alvo->id));
        $this->assertSame(0, DB::table('auditoria_acessos')->count());
    }

    // --------------------------------------------------------------- reativar

    public function test_reativar_exige_senha_nova_temporaria(): void
    {
        $dono = $this->dono();
        $alvo = User::factory()->state(['ativo' => false, 'password' => 'SenhaAntiga123456'])->create();
        $this->sessao($alvo->id, 'sessao-velha');

        $acesso = $this->equipe()->reativar($dono, $alvo->id);

        $depois = $alvo->fresh();
        $this->assertTrue($depois->ativo);
        $this->assertTrue((bool) $depois->senha_temporaria);
        $this->assertFalse(Hash::check('SenhaAntiga123456', $depois->password), 'a senha antiga nao volta');
        $this->assertTrue(Hash::check($acesso->senhaTemporaria, $depois->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $alvo->id)->count());
        $this->assertSame('usuario_reativado', $this->auditoria()[0]->evento);
    }

    public function test_reativar_quem_ja_esta_ativo_e_recusado(): void
    {
        $dono = $this->dono();
        $alvo = User::factory()->create();

        $this->recusa('ja_ativo', fn () => $this->equipe()->reativar($dono, $alvo->id));
    }

    public function test_reativar_nao_vale_para_proprietario(): void
    {
        $dono = $this->dono();
        $outroDono = User::factory()->proprietario()->state(['ativo' => false])->create();

        $this->recusa('alvo_protegido', fn () => $this->equipe()->reativar($dono, $outroDono->id));
        $this->assertFalse($outroDono->fresh()->ativo);
    }

    // ------------------------------------------------------ redefinir a senha

    public function test_redefinir_senha_gera_temporaria_derruba_sessoes_e_audita(): void
    {
        $dono = $this->dono();
        $alvo = User::factory()->state(['password' => 'SenhaAntiga123456', 'senha_temporaria' => false])->create();
        $this->sessao($alvo->id, 'sessao-1');

        $acesso = $this->equipe()->redefinirSenha($dono, $alvo->id);

        $depois = $alvo->fresh();
        $this->assertTrue((bool) $depois->senha_temporaria);
        $this->assertFalse(Hash::check('SenhaAntiga123456', $depois->password));
        $this->assertTrue(Hash::check($acesso->senhaTemporaria, $depois->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $alvo->id)->count());
        $this->assertTrue($depois->ativo, 'redefinir nao desativa');

        $linha = $this->auditoria()[0];
        $this->assertSame('senha_redefinida', $linha->evento);
        $this->assertSame($dono->id, (int) $linha->autor_id);
        $this->assertStringNotContainsString($acesso->senhaTemporaria, json_encode($this->auditoria()));
    }

    public function test_redefinir_nao_vale_para_proprietario_nem_inexistente(): void
    {
        $dono = $this->dono();
        $outroDono = $this->dono();

        $this->recusa('alvo_protegido', fn () => $this->equipe()->redefinirSenha($dono, $dono->id));
        $this->recusa('alvo_protegido', fn () => $this->equipe()->redefinirSenha($dono, $outroDono->id));
        $this->recusa('usuario_nao_encontrado', fn () => $this->equipe()->redefinirSenha($dono, 999999));
    }

    public function test_nenhuma_mensagem_de_recusa_e_um_erro_cru(): void
    {
        $dono = $this->dono();

        $e = $this->recusa('profissional_inexistente', fn () => $this->equipe()->criarUsuario($dono, 'Fulano de Tal', 'x@exemplo.com', PapelUsuario::Barbeiro, 999999));

        $this->assertMatchesRegularExpression('/^[A-ZÀ-Ú]/u', $e->getMessage());
        $this->assertStringNotContainsString('SQL', $e->getMessage());
        $this->assertStringNotContainsString('999999', $e->getMessage());
    }
}
