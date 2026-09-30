<?php

namespace Tests\Feature\Banco;

use App\Enums\EstadoAgendamento;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * O contrato de estados e aplicado pelo banco (trigger) e espelhado no
 * enum. Aqui os dois sao confrontados em TODOS os pares.
 */
class ContratoDeEstadosTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    private function agendamentoEm(EstadoAgendamento $estado): int
    {
        $nasce = in_array($estado, EstadoAgendamento::iniciais(), true) ? $estado : EstadoAgendamento::Confirmado;
        $id = $this->novoAgendamento('10:00', '10:30', ['estado' => $nasce->value]);

        if ($nasce !== $estado) {
            DB::table('agendamentos')->where('id', $id)->update($this->colunasPara($estado));
        }

        return $id;
    }

    private function colunasPara(EstadoAgendamento $estado): array
    {
        return $estado === EstadoAgendamento::Cancelado
            ? ['estado' => $estado->value, 'cancelado_em' => now(), 'motivo_cancelamento' => 'teste']
            : ['estado' => $estado->value, 'cancelado_em' => null];
    }

    public function test_banco_e_enum_concordam_em_todas_as_transicoes(): void
    {
        $divergencias = [];

        foreach (EstadoAgendamento::cases() as $de) {
            foreach (EstadoAgendamento::cases() as $para) {
                if ($de === $para) {
                    continue;
                }
                $id = $this->agendamentoEm($de);

                try {
                    DB::transaction(fn () => DB::table('agendamentos')->where('id', $id)->update($this->colunasPara($para)));
                    $bancoAceitou = true;
                } catch (QueryException $e) {
                    $this->assertStringContainsString('agendamentos_transicao_estado', $e->getMessage(),
                        "{$de->value} -> {$para->value} recusado por outro motivo");
                    $bancoAceitou = false;
                }

                if ($bancoAceitou !== $de->podeIrPara($para)) {
                    $divergencias[] = "{$de->value} -> {$para->value}: banco ".($bancoAceitou ? 'aceita' : 'recusa');
                }
            }
        }

        $this->assertSame([], $divergencias);
    }

    public function test_estados_iniciais(): void
    {
        foreach (EstadoAgendamento::cases() as $estado) {
            $criar = fn () => $this->novoAgendamento('10:00', '10:30', $this->colunasPara($estado));

            if (in_array($estado, EstadoAgendamento::iniciais(), true)) {
                $this->assertIsInt($criar(), $estado->value);
            } else {
                $this->assertBancoRecusa('agendamentos_estado_inicial', $criar);
            }
        }
    }

    public function test_encerrado_nao_pode_ser_remarcado(): void
    {
        $id = $this->agendamentoEm(EstadoAgendamento::Concluido);

        $this->assertBancoRecusa('agendamentos_encerrado_imutavel', fn () => DB::table('agendamentos')->where('id', $id)->update([
            'inicio_servico' => $this->em('15:00'), 'fim_servico' => $this->em('15:30'),
            'inicio_ocupado' => $this->em('15:00'), 'fim_ocupado' => $this->em('15:30'),
        ]));
    }

    public function test_historico_registra_quem_mudou(): void
    {
        $operador = User::factory()->create();
        $id = $this->agendamentoEm(EstadoAgendamento::Solicitado);

        $this->comoAtor('operador', $operador->id);
        DB::table('agendamentos')->where('id', $id)->update(['estado' => 'confirmado']);

        $eventos = DB::table('agendamento_eventos')->where('agendamento_id', $id)->orderBy('id')->get();
        $this->assertSame(['criado', 'estado_alterado'], $eventos->pluck('tipo')->all());
        $this->assertSame('sistema', $eventos[0]->ator);
        $this->assertSame('operador', $eventos[1]->ator);
        $this->assertSame($operador->id, $eventos[1]->usuario_id);
        $this->assertSame(['solicitado', 'confirmado'], [$eventos[1]->estado_anterior, $eventos[1]->estado_novo]);
    }

    /** A anonimizacao recusa cliente com agendamento em aberto: mesma lista do enum. */
    public function test_anonimizacao_usa_os_estados_em_aberto_do_enum(): void
    {
        $fonte = (string) DB::scalar("SELECT pg_get_functiondef('public.cleison_anonimizar_cliente(bigint, bigint, varchar, varchar)'::regprocedure)");
        $this->assertSame(1, preg_match("/a\.estado IN \(([^)]*)\)/", $fonte, $m), $fonte);
        preg_match_all("/'([a-z_]+)'/", $m[1], $estados);

        $this->assertEqualsCanonicalizing(EstadoAgendamento::emAberto(), $estados[1]);
    }
}
