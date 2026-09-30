<?php

namespace Tests\Feature\Api;

use App\Support\ErroDeBanco;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use RuntimeException;

/**
 * Achados do security-reviewer (Fase 5):
 *   #4 caractere de controle (NUL...) em texto livre chegava ao banco e
 *      virava 500 (22021); agora e 422 na validacao, e o ErroDeBanco traduz
 *      22021/22001 para 422 como segunda camada;
 *   #6 erro inesperado em api/* responde JSON fixo, sem trace, mesmo com
 *      APP_DEBUG ligado (o ambiente de teste liga).
 */
class ErrosDaApiTest extends ApiTestCase
{
    public function test_caractere_de_controle_no_nome_e_recusado_na_validacao(): void
    {
        $corpo = $this->corpo();
        $corpo['cliente']['nome'] = "Ana\u{0000} Souza";

        $r = $this->reservar($corpo)->assertStatus(422)->assertJsonPath('codigo', 'dados_invalidos');
        $this->assertArrayHasKey('cliente.nome', $r->json('erros'));
    }

    public function test_caractere_de_controle_na_observacao_e_no_endereco_e_recusado(): void
    {
        $this->reservar($this->corpo(['observacao' => "chego\u{0001} cedo"]))
            ->assertStatus(422)->assertJsonStructure(['erros' => ['observacao']]);

        $domicilio = $this->corpo(['modalidade' => 'domicilio', 'regiao_id' => $this->regiaoId,
            'endereco' => ['logradouro' => "Rua A\u{0000}, 100"]]);
        $this->reservar($domicilio)->assertStatus(422)->assertJsonStructure(['erros' => ['endereco.logradouro']]);
    }

    public function test_quebra_de_linha_e_tab_continuam_aceitos_na_observacao(): void
    {
        $this->reservar($this->corpo(['observacao' => "chego\tcedo\ncom meu filho"]))->assertStatus(201);
    }

    public function test_erro_de_texto_do_banco_vira_422_e_nao_500(): void
    {
        foreach (['22021', '22001'] as $sqlstate) {
            $pdo = new PDOException("SQLSTATE[{$sqlstate}]: erro de texto");
            $pdo->errorInfo = [$sqlstate, 7, 'ERROR: erro de texto'];
            $e = new QueryException('pgsql', 'insert into clientes ...', [], $pdo);

            [$status, $codigo] = ErroDeBanco::classificar($e);
            $this->assertSame([422, 'dados_invalidos'], [$status, $codigo], $sqlstate);
        }
    }

    public function test_erro_inesperado_na_api_e_500_fixo_sem_trace(): void
    {
        $this->assertTrue((bool) config('app.debug'), 'o teste precisa do debug ligado para valer');
        Route::post('/api/v1/_teste-erro-inesperado', fn () => throw new RuntimeException('detalhe interno /var/www segredo'));

        $r = $this->postJson('/api/v1/_teste-erro-inesperado')->assertStatus(500);

        $this->assertSame(['mensagem' => 'Erro interno.', 'codigo' => 'erro_interno'], $r->json());
        $this->assertStringNotContainsString('segredo', (string) $r->getContent());
        $this->assertStringNotContainsString('trace', (string) $r->getContent());
    }

    /**
     * Erro HTTP do framework (abort, manutencao...) mantem o status, com
     * corpo fixo. Regressao: sem o `use` de HttpExceptionInterface no
     * bootstrap/app.php, o instanceof nunca casava e tudo virava 500.
     */
    public function test_erro_http_do_framework_mantem_o_status_com_corpo_fixo(): void
    {
        Route::post('/api/v1/_teste-abort', fn () => abort(413, 'detalhe interno segredo'));

        $r = $this->postJson('/api/v1/_teste-abort')->assertStatus(413);

        $this->assertSame(['mensagem' => 'Requisicao recusada.', 'codigo' => 'requisicao_recusada'], $r->json());
        $this->assertStringNotContainsString('segredo', (string) $r->getContent());
    }
}
