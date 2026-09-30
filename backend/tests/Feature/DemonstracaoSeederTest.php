<?php

namespace Tests\Feature;

use App\Models\Estabelecimento;
use App\Models\Profissional;
use App\Models\RegiaoAtendimento;
use App\Models\Servico;
use App\Models\User;
use Database\Seeders\DemonstracaoSeeder;
use RuntimeException;
use Tests\Suporte\BancoDeTeste;
use Tests\TestCase;

class DemonstracaoSeederTest extends TestCase
{
    use BancoDeTeste;

    public function test_cria_dados_de_demonstracao_identificados_e_nenhum_usuario(): void
    {
        $this->seed(DemonstracaoSeeder::class);

        $estabelecimento = Estabelecimento::atual();
        $this->assertTrue($estabelecimento->dados_demonstracao);
        $this->assertStringContainsString('demonstracao', $estabelecimento->nome);
        $this->assertSame('America/Sao_Paulo', $estabelecimento->fuso_horario);

        $this->assertSame(0, User::query()->count(), 'seed nao cria login nem senha');

        // Precos do config.js (reais) convertidos para centavos inteiros.
        $this->assertSame(
            [4000, 3000, 6500, 5000, 2000, 3500],
            Servico::query()->orderBy('ordem')->pluck('preco_centavos')->all()
        );
        $this->assertSame(
            ['corte', 'corte-barba', 'degrade', 'infantil'],
            Servico::query()->where('conta_como_corte', true)->orderBy('ordem')->pluck('codigo')->all()
        );
        $this->assertSame(5, RegiaoAtendimento::query()->where('taxa_centavos', 2000)->count());

        $ze = Profissional::query()->sole();
        $this->assertStringContainsString('demonstracao', $ze->nome_exibicao);
        $this->assertSame(6, $ze->servicos()->count());
        $this->assertSame(7, $ze->expedientes()->count());
    }

    public function test_rodar_de_novo_nao_duplica(): void
    {
        $this->seed(DemonstracaoSeeder::class);
        $this->seed(DemonstracaoSeeder::class);

        $this->assertSame(6, Servico::query()->count());
        $this->assertSame(5, RegiaoAtendimento::query()->count());
        $this->assertSame(1, Profissional::query()->count());
        $this->assertSame(7, Profissional::query()->sole()->expedientes()->count());
    }

    public function test_nao_sobrescreve_estabelecimento_real(): void
    {
        Estabelecimento::query()->forceCreate(['nome' => 'Barbearia Real', 'dados_demonstracao' => false]);

        try {
            $this->seed(DemonstracaoSeeder::class);
            $this->fail('Seed de demonstracao sobrescreveu um estabelecimento real.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('estabelecimento real', $e->getMessage());
        }

        $this->assertSame('Barbearia Real', Estabelecimento::atual()->nome);
        $this->assertSame(0, Servico::query()->count());
    }

    public function test_recusa_rodar_em_producao(): void
    {
        $this->app['env'] = 'production';

        // Chama o seeder direto: `db:seed` em producao ja pede confirmacao,
        // mas a trava que importa e a do proprio seeder.
        try {
            $this->app->make(DemonstracaoSeeder::class)->run();
            $this->fail('Seed de demonstracao rodou em producao.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('producao', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertNull(Estabelecimento::atual());
    }
}
