<?php

namespace Tests\Suporte;

use App\Domain\Agenda\PedidoDeReserva;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cenario da reserva (App\Domain\Agenda\ReservarHorario): estabelecimento
 * (Sao Paulo, grade 30, antecedencia 30 min, horizonte 30 dias), um
 * profissional com expediente 08:00-12:00 e 13:00-20:00 em todos os dias,
 * dois servicos vinculados (corte e barba, 30 min cada) e uma regiao.
 *
 * "Agora" fixo: 2026-10-05 10:00 em Sao Paulo (13:00 UTC), segunda-feira.
 * A reserva padrao e quarta 2026-10-07, 10:00. Quem usa deve chamar
 * Carbon::setTestNow() (sem argumento) no tearDown.
 *
 * Grava direto no banco (query builder): so prepara o terreno.
 */
trait DadosDeReserva
{
    protected int $profissionalId;

    protected int $corteId;

    protected int $barbaId;

    protected int $regiaoId;

    protected function fixarRelogio(string $instante = '2026-10-05 13:00:00'): void
    {
        Carbon::setTestNow(Carbon::parse($instante, 'UTC'));
    }

    /** @param array<string, mixed> $estabelecimento colunas a sobrescrever */
    protected function montarAgendaDeReserva(array $estabelecimento = []): void
    {
        DB::table('estabelecimento')->updateOrInsert(['id' => 1], $estabelecimento + [
            'nome' => 'Barbearia de Teste',
            'fuso_horario' => 'America/Sao_Paulo',
            'grade_minutos' => 30,
            'antecedencia_minima_minutos' => 30,
            'horizonte_dias' => 30,
            'domicilio_ativo' => true,
        ]);

        $this->profissionalId = DB::table('profissionais')->insertGetId(['nome_exibicao' => 'Ze do Corte']);
        $this->corteId = $this->novoServicoDeReserva('Corte', 4000, 30);
        $this->barbaId = $this->novoServicoDeReserva('Barba', 3000, 30);
        $this->vincular($this->profissionalId, [$this->corteId, $this->barbaId]);
        $this->regiaoId = DB::table('regioes_atendimento')->insertGetId([
            'codigo' => 'zona-sul-'.bin2hex(random_bytes(3)),
            'nome' => 'Zona Sul',
            'deslocamento_minutos' => 30,
            'taxa_centavos' => 2000,
        ]);
        $this->expedientePadrao($this->profissionalId);
    }

    protected function novoServicoDeReserva(string $nome, int $preco, int $duracao, array $extra = []): int
    {
        return DB::table('servicos')->insertGetId($extra + [
            'codigo' => strtolower($nome).'-'.bin2hex(random_bytes(3)),
            'nome' => $nome,
            'preco_centavos' => $preco,
            'duracao_minutos' => $duracao,
            'conta_como_corte' => $nome === 'Corte',
        ]);
    }

    /** @param list<int> $servicos */
    protected function vincular(int $profissional, array $servicos): void
    {
        foreach ($servicos as $servico) {
            DB::table('profissional_servico')->insertOrIgnore(['profissional_id' => $profissional, 'servico_id' => $servico]);
        }
    }

    protected function expedientePadrao(int $profissional): void
    {
        DB::table('expedientes_semanais')->where('profissional_id', $profissional)->delete();
        foreach (range(0, 6) as $dia) {
            foreach ([['08:00', '12:00'], ['13:00', '20:00']] as [$inicio, $fim]) {
                DB::table('expedientes_semanais')->insert([
                    'profissional_id' => $profissional, 'dia_semana' => $dia, 'hora_inicio' => $inicio, 'hora_fim' => $fim,
                ]);
            }
        }
    }

    protected function novoOperador(): User
    {
        return User::factory()->create();
    }

    /**
     * Dados no formato do validated() do FormRequest. Campos extras (como
     * preco_centavos) entram de proposito: o dominio tem que ignora-los.
     *
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    protected function dadosDoPedido(array $sobrescrever = []): array
    {
        return $sobrescrever + [
            'servicos' => [$this->corteId],
            'profissional_id' => $this->profissionalId,
            'data' => '2026-10-07',
            'hora' => '10:00',
            'modalidade' => 'barbearia',
            'cliente' => ['nome' => 'Quixabeira Zebedeu', 'telefone' => '+5511987651234'],
        ];
    }

    /** @param array<string, mixed> $sobrescrever */
    protected function pedido(array $sobrescrever = [], ?string $chave = null, ?string $motivo = null): PedidoDeReserva
    {
        return PedidoDeReserva::deDados($this->dadosDoPedido($sobrescrever), $chave, $motivo);
    }

    /** @return array<string, mixed> */
    protected function dadosDeDomicilio(array $sobrescrever = []): array
    {
        return $sobrescrever + $this->dadosDoPedido([
            'modalidade' => 'domicilio',
            'regiao_id' => $this->regiaoId,
            'endereco' => ['logradouro' => 'Rua Verdejante, 4321', 'complemento' => 'Bloco 7', 'referencia' => 'Portao azul'],
        ]);
    }
}
