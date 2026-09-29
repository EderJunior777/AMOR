<?php

namespace Tests\Suporte;

use Illuminate\Support\Facades\DB;

/**
 * Atalhos para montar cenarios direto no banco (SQL via query builder, sem
 * passar por regra de aplicacao): o que se testa aqui e o que o PostgreSQL
 * garante sozinho.
 *
 * Horarios: "HH:MM" no dia base, em -03:00 (America/Sao_Paulo).
 */
trait DadosDeAgenda
{
    protected string $diaBase = '2026-10-01';

    private ?int $servicoPadrao = null;

    protected function em(string $hhmm, ?string $dia = null): string
    {
        return ($dia ?? $this->diaBase)." {$hhmm}:00-03:00";
    }

    protected function novoProfissional(string $nome = 'Profissional Teste'): int
    {
        return DB::table('profissionais')->insertGetId(['nome_exibicao' => $nome]);
    }

    protected function novoCliente(string $nome = 'Cliente Teste', ?string $telefone = null): int
    {
        return DB::table('clientes')->insertGetId(['nome' => $nome, 'telefone' => $telefone]);
    }

    protected function novoServico(array $dados = []): int
    {
        static $seq = 0;
        $seq++;

        return DB::table('servicos')->insertGetId($dados + [
            'codigo' => "servico-{$seq}-".bin2hex(random_bytes(3)),
            'nome' => 'Corte',
            'preco_centavos' => 4000,
            'duracao_minutos' => 30,
            'conta_como_corte' => true,
        ]);
    }

    protected function novaRegiao(array $dados = []): int
    {
        return DB::table('regioes_atendimento')->insertGetId($dados + [
            'codigo' => 'regiao-'.bin2hex(random_bytes(3)),
            'nome' => 'Zona Sul',
            'deslocamento_minutos' => 30,
            'taxa_centavos' => 2000,
        ]);
    }

    /**
     * Cria agendamento + itens. Por padrao: barbearia, confirmado, um item
     * cuja duracao cobre exatamente o servico, ocupado = servico.
     *
     * @param  array<string, mixed>  $dados  colunas de agendamentos a sobrescrever
     * @param  list<array<string, mixed>>|null  $itens
     */
    protected function novoAgendamento(string $inicio, string $fim, array $dados = [], ?array $itens = null): int
    {
        $dados += [
            'profissional_id' => $dados['profissional_id'] ?? $this->novoProfissional(),
            'cliente_id' => $dados['cliente_id'] ?? $this->novoCliente(),
            'estado' => 'confirmado',
            'origem' => 'site',
            'modalidade' => 'barbearia',
            'inicio_servico' => $this->em($inicio),
            'fim_servico' => $this->em($fim),
        ];
        $dados += [
            'inicio_ocupado' => $dados['inicio_servico'],
            'fim_ocupado' => $dados['fim_servico'],
        ];

        $id = DB::table('agendamentos')->insertGetId($dados);

        if ($itens === null) {
            $minutos = (int) DB::selectOne(
                'SELECT extract(epoch FROM (?::timestamptz - ?::timestamptz))::int / 60 AS m',
                [$dados['fim_servico'], $dados['inicio_servico']]
            )->m;
            $itens = [['duracao_minutos' => $minutos]];
        }

        // O id em cache pode ter sido criado num savepoint ja desfeito.
        if ($this->servicoPadrao === null || ! DB::table('servicos')->where('id', $this->servicoPadrao)->exists()) {
            $this->servicoPadrao = $this->novoServico();
        }

        foreach (array_values($itens) as $i => $item) {
            DB::table('agendamento_itens')->insert($item + [
                'agendamento_id' => $id,
                'servico_id' => $this->servicoPadrao,
                'ordem' => $i + 1,
                'servico_nome' => 'Corte',
                'preco_centavos' => 4000,
                'duracao_minutos' => 30,
                'conta_como_corte' => true,
            ]);
        }

        return $id;
    }

    /** Periodos ocupados do profissional, como texto do PostgreSQL em -03. */
    protected function ocupacoes(int $profissionalId): array
    {
        DB::statement("SET LOCAL TIME ZONE 'America/Sao_Paulo'");

        return DB::table('ocupacoes_agenda')
            ->where('profissional_id', $profissionalId)
            ->orderByRaw('lower(periodo)')
            ->pluck('periodo')
            ->all();
    }

    /** Define quem esta agindo (lido pelo trigger de historico). */
    protected function comoAtor(string $ator, ?int $usuarioId = null): void
    {
        DB::select("SELECT set_config('cleison.ator', ?, true)", [$ator]);
        DB::select("SELECT set_config('cleison.usuario_id', ?, true)", [$usuarioId === null ? '' : (string) $usuarioId]);
    }
}
