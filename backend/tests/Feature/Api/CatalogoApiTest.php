<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

/** GET /api/v1/servicos, /regioes e /profissionais: catalogo publico, so o necessario. */
class CatalogoApiTest extends ApiTestCase
{
    public function test_servicos_devolve_so_os_ativos_em_ordem_e_com_os_campos_publicos(): void
    {
        DB::table('servicos')->update(['ordem' => 5]);
        $primeiro = $this->novoServicoDeReserva('Pezinho', 1500, 15, ['ordem' => 1, 'descricao' => 'Acabamento', 'permite_domicilio' => false]);
        $this->novoServicoDeReserva('Antigo', 999, 30, ['ativo' => false, 'ordem' => 0]);

        $resposta = $this->getJson('/api/v1/servicos')->assertOk();

        $esperado = DB::table('servicos')->where('ativo', true)->orderBy('ordem')->orderBy('nome')->get()
            ->map(fn ($s) => [
                'id' => $s->id, 'codigo' => $s->codigo, 'nome' => $s->nome, 'descricao' => $s->descricao,
                'preco_centavos' => $s->preco_centavos, 'duracao_minutos' => $s->duracao_minutos,
                'permite_barbearia' => $s->permite_barbearia, 'permite_domicilio' => $s->permite_domicilio,
            ])->all();
        $resposta->assertExactJson(['servicos' => $esperado]);
        $this->assertSame($primeiro, $resposta->json('servicos.0.id'));
        $this->assertCount(3, $resposta->json('servicos'));
        $this->assertSame(['Pezinho', 'Barba', 'Corte'], array_column($resposta->json('servicos'), 'nome'));
    }

    public function test_regioes_devolve_so_as_ativas_e_sem_deslocamento(): void
    {
        DB::table('regioes_atendimento')->insert(['codigo' => 'fechada', 'nome' => 'Fechada', 'deslocamento_minutos' => 30, 'taxa_centavos' => 1, 'ativo' => false]);

        $this->getJson('/api/v1/regioes')->assertOk()->assertExactJson(['regioes' => [
            ['id' => $this->regiaoId, 'codigo' => DB::table('regioes_atendimento')->where('id', $this->regiaoId)->value('codigo'), 'nome' => 'Zona Sul', 'taxa_centavos' => 2000],
        ]]);
    }

    public function test_regioes_vazio_com_domicilio_desligado(): void
    {
        DB::table('estabelecimento')->update(['domicilio_ativo' => false]);

        $this->getJson('/api/v1/regioes')->assertOk()->assertExactJson(['regioes' => []]);
    }

    public function test_profissionais_exige_todos_os_servicos_e_devolve_so_id_e_nome(): void
    {
        $so = DB::table('profissionais')->insertGetId(['nome_exibicao' => 'So Corte', 'user_id' => null]);
        $this->vincular($so, [$this->corteId]);
        DB::table('profissionais')->insert(['nome_exibicao' => 'Inativo', 'ativo' => false]);
        $inativo = (int) DB::table('profissionais')->where('nome_exibicao', 'Inativo')->value('id');
        $this->vincular($inativo, [$this->corteId, $this->barbaId]);

        $ambos = $this->getJson('/api/v1/profissionais?servicos[]='.$this->corteId.'&servicos[]='.$this->barbaId)->assertOk();
        $ambos->assertExactJson(['profissionais' => [['id' => $this->profissionalId, 'nome_exibicao' => 'Ze do Corte']]]);

        $corte = $this->getJson('/api/v1/profissionais?servicos[]='.$this->corteId)->assertOk();
        $this->assertEqualsCanonicalizing([$this->profissionalId, $so], array_column($corte->json('profissionais'), 'id'));
    }

    public function test_profissionais_recusa_lista_ausente_grande_repetida_ou_nao_inteira(): void
    {
        $c = $this->corteId;
        $consultas = [
            '' => 'servicos',
            '?servicos[]=' => 'servicos.0',
            '?servicos[]=1&servicos[]=2&servicos[]=3&servicos[]=4' => 'servicos',
            "?servicos[]={$c}&servicos[]={$c}" => 'servicos.0',
            '?servicos[]=abc' => 'servicos.0',
            '?servicos[]=0' => 'servicos.0',
            '?servicos=1' => 'servicos',
        ];
        foreach ($consultas as $query => $campo) {
            $r = $this->getJson('/api/v1/profissionais'.$query)->assertStatus(422);
            $r->assertJsonPath('codigo', 'dados_invalidos');
            $this->assertArrayHasKey($campo, $r->json('erros'), $query);
            $this->assertArrayHasKey('mensagem', $r->json());
        }
    }

    public function test_profissionais_ids_repetidos_com_grafia_diferente_tambem_sao_recusados(): void
    {
        $this->getJson("/api/v1/profissionais?servicos[]={$this->corteId}&servicos[]=0{$this->corteId}")
            ->assertStatus(422)->assertJsonPath('codigo', 'dados_invalidos');
    }
}
