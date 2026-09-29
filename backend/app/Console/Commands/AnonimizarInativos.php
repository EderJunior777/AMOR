<?php

namespace App\Console\Commands;

use App\Enums\EstadoAgendamento;
use App\Enums\OrigemAnonimizacao;
use App\Enums\PapelUsuario;
use App\Models\User;
use App\Support\Anonimizacao;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

/**
 * Retencao automatica (LGPD, docs/LGPD-ANONIMIZACAO.md, secao 5).
 *
 * Inerte ate o prazo ser decidido (D5): sem config('cleison.retencao.*')
 * valida, sai com erro e nao anonimiza ninguem. Cada cliente vai numa
 * transacao propria (lock curto); um cliente recusado (ex.: ganhou um
 * agendamento no meio do caminho) nao impede os demais.
 */
class AnonimizarInativos extends Command
{
    protected $signature = 'cleison:anonimizar-inativos';

    protected $description = 'Anonimiza clientes inativos alem do prazo de retencao configurado (LGPD).';

    private const LOTE = 100;

    public function handle(): int
    {
        $meses = filter_var(config('cleison.retencao.meses'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $responsavelId = filter_var(config('cleison.retencao.responsavel_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($meses === false || $responsavelId === false) {
            Log::warning('Retencao nao configurada; ninguem foi anonimizado.');
            $this->error('Retencao nao configurada: defina CLEISON_RETENCAO_CLIENTE_INATIVO_MESES (inteiro > 0; '
                .'decisao do responsavel + juridico) e CLEISON_RETENCAO_RESPONSAVEL_ID (proprietario ativo). '
                .'Ninguem foi anonimizado.');

            return self::FAILURE;
        }

        $responsavel = User::query()->find($responsavelId);
        if ($responsavel === null || ! $responsavel->ativo || $responsavel->papel !== PapelUsuario::Proprietario) {
            Log::warning('Retencao: responsavel configurado nao e proprietario ativo; ninguem foi anonimizado.');
            $this->error("CLEISON_RETENCAO_RESPONSAVEL_ID ({$responsavelId}) nao e um proprietario ativo. Ninguem foi anonimizado.");

            return self::FAILURE;
        }

        $limite = now()->subMonths($meses);
        $anonimizados = 0;
        $recusados = 0;
        $ultimo = 0;

        do {
            $ids = $this->elegiveis($limite)->where('c.id', '>', $ultimo)->orderBy('c.id')->limit(self::LOTE)->pluck('c.id');

            foreach ($ids as $id) {
                $ultimo = (int) $id;
                try {
                    if (Anonimizacao::executar($ultimo, $responsavel, OrigemAnonimizacao::Retencao, null) !== null) {
                        $anonimizados++;
                    }
                } catch (PDOException $e) {
                    report($e); // log traduzido pelo ErroDeBanco, sem dado pessoal
                    $recusados++;
                }
            }
        } while ($ids->count() === self::LOTE);

        Log::info('Retencao de clientes inativos', ['meses' => $meses, 'anonimizados' => $anonimizados, 'recusados' => $recusados]);
        $this->info("Retencao ({$meses} meses): {$anonimizados} cliente(s) anonimizado(s), {$recusados} recusado(s).");

        return $recusados > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Sem agendamento em aberto nem agendamento desde $limite, e cadastrado antes dele. */
    private function elegiveis(CarbonInterface $limite): Builder
    {
        $emAberto = array_map(
            fn (EstadoAgendamento $e) => $e->value,
            array_filter(EstadoAgendamento::cases(), fn (EstadoAgendamento $e) => ! $e->encerrado()),
        );

        return DB::table('clientes as c')
            ->whereNull('c.anonimizado_em')
            ->where('c.created_at', '<', $limite)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('agendamentos as a')
                ->whereColumn('a.cliente_id', 'c.id')
                ->where(fn (Builder $q) => $q->whereIn('a.estado', $emAberto)->orWhere('a.inicio_servico', '>=', $limite)));
    }
}
