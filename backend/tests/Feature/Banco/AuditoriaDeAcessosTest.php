<?php

namespace Tests\Feature\Banco;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * Migration 2026_10_01_000100: users.senha_temporaria, users.ultimo_acesso_em
 * e a tabela auditoria_acessos (so insercao; sem senha, sem IP).
 */
class AuditoriaDeAcessosTest extends TestCase
{
    use BancoDeTeste;

    private function usuario(string $email = 'dono@exemplo.com'): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Usuario de Teste',
            'email' => $email,
            'password' => 'x',
            'papel' => 'barbeiro',
        ]);
    }

    /** @param array<string, mixed> $linha */
    private function registrar(array $linha): int
    {
        return (int) DB::table('auditoria_acessos')->insertGetId($linha + ['resultado' => 'sucesso']);
    }

    public function test_users_ganha_senha_temporaria_e_ultimo_acesso(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['senha_temporaria', 'ultimo_acesso_em']));

        $id = $this->usuario();
        $linha = DB::table('users')->where('id', $id)->first();

        $this->assertFalse((bool) $linha->senha_temporaria, 'padrao: senha definitiva');
        $this->assertNull($linha->ultimo_acesso_em);
    }

    public function test_a_tabela_nao_tem_coluna_de_ip_nem_de_senha(): void
    {
        $colunas = Schema::getColumnListing('auditoria_acessos');

        $this->assertEqualsCanonicalizing(
            ['id', 'usuario_id', 'autor_id', 'evento', 'resultado', 'email_tentado', 'ocorrido_em'],
            $colunas,
        );
    }

    public function test_aceita_os_registros_validos(): void
    {
        $alvo = $this->usuario();
        $autor = $this->usuario('autor@exemplo.com');

        $this->registrar(['evento' => 'login', 'resultado' => 'sucesso', 'usuario_id' => $alvo]);
        $this->registrar(['evento' => 'login', 'resultado' => 'falha', 'usuario_id' => $alvo]);
        $this->registrar(['evento' => 'login', 'resultado' => 'bloqueado', 'usuario_id' => $alvo]);
        $this->registrar(['evento' => 'login', 'resultado' => 'falha', 'email_tentado' => 'ninguem@exemplo.com']);
        $this->registrar(['evento' => 'login', 'resultado' => 'falha']); // e-mail descartado pela aplicacao
        $this->registrar(['evento' => 'logout', 'resultado' => 'logout', 'usuario_id' => $alvo]);
        $this->registrar(['evento' => 'senha_trocada', 'usuario_id' => $alvo]);
        foreach (['usuario_criado', 'usuario_desativado', 'usuario_reativado', 'senha_redefinida'] as $evento) {
            $this->registrar(['evento' => $evento, 'usuario_id' => $alvo, 'autor_id' => $autor]);
        }

        $this->assertSame(11, DB::table('auditoria_acessos')->count());
        $this->assertNotNull(DB::table('auditoria_acessos')->value('ocorrido_em'), 'a data e hora vem do banco');
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function registrosInvalidos(): array
    {
        return [
            'evento desconhecido' => ['auditoria_acessos_evento', ['evento' => 'invadiu']],
            'resultado desconhecido' => ['auditoria_acessos_resultado', ['evento' => 'login', 'resultado' => 'talvez']],
            'logout com resultado sucesso' => ['auditoria_acessos_logout_coerente', ['evento' => 'logout', 'resultado' => 'sucesso', 'usuario_id' => 'ALVO']],
            'login com resultado logout' => ['auditoria_acessos_logout_coerente', ['evento' => 'login', 'resultado' => 'logout', 'usuario_id' => 'ALVO']],
            'falha que nao e de login' => ['auditoria_acessos_so_login_falha', ['evento' => 'senha_trocada', 'resultado' => 'falha', 'usuario_id' => 'ALVO']],
            'gestao sem autor' => ['auditoria_acessos_gestao_identificada', ['evento' => 'usuario_criado', 'usuario_id' => 'ALVO']],
            'gestao sem alvo' => ['auditoria_acessos_gestao_identificada', ['evento' => 'usuario_criado', 'autor_id' => 'ALVO']],
            'acesso com autor' => ['auditoria_acessos_acesso_sem_autor', ['evento' => 'login', 'usuario_id' => 'ALVO', 'autor_id' => 'ALVO']],
            'e-mail em login com sucesso' => ['auditoria_acessos_email_so_na_falha', ['evento' => 'login', 'resultado' => 'sucesso', 'usuario_id' => 'ALVO', 'email_tentado' => 'a@exemplo.com']],
            'e-mail junto com usuario' => ['auditoria_acessos_email_so_na_falha', ['evento' => 'login', 'resultado' => 'falha', 'usuario_id' => 'ALVO', 'email_tentado' => 'a@exemplo.com']],
            'texto que nao e e-mail (senha digitada no campo)' => ['auditoria_acessos_email_so_na_falha', ['evento' => 'login', 'resultado' => 'falha', 'email_tentado' => 'MinhaSenhaSecreta123']],
            'e-mail fora do formato normalizado' => ['auditoria_acessos_email_so_na_falha', ['evento' => 'login', 'resultado' => 'falha', 'email_tentado' => 'Fulano@Exemplo.com']],
            'logout sem usuario' => ['auditoria_acessos_sucesso_identificado', ['evento' => 'logout', 'resultado' => 'logout']],
            'login com sucesso sem usuario' => ['auditoria_acessos_login_sucesso_identificado', ['evento' => 'login', 'resultado' => 'sucesso']],
        ];
    }

    /** @param array<string, mixed> $linha */
    #[DataProvider('registrosInvalidos')]
    public function test_o_banco_recusa_o_registro_invalido(string $regra, array $linha): void
    {
        $alvo = $this->usuario();
        $linha = array_map(fn ($v) => $v === 'ALVO' ? $alvo : $v, $linha);

        $this->assertBancoRecusa($regra, fn () => $this->registrar($linha));
    }

    public function test_a_aplicacao_nao_altera_nem_apaga_nem_trunca(): void
    {
        $alvo = $this->usuario();
        $id = $this->registrar(['evento' => 'login', 'usuario_id' => $alvo]);

        foreach ([
            'UPDATE public.auditoria_acessos SET resultado = \'falha\' WHERE id = '.$id,
            'DELETE FROM public.auditoria_acessos WHERE id = '.$id,
            'TRUNCATE public.auditoria_acessos',
        ] as $sql) {
            DB::statement('SAVEPOINT tentativa');
            try {
                DB::statement($sql);
                $this->fail("O papel da aplicacao nao deveria conseguir: {$sql}");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0], 'insufficient_privilege: '.$sql);
            } finally {
                DB::statement('ROLLBACK TO SAVEPOINT tentativa');
            }
        }

        $this->assertSame(1, DB::table('auditoria_acessos')->count());
    }

    public function test_nem_o_dono_altera_ou_apaga_o_registro(): void
    {
        $dono = $this->conexaoDono();
        $dono->beginTransaction();

        try {
            $usuario = $dono->table('users')->insertGetId([
                'name' => 'Dono do Teste', 'email' => 'dono-trigger@exemplo.com', 'password' => 'x', 'papel' => 'barbeiro',
            ]);
            $id = $dono->table('auditoria_acessos')->insertGetId([
                'evento' => 'login', 'resultado' => 'sucesso', 'usuario_id' => $usuario,
            ]);

            foreach (['UPDATE public.auditoria_acessos SET resultado = \'falha\' WHERE id = ?', 'DELETE FROM public.auditoria_acessos WHERE id = ?'] as $sql) {
                $dono->statement('SAVEPOINT tentativa');
                try {
                    $dono->statement($sql, [$id]);
                    $this->fail("O trigger deveria recusar: {$sql}");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('auditoria_acessos_somente_insercao', $e->getMessage());
                    $this->assertSame('23514', $e->errorInfo[0]);
                } finally {
                    $dono->statement('ROLLBACK TO SAVEPOINT tentativa');
                }
            }
        } finally {
            $dono->rollBack();
        }
    }

    public function test_o_usuario_com_auditoria_nao_pode_ser_apagado(): void
    {
        $alvo = $this->usuario();
        $this->registrar(['evento' => 'login', 'usuario_id' => $alvo]);

        $this->assertBancoRecusa('auditoria_acessos_usuario_id_fkey', fn () => DB::table('users')->where('id', $alvo)->delete());
    }
}
