<?php

namespace App\Console\Commands;

use App\Enums\PapelUsuario;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Bootstrap do PRIMEIRO acesso administrativo.
 *
 * Nao existe usuario nem senha padrao no projeto. Quem instala roda este
 * comando no servidor (acesso ao shell = ja e de confianca) e:
 *   - digita a senha (oculta, duas vezes), ou
 *   - usa --gerar-senha: a senha aleatoria aparece UMA vez e nao e gravada
 *     em lugar nenhum alem do hash.
 *
 * So cria o primeiro proprietario. Os demais usuarios serao criados pelo
 * painel (etapa 3), que tambem trara MFA e recuperacao de acesso.
 */
class CriarProprietario extends Command
{
    protected $signature = 'cleison:criar-proprietario
        {email : E-mail de login}
        {--nome= : Nome exibido no painel}
        {--gerar-senha : Gera uma senha aleatoria forte e mostra uma unica vez}';

    protected $description = 'Cria o primeiro usuario proprietario (sem senha padrao).';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $nome = trim((string) ($this->option('nome') ?? ''));

        if ($nome === '') {
            if (! $this->input->isInteractive()) {
                $this->error('Informe --nome quando rodar sem interacao.');

                return self::FAILURE;
            }
            $nome = trim((string) $this->ask('Nome exibido no painel'));
        }

        $gerada = (bool) $this->option('gerar-senha');
        if ($gerada) {
            // Sem simbolos: "<" e ">" seriam lidos como tag pelo console e a
            // senha exibida sairia diferente da gravada. 24 alfanumericos
            // ~ 143 bits de entropia.
            $senha = Str::password(24, symbols: false);
        } else {
            if (! $this->input->isInteractive()) {
                $this->error('Sem interacao, use --gerar-senha. Senha nao e aceita por argumento (ficaria no historico do shell).');

                return self::FAILURE;
            }
            $senha = (string) $this->secret('Senha (minimo 12 caracteres, com letras e numeros)');
            if ($senha !== (string) $this->secret('Repita a senha')) {
                $this->error('As senhas nao conferem.');

                return self::FAILURE;
            }
        }

        $validacao = Validator::make(
            ['email' => $email, 'nome' => $nome, 'senha' => $senha],
            [
                'email' => ['required', 'email:rfc', 'max:254'],
                'nome' => ['required', 'string', 'min:2', 'max:120'],
                'senha' => ['required', Password::min(12)->letters()->numbers()],
            ]
        );

        if ($validacao->fails()) {
            foreach ($validacao->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $resultado = DB::transaction(function () use ($email, $nome, $senha) {
            // Dois bootstraps simultaneos nao criam dois proprietarios.
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('cleison:criar-proprietario'))");

            if (User::query()->where('papel', PapelUsuario::Proprietario)->exists()) {
                return 'ja-existe';
            }
            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                return 'email-em-uso';
            }

            return User::query()->create([
                'name' => $nome,
                'email' => $email,
                'password' => $senha, // cast "hashed": grava so o hash
                'papel' => PapelUsuario::Proprietario,
                'ativo' => true,
            ]);
        });

        if ($resultado === 'ja-existe') {
            $this->error('Ja existe um proprietario. Novos usuarios serao criados pelo painel administrativo.');

            return self::FAILURE;
        }
        if ($resultado === 'email-em-uso') {
            $this->error('Esse e-mail ja esta em uso.');

            return self::FAILURE;
        }

        $this->info("Proprietario criado (id {$resultado->id}, {$resultado->email}).");

        if ($gerada) {
            $this->newLine();
            $this->warn('Senha gerada (aparece so agora, guarde num gerenciador de senhas):');
            $this->line('  '.OutputFormatter::escape($senha));
        }

        return self::SUCCESS;
    }
}
