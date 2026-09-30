<?php

namespace Tests\Feature\Banco;

use App\Support\ErroDeBanco;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * Decisao E1 (docs/ESPEC-RESERVA.md, secao 10): defesa em profundidade para
 * o canal site. Um item de agendamento com origem 'site' so entra com
 * servico ATIVO e com vinculo profissional_servico, mesmo gravado direto
 * pelo papel da aplicacao, sem passar pelo dominio. Presencial e WhatsApp
 * (operador, historico) nao sao afetados. Relogio (expediente, antecedencia,
 * horizonte) fica no dominio.
 */
class CatalogoNoSiteTest extends TestCase
{
    use BancoDeTeste, DadosDeAgenda;

    private const REGRA = 'agendamento_itens_catalogo_no_site';

    private int $profissional;

    private int $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profissional = $this->novoProfissional();
        $this->cliente = $this->novoCliente();
    }

    private function agendamentoCru(string $origem, string $inicio = '03:00', string $fim = '03:30'): int
    {
        return DB::table('agendamentos')->insertGetId([
            'profissional_id' => $this->profissional, 'cliente_id' => $this->cliente,
            'estado' => $origem === 'site' ? 'solicitado' : 'confirmado',
            'origem' => $origem, 'modalidade' => 'barbearia',
            'inicio_servico' => $this->em($inicio), 'fim_servico' => $this->em($fim),
            'inicio_ocupado' => $this->em($inicio), 'fim_ocupado' => $this->em($fim),
        ]);
    }

    private function item(int $agendamento, int $servico): void
    {
        DB::table('agendamento_itens')->insert([
            'agendamento_id' => $agendamento, 'servico_id' => $servico, 'ordem' => 1,
            'servico_nome' => 'Corte', 'preco_centavos' => 1, 'duracao_minutos' => 30,
            'conta_como_corte' => true,
        ]);
    }

    private function vincular(int $servico): void
    {
        DB::table('profissional_servico')->insert(['profissional_id' => $this->profissional, 'servico_id' => $servico]);
    }

    public function test_site_com_servico_inativo_e_recusado_mesmo_com_vinculo(): void
    {
        $inativo = $this->novoServico(['ativo' => false]);
        $this->vincular($inativo);

        $this->assertBancoRecusa(self::REGRA, fn () => $this->item($this->agendamentoCru('site'), $inativo));
    }

    public function test_site_sem_vinculo_do_profissional_e_recusado(): void
    {
        $semVinculo = $this->novoServico();

        $this->assertBancoRecusa(self::REGRA, fn () => $this->item($this->agendamentoCru('site'), $semVinculo));
    }

    public function test_brecha_original_pelo_site_e_recusada(): void
    {
        // 03:00, servico inativo, sem vinculo, preco de R$ 0,01: o banco para
        // pelo menos o que nao depende do relogio.
        $inativo = $this->novoServico(['ativo' => false]);

        $this->assertBancoRecusa(self::REGRA, fn () => $this->item($this->agendamentoCru('site'), $inativo));
    }

    public function test_site_com_servico_ativo_e_vinculado_e_aceito(): void
    {
        $servico = $this->novoServico();
        $this->vincular($servico);
        $agendamento = $this->agendamentoCru('site');

        $this->item($agendamento, $servico);

        $this->assertSame(1, DB::table('agendamento_itens')->where('agendamento_id', $agendamento)->count());
    }

    public function test_presencial_e_whatsapp_aceitam_servico_inativo_e_sem_vinculo(): void
    {
        $inativo = $this->novoServico(['ativo' => false]);

        foreach (['presencial' => ['03:00', '03:30'], 'whatsapp' => ['04:00', '04:30']] as $origem => [$inicio, $fim]) {
            $agendamento = $this->agendamentoCru($origem, $inicio, $fim);
            $this->item($agendamento, $inativo);
            $this->assertSame(1, DB::table('agendamento_itens')->where('agendamento_id', $agendamento)->count(), $origem);
        }
    }

    public function test_trocar_o_servico_de_item_do_site_por_um_inativo_e_recusado(): void
    {
        $servico = $this->novoServico();
        $this->vincular($servico);
        $agendamento = $this->agendamentoCru('site');
        $this->item($agendamento, $servico);

        $inativo = $this->novoServico(['ativo' => false]);
        $this->vincular($inativo);

        $this->assertBancoRecusa(self::REGRA, fn () => DB::table('agendamento_itens')
            ->where('agendamento_id', $agendamento)->update(['servico_id' => $inativo]));
    }

    public function test_gravar_como_presencial_e_trocar_a_origem_para_site_e_recusado(): void
    {
        $inativo = $this->novoServico(['ativo' => false]);
        $agendamento = $this->agendamentoCru('presencial');
        $this->item($agendamento, $inativo);

        $this->assertBancoRecusa(self::REGRA, fn () => DB::table('agendamentos')
            ->where('id', $agendamento)->update(['origem' => 'site']));
    }

    public function test_trocar_o_profissional_do_site_exige_vinculo_do_novo(): void
    {
        $servico = $this->novoServico();
        $this->vincular($servico);
        $agendamento = $this->agendamentoCru('site');
        $this->item($agendamento, $servico);

        $semVinculo = $this->novoProfissional('Sem vinculo');
        $this->assertBancoRecusa(self::REGRA, fn () => DB::table('agendamentos')
            ->where('id', $agendamento)->update(['profissional_id' => $semVinculo]));

        $comVinculo = $this->novoProfissional('Com vinculo');
        DB::table('profissional_servico')->insert(['profissional_id' => $comVinculo, 'servico_id' => $servico]);
        DB::table('agendamentos')->where('id', $agendamento)->update(['profissional_id' => $comVinculo]);

        $this->assertSame($comVinculo, (int) DB::table('agendamentos')->where('id', $agendamento)->value('profissional_id'));
    }

    public function test_regravar_o_mesmo_servico_de_item_historico_nao_e_troca(): void
    {
        $servico = $this->novoServico();
        $this->vincular($servico);
        $agendamento = $this->agendamentoCru('site');
        $this->item($agendamento, $servico);
        DB::table('servicos')->where('id', $servico)->update(['ativo' => false]);

        DB::table('agendamento_itens')->where('agendamento_id', $agendamento)->update(['servico_id' => $servico]);

        $this->assertSame($servico, (int) DB::table('agendamento_itens')->where('agendamento_id', $agendamento)->value('servico_id'));
    }

    public function test_desativar_o_servico_depois_nao_invalida_o_historico(): void
    {
        $servico = $this->novoServico();
        $this->vincular($servico);
        $agendamento = $this->agendamentoCru('site');
        $this->item($agendamento, $servico);

        DB::table('servicos')->where('id', $servico)->update(['ativo' => false]);
        DB::table('profissional_servico')->where('servico_id', $servico)->delete();
        DB::table('agendamento_itens')->where('agendamento_id', $agendamento)->update(['servico_nome' => 'Corte classico']);

        $this->assertSame('Corte classico', DB::table('agendamento_itens')->where('agendamento_id', $agendamento)->value('servico_nome'));
    }

    public function test_funcoes_sao_security_invoker_com_search_path_fixo(): void
    {
        foreach ([
            'public.cleison_conferir_catalogo_no_site(bigint, bigint)',
            'public.cleison_item_exige_catalogo_no_site()',
            'public.cleison_agendamento_exige_catalogo_no_site()',
        ] as $assinatura) {
            $funcao = DB::selectOne('SELECT prosecdef, proconfig FROM pg_proc WHERE oid = ?::regprocedure', [$assinatura]);

            $this->assertFalse($funcao->prosecdef, $assinatura);
            $this->assertSame('{"search_path=pg_catalog, public, pg_temp"}', $funcao->proconfig, $assinatura);
        }
    }

    public function test_mensagem_traduzida_sem_detalhe(): void
    {
        $this->assertArrayHasKey(self::REGRA, ErroDeBanco::MENSAGENS);
        $this->assertStringNotContainsStringIgnoringCase('profissional_servico', ErroDeBanco::MENSAGENS[self::REGRA]);
    }
}
