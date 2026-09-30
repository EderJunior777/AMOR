<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\Estabelecimento;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Campos que definem poder (papel, ativo), estado de dominio (estado,
 * cancelamento, idempotencia, autoria), valores calculados no servidor
 * (horarios, taxa) ou natureza do dado (demonstracao) nao entram por
 * atribuicao em massa: um array vindo de uma requisicao nao pode promover
 * alguem a proprietario nem pular a maquina de estados.
 *
 * Fora de producao (testes inclusive) o modo estrito transforma a tentativa
 * em excecao; em producao o Eloquent descarta o campo.
 */
class AtribuicaoEmMassaTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::preventSilentlyDiscardingAttributes(true);
        parent::tearDown();
    }

    public function test_modo_estrito_ligado_fora_de_producao(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
        $this->assertTrue(Model::preventsSilentlyDiscardingAttributes());
        $this->assertTrue(Model::preventsAccessingMissingAttributes());
    }

    /** @return array<string, array{class-string<Model>, string, mixed}> */
    public static function camposProtegidos(): array
    {
        return [
            'user.papel' => [User::class, 'papel', 'proprietario'],
            'user.ativo' => [User::class, 'ativo', true],
            'agendamento.estado' => [Agendamento::class, 'estado', 'concluido'],
            'agendamento.cliente_id' => [Agendamento::class, 'cliente_id', 1],
            'agendamento.criado_por_user_id' => [Agendamento::class, 'criado_por_user_id', 1],
            'agendamento.chave_idempotencia' => [Agendamento::class, 'chave_idempotencia', 'x'],
            'agendamento.hash_requisicao' => [Agendamento::class, 'hash_requisicao', 'x'],
            'agendamento.cancelado_em' => [Agendamento::class, 'cancelado_em', '2026-10-01 10:00:00'],
            'agendamento.codigo_publico' => [Agendamento::class, 'codigo_publico', '00000000-0000-0000-0000-000000000000'],
            'agendamento.taxa' => [Agendamento::class, 'taxa_deslocamento_centavos', 1],
            'agendamento.inicio_servico' => [Agendamento::class, 'inicio_servico', '2026-10-01 03:00:00'],
            'estabelecimento.dados_demonstracao' => [Estabelecimento::class, 'dados_demonstracao', true],
        ];
    }

    /** @param class-string<Model> $classe */
    #[DataProvider('camposProtegidos')]
    public function test_campo_protegido_lanca_erro_fora_de_producao(string $classe, string $campo, mixed $valor): void
    {
        $this->expectException(MassAssignmentException::class);

        new $classe([$campo => $valor]);
    }

    /** @param class-string<Model> $classe */
    #[DataProvider('camposProtegidos')]
    public function test_campo_protegido_e_ignorado_em_producao(string $classe, string $campo, mixed $valor): void
    {
        Model::preventSilentlyDiscardingAttributes(false);

        $modelo = new $classe([$campo => $valor]);

        $this->assertArrayNotHasKey($campo, $modelo->getAttributes());
    }

    public function test_agendamento_so_aceita_em_massa_a_observacao_do_cliente(): void
    {
        $this->assertSame(['observacao_cliente'], (new Agendamento)->getFillable());
    }
}
