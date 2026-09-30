<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Varredura: existe OUTRO cliente com nome, telefone, endereco e observacao
 * unicos e uma reserva dele. Todos os endpoints, do ponto de vista de um
 * cliente qualquer, nunca podem conter nenhum desses valores nem o codigo
 * publico dele.
 */
class VarreduraApiTest extends ApiTestCase
{
    private const NOME = 'Zeferina Xilindro Ultramarina';

    private const TELEFONE_OUTRO = '+5511955550077';

    private const ENDERECO = 'Travessa Xingu Secreta, 7777';

    private const OBSERVACAO = 'observacao-unica-do-outro-cliente';

    /** @var list<string> */
    private array $proibidos;

    private string $codigoDoOutro;

    protected function setUp(): void
    {
        parent::setUp();
        $resposta = $this->reservar($this->corpo($this->dadosDeDomicilio([
            'hora' => '14:00',
            'cliente' => ['nome' => self::NOME, 'telefone' => self::TELEFONE_OUTRO],
            'endereco' => ['logradouro' => self::ENDERECO, 'complemento' => 'Fundos-secretos', 'referencia' => 'Casa-verde-unica'],
            'observacao' => self::OBSERVACAO,
        ])), 'chave-do-outro-0123456789')->assertCreated();

        $this->codigoDoOutro = $resposta->json('codigo');
        $this->proibidos = [
            'Zeferina', 'Xilindro', 'Ultramarina', '955550077', self::ENDERECO, 'Fundos-secretos',
            'Casa-verde-unica', self::OBSERVACAO, $this->codigoDoOutro,
        ];
        $this->segredos[] = '11955550077';
    }

    private function assertLimpa(TestResponse $r, string $onde): void
    {
        foreach ($this->proibidos as $valor) {
            $this->assertStringNotContainsString($valor, $r->getContent(), "{$onde} vazou {$valor}");
        }
    }

    public function test_nenhum_endpoint_devolve_dado_do_outro_cliente(): void
    {
        $meu = $this->reservar($this->corpo(['hora' => '09:00']), 'chave-do-meu-0123456789')->assertCreated();
        $this->assertLimpa($meu, 'criar');

        $consulta = fn () => $this->postJson('/api/v1/reservas/consultar', ['codigo' => $meu->json('codigo'), 'telefone' => self::TELEFONE]);
        $this->assertLimpa($consulta()->assertOk(), 'consultar');
        $this->assertLimpa($this->reservar($this->corpo(['hora' => '09:00']), 'chave-do-meu-0123456789')->assertOk(), 'idempotente');

        $query = http_build_query(['data' => '2026-10-07', 'servicos' => [$this->corteId], 'profissional_id' => $this->profissionalId, 'modalidade' => 'barbearia']);
        $this->assertLimpa($this->getJson('/api/v1/disponibilidade?'.$query)->assertOk(), 'disponibilidade');
        $this->assertLimpa($this->getJson('/api/v1/servicos')->assertOk(), 'servicos');
        $this->assertLimpa($this->getJson('/api/v1/regioes')->assertOk(), 'regioes');
        $this->assertLimpa($this->getJson('/api/v1/profissionais?servicos[]='.$this->corteId)->assertOk(), 'profissionais');

        // Erros: horario do outro (409), conflito de chave, codigo dele com o MEU telefone, validacao.
        $this->assertLimpa($this->reservar($this->corpo(['hora' => '14:00']), 'chave-conflito-0123456789')->assertStatus(409), '409');
        $this->assertLimpa($this->reservar($this->corpo(['hora' => '15:00']), 'chave-do-meu-0123456789')->assertStatus(422), 'idempotencia');
        $this->assertLimpa($this->reservar(['x' => 1], 'chave-validacao-0123456789')->assertStatus(422), 'validacao');
        foreach (['consultar', 'cancelar'] as $rota) {
            $this->assertLimpa($this->postJson('/api/v1/reservas/'.$rota, ['codigo' => $this->codigoDoOutro, 'telefone' => self::TELEFONE])->assertStatus(422), $rota);
        }
        $this->assertLimpa($this->postJson('/api/v1/reservas/remarcar', ['codigo' => $this->codigoDoOutro, 'telefone' => self::TELEFONE, 'data' => '2026-10-08', 'hora' => '10:00'])->assertStatus(422), 'remarcar');
        $remarcar409 = $this->postJson('/api/v1/reservas/remarcar', ['codigo' => $meu->json('codigo'), 'telefone' => self::TELEFONE, 'data' => '2026-10-07', 'hora' => '14:00'])->assertStatus(409);
        $this->assertLimpa($remarcar409, 'remarcar 409');

        // Nada do outro foi tocado.
        $this->assertSame('solicitado', DB::table('agendamentos')->where('codigo_publico', $this->codigoDoOutro)->value('estado'));
    }

    public function test_o_codigo_do_outro_com_o_telefone_dele_so_mostra_a_reserva_dele(): void
    {
        // Contraprova: com codigo + telefone certos a consulta funciona (nao ha bloqueio cego).
        $r = $this->postJson('/api/v1/reservas/consultar', ['codigo' => $this->codigoDoOutro, 'telefone' => self::TELEFONE_OUTRO])->assertOk();
        $this->assertSame($this->codigoDoOutro, $r->json('codigo'));
        $this->assertStringNotContainsString('Zeferina', $r->getContent());
        $this->assertStringNotContainsString(self::ENDERECO, $r->getContent());
    }
}
