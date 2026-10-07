<?php

namespace Tests\Feature\Painel;

use App\Support\LimiteDeLogin;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * O limite conta ANTES de conferir a senha (a conferencia demora centenas de
 * ms; requisicoes paralelas passariam todas pela checagem antes de qualquer
 * contagem). Chaves de e-mail+IP, de e-mail (teto) e de IP; tudo no cache.
 */
class LimiteDeLoginTest extends TestCase
{
    public function test_contar_antes_de_conferir_nao_deixa_passar_mais_que_o_maximo_numa_corrida(): void
    {
        // Simula N requisicoes paralelas: todas passam pela checagem (espera() nulo)
        // antes de qualquer contagem; so as primeiras 5 podem conferir a senha.
        $podemConferir = 0;
        $barradas = 0;
        for ($i = 0; $i < 12; $i++) {
            LimiteDeLogin::contar('alvo@exemplo.com', '203.0.113.1') === null ? $podemConferir++ : $barradas++;
        }

        $this->assertSame(5, $podemConferir, 'no maximo 5 palpites por e-mail e IP na janela, mesmo em paralelo');
        $this->assertSame(7, $barradas);
    }

    public function test_o_teto_por_ip_vale_em_paralelo_para_qualquer_email(): void
    {
        $podem = 0;
        for ($i = 0; $i < 30; $i++) {
            if (LimiteDeLogin::contar("alvo{$i}@exemplo.com", '203.0.113.2') === null) {
                $podem++;
            }
        }

        $this->assertSame(20, $podem);
    }

    public function test_espera_e_nula_ate_o_maximo_e_positiva_depois(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(LimiteDeLogin::espera('a@exemplo.com', '203.0.113.3'));
            LimiteDeLogin::contar('a@exemplo.com', '203.0.113.3');
        }

        $this->assertGreaterThan(0, LimiteDeLogin::espera('a@exemplo.com', '203.0.113.3'));
        $this->assertNull(LimiteDeLogin::espera('a@exemplo.com', '203.0.113.4'), 'outro IP, mesmo e-mail');
    }

    public function test_login_certo_limpa_so_o_par_email_e_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            LimiteDeLogin::contar('a@exemplo.com', '203.0.113.5');
            LimiteDeLogin::contar('a@exemplo.com', '203.0.113.6');
        }

        LimiteDeLogin::loginCerto('a@exemplo.com', '203.0.113.5');

        $this->assertNull(LimiteDeLogin::espera('a@exemplo.com', '203.0.113.5'));
        $this->assertNotNull(LimiteDeLogin::espera('a@exemplo.com', '203.0.113.6'));
    }

    public function test_login_certo_devolve_a_tentativa_nos_contadores_de_ip_e_de_email(): void
    {
        // 25 logins certos do mesmo IP (teto 20) e do mesmo e-mail por IPs
        // diferentes (teto 30 somando os IPs): nada acumula.
        for ($i = 0; $i < 25; $i++) {
            $this->assertNull(LimiteDeLogin::contar("pessoa{$i}@exemplo.com", '203.0.113.12'));
            LimiteDeLogin::loginCerto("pessoa{$i}@exemplo.com", '203.0.113.12');
        }
        for ($i = 0; $i < 35; $i++) {
            $this->assertNull(LimiteDeLogin::contar('dono@exemplo.com', "198.51.100.{$i}"));
            LimiteDeLogin::loginCerto('dono@exemplo.com', "198.51.100.{$i}");
        }

        $this->assertNull(LimiteDeLogin::espera('outra@exemplo.com', '203.0.113.12'));
        $this->assertNull(LimiteDeLogin::espera('dono@exemplo.com', '198.51.100.200'));
    }

    public function test_login_certo_nao_apaga_as_falhas_anteriores_do_ip(): void
    {
        // 19 falhas de e-mails variados, um login certo no meio e mais uma
        // falha: sao 20 falhas, o IP bloqueia no mesmo numero de antes.
        for ($i = 0; $i < 19; $i++) {
            LimiteDeLogin::contar("varredura{$i}@exemplo.com", '203.0.113.13');
        }
        LimiteDeLogin::contar('certo@exemplo.com', '203.0.113.13');
        LimiteDeLogin::loginCerto('certo@exemplo.com', '203.0.113.13');
        $this->assertNull(LimiteDeLogin::contar('varredura19@exemplo.com', '203.0.113.13'), 'a 20a falha ainda confere');

        $this->assertNotNull(LimiteDeLogin::contar('varredura20@exemplo.com', '203.0.113.13'), 'a 21a tentativa e barrada');
        $this->assertNotNull(LimiteDeLogin::espera('certo@exemplo.com', '203.0.113.13'));
    }

    public function test_login_certo_sem_contagem_nao_deixa_contador_negativo(): void
    {
        // Janela que acabou entre contar() e o login certo: nada a devolver.
        // Sem a guarda, o contador ia a -1 e o IP ganhava uma tentativa extra.
        LimiteDeLogin::loginCerto('a@exemplo.com', '203.0.113.14');

        $podem = 0;
        for ($i = 0; $i < 30; $i++) {
            if (LimiteDeLogin::contar("alvo{$i}@exemplo.com", '203.0.113.14') === null) {
                $podem++;
            }
        }

        $this->assertSame(20, $podem);
    }

    public function test_o_email_e_normalizado_para_a_chave(): void
    {
        for ($i = 0; $i < 5; $i++) {
            LimiteDeLogin::contar('  Fulano@Exemplo.COM ', '203.0.113.7');
        }

        $this->assertNotNull(LimiteDeLogin::espera('fulano@exemplo.com', '203.0.113.7'), 'maiusculas e espacos nao criam outra chave');
    }

    public function test_o_primeiro_bloqueio_da_janela_e_so_um(): void
    {
        $this->assertTrue(LimiteDeLogin::primeiroBloqueio('a@exemplo.com', '203.0.113.8', 60));
        $this->assertFalse(LimiteDeLogin::primeiroBloqueio('a@exemplo.com', '203.0.113.8', 60));
        $this->assertTrue(LimiteDeLogin::primeiroBloqueio('a@exemplo.com', '203.0.113.9', 60), 'outro IP tem o seu');
    }

    public function test_nenhuma_chave_do_cache_leva_email_nem_ip_crus(): void
    {
        LimiteDeLogin::contar('segredo@exemplo.com', '203.0.113.10');
        LimiteDeLogin::primeiroBloqueio('segredo@exemplo.com', '203.0.113.10', 60);

        $conteudo = serialize(Cache::store('array')->getStore());
        $this->assertStringNotContainsString('segredo@exemplo.com', $conteudo);
        $this->assertStringNotContainsString('203.0.113.10', $conteudo);
        $this->assertStringContainsString('painel-login', $conteudo, 'as chaves do limite estao no cache (em HMAC)');
    }

    public function test_configuracao_invalida_falha_fechado(): void
    {
        config(['cleison.painel.login_max_falhas_por_ip' => 0]);

        $this->expectException(InvalidArgumentException::class);
        LimiteDeLogin::espera('a@exemplo.com', '203.0.113.11');
    }
}
