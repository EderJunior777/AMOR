<?php

namespace Tests\Feature\Painel;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

/**
 * O IP nunca vai para o banco (decisao da etapa 3): nem na auditoria, nem na
 * tabela de sessoes do Laravel (que, por padrao, guarda ip_address e
 * user_agent de toda sessao).
 */
class SessaoSemIpTest extends TestCase
{
    use BancoDeTeste;

    public function test_a_tabela_de_sessoes_nao_tem_coluna_de_ip_nem_de_user_agent(): void
    {
        $this->assertFalse(Schema::hasColumn('sessions', 'ip_address'));
        $this->assertFalse(Schema::hasColumn('sessions', 'user_agent'));
        $this->assertTrue(Schema::hasColumns('sessions', ['id', 'user_id', 'payload', 'last_activity']));
    }

    public function test_com_a_sessao_no_banco_o_ip_e_o_navegador_nao_sao_gravados(): void
    {
        config(['session.driver' => 'database']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->withHeader('User-Agent', 'NavegadorDeTeste/9.9 (segredo-do-aparelho)')
            ->get('/painel/entrar')
            ->assertOk();

        $linhas = DB::table('sessions')->get();
        $this->assertCount(1, $linhas, 'a sessao foi gravada no banco');
        $tudo = json_encode($linhas);
        $this->assertStringNotContainsString('203.0.113.77', $tudo);
        $this->assertStringNotContainsString('NavegadorDeTeste', $tudo);
        $this->assertStringNotContainsString('segredo-do-aparelho', $tudo);
    }

    public function test_a_sessao_de_quem_entrou_fica_ligada_ao_usuario(): void
    {
        config(['session.driver' => 'database']);
        $usuario = User::factory()->state(['password' => 'SenhaSegura12345', 'senha_temporaria' => false])->create();

        $this->post('/painel/entrar', ['email' => $usuario->email, 'senha' => 'SenhaSegura12345'])->assertRedirect('/painel');

        $this->assertSame(1, DB::table('sessions')->where('user_id', $usuario->id)->count(),
            'user_id continua gravado: e ele que permite derrubar as sessoes ao desativar');
    }
}
