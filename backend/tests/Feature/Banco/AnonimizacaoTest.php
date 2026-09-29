<?php

namespace Tests\Feature\Banco;

use App\Enums\PapelUsuario;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\CenarioDeAnonimizacao;
use Tests\Suporte\DadosDeAgenda;
use Tests\TestCase;

/**
 * cleison_anonimizar_cliente chamada como a aplicacao chama (papel sem DDL,
 * dentro da transacao do teste). docs/LGPD-ANONIMIZACAO.md, secao 7.
 * O que depende do papel dono esta em AnonimizacaoPeloDonoTest.
 */
class AnonimizacaoTest extends TestCase
{
    use BancoDeTeste, CenarioDeAnonimizacao, DadosDeAgenda;

    /** O que cleison_evento_dados_anonimos mantem (espelho da migration). */
    private const CHAVES_MANTIDAS = ['inicio_servico', 'fim_servico', 'inicio_ocupado', 'fim_ocupado', 'profissional_id', 'origem', 'de', 'para'];

    /** Texto livre que o trigger de historico grava hoje e que sai. */
    private const CHAVES_DE_TEXTO_LIVRE = ['motivo'];

    private function fotografia(string $sql, array $params = []): array
    {
        return array_map(fn ($linha) => (array) $linha, DB::select($sql, $params));
    }

    /** Tudo de um cliente, linha a linha e coluna a coluna. */
    private function tudoDoCliente(int $cliente): array
    {
        return [
            'clientes' => $this->fotografia('SELECT to_jsonb(c) AS l FROM clientes c WHERE id = ?', [$cliente]),
            'enderecos' => $this->fotografia('SELECT to_jsonb(e) AS l FROM enderecos_cliente e WHERE cliente_id = ? ORDER BY id', [$cliente]),
            'agendamentos' => $this->fotografia('SELECT to_jsonb(a) AS l FROM agendamentos a WHERE cliente_id = ? ORDER BY id', [$cliente]),
            'itens' => $this->fotografia('SELECT to_jsonb(i) AS l FROM agendamento_itens i JOIN agendamentos a ON a.id = i.agendamento_id WHERE a.cliente_id = ? ORDER BY i.id', [$cliente]),
            'eventos' => $this->fotografia('SELECT to_jsonb(e) AS l FROM agendamento_eventos e JOIN agendamentos a ON a.id = e.agendamento_id WHERE a.cliente_id = ? ORDER BY e.id', [$cliente]),
            'anonimizacoes' => $this->fotografia('SELECT to_jsonb(x) AS l FROM anonimizacoes x WHERE cliente_id = ?', [$cliente]),
        ];
    }

    public function test_depois_de_anonimizar_nenhum_dado_pessoal_sobra_em_nenhuma_coluna(): void
    {
        $c = $this->clienteComHistorico();
        foreach ($this->dadosPessoais() as $dado) {
            $this->assertNotEmpty($this->ocorrencias($dado), "cenario sem '{$dado}' antes de anonimizar");
        }

        $this->assertNotNull($this->anonimizar($c['cliente'], $this->proprietario()->id));

        foreach ($this->dadosPessoais() as $dado) {
            $this->assertSame([], $this->ocorrencias($dado), "'{$dado}' sobrou depois de anonimizar");
        }
    }

    /**
     * Varre TODAS as colunas text/varchar/char/jsonb do schema public
     * (information_schema: pega tambem colunas futuras), em todas as linhas.
     *
     * @return list<string> tabela.coluna onde o dado aparece
     */
    private function ocorrencias(string $dado): array
    {
        $colunas = DB::select(
            "SELECT c.table_name, c.column_name
               FROM information_schema.columns c
               JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
              WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE'
                AND c.data_type IN ('text', 'character varying', 'character', 'jsonb')
              ORDER BY 1, 2"
        );
        $tabelas = array_unique(array_column($colunas, 'table_name'));
        foreach (['clientes', 'enderecos_cliente', 'agendamentos', 'agendamento_itens', 'agendamento_eventos', 'anonimizacoes'] as $tabela) {
            $this->assertContains($tabela, $tabelas, "varredura nao alcanca {$tabela}");
        }

        $achados = [];
        foreach ($colunas as $c) {
            $sql = sprintf('SELECT count(*) FROM %s WHERE CAST(%s AS text) ILIKE ?',
                DB::scalar('SELECT quote_ident(?)', [$c->table_name]), DB::scalar('SELECT quote_ident(?)', [$c->column_name]));
            if ((int) DB::scalar($sql, ['%'.$dado.'%']) > 0) {
                $achados[] = "{$c->table_name}.{$c->column_name}";
            }
        }

        return $achados;
    }

    public function test_grava_os_valores_anonimizados_e_o_registro_sem_dado_pessoal(): void
    {
        $c = $this->clienteComHistorico();
        $dono = $this->proprietario();
        $eventos = DB::table('agendamento_eventos')->whereIn('agendamento_id', [$c['domicilio'], $c['cancelado'], $c['faltou']])->count();

        $registro = (int) $this->anonimizar($c['cliente'], $dono->id);

        $cliente = DB::table('clientes')->find($c['cliente']);
        $this->assertSame('Cliente anonimizado', $cliente->nome);
        $this->assertNull($cliente->telefone);
        $this->assertNull($cliente->observacoes);
        $this->assertNotNull($cliente->anonimizado_em);

        $endereco = DB::table('enderecos_cliente')->find($c['endereco']);
        $this->assertSame(['[anonimizado]', null, null], [$endereco->logradouro, $endereco->complemento, $endereco->referencia]);
        $this->assertNotNull($endereco->arquivado_em);

        foreach (DB::table('agendamentos')->where('cliente_id', $c['cliente'])->get() as $a) {
            $this->assertSame($a->id === $c['domicilio'] ? '[anonimizado]' : null, $a->endereco_texto);
            $this->assertNull($a->observacao_cliente);
            $this->assertNull($a->motivo_cancelamento);
            // Chave e hash saem juntos (agendamentos_idempotencia_coerente).
            $this->assertNull($a->chave_idempotencia);
            $this->assertNull($a->hash_requisicao);
        }

        $this->assertEquals([
            'cliente_id' => $c['cliente'], 'origem' => 'pedido_titular', 'usuario_id' => $dono->id, 'protocolo' => 'LGPD-2026/001',
            'enderecos_afetados' => 1, 'agendamentos_afetados' => 3, 'eventos_afetados' => $eventos,
        ], (array) DB::table('anonimizacoes')->where('id', $registro)
            ->first(['cliente_id', 'origem', 'usuario_id', 'protocolo', 'enderecos_afetados', 'agendamentos_afetados', 'eventos_afetados']));

        // So colunas sem texto livre: nada do que foi apagado fica guardado.
        $this->assertEqualsCanonicalizing(
            ['id', 'cliente_id', 'ocorrido_em', 'origem', 'usuario_id', 'protocolo', 'enderecos_afetados', 'agendamentos_afetados', 'eventos_afetados'],
            DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'anonimizacoes')->pluck('column_name')->all(),
        );
    }

    public function test_agenda_ocupacoes_itens_e_historico_continuam_integros(): void
    {
        $c = $this->clienteComHistorico();
        $outro = $this->novoCliente('Cliente de Controle', '+5511900001111');
        $ativoDoOutro = $this->novoAgendamento('08:00', '08:30', ['profissional_id' => $c['profissional'], 'cliente_id' => $outro]);

        $semDadoPessoal = "to_jsonb(a) - 'endereco_texto' - 'observacao_cliente' - 'motivo_cancelamento' - 'chave_idempotencia' - 'hash_requisicao' - 'updated_at'";
        $antes = [
            'ocupacoes' => $this->fotografia('SELECT to_jsonb(o) AS l FROM ocupacoes_agenda o ORDER BY id'),
            'itens' => $this->fotografia('SELECT to_jsonb(i) AS l FROM agendamento_itens i ORDER BY id'),
            'agendamentos' => $this->fotografia("SELECT {$semDadoPessoal} AS l FROM agendamentos a ORDER BY id"),
            'eventos' => $this->fotografia("SELECT to_jsonb(e) - 'dados' AS l, cleison_evento_dados_anonimos(dados) AS d FROM agendamento_eventos e ORDER BY id"),
            'outro' => $this->tudoDoCliente($outro),
        ];

        $this->anonimizar($c['cliente'], $this->proprietario()->id);

        $this->assertEquals($antes['ocupacoes'], $this->fotografia('SELECT to_jsonb(o) AS l FROM ocupacoes_agenda o ORDER BY id'));
        $this->assertEquals($antes['itens'], $this->fotografia('SELECT to_jsonb(i) AS l FROM agendamento_itens i ORDER BY id'));
        $this->assertEquals($antes['agendamentos'], $this->fotografia("SELECT {$semDadoPessoal} AS l FROM agendamentos a ORDER BY id"));
        // Mesmo numero de eventos, tudo igual, e dados = so a lista fechada.
        $this->assertEquals($antes['eventos'], $this->fotografia("SELECT to_jsonb(e) - 'dados' AS l, dados AS d FROM agendamento_eventos e ORDER BY id"));
        $this->assertEquals($antes['outro'], $this->tudoDoCliente($outro));

        // A exclusao continua valendo: o concluido anonimizado e o ativo do
        // outro cliente seguem segurando o horario.
        foreach ([['11:00', '11:30'], ['08:00', '08:30']] as [$inicio, $fim]) {
            $this->assertBancoRecusa('ocupacoes_sem_sobreposicao',
                fn () => $this->novoAgendamento($inicio, $fim, ['profissional_id' => $c['profissional']]));
        }
        $this->assertSame('confirmado', DB::table('agendamentos')->where('id', $ativoDoOutro)->value('estado'));
    }

    public function test_anonimizar_de_novo_nao_muda_nada_nem_duplica_o_registro(): void
    {
        $c = $this->clienteComHistorico();
        $dono = $this->proprietario();
        $this->assertNotNull($this->anonimizar($c['cliente'], $dono->id));
        $depoisDaPrimeira = $this->tudoDoCliente($c['cliente']);

        $this->assertNull($this->anonimizar($c['cliente'], $dono->id));
        $this->assertNull($this->anonimizar($c['cliente'], $dono->id, 'retencao', null));

        $this->assertEquals($depoisDaPrimeira, $this->tudoDoCliente($c['cliente']));
        $this->assertSame(1, DB::table('anonimizacoes')->where('cliente_id', $c['cliente'])->count());
    }

    public function test_recusa_cliente_com_agendamento_em_aberto_e_nada_muda(): void
    {
        $c = $this->clienteComHistorico();
        $this->novoAgendamento('18:00', '18:30', ['cliente_id' => $c['cliente']]); // confirmado
        $antes = $this->tudoDoCliente($c['cliente']);

        $this->assertBancoRecusa('clientes_anonimizacao_com_ativo', fn () => $this->anonimizar($c['cliente'], $this->proprietario()->id));

        $this->assertEquals($antes, $this->tudoDoCliente($c['cliente']));
    }

    public function test_so_proprietario_ativo_executa_conferido_no_banco(): void
    {
        $c = $this->clienteComHistorico();
        $antes = $this->tudoDoCliente($c['cliente']);

        $barbeiro = User::factory()->create(['papel' => PapelUsuario::Barbeiro]);
        $recepcao = User::factory()->create(['papel' => PapelUsuario::Recepcao]);
        $inativo = User::factory()->proprietario()->create(['ativo' => false]);

        foreach (['barbeiro' => $barbeiro->id, 'recepcao' => $recepcao->id, 'proprietario inativo' => $inativo->id, 'inexistente' => 999999, 'nulo' => null] as $quem => $usuario) {
            $this->assertBancoRecusa('anonimizacao_exige_proprietario', fn () => $this->anonimizar($c['cliente'], $usuario));
            $this->assertEquals($antes, $this->tudoDoCliente($c['cliente']), "{$quem}: algo mudou");
        }
        $this->assertSame(0, DB::table('anonimizacoes')->count());
    }

    public function test_protocolo_tem_formato_fechado(): void
    {
        $dono = $this->proprietario()->id;

        foreach (['LGPD 001', 'fulano@exemplo.com', str_repeat('A', 41), ''] as $protocolo) {
            $cliente = $this->novoCliente('Sem Historico');
            $this->assertBancoRecusa('anonimizacoes_protocolo', fn () => $this->anonimizar($cliente, $dono, 'pedido_titular', $protocolo));
        }

        $cliente = $this->novoCliente('Sem Historico');
        $this->assertBancoRecusa('anonimizacoes_protocolo_do_pedido', fn () => $this->anonimizar($cliente, $dono, 'pedido_titular', null));
        $this->assertBancoRecusa('anonimizacoes_origem', fn () => $this->anonimizar($cliente, $dono, 'outra', 'P-1'));

        $this->assertNotNull($this->anonimizar($cliente, $dono, 'retencao', null));
        $this->assertNotNull($this->anonimizar($this->novoCliente('Sem Historico'), $dono, 'pedido_titular', str_repeat('A', 40)));
    }

    public function test_aplicacao_nao_faz_a_troca_nem_com_variavel_de_sessao(): void
    {
        $c = $this->clienteComHistorico();
        $trocaExata = [
            'endereco_texto' => '[anonimizado]', 'observacao_cliente' => null, 'motivo_cancelamento' => null,
            'chave_idempotencia' => null, 'hash_requisicao' => null,
        ];

        foreach ([[], ['cleison.anonimizando' => 'sim'], ['cleison.ator' => 'sistema'], ['cleison.dono' => 'sim']] as $variaveis) {
            $this->assertBancoRecusa('agendamentos_encerrado_imutavel', function () use ($c, $trocaExata, $variaveis) {
                foreach ($variaveis as $nome => $valor) {
                    DB::select('SELECT set_config(?, ?, true)', [$nome, $valor]);
                }
                DB::table('agendamentos')->where('id', $c['domicilio'])->update($trocaExata);
            });
        }

        // Historico e registro: a aplicacao nem tem UPDATE/DELETE/INSERT.
        $dono = $this->proprietario()->id;
        foreach ([
            fn () => DB::table('agendamento_eventos')->where('agendamento_id', $c['cancelado'])->update(['dados' => DB::raw('cleison_evento_dados_anonimos(dados)')]),
            fn () => DB::table('anonimizacoes')->delete(),
            fn () => DB::table('anonimizacoes')->insert(['cliente_id' => $c['cliente'], 'origem' => 'retencao', 'usuario_id' => $dono,
                'enderecos_afetados' => 0, 'agendamentos_afetados' => 0, 'eventos_afetados' => 0]),
        ] as $tentativa) {
            $this->assertBancoRecusaCom42501($tentativa);
        }

        // Nem virar o dono.
        $papelDono = DB::scalar("SELECT quote_ident(tableowner) FROM pg_tables WHERE schemaname = 'public' AND tablename = 'agendamentos'");
        $this->assertBancoRecusaCom42501(fn () => DB::statement("SET ROLE {$papelDono}"));
        $this->assertBancoRecusaCom42501(fn () => DB::statement("SET SESSION AUTHORIZATION {$papelDono}"));
    }

    private function assertBancoRecusaCom42501(callable $acao): void
    {
        try {
            DB::transaction(fn () => $acao());
            $this->fail('A aplicacao conseguiu.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }

    public function test_cliente_anonimizado_fica_fechado(): void
    {
        $c = $this->clienteComHistorico();
        $outro = $this->novoCliente('Cliente de Controle');
        $doOutro = $this->novoAgendamento('08:00', '08:30', ['cliente_id' => $outro]);
        $this->anonimizar($c['cliente'], $this->proprietario()->id);

        $this->assertBancoRecusa('clientes_anonimizado_imutavel',
            fn () => DB::table('clientes')->where('id', $c['cliente'])->update(['nome' => 'Zebedeu de Volta']));
        $this->assertBancoRecusa('clientes_anonimizado_fechado',
            fn () => DB::table('enderecos_cliente')->insert(['cliente_id' => $c['cliente'], 'logradouro' => 'Rua Nova 100']));
        $this->assertBancoRecusa('clientes_anonimizado_fechado',
            fn () => DB::table('enderecos_cliente')->where('id', $c['endereco'])->update(['referencia' => 'Portao Novo']));
        $this->assertBancoRecusa('clientes_anonimizado_fechado',
            fn () => $this->novoAgendamento('19:00', '19:30', ['cliente_id' => $c['cliente']]));
        $this->assertBancoRecusa('clientes_anonimizado_fechado',
            fn () => DB::table('agendamentos')->where('id', $doOutro)->update(['cliente_id' => $c['cliente']]));

        // So a funcao marca anonimizado_em.
        $this->assertBancoRecusa('clientes_anonimizacao_so_pela_funcao',
            fn () => DB::table('clientes')->where('id', $outro)->update([
                'nome' => 'Cliente anonimizado', 'telefone' => null, 'observacoes' => null, 'anonimizado_em' => now()]));
        $this->assertBancoRecusa('clientes_anonimizacao_so_pela_funcao',
            fn () => DB::table('clientes')->insert(['nome' => 'Cliente anonimizado', 'anonimizado_em' => now()]));
    }

    public function test_telefone_liberado_serve_para_um_cliente_novo(): void
    {
        $c = $this->clienteComHistorico();
        $this->anonimizar($c['cliente'], $this->proprietario()->id);

        $novo = $this->novoCliente('Cliente Que Voltou', '+5511987651234');

        $this->assertNotSame($c['cliente'], $novo);
    }

    public function test_execute_so_para_o_papel_da_aplicacao_e_funcoes_com_papel_certo(): void
    {
        $assinatura = 'public.cleison_anonimizar_cliente(bigint, bigint, varchar, varchar)';

        $this->assertNotNull(DB::scalar('SELECT proacl FROM pg_proc WHERE oid = ?::regprocedure', [$assinatura]),
            'proacl nulo = privilegio padrao (EXECUTE para PUBLIC)');
        $comExecute = array_map('intval', array_column(DB::select(
            "SELECT a.grantee FROM pg_proc p CROSS JOIN LATERAL aclexplode(p.proacl) a
              WHERE p.oid = ?::regprocedure AND a.privilege_type = 'EXECUTE'", [$assinatura]), 'grantee'));

        $this->assertNotContains(0, $comExecute, 'EXECUTE para PUBLIC');
        $this->assertEqualsCanonicalizing([
            (int) DB::scalar('SELECT proowner FROM pg_proc WHERE oid = ?::regprocedure', [$assinatura]),
            (int) DB::scalar('SELECT oid FROM pg_roles WHERE rolname = ?', [config('database.connections.pgsql.username')]),
        ], $comExecute);

        $funcao = DB::selectOne('SELECT prosecdef, proconfig FROM pg_proc WHERE oid = ?::regprocedure', [$assinatura]);
        $this->assertTrue($funcao->prosecdef);
        $this->assertSame('{"search_path=pg_catalog, public, pg_temp"}', $funcao->proconfig);

        // Como SECURITY DEFINER, current_user la dentro seria sempre o dono.
        $this->assertFalse((bool) DB::scalar("SELECT prosecdef FROM pg_proc WHERE oid = 'public.cleison_papel_atual_e_dono(oid)'::regprocedure"));
    }

    /**
     * Chaves (argumentos de posicao impar) de cada jsonb_build_object(...)
     * do codigo, inclusive os aninhados.
     *
     * @return list<string>
     */
    private function chavesDosJsonbBuildObject(string $fonte): array
    {
        $chaves = [];
        $pos = 0;
        while (($inicio = stripos($fonte, 'jsonb_build_object(', $pos)) !== false) {
            $i = $inicio + strlen('jsonb_build_object(');
            $profundidade = 1;
            $argumento = 0;
            $atual = '';
            for (; $i < strlen($fonte) && $profundidade > 0; $i++) {
                $ch = $fonte[$i];
                if ($ch === '(') {
                    $profundidade++;
                } elseif ($ch === ')') {
                    $profundidade--;
                }
                if ($profundidade === 1 && $ch === ',' || $profundidade === 0) {
                    if ($argumento % 2 === 0 && preg_match("/^\s*'([a-z_]+)'\s*$/", $atual, $m)) {
                        $chaves[] = $m[1];
                    }
                    $argumento++;
                    $atual = '';
                } else {
                    $atual .= $ch;
                }
            }
            $pos = $inicio + 1; // os aninhados sao lidos na proxima volta
        }

        return $chaves;
    }

    /** A lista fechada acompanha o trigger: chave nova no historico exige decisao. */
    public function test_lista_fechada_do_jsonb_cobre_tudo_que_o_trigger_grava(): void
    {
        $fonte = (string) DB::scalar("SELECT pg_get_functiondef('public.cleison_registrar_evento_agendamento()'::regprocedure)");
        $gravadas = array_values(array_unique($this->chavesDosJsonbBuildObject($fonte)));

        $this->assertEqualsCanonicalizing(array_merge(self::CHAVES_MANTIDAS, self::CHAVES_DE_TEXTO_LIVRE), $gravadas,
            'O trigger de historico grava uma chave que a anonimizacao nao conhece: decida se e texto livre.');

        $entrada = [
            'inicio_servico' => '2026-10-01T13:00:00+00:00', 'origem' => 'site', 'profissional_id' => 3,
            'motivo' => 'Zebedeu viajou', 'nota' => 'texto livre futuro', 'obj' => ['x' => 'y'], 'origem_lista' => ['a'],
            'de' => ['inicio_servico' => '2026-10-01T13:00:00+00:00', 'motivo' => 'escondido', 'profissional_id' => 3],
            'para' => 'nao e objeto',
        ];
        $saida = json_decode((string) DB::scalar('SELECT cleison_evento_dados_anonimos(?::jsonb)', [json_encode($entrada)]), true);

        $this->assertEquals([
            'de' => ['inicio_servico' => '2026-10-01T13:00:00+00:00', 'profissional_id' => 3],
            'origem' => 'site', 'inicio_servico' => '2026-10-01T13:00:00+00:00', 'profissional_id' => 3,
        ], $saida);
        $this->assertSame('{}', DB::scalar("SELECT cleison_evento_dados_anonimos('[1, 2]'::jsonb)::text"));
    }
}
