<?php

namespace Database\Seeders;

use App\Models\Estabelecimento;
use App\Models\ExpedienteSemanal;
use App\Models\Profissional;
use App\Models\RegiaoAtendimento;
use App\Models\Servico;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dados de DEMONSTRACAO copiados do assets/config.js do ZIP original
 * ("Barbearia do Ze", precos, regioes, horario 08:00-20:00, todos os dias).
 * NAO sao dados confirmados do Cleison.
 *
 * Salvaguardas:
 *   - recusa rodar em producao;
 *   - recusa sobrescrever um estabelecimento real (dados_demonstracao=false);
 *   - marca estabelecimento.dados_demonstracao = true e poe "(demonstracao)"
 *     nos nomes, para ninguem confundir com a marca real;
 *   - nao cria usuario nem senha;
 *   - pode rodar de novo sem duplicar nada.
 */
class DemonstracaoSeeder extends Seeder
{
    /** config.js: preco em reais -> centavos; conta_como_corte e decisao pendente. */
    private const SERVICOS = [
        ['codigo' => 'corte', 'nome' => 'Corte', 'preco' => 4000, 'duracao' => 30, 'corte' => true, 'descricao' => 'Maquina, tesoura e acabamento.'],
        ['codigo' => 'barba', 'nome' => 'Barba', 'preco' => 3000, 'duracao' => 30, 'corte' => false, 'descricao' => 'Toalha quente, navalha e balm.'],
        ['codigo' => 'corte-barba', 'nome' => 'Corte + Barba', 'preco' => 6500, 'duracao' => 60, 'corte' => true, 'descricao' => 'O combo completo.'],
        ['codigo' => 'degrade', 'nome' => 'Degrade', 'preco' => 5000, 'duracao' => 60, 'corte' => true, 'descricao' => 'Fade caprichado, do zero ao topo.'],
        ['codigo' => 'pezinho', 'nome' => 'Pezinho', 'preco' => 2000, 'duracao' => 30, 'corte' => false, 'descricao' => 'So o acabamento pra segurar a semana.'],
        ['codigo' => 'infantil', 'nome' => 'Corte infantil', 'preco' => 3500, 'duracao' => 30, 'corte' => true, 'descricao' => 'Paciencia inclusa.'],
    ];

    /** config.js: taxaDomicilio unica de R$ 20 aplicada a todas as regioes. */
    private const REGIOES = [
        ['codigo' => 'centro', 'nome' => 'Centro', 'deslocamento' => 15],
        ['codigo' => 'zona-sul', 'nome' => 'Zona Sul', 'deslocamento' => 30],
        ['codigo' => 'zona-norte', 'nome' => 'Zona Norte', 'deslocamento' => 30],
        ['codigo' => 'zona-leste', 'nome' => 'Zona Leste', 'deslocamento' => 45],
        ['codigo' => 'zona-oeste', 'nome' => 'Zona Oeste', 'deslocamento' => 45],
    ];

    private const TAXA_DOMICILIO_CENTAVOS = 2000;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Seed de demonstracao nao roda em producao.');
        }

        DB::transaction(function () {
            $existente = Estabelecimento::query()->lockForUpdate()->find(Estabelecimento::ID);
            if ($existente && ! $existente->dados_demonstracao) {
                throw new RuntimeException(
                    'Este banco ja tem um estabelecimento real (dados_demonstracao=false). '
                    .'O seed de demonstracao nao vai sobrescreve-lo.'
                );
            }

            // forceFill: dados_demonstracao nao e atribuivel em massa.
            ($existente ?? new Estabelecimento)->forceFill([
                'nome' => 'Barbearia do Ze (demonstracao)',
                'fuso_horario' => 'America/Sao_Paulo',
                'whatsapp' => '+5511987654321',
                'endereco' => 'Rua das Tesouras, 100 - Centro (endereco de exemplo)',
                'grade_minutos' => 30,
                'antecedencia_minima_minutos' => 30,
                'horizonte_dias' => 30,
                'domicilio_ativo' => true,
                'dados_demonstracao' => true,
            ])->save();

            $servicos = collect(self::SERVICOS)->values()->map(fn (array $s, int $i) => Servico::query()->updateOrCreate(
                ['codigo' => $s['codigo']],
                [
                    'nome' => $s['nome'],
                    'descricao' => $s['descricao'],
                    'preco_centavos' => $s['preco'],
                    'duracao_minutos' => $s['duracao'],
                    'conta_como_corte' => $s['corte'],
                    'permite_barbearia' => true,
                    'permite_domicilio' => true,
                    'ativo' => true,
                    'ordem' => $i + 1,
                ]
            ));

            foreach (self::REGIOES as $i => $r) {
                RegiaoAtendimento::query()->updateOrCreate(['codigo' => $r['codigo']], [
                    'nome' => $r['nome'],
                    'deslocamento_minutos' => $r['deslocamento'],
                    'taxa_centavos' => self::TAXA_DOMICILIO_CENTAVOS,
                    'ativo' => true,
                    'ordem' => $i + 1,
                ]);
            }

            $ze = Profissional::query()->firstOrCreate(
                ['nome_exibicao' => 'Ze (demonstracao)'],
                ['ativo' => true, 'ordem' => 1]
            );
            $ze->servicos()->syncWithoutDetaching($servicos->pluck('id')->all());

            // config.js: abertura 08:00, fechamento 20:00, diasFechados [].
            foreach (range(0, 6) as $dia) {
                ExpedienteSemanal::query()->firstOrCreate([
                    'profissional_id' => $ze->id,
                    'dia_semana' => $dia,
                    'hora_inicio' => '08:00',
                    'hora_fim' => '20:00',
                ]);
            }
        });
    }
}
