<?php

namespace Tests\Feature;

use App\Enums\Ator;
use App\Models\User;
use App\Support\AutoriaInvalida;
use App\Support\TransacaoAuditada;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

class TransacaoAuditadaTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    private function eventoCriado(int $agendamento): object
    {
        return DB::table('agendamento_eventos')->where('agendamento_id', $agendamento)->where('tipo', 'criado')->sole();
    }

    private function atorAtual(): array
    {
        $linha = DB::selectOne("SELECT current_setting('cleison.ator', true) AS ator, current_setting('cleison.usuario_id', true) AS usuario");

        return [(string) $linha->ator, (string) $linha->usuario];
    }

    public function test_operador_ativo_fica_registrado_no_historico(): void
    {
        $operador = User::factory()->create();

        $id = TransacaoAuditada::executar(Ator::Operador, $operador, fn () => $this->novoAgendamento('10:00', '10:30'));

        $evento = $this->eventoCriado($id);
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($operador->id, (int) $evento->usuario_id);
    }

    public function test_cliente_e_sistema_ficam_registrados_sem_usuario(): void
    {
        $doCliente = TransacaoAuditada::executar(Ator::Cliente, null, fn () => $this->novoAgendamento('10:00', '10:30'));
        $doSistema = TransacaoAuditada::executar(Ator::Sistema, null, fn () => $this->novoAgendamento('11:00', '11:30'));

        $this->assertSame('cliente', $this->eventoCriado($doCliente)->ator);
        $this->assertNull($this->eventoCriado($doCliente)->usuario_id);
        $this->assertSame('sistema', $this->eventoCriado($doSistema)->ator);
    }

    public function test_operador_sem_usuario_falha_sem_gravar(): void
    {
        try {
            TransacaoAuditada::executar(Ator::Operador, null, fn () => $this->novoAgendamento('10:00', '10:30'));
            $this->fail('Operador sem usuario foi aceito.');
        } catch (AutoriaInvalida $e) {
            $this->assertStringContainsString('operador', $e->getMessage());
        }

        $this->assertSame(0, DB::table('agendamentos')->count());
    }

    public function test_operador_inativo_falha_sem_gravar(): void
    {
        $inativo = User::factory()->create();
        $inativo->forceFill(['ativo' => false])->save();

        try {
            TransacaoAuditada::executar(Ator::Operador, $inativo, fn () => $this->novoAgendamento('10:00', '10:30'));
            $this->fail('Operador inativo foi aceito.');
        } catch (AutoriaInvalida) {
            $this->assertSame(0, DB::table('agendamentos')->count());
        }
    }

    public function test_operador_desativado_depois_de_carregado_e_recusado(): void
    {
        $operador = User::factory()->create();
        DB::table('users')->where('id', $operador->id)->update(['ativo' => false]); // o model em memoria ainda diz "ativo"

        $this->expectException(AutoriaInvalida::class);
        TransacaoAuditada::executar(Ator::Operador, $operador, fn () => null);
    }

    public function test_operador_nao_salvo_e_recusado(): void
    {
        $this->expectException(AutoriaInvalida::class);
        TransacaoAuditada::executar(Ator::Operador, User::factory()->make(), fn () => null);
    }

    public function test_cliente_ou_sistema_com_usuario_e_incoerente(): void
    {
        $usuario = User::factory()->create();

        foreach ([Ator::Cliente, Ator::Sistema] as $ator) {
            try {
                TransacaoAuditada::executar($ator, $usuario, fn () => null);
                $this->fail("{$ator->value} com usuario foi aceito.");
            } catch (AutoriaInvalida) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_erro_dentro_desfaz_tudo_e_nao_vaza_o_ator(): void
    {
        $operador = User::factory()->create();

        try {
            TransacaoAuditada::executar(Ator::Operador, $operador, function () {
                $this->novoAgendamento('10:00', '10:30');
                throw new RuntimeException('falhou no meio');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('agendamentos')->count());
        $this->assertSame(['', ''], $this->atorAtual());
    }

    public function test_aninhada_usa_o_ator_de_dentro_e_restaura_o_de_fora(): void
    {
        $operador = User::factory()->create();

        [$externo, $interno, $depois] = TransacaoAuditada::executar(Ator::Sistema, null, function () use ($operador) {
            $externo = $this->novoAgendamento('09:00', '09:30');
            $interno = TransacaoAuditada::executar(Ator::Operador, $operador, fn () => $this->novoAgendamento('10:00', '10:30'));
            $depois = $this->novoAgendamento('11:00', '11:30');

            return [$externo, $interno, $depois];
        });

        $this->assertSame('sistema', $this->eventoCriado($externo)->ator);
        $this->assertSame('operador', $this->eventoCriado($interno)->ator);
        $this->assertSame('sistema', $this->eventoCriado($depois)->ator);
        $this->assertNull($this->eventoCriado($depois)->usuario_id);
    }

    /** Um unico ponto de entrada define a autoria: nada mais em app/ usa o set_config do ator. */
    public function test_so_transacao_auditada_define_o_ator(): void
    {
        $arquivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        $usos = [];
        foreach ($arquivos as $arquivo) {
            if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), '.php')
                && str_contains((string) file_get_contents($arquivo->getPathname()), 'cleison.ator')) {
                $usos[] = str_replace('\\', '/', substr($arquivo->getPathname(), strlen(app_path()) + 1));
            }
        }

        $this->assertSame(['Support/TransacaoAuditada.php'], $usos);
    }
}
