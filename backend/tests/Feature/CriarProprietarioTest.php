<?php

namespace Tests\Feature;

use App\Enums\PapelUsuario;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

class CriarProprietarioTest extends TestCase
{
    use BancoDeTeste;

    private const PERGUNTA_SENHA = 'Senha (minimo 12 caracteres, com letras e numeros)';

    public function test_senha_gerada_aparece_uma_vez_e_so_o_hash_e_gravado(): void
    {
        $codigo = Artisan::call('cleison:criar-proprietario', [
            'email' => 'Dono@Exemplo.com', '--nome' => 'Dono da Barbearia',
            '--gerar-senha' => true, '--no-interaction' => true,
        ]);
        $saida = Artisan::output();

        $this->assertSame(0, $codigo, $saida);
        $this->assertSame(1, preg_match('/^\s*([A-Za-z0-9]{24})\s*$/m', $saida, $m), $saida);
        $senha = $m[1];

        $usuario = User::query()->sole();
        $this->assertSame('dono@exemplo.com', $usuario->email);
        $this->assertSame(PapelUsuario::Proprietario, $usuario->papel);
        $this->assertTrue(Hash::check($senha, $usuario->password));
        $this->assertStringNotContainsString($senha, json_encode(DB::table('users')->get()));
    }

    public function test_senha_digitada_com_confirmacao(): void
    {
        $this->artisan('cleison:criar-proprietario', ['email' => 'dono@exemplo.com', '--nome' => 'Dono'])
            ->expectsQuestion(self::PERGUNTA_SENHA, 'Navalha2026segura')
            ->expectsQuestion('Repita a senha', 'Navalha2026segura')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('Navalha2026segura', User::query()->sole()->password));
    }

    public function test_recusa_senha_fraca(): void
    {
        $this->artisan('cleison:criar-proprietario', ['email' => 'dono@exemplo.com', '--nome' => 'Dono'])
            ->expectsQuestion(self::PERGUNTA_SENHA, '1234')
            ->expectsQuestion('Repita a senha', '1234')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_recusa_confirmacao_diferente(): void
    {
        $this->artisan('cleison:criar-proprietario', ['email' => 'dono@exemplo.com', '--nome' => 'Dono'])
            ->expectsQuestion(self::PERGUNTA_SENHA, 'Navalha2026segura')
            ->expectsQuestion('Repita a senha', 'Navalha2026outra')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_sem_interacao_nao_aceita_senha_digitada(): void
    {
        $this->artisan('cleison:criar-proprietario', [
            'email' => 'dono@exemplo.com', '--nome' => 'Dono', '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_so_cria_o_primeiro_proprietario(): void
    {
        User::factory()->proprietario()->create();

        $this->artisan('cleison:criar-proprietario', [
            'email' => 'outro@exemplo.com', '--nome' => 'Outro', '--gerar-senha' => true, '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_recusa_email_invalido(): void
    {
        $this->artisan('cleison:criar-proprietario', [
            'email' => 'nao-e-email', '--nome' => 'Dono', '--gerar-senha' => true, '--no-interaction' => true,
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
