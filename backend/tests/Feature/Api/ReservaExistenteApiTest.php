<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** POST /api/v1/reservas/consultar, /cancelar e /remarcar (codigo + telefone no CORPO, nunca na URL). */
class ReservaExistenteApiTest extends ApiTestCase
{
    private function acao(string $rota, array $corpo): TestResponse
    {
        return $this->postJson('/api/v1/reservas/'.$rota, $corpo);
    }

    public function test_consultar_devolve_a_reserva_no_mesmo_formato_da_criacao(): void
    {
        $criada = $this->reservar($this->corpo())->assertCreated();

        $consulta = $this->acao('consultar', ['codigo' => $criada->json('codigo'), 'telefone' => '(11) 98765-1234'])->assertOk();

        $this->assertSame($criada->json(), $consulta->json());
        $this->assertSemChavesDeId($consulta->json());
    }

    public function test_cancelar_cancela_e_devolve_a_reserva(): void
    {
        $codigo = $this->codigoDeUmaReserva();

        $this->acao('cancelar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])->assertOk()
            ->assertJsonPath('estado', 'cancelado')->assertJsonPath('codigo', $codigo);
        $this->assertSame('cancelado', DB::table('agendamentos')->value('estado'));
    }

    public function test_cancelar_de_novo_e_recusado_pelo_estado(): void
    {
        $codigo = $this->codigoDeUmaReserva();
        $this->acao('cancelar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])->assertOk();

        $this->acao('cancelar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])->assertStatus(422)
            ->assertJsonPath('codigo', 'estado_nao_permite');
    }

    public function test_cancelar_fora_do_prazo_e_422(): void
    {
        $codigo = $this->codigoDeUmaReserva();
        $this->fixarRelogio('2026-10-07 12:45:00'); // 09:45 SP; a reserva e as 10:00, prazo de 30 min

        $this->acao('cancelar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])->assertStatus(422)
            ->assertJsonPath('codigo', 'fora_do_prazo');
    }

    public function test_remarcar_muda_o_horario_e_mantem_codigo_e_itens(): void
    {
        $codigo = $this->codigoDeUmaReserva();

        $r = $this->acao('remarcar', ['codigo' => $codigo, 'telefone' => self::TELEFONE, 'data' => '2026-10-08', 'hora' => '14:30'])->assertOk();

        $r->assertJsonPath('codigo', $codigo)->assertJsonPath('data', '2026-10-08')->assertJsonPath('hora', '14:30')
            ->assertJsonPath('inicio', '2026-10-08T14:30:00-03:00')->assertJsonPath('fim', '2026-10-08T15:00:00-03:00')
            ->assertJsonPath('estado', 'solicitado')->assertJsonPath('total_centavos', 4000);
        $this->assertSemChavesDeId($r->json());
    }

    public function test_remarcar_reserva_confirmada_exige_novo_pedido(): void
    {
        $codigo = $this->codigoDeUmaReserva();
        DB::table('agendamentos')->where('codigo_publico', $codigo)->update(['estado' => 'confirmado']);

        $this->acao('remarcar', ['codigo' => $codigo, 'telefone' => self::TELEFONE, 'data' => '2026-10-08', 'hora' => '14:30'])
            ->assertStatus(422)->assertJsonPath('codigo', 'remarcacao_exige_novo_pedido');
    }

    public function test_remarcar_para_horario_ocupado_e_409(): void
    {
        $codigo = $this->codigoDeUmaReserva();
        $this->codigoDeUmaReserva(['hora' => '15:00', 'cliente' => ['nome' => 'Segundo Cliente', 'telefone' => '+5511912345678']], 'chave-segunda-0123456789');

        $r = $this->acao('remarcar', ['codigo' => $codigo, 'telefone' => self::TELEFONE, 'data' => '2026-10-07', 'hora' => '15:00'])
            ->assertStatus(409);

        $this->assertSame('horario_indisponivel', $r->json('codigo'));
        $this->assertStringNotContainsString('SQLSTATE', $r->getContent());
        $this->segredos[] = '11912345678';
        // A reserva original continua no horario antigo.
        $this->acao('consultar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])->assertJsonPath('hora', '10:00');
    }

    public function test_remarcar_recusas_do_dominio(): void
    {
        $codigo = $this->codigoDeUmaReserva();
        $base = ['codigo' => $codigo, 'telefone' => self::TELEFONE];

        $this->acao('remarcar', $base + ['data' => '2026-10-08', 'hora' => '03:00'])->assertStatus(422)->assertJsonPath('codigo', 'fora_do_expediente');
        $this->acao('remarcar', $base + ['data' => '2026-10-08', 'hora' => '10:10'])->assertStatus(422)->assertJsonPath('codigo', 'fora_da_grade');
        $this->acao('remarcar', $base + ['data' => '2026-11-30', 'hora' => '10:00'])->assertStatus(422)->assertJsonPath('codigo', 'alem_do_horizonte');
        $this->acao('remarcar', $base + ['data' => '2026-10-05', 'hora' => '10:00'])->assertStatus(422)->assertJsonPath('codigo', 'antecedencia');
    }

    public function test_remarcar_valida_o_formato_de_data_e_hora(): void
    {
        $base = ['codigo' => $this->codigoDeUmaReserva(), 'telefone' => self::TELEFONE];

        foreach ([['data' => 'x', 'hora' => '10:00'], ['data' => '2026-10-08', 'hora' => 'x'], ['hora' => '10:00'], ['data' => '2026-10-08']] as $ruim) {
            $r = $this->acao('remarcar', $base + $ruim)->assertStatus(422);
            $r->assertJsonPath('codigo', 'dados_invalidos');
        }
    }

    public function test_codigo_inexistente_e_telefone_errado_dao_resposta_identica(): void
    {
        $codigo = $this->codigoDeUmaReserva();
        $inexistente = '00000000-0000-4000-8000-000000000000';
        $this->segredos[] = $inexistente;
        $this->segredos[] = '11900001111';

        foreach (['consultar' => [], 'cancelar' => [], 'remarcar' => ['data' => '2026-10-08', 'hora' => '14:30']] as $rota => $extra) {
            $codigoErrado = $this->acao($rota, ['codigo' => $inexistente, 'telefone' => self::TELEFONE] + $extra);
            $telefoneErrado = $this->acao($rota, ['codigo' => $codigo, 'telefone' => '+5511900001111'] + $extra);
            $malFormado = $this->acao($rota, ['codigo' => 'nao-e-uuid', 'telefone' => self::TELEFONE] + $extra);
            $telefoneInvalido = $this->acao($rota, ['codigo' => $codigo, 'telefone' => '12'] + $extra);

            $codigoErrado->assertStatus(422)->assertJsonPath('codigo', 'reserva_nao_encontrada');
            foreach ([$telefoneErrado, $malFormado, $telefoneInvalido] as $outra) {
                $this->assertSame($codigoErrado->getStatusCode(), $outra->getStatusCode(), $rota);
                $this->assertSame($codigoErrado->getContent(), $outra->getContent(), $rota);
            }
        }
        // Nada mudou na reserva.
        $this->assertSame('solicitado', DB::table('agendamentos')->value('estado'));
        $this->assertSame('10:00', $this->acao('consultar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])->json('hora'));
    }

    public function test_codigo_e_telefone_ausentes_ou_do_tipo_errado_sao_422_de_formato(): void
    {
        foreach (['consultar', 'cancelar', 'remarcar'] as $rota) {
            foreach ([[], ['codigo' => 'x'], ['telefone' => self::TELEFONE], ['codigo' => ['x'], 'telefone' => self::TELEFONE], ['codigo' => str_repeat('a', 101), 'telefone' => self::TELEFONE]] as $corpo) {
                $this->acao($rota, $corpo + ['data' => '2026-10-08', 'hora' => '14:30'])->assertStatus(422)->assertJsonPath('codigo', 'dados_invalidos');
            }
        }
    }

    public function test_codigo_e_telefone_na_url_nao_existem_como_rota(): void
    {
        $codigo = $this->codigoDeUmaReserva();

        $this->getJson('/api/v1/reservas/'.$codigo)->assertStatus(404)->assertJsonPath('codigo', 'nao_encontrado');
        $this->getJson('/api/v1/reservas/consultar?codigo='.$codigo.'&telefone=11987651234')->assertStatus(405);
    }
}
