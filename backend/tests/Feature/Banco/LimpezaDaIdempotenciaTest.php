<?php

namespace Tests\Feature\Banco;

use App\Domain\Agenda\ReservarHorario;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * Fase 6 (docs/ESPEC-RESERVA.md, secao 4; LGPD D6): uma rotina diaria anula
 * chave_idempotencia e hash_requisicao (JUNTOS) de agendamentos criados ha
 * mais de 7 dias, em qualquer estado. Unico caminho:
 * cleison_limpar_idempotencia(), SECURITY DEFINER do dono. O trigger de
 * imutabilidade do encerrado ganha uma excecao estreita (dono + so essas
 * duas colunas indo a NULL); a prova com o dono esta no
 * LimpezaDaIdempotenciaPeloDonoTest. Aqui: o papel da aplicacao, como a
 * aplicacao roda. O "ha mais de 7 dias" e o now() do banco.
 */
class LimpezaDaIdempotenciaTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    private int $profissional;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profissional = $this->novoProfissional();
    }

    /** Agendamento com chave e hash, criado ha $dias dias, no estado pedido (por transicoes validas). */
    private function comChave(float $dias, string $estado, string $hora): int
    {
        $id = $this->novoAgendamento($hora, $this->mais30($hora), [
            'profissional_id' => $this->profissional,
            'estado' => 'confirmado',
            'chave_idempotencia' => 'chave-'.bin2hex(random_bytes(8)),
            'hash_requisicao' => bin2hex(random_bytes(32)),
            'observacao_cliente' => 'chego cedo',
            'created_at' => CarbonImmutable::now()->subSeconds((int) ($dias * 86400)),
        ]);
        $mudanca = match ($estado) {
            'confirmado' => null,
            'cancelado' => ['estado' => 'cancelado', 'cancelado_em' => now(), 'motivo_cancelamento' => 'motivo'],
            'concluido', 'nao_compareceu' => ['estado' => $estado],
        };
        if ($mudanca !== null) {
            DB::table('agendamentos')->where('id', $id)->update($mudanca);
        }

        return $id;
    }

    private function mais30(string $hora): string
    {
        return CarbonImmutable::createFromFormat('H:i', $hora)->addMinutes(30)->format('H:i');
    }

    /** @return array<string, mixed> a linha, sem as colunas que a limpeza pode mudar */
    private function resto(int $id): array
    {
        return (array) DB::selectOne(
            "SELECT to_jsonb(a) - 'updated_at' - 'chave_idempotencia' - 'hash_requisicao' AS linha FROM agendamentos a WHERE id = ?",
            [$id],
        );
    }

    private function limpar(): int
    {
        return (int) DB::scalar('SELECT public.cleison_limpar_idempotencia()');
    }

    public function test_anula_chave_e_hash_juntos_dos_criados_ha_mais_de_7_dias_em_qualquer_estado(): void
    {
        $ids = [
            $this->comChave(8, 'confirmado', '08:00'),
            $this->comChave(8, 'cancelado', '09:00'),
            $this->comChave(30, 'concluido', '10:00'),
            $this->comChave(7.01, 'nao_compareceu', '11:00'),
        ];
        $antes = array_map($this->resto(...), $ids);
        $eventos = DB::table('agendamento_eventos')->count();

        $this->assertSame(4, $this->limpar());

        foreach ($ids as $i => $id) {
            $linha = DB::table('agendamentos')->where('id', $id)->first();
            $this->assertNull($linha->chave_idempotencia);
            $this->assertNull($linha->hash_requisicao);
            $this->assertSame($antes[$i], $this->resto($id), 'nenhuma outra coluna muda');
        }
        $this->assertSame($eventos, DB::table('agendamento_eventos')->count(), 'limpeza nao e evento do historico');
    }

    public function test_nao_toca_os_criados_ha_7_dias_ou_menos_nem_quem_ja_esta_sem_chave(): void
    {
        $recente = $this->comChave(6.99, 'cancelado', '08:00');
        $semChave = $this->novoAgendamento('09:00', '09:30', [
            'profissional_id' => $this->profissional,
            'created_at' => CarbonImmutable::now()->subDays(30),
        ]);

        $this->assertSame(0, $this->limpar());

        $this->assertNotNull(DB::table('agendamentos')->where('id', $recente)->value('chave_idempotencia'));
        $this->assertNull(DB::table('agendamentos')->where('id', $semChave)->value('chave_idempotencia'));
    }

    public function test_rodar_de_novo_nao_muda_nada(): void
    {
        $this->comChave(8, 'cancelado', '08:00');

        $this->assertSame(1, $this->limpar());
        $this->assertSame(0, $this->limpar());
    }

    public function test_a_aplicacao_continua_sem_poder_mexer_na_chave_do_encerrado(): void
    {
        $id = $this->comChave(8, 'cancelado', '08:00');

        $this->assertBancoRecusa('agendamentos_encerrado_imutavel', fn () => DB::table('agendamentos')->where('id', $id)
            ->update(['chave_idempotencia' => null, 'hash_requisicao' => null]));
    }

    public function test_funcao_e_do_dono_com_search_path_fixo_e_so_a_aplicacao_executa(): void
    {
        $funcao = DB::selectOne(
            "SELECT p.prosecdef, p.proconfig, pg_get_userbyid(p.proowner) AS dono
               FROM pg_proc p WHERE p.oid = 'public.cleison_limpar_idempotencia()'::regprocedure"
        );
        $this->assertTrue((bool) $funcao->prosecdef, 'SECURITY DEFINER');
        $this->assertStringContainsString('search_path=pg_catalog, public, pg_temp', (string) $funcao->proconfig);
        $this->assertSame(config('database.connections.pgsql_migracao.username'), $funcao->dono);

        $this->assertFalse((bool) DB::scalar(
            "SELECT has_function_privilege('public', 'public.cleison_limpar_idempotencia()', 'EXECUTE')"
        ), 'PUBLIC nao executa');
        $this->assertTrue((bool) DB::scalar(
            "SELECT has_function_privilege(current_user, 'public.cleison_limpar_idempotencia()', 'EXECUTE')"
        ), 'o papel da aplicacao executa');
    }

    public function test_dominio_e_comando_limpam_e_informam_so_a_contagem(): void
    {
        $this->comChave(8, 'cancelado', '08:00');
        $this->comChave(9, 'confirmado', '09:00');

        $this->assertSame(2, $this->app->make(ReservarHorario::class)->limparIdempotencia());

        $this->comChave(10, 'concluido', '10:00');
        $this->artisan('cleison:limpar-idempotencia')
            ->expectsOutput('Idempotencia limpa em 1 agendamento(s).')
            ->assertSuccessful();
    }

    public function test_comando_roda_todo_dia_sem_sobreposicao(): void
    {
        $evento = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'cleison:limpar-idempotencia'));

        $this->assertNotNull($evento);
        $this->assertSame('45 3 * * *', $evento->expression);
        $this->assertSame('America/Sao_Paulo', $evento->timezone);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame(60, $evento->expiresAt);
    }
}
