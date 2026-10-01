<?php

namespace Tests\Feature\Painel;

use App\Enums\PapelUsuario;
use App\Models\Agendamento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * Matriz de autorizacao do painel (etapa 3, Fase 2): papel x acao x reserva
 * propria ou de OUTRO profissional (IDOR). O barbeiro so enxerga e age nas
 * reservas do proprio profissional; proprietario e recepcao, em todas;
 * usuario inativo, em nenhuma. A policy decide por QUEM e a reserva; o ESTADO
 * da reserva e do dominio (ReservarHorario).
 */
class PoliticasTest extends TestCase
{
    use BancoDeTeste;

    private const ACOES_DA_RESERVA = ['ver', 'confirmar', 'recusar', 'iniciar', 'concluir', 'faltou', 'cancelar'];

    private int $profissionalA;

    private int $profissionalB;

    private User $barbeiroA;

    private User $barbeiroB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->barbeiroA = User::factory()->create();
        $this->barbeiroB = User::factory()->create();
        $this->profissionalA = (int) DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Profissional A', 'user_id' => $this->barbeiroA->id]);
        $this->profissionalB = (int) DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Profissional B', 'user_id' => $this->barbeiroB->id]);
    }

    private function reservaDe(int $profissionalId): Agendamento
    {
        return (new Agendamento)->forceFill(['id' => 1, 'profissional_id' => $profissionalId]);
    }

    private function pode(User $usuario, string $acao, Agendamento $reserva): bool
    {
        return Gate::forUser($usuario)->allows($acao, $reserva);
    }

    /** @return array<string, array{0: string}> */
    public static function acoes(): array
    {
        return array_combine(self::ACOES_DA_RESERVA, array_map(fn ($a) => [$a], self::ACOES_DA_RESERVA));
    }

    #[DataProvider('acoes')]
    public function test_proprietario_e_recepcao_agem_em_qualquer_reserva(string $acao): void
    {
        $dono = User::factory()->proprietario()->create();
        $recepcao = User::factory()->state(['papel' => PapelUsuario::Recepcao])->create();

        foreach ([$dono, $recepcao] as $usuario) {
            $this->assertTrue($this->pode($usuario, $acao, $this->reservaDe($this->profissionalA)), "{$usuario->papel->value} deveria poder {$acao} (A)");
            $this->assertTrue($this->pode($usuario, $acao, $this->reservaDe($this->profissionalB)), "{$usuario->papel->value} deveria poder {$acao} (B)");
        }
    }

    #[DataProvider('acoes')]
    public function test_barbeiro_age_so_nas_reservas_do_proprio_profissional(string $acao): void
    {
        $this->assertTrue($this->pode($this->barbeiroA, $acao, $this->reservaDe($this->profissionalA)), "A deveria poder {$acao} na propria");
        $this->assertFalse($this->pode($this->barbeiroA, $acao, $this->reservaDe($this->profissionalB)), "A NAO pode {$acao} na de B (IDOR)");
        $this->assertTrue($this->pode($this->barbeiroB, $acao, $this->reservaDe($this->profissionalB)));
        $this->assertFalse($this->pode($this->barbeiroB, $acao, $this->reservaDe($this->profissionalA)), "B NAO pode {$acao} na de A (IDOR)");
    }

    #[DataProvider('acoes')]
    public function test_barbeiro_sem_profissional_vinculado_nao_age_em_nada(string $acao): void
    {
        $solto = User::factory()->create();

        $this->assertFalse($this->pode($solto, $acao, $this->reservaDe($this->profissionalA)));
        $this->assertFalse($this->pode($solto, $acao, $this->reservaDe($this->profissionalB)));
    }

    #[DataProvider('acoes')]
    public function test_reserva_sem_profissional_conhecido_so_para_proprietario_e_recepcao(string $acao): void
    {
        $this->assertFalse($this->pode($this->barbeiroA, $acao, $this->reservaDe(999999)));
        $this->assertTrue($this->pode(User::factory()->proprietario()->create(), $acao, $this->reservaDe(999999)));
    }

    #[DataProvider('acoes')]
    public function test_usuario_inativo_nao_age_em_nada_qualquer_que_seja_o_papel(string $acao): void
    {
        $inativos = [
            User::factory()->proprietario()->state(['ativo' => false])->create(),
            User::factory()->state(['papel' => PapelUsuario::Recepcao, 'ativo' => false])->create(),
        ];
        DB::table('users')->where('id', $this->barbeiroA->id)->update(['ativo' => false]);
        $inativos[] = $this->barbeiroA->fresh();

        foreach ($inativos as $usuario) {
            $this->assertFalse($this->pode($usuario, $acao, $this->reservaDe($this->profissionalA)), "{$usuario->papel->value} inativo nao pode {$acao}");
        }
    }

    public function test_a_acao_desconhecida_e_negada_para_todos(): void
    {
        $dono = User::factory()->proprietario()->create();

        $this->assertFalse(Gate::forUser($dono)->allows('apagar', $this->reservaDe($this->profissionalA)));
    }

    // -------------------------------------------------- gestao de usuarios

    public function test_so_proprietario_ativo_gere_a_equipe(): void
    {
        $dono = User::factory()->proprietario()->create();
        $donoInativo = User::factory()->proprietario()->state(['ativo' => false])->create();
        $recepcao = User::factory()->state(['papel' => PapelUsuario::Recepcao])->create();

        $this->assertTrue(Gate::forUser($dono)->allows('gerirEquipe', User::class));
        $this->assertFalse(Gate::forUser($donoInativo)->allows('gerirEquipe', User::class));
        $this->assertFalse(Gate::forUser($recepcao)->allows('gerirEquipe', User::class));
        $this->assertFalse(Gate::forUser($this->barbeiroA)->allows('gerirEquipe', User::class));
    }

    public function test_gerir_um_usuario_exige_proprietario_e_alvo_que_nao_seja_proprietario(): void
    {
        $dono = User::factory()->proprietario()->create();
        $outroDono = User::factory()->proprietario()->create();
        $recepcao = User::factory()->state(['papel' => PapelUsuario::Recepcao])->create();

        $this->assertTrue(Gate::forUser($dono)->allows('gerir', $this->barbeiroA));
        $this->assertTrue(Gate::forUser($dono)->allows('gerir', $recepcao));
        $this->assertFalse(Gate::forUser($dono)->allows('gerir', $outroDono), 'proprietario nao e alvo');
        $this->assertFalse(Gate::forUser($dono)->allows('gerir', $dono), 'nem o proprio autor');

        $this->assertFalse(Gate::forUser($this->barbeiroA)->allows('gerir', $this->barbeiroB), 'barbeiro nao gere ninguem');
        $this->assertFalse(Gate::forUser($recepcao)->allows('gerir', $this->barbeiroA), 'recepcao nao gere ninguem');
    }

    public function test_gerir_usuarios_tambem_e_negado_a_quem_nao_esta_logado(): void
    {
        $this->assertFalse(Gate::allows('gerirEquipe', User::class));
        $this->assertFalse(Gate::allows('ver', $this->reservaDe($this->profissionalA)));
    }
}
