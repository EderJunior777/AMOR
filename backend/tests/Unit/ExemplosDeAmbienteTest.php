<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Os arquivos de exemplo viram o .env de alguem. Um valor inseguro aqui
 * vence o padrao seguro de config/*.php (env() so cai no padrao quando a
 * variavel NAO existe), entao o exemplo nao pode definir o que deve ficar
 * a cargo do padrao.
 */
class ExemplosDeAmbienteTest extends TestCase
{
    /** @return array<string, string> variaveis definidas (linhas nao comentadas) */
    private function variaveis(string $arquivo): array
    {
        $caminho = dirname(__DIR__, 2).'/'.$arquivo;
        $this->assertFileExists($caminho);

        $vars = [];
        foreach (file($caminho, FILE_IGNORE_NEW_LINES) as $linha) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=(.*)$/', $linha, $m)) {
                $vars[$m[1]] = trim($m[2]);
            }
        }

        return $vars;
    }

    public function test_exemplo_nao_desliga_cookie_seguro_nem_liga_debug(): void
    {
        $vars = $this->variaveis('.env.example');

        // Definida (mesmo "false"), anula o padrao "true em producao" de
        // config/session.php. Quem roda local em http descomenta.
        $this->assertArrayNotHasKey('SESSION_SECURE_COOKIE', $vars);
        $this->assertSame('false', $vars['APP_DEBUG'] ?? null);
    }

    public function test_exemplo_nao_traz_senha_nem_chave(): void
    {
        foreach (['.env.example', '.env.production.example'] as $arquivo) {
            $vars = $this->variaveis($arquivo);
            foreach (['APP_KEY', 'DB_PASSWORD', 'DB_MIGRACAO_PASSWORD'] as $nome) {
                $this->assertSame('', $vars[$nome] ?? '', "{$arquivo}: {$nome} deve vir vazio");
            }
        }
    }

    public function test_exemplo_de_producao_e_seguro_por_padrao(): void
    {
        $vars = $this->variaveis('.env.production.example');

        $this->assertSame('production', $vars['APP_ENV'] ?? null);
        $this->assertSame('false', $vars['APP_DEBUG'] ?? null);
        $this->assertSame('daily', $vars['LOG_STACK'] ?? null);
        $this->assertSame('14', $vars['LOG_DAILY_DAYS'] ?? null);
        $this->assertSame('warning', $vars['LOG_LEVEL'] ?? null);
        $this->assertSame('verify-full', $vars['DB_SSLMODE'] ?? null);
        $this->assertContains($vars['SESSION_SECURE_COOKIE'] ?? 'ausente', ['ausente', 'true']);

        // A senha (e o usuario/URL) do papel dono nao mora no servidor web:
        // migrations rodam no pipeline de deploy, com segredo proprio.
        $this->assertSame([], preg_grep('/^DB_MIGRACAO_/', array_keys($vars)));
    }
}
