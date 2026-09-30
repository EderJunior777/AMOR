<?php

namespace Tests\Feature\Api;

use App\Domain\Agenda\ReservaRecusada;
use Illuminate\Support\Facades\DB;

/** POST /api/v1/reservas: criacao, idempotencia, formato e campos proibidos. */
class ReservaApiTest extends ApiTestCase
{
    private function esperadoDaBarbearia(string $codigo): array
    {
        return [
            'codigo' => $codigo,
            'estado' => 'solicitado',
            'data' => '2026-10-07',
            'hora' => '10:00',
            'inicio' => '2026-10-07T10:00:00-03:00',
            'fim' => '2026-10-07T10:30:00-03:00',
            'modalidade' => 'barbearia',
            'profissional' => ['nome_exibicao' => 'Ze do Corte'],
            'servicos' => [['nome' => 'Corte', 'preco_centavos' => 4000, 'duracao_minutos' => 30]],
            'taxa_deslocamento_centavos' => 0,
            'total_centavos' => 4000,
            'regiao_nome' => null,
        ];
    }

    public function test_cria_reserva_na_barbearia_com_201_e_formato_exato(): void
    {
        $resposta = $this->reservar($this->corpo())->assertCreated();

        $codigo = $resposta->json('codigo');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $codigo);
        $resposta->assertExactJson($this->esperadoDaBarbearia($codigo));
        $this->assertSemChavesDeId($resposta->json());

        $linha = DB::table('agendamentos')->first();
        $this->assertSame('site', $linha->origem);
        $this->assertSame('solicitado', $linha->estado);
        $this->assertSame($codigo, $linha->codigo_publico);
    }

    public function test_cria_reserva_a_domicilio_com_taxa_regiao_e_total(): void
    {
        $resposta = $this->reservar($this->corpo($this->dadosDeDomicilio()))->assertCreated();

        $resposta->assertJsonPath('modalidade', 'domicilio')
            ->assertJsonPath('taxa_deslocamento_centavos', 2000)
            ->assertJsonPath('total_centavos', 6000)
            ->assertJsonPath('regiao_nome', 'Zona Sul')
            ->assertJsonPath('inicio', '2026-10-07T10:00:00-03:00')
            ->assertJsonPath('fim', '2026-10-07T10:30:00-03:00');
        $this->assertSemChavesDeId($resposta->json());
        // Nem endereco, nem telefone, nem nome do cliente na saida.
        foreach (['Verdejante', 'Bloco 7', 'Portao azul', '5511987651234', 'Quixabeira'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $resposta->getContent());
        }
    }

    public function test_varios_servicos_somam_preco_e_duracao_na_ordem_pedida(): void
    {
        $resposta = $this->reservar($this->corpo(['servicos' => [$this->barbaId, $this->corteId]]))->assertCreated();

        $resposta->assertJsonPath('servicos', [
            ['nome' => 'Barba', 'preco_centavos' => 3000, 'duracao_minutos' => 30],
            ['nome' => 'Corte', 'preco_centavos' => 4000, 'duracao_minutos' => 30],
        ])->assertJsonPath('total_centavos', 7000)->assertJsonPath('fim', '2026-10-07T11:00:00-03:00');
    }

    public function test_servicos_do_resource_vem_do_snapshot_e_nao_do_catalogo_atual(): void
    {
        $codigo = $this->codigoDeUmaReserva();
        DB::table('servicos')->where('id', $this->corteId)->update(['preco_centavos' => 9900, 'nome' => 'Corte Premium']);

        $this->postJson('/api/v1/reservas/consultar', ['codigo' => $codigo, 'telefone' => self::TELEFONE])
            ->assertOk()
            ->assertJsonPath('servicos', [['nome' => 'Corte', 'preco_centavos' => 4000, 'duracao_minutos' => 30]])
            ->assertJsonPath('total_centavos', 4000);
    }

    public function test_campos_proibidos_no_corpo_sao_ignorados(): void
    {
        $outro = DB::table('clientes')->insertGetId(['nome' => 'Outro Cliente', 'telefone' => '+5511955550001']);
        $proibidos = [
            'preco_centavos' => 1, 'taxa' => 1, 'taxa_deslocamento_centavos' => 1, 'duracao' => 1, 'duracao_minutos' => 1,
            'inicio_ocupado' => '2026-10-07T00:00:00Z', 'fim_ocupado' => '2026-10-07T23:00:00Z',
            'estado' => 'concluido', 'origem' => 'presencial', 'cliente_id' => $outro,
            'criado_por_user_id' => 1, 'codigo_publico' => '00000000-0000-4000-8000-000000000000',
            'fuso' => 'Asia/Tokyo', 'total_centavos' => 1, 'deslocamento_minutos' => 999, 'motivo_encaixe' => 'x',
        ];

        $resposta = $this->reservar($this->corpo($proibidos))->assertCreated();

        $resposta->assertExactJson($this->esperadoDaBarbearia($resposta->json('codigo')));
        $this->assertNotSame('00000000-0000-4000-8000-000000000000', $resposta->json('codigo'));
        $linha = DB::table('agendamentos')->first();
        $this->assertSame('site', $linha->origem);
        $this->assertSame('solicitado', $linha->estado);
        $this->assertNull($linha->criado_por_user_id);
        $this->assertNotSame($outro, $linha->cliente_id);
        $this->assertSame(4000, (int) DB::table('agendamento_itens')->value('preco_centavos'));
        $this->assertSame(0, $linha->taxa_deslocamento_centavos);
        $this->assertSame(0, $linha->deslocamento_minutos);
        $this->assertSame('+5511987651234', DB::table('clientes')->where('id', $linha->cliente_id)->value('telefone'));
    }

    public function test_repeticao_com_a_mesma_chave_devolve_200_e_o_mesmo_corpo(): void
    {
        $primeira = $this->reservar($this->corpo())->assertCreated();
        $segunda = $this->reservar($this->corpo())->assertOk();

        $this->assertSame($primeira->json(), $segunda->json());
        $this->assertSame(1, DB::table('agendamentos')->count());
    }

    public function test_repeticao_com_o_corpo_reescrito_igual_continua_sendo_a_mesma_reserva(): void
    {
        $this->reservar($this->corpo())->assertCreated();

        $this->reservar($this->corpo(['cliente' => ['nome' => '  Quixabeira   Zebedeu ', 'telefone' => '(11) 98765-1234']]))->assertOk();
        $this->assertSame(1, DB::table('agendamentos')->count());
    }

    public function test_mesma_chave_com_outro_corpo_e_422_idempotencia_conflito(): void
    {
        $this->reservar($this->corpo())->assertCreated();

        $this->reservar($this->corpo(['hora' => '11:00']))->assertStatus(422)
            ->assertExactJson(['mensagem' => 'Esta solicitacao ja foi usada com outros dados. Recarregue a pagina e tente de novo.', 'codigo' => 'idempotencia_conflito']);
        $this->assertSame(1, DB::table('agendamentos')->count());
    }

    public function test_idempotency_key_ausente_curta_longa_ou_com_caractere_invalido_e_422(): void
    {
        $chaves = [
            'ausente' => null,
            'curta' => str_repeat('a', 15),
            'longa' => str_repeat('a', 101),
            'espaco' => 'chave com espaco 1234567',
            'simbolo' => 'chave-0123456789abc$',
            'acento' => 'chave-0123456789abcé',
        ];
        foreach ($chaves as $nome => $chave) {
            $r = $this->reservar($this->corpo(), $chave)->assertStatus(422);
            $r->assertJsonPath('codigo', 'dados_invalidos');
            $this->assertArrayHasKey('idempotency_key', $r->json('erros'), $nome);
            $this->assertArrayHasKey('mensagem', $r->json());
        }
        $this->assertSame(0, DB::table('agendamentos')->count());

        $this->reservar($this->corpo(), str_repeat('A', 16))->assertCreated();
        $this->reservar($this->corpo(['hora' => '11:00']), str_repeat('_-Zz9', 20))->assertCreated();
    }

    public function test_chave_no_corpo_nao_substitui_o_cabecalho(): void
    {
        $this->reservar($this->corpo(['idempotency_key' => self::CHAVE]), null)->assertStatus(422);
    }

    public function test_validacao_de_formato_nao_ecoa_os_valores_enviados(): void
    {
        $valorSecreto = 'valor-secreto-zzz';
        $r = $this->reservar($this->corpo([
            'data' => $valorSecreto, 'hora' => $valorSecreto, 'modalidade' => $valorSecreto,
            'servicos' => [$valorSecreto], 'profissional_id' => $valorSecreto,
            'cliente' => ['nome' => 'A', 'telefone' => $valorSecreto],
            'observacao' => str_repeat('x', 301),
        ]))->assertStatus(422);

        $r->assertJsonPath('codigo', 'dados_invalidos');
        $this->assertStringNotContainsString($valorSecreto, $r->getContent());
        $this->assertEqualsCanonicalizing(
            ['data', 'hora', 'modalidade', 'servicos.0', 'profissional_id', 'cliente.nome', 'cliente.telefone', 'observacao'],
            array_keys($r->json('erros')),
        );
    }

    public function test_regras_de_formato_do_cliente_e_da_lista_de_servicos(): void
    {
        $tel = self::TELEFONE;
        $casos = [
            [['cliente' => ['nome' => 'A', 'telefone' => $tel]], 'cliente.nome'],
            [['cliente' => ['nome' => str_repeat('n', 121), 'telefone' => $tel]], 'cliente.nome'],
            [['cliente' => ['nome' => ' a ', 'telefone' => $tel]], 'cliente.nome'],
            [['cliente' => ['nome' => 'Nome Ok', 'telefone' => '123']], 'cliente.telefone'],
            [['cliente' => ['nome' => 'Nome Ok', 'telefone' => ['x']]], 'cliente.telefone'],
            [['cliente' => ['telefone' => $tel]], 'cliente.nome'],
            [['cliente' => 'texto'], 'cliente'],
            [['servicos' => []], 'servicos'],
            [['servicos' => [1, 2, 3, 4]], 'servicos'],
            [['servicos' => [$this->corteId, $this->corteId]], 'servicos.0'],
            [['servicos' => ['abc']], 'servicos.0'],
            [['hora' => '25:99x'], 'hora'],
            [['data' => '07/10/2026'], 'data'],
            [['observacao' => ['x']], 'observacao'],
        ];
        foreach ($casos as [$mudanca, $campo]) {
            $r = $this->reservar($this->corpo($mudanca))->assertStatus(422);
            $this->assertArrayHasKey($campo, $r->json('erros'), json_encode($mudanca));
        }
        $this->assertSame(0, DB::table('agendamentos')->count());
    }

    public function test_domicilio_exige_regiao_e_logradouro_e_barbearia_ignora_os_dois(): void
    {
        $r = $this->reservar($this->corpo(['modalidade' => 'domicilio']))->assertStatus(422);
        $this->assertArrayHasKey('regiao_id', $r->json('erros'));
        $this->assertArrayHasKey('endereco.logradouro', $r->json('erros'));

        // Na barbearia, regiao e endereco sao ignorados (ate lixo).
        $this->reservar($this->corpo(['regiao_id' => 'lixo', 'endereco' => 'lixo']))->assertCreated()
            ->assertJsonPath('regiao_nome', null)->assertJsonPath('taxa_deslocamento_centavos', 0);
        $this->assertNull(DB::table('agendamentos')->value('regiao_id'));
    }

    public function test_recusas_do_dominio_saem_como_422_com_codigo_estavel(): void
    {
        $casos = [
            'fora_da_grade' => ['hora' => '10:10'],
            'data_invalida' => ['data' => '2026-02-30'],
            'antecedencia' => ['data' => '2026-10-05', 'hora' => '10:00'],
            'alem_do_horizonte' => ['data' => '2026-11-05'],
            'fora_do_expediente' => ['hora' => '03:00'],
            'profissional_indisponivel' => ['profissional_id' => 999999],
            'servico_indisponivel' => ['servicos' => [999999]],
            'domicilio_indisponivel' => $this->dadosDeDomicilio(['regiao_id' => 999999]),
        ];
        foreach ($casos as $codigo => $mudanca) {
            $r = $this->reservar($this->corpo($mudanca), 'chave-'.$codigo.'-0123456789')->assertStatus(422);
            $r->assertExactJson(['mensagem' => ReservaRecusada::MENSAGENS[$codigo], 'codigo' => $codigo]);
        }
        $this->assertSame(0, DB::table('agendamentos')->count());
    }

    public function test_horario_ja_ocupado_e_409_sem_detalhe_do_banco(): void
    {
        $this->reservar($this->corpo(), 'chave-primeira-0123456789')->assertCreated();

        $r = $this->reservar($this->corpo(['cliente' => ['nome' => 'Segundo Cliente', 'telefone' => '+5511912345678']]), 'chave-segunda-0123456789')
            ->assertStatus(409);

        $this->assertSame('horario_indisponivel', $r->json('codigo'));
        $this->assertEqualsCanonicalizing(['mensagem', 'codigo', 'correlacao'], array_keys($r->json()));
        foreach (['SQLSTATE', 'ocupacoes', 'agendamentos', 'Quixabeira', 'Segundo'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $r->getContent());
        }
        $this->segredos[] = '11912345678';
    }

    public function test_o_mesmo_telefone_reaproveita_o_cliente_sem_trocar_o_nome(): void
    {
        $this->reservar($this->corpo(), 'chave-primeira-0123456789')->assertCreated();
        $this->reservar($this->corpo(['hora' => '11:00', 'cliente' => ['nome' => 'Nome Trocado', 'telefone' => '(11) 98765-1234']]), 'chave-segunda-0123456789')->assertCreated();

        $this->assertSame(1, DB::table('clientes')->count());
        $this->assertSame('Quixabeira Zebedeu', DB::table('clientes')->value('nome'));
    }
}
