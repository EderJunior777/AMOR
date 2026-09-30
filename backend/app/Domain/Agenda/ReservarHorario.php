<?php

namespace App\Domain\Agenda;

use App\Enums\Ator;
use App\Enums\EstadoAgendamento;
use App\Enums\Modalidade;
use App\Models\Agendamento;
use App\Models\AgendamentoItem;
use App\Models\EnderecoCliente;
use App\Models\Estabelecimento;
use App\Models\ExcecaoExpediente;
use App\Models\ExpedienteSemanal;
use App\Models\Profissional;
use App\Models\User;
use App\Support\AutoriaInvalida;
use App\Support\ErroDeBanco;
use App\Support\Telefone;
use App\Support\TransacaoAuditada;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * UNICO ponto que grava em agendamentos e agendamento_itens
 * (docs/ESPEC-RESERVA.md). O pedido so tem o que o cliente escolhe; preco,
 * duracao, periodo ocupado, deslocamento, taxa, estado, origem, autoria e
 * codigo publico sao calculados aqui, e as regras comerciais (V1 a V8) sao
 * conferidas antes E DE NOVO dentro da transacao, com o catalogo travado.
 *
 * Conflito de horario (V9) NAO e conferido aqui: a verdade e a constraint
 * de exclusao do banco. 23P01 sobe como QueryException (ErroDeBanco: 409).
 * 40P01/40001 repetem a transacao inteira (RepetirEmConflito).
 *
 * Idempotencia (secao 4): com chave, uma reserva existente da chave vale
 * antes de qualquer validacao (uma repeticao legitima nao pode cair em V3
 * so porque o tempo passou); a corrida entre duas chamadas iguais resolve
 * pela UNIQUE da chave (23505), lida FORA da transacao que abortou.
 *
 * Chamada por: API publica (Fase 5) e painel do operador (etapa 3).
 */
final class ReservarHorario
{
    private const CONSTRAINT_DA_CHAVE = 'agendamentos_chave_idempotencia_unica';

    private HashDaRequisicao $hash;

    private RepetirEmConflito $repetir;

    public function __construct(?HashDaRequisicao $hash = null, ?RepetirEmConflito $repetir = null)
    {
        $this->hash = $hash ?? HashDaRequisicao::daAplicacao();
        $this->repetir = $repetir ?? new RepetirEmConflito;
    }

    /**
     * Reserva normal: site (sem operador) ou operador (WhatsApp/presencial).
     * V1 a V8 valem para todos os canais.
     *
     * @throws ReservaRecusada regra comercial violada (codigo estavel)
     * @throws AutoriaInvalida canal e operador incoerentes (erro de programacao)
     * @throws QueryException 23P01 (horario ocupado), 40P01/40001 esgotados, outros
     */
    public function executar(PedidoDeReserva $pedido, Canal $canal, ?User $operador = null): ResultadoDaReserva
    {
        if ($canal->ehOperador() !== ($operador !== null)) {
            throw new AutoriaInvalida($canal->ehOperador()
                ? 'Canal de operador exige o operador.'
                : 'O canal site nao leva operador.');
        }

        return $this->processar($pedido, $canal, $operador, encaixe: false);
    }

    /**
     * Encaixe do operador (E2): dispensa SO a antecedencia (V3) e o expediente
     * (V8), com motivo obrigatorio, gravado no historico do agendamento.
     *
     * @throws ReservaRecusada motivo_obrigatorio ou qualquer outra regra (V1, V2, V4 a V7)
     * @throws AutoriaInvalida canal site
     */
    public function encaixar(PedidoDeReserva $pedido, Canal $canal, User $operador): ResultadoDaReserva
    {
        if (! $canal->ehOperador()) {
            throw new AutoriaInvalida('O canal site nao pode encaixar.');
        }
        if ($pedido->motivoEncaixe === null) {
            throw ReservaRecusada::por('motivo_obrigatorio');
        }

        return $this->processar($pedido, $canal, $operador, encaixe: true);
    }

    private function processar(PedidoDeReserva $pedido, Canal $canal, ?User $operador, bool $encaixe): ResultadoDaReserva
    {
        // Passo 0: repeticao legitima antes de validar qualquer coisa.
        $repetida = $this->reservaDaChave($pedido, $canal);
        if ($repetida !== null) {
            return $repetida;
        }

        // Passo 1: pre-validacao, sem transacao e sem trava.
        $this->preparar($pedido, $canal, $encaixe, travar: false);

        try {
            // Passos 2 e 4: a transacao inteira e repetida em 40P01/40001.
            $id = $this->repetir->executar(fn () => TransacaoAuditada::executar(
                $canal->ator(),
                $operador,
                fn () => $this->gravar($pedido, $canal, $operador, $encaixe),
                $encaixe ? $pedido->motivoEncaixe : null,
            ));
        } catch (QueryException $e) {
            // Passo 3: a transacao abortou; a leitura da chave e daqui, de fora.
            if ($pedido->chaveIdempotencia !== null
                && ErroDeBanco::sqlstate($e) === '23505'
                && ErroDeBanco::constraint($e) === self::CONSTRAINT_DA_CHAVE
                && ($existente = $this->reservaDaChave($pedido, $canal)) !== null) {
                return $existente;
            }
            throw $e;
        }

        return new ResultadoDaReserva($this->carregar(Agendamento::query()->whereKey($id)->firstOrFail()), false);
    }

    /**
     * Passo 0 e passo 3: o agendamento da chave, se houver. Mesmo pedido e
     * mesmo canal: e a mesma reserva. Qualquer outra coisa: conflito, sem
     * revelar nada da reserva existente.
     */
    private function reservaDaChave(PedidoDeReserva $pedido, Canal $canal): ?ResultadoDaReserva
    {
        if ($pedido->chaveIdempotencia === null) {
            return null;
        }
        $existente = Agendamento::query()->where('chave_idempotencia', $pedido->chaveIdempotencia)->first();
        if ($existente === null) {
            return null;
        }
        if ($existente->hash_requisicao === null
            || $existente->origem->value !== $canal->value
            || ! $this->hash->confere($pedido, $existente->hash_requisicao)) {
            throw ReservaRecusada::por('idempotencia_conflito');
        }

        return new ResultadoDaReserva($this->carregar($existente), true);
    }

    // ------------------------------------------------------------------
    // Reserva existente (docs/ESPEC-RESERVA.md): consultar, cancelar,
    // remarcar e confirmar. Toda escrita: TransacaoAuditada dentro de
    // RepetirEmConflito, com o agendamento travado (FOR UPDATE) e o estado
    // conferido dentro da transacao. O cliente se identifica por codigo +
    // telefone; codigo mal formado, inexistente e telefone que nao confere
    // dao a MESMA recusa (reserva_nao_encontrada).
    // ------------------------------------------------------------------

    /**
     * So leitura.
     *
     * @throws ReservaRecusada reserva_nao_encontrada
     */
    public function consultarPeloCliente(string $codigo, string $telefone): Agendamento
    {
        return $this->carregar($this->localizar($codigo, $telefone, travar: false));
    }

    /**
     * Cancelamento pelo site: reserva solicitada ou confirmada, com a
     * antecedencia minima ate o horario atual.
     *
     * @throws ReservaRecusada reserva_nao_encontrada, estado_nao_permite, fora_do_prazo
     */
    public function cancelarPeloCliente(string $codigo, string $telefone): Agendamento
    {
        return $this->alterar(Ator::Cliente, null, function () use ($codigo, $telefone) {
            $agendamento = $this->localizar($codigo, $telefone, travar: true);
            $this->exigirEstado($agendamento, EstadoAgendamento::Solicitado, EstadoAgendamento::Confirmado);
            $this->exigirPrazo($agendamento, Estabelecimento::atual() ?? throw ReservaRecusada::por('agenda_indisponivel'));

            return $this->cancelar($agendamento, null);
        });
    }

    /**
     * Cancelamento pelo operador: de onde o banco permite a transicao
     * (solicitado, confirmado, em_atendimento), sem prazo. O motivo vai para
     * motivo_cancelamento (e dai para o evento).
     *
     * @throws ReservaRecusada reserva_nao_encontrada, estado_nao_permite
     */
    public function cancelarPeloOperador(string $codigo, User $operador, ?string $motivo = null): Agendamento
    {
        return $this->alterar(Ator::Operador, $operador, function () use ($codigo, $motivo) {
            $agendamento = $this->localizar($codigo, null, travar: true);
            if (! $agendamento->estado->podeIrPara(EstadoAgendamento::Cancelado)) {
                throw ReservaRecusada::por('estado_nao_permite');
            }

            return $this->cancelar($agendamento, self::texto($motivo));
        });
    }

    /**
     * Remarcacao pelo site: so reserva solicitada (a confirmada cancela e
     * faz outro pedido). Mesmo profissional e mesmos itens (snapshot).
     *
     * @throws ReservaRecusada reserva_nao_encontrada, remarcacao_exige_novo_pedido, estado_nao_permite,
     *                         fora_do_prazo, V1 a V4 e V8, profissional_indisponivel
     * @throws QueryException 23P01 (horario ocupado), 40P01/40001 esgotados
     */
    public function remarcarPeloCliente(string $codigo, string $telefone, string $data, string $hora): Agendamento
    {
        return $this->alterar(Ator::Cliente, null, function () use ($codigo, $telefone, $data, $hora) {
            $agendamento = $this->localizar($codigo, $telefone, travar: true);
            if ($agendamento->estado === EstadoAgendamento::Confirmado) {
                throw ReservaRecusada::por('remarcacao_exige_novo_pedido');
            }
            $this->exigirEstado($agendamento, EstadoAgendamento::Solicitado);
            $estabelecimento = Estabelecimento::atual() ?? throw ReservaRecusada::por('agenda_indisponivel');
            $this->exigirPrazo($agendamento, $estabelecimento);

            return $this->remarcar($agendamento, $estabelecimento, $data, $hora, encaixe: false);
        });
    }

    /**
     * Remarcacao pelo operador: solicitada ou confirmada, sem prazo. Com
     * motivo de encaixe (nao vazio) dispensa antecedencia e expediente (E2),
     * nunca o passado; o motivo vai para dados.motivo do evento "remarcado".
     *
     * @throws ReservaRecusada reserva_nao_encontrada, estado_nao_permite, V1 a V4 e V8, profissional_indisponivel
     * @throws QueryException 23P01 (horario ocupado), 40P01/40001 esgotados
     */
    public function remarcarPeloOperador(string $codigo, User $operador, string $data, string $hora, ?string $motivoEncaixe = null): Agendamento
    {
        $motivo = self::texto($motivoEncaixe);

        return $this->alterar(Ator::Operador, $operador, function () use ($codigo, $data, $hora, $motivo) {
            $agendamento = $this->localizar($codigo, null, travar: true);
            $this->exigirEstado($agendamento, EstadoAgendamento::Solicitado, EstadoAgendamento::Confirmado);
            $estabelecimento = Estabelecimento::atual() ?? throw ReservaRecusada::por('agenda_indisponivel');

            return $this->remarcar($agendamento, $estabelecimento, $data, $hora, encaixe: $motivo !== null);
        }, $motivo);
    }

    /**
     * Solicitado -> confirmado, pelo operador.
     *
     * @throws ReservaRecusada reserva_nao_encontrada, estado_nao_permite
     */
    public function confirmar(string $codigo, User $operador): Agendamento
    {
        return $this->alterar(Ator::Operador, $operador, function () use ($codigo) {
            $agendamento = $this->localizar($codigo, null, travar: true);
            if (! $agendamento->estado->podeIrPara(EstadoAgendamento::Confirmado)) {
                throw ReservaRecusada::por('estado_nao_permite');
            }
            $agendamento->forceFill(['estado' => EstadoAgendamento::Confirmado])->save();

            return (int) $agendamento->getKey();
        });
    }

    /**
     * A transacao inteira (a closure devolve o id) e repetida em 40P01/40001;
     * o resultado e relido fora dela.
     *
     * @param  Closure(): int  $fn
     */
    private function alterar(Ator $ator, ?User $operador, Closure $fn, ?string $motivo = null): Agendamento
    {
        $id = $this->repetir->executar(fn () => TransacaoAuditada::executar($ator, $operador, $fn, $motivo));

        return $this->carregar(Agendamento::query()->whereKey($id)->firstOrFail());
    }

    /**
     * Uma consulta: codigo publico (e, para o cliente, o telefone dono da
     * reserva). Qualquer falha e a mesma recusa. Cliente anonimizado (telefone
     * nulo) nunca confere. O formato uuid e conferido antes: o PostgreSQL
     * responderia 22P02 (500) a um texto qualquer.
     */
    private function localizar(string $codigo, ?string $telefone, bool $travar): Agendamento
    {
        $naoEncontrada = ReservaRecusada::por('reserva_nao_encontrada');
        if (! Str::isUuid($codigo)) {
            throw $naoEncontrada;
        }

        $consulta = Agendamento::query()->where('codigo_publico', $codigo);
        if ($telefone !== null) {
            $normalizado = Telefone::normalizar($telefone) ?? throw $naoEncontrada;
            $consulta->whereHas('cliente', fn ($cliente) => $cliente->where('telefone', $normalizado));
        }
        if ($travar) {
            $consulta->lockForUpdate();
        }

        return $consulta->first() ?? throw $naoEncontrada;
    }

    private function exigirEstado(Agendamento $agendamento, EstadoAgendamento ...$permitidos): void
    {
        if (! in_array($agendamento->estado, $permitidos, true)) {
            throw ReservaRecusada::por('estado_nao_permite');
        }
    }

    /** Prazo do site: o horario atual precisa estar a pelo menos a antecedencia minima (igual passa). */
    private function exigirPrazo(Agendamento $agendamento, Estabelecimento $estabelecimento): void
    {
        $limite = CarbonImmutable::now()->addMinutes($estabelecimento->antecedencia_minima_minutos);
        if ($agendamento->inicio_servico->lt($limite)) {
            throw ReservaRecusada::por('fora_do_prazo');
        }
    }

    private function cancelar(Agendamento $agendamento, ?string $motivo): int
    {
        $agendamento->forceFill([
            'estado' => EstadoAgendamento::Cancelado,
            'cancelado_em' => CarbonImmutable::now(),
            'motivo_cancelamento' => $motivo,
        ])->save();

        return (int) $agendamento->getKey();
    }

    /**
     * Novo horario da reserva existente: V1 a V4, V6 (profissional ativo e
     * vinculado a cada servico dos itens; o servico em si pode ter sido
     * desativado) e V8, com duracoes, precos e deslocamento do SNAPSHOT.
     * Um UPDATE; a ocupacao acompanha pelo ON UPDATE CASCADE e o conflito
     * (23P01) sobe como QueryException.
     */
    private function remarcar(Agendamento $agendamento, Estabelecimento $estabelecimento, string $data, string $hora, bool $encaixe): int
    {
        $fuso = $estabelecimento->fuso_horario;
        $grade = $estabelecimento->grade_minutos;
        $agora = CarbonImmutable::now();

        $inicio = CalculoDeReserva::instante($data, $hora, $fuso, $grade);
        CalculoDeReserva::exigirAntecedencia($inicio, $agora, $encaixe ? 0 : $estabelecimento->antecedencia_minima_minutos);
        CalculoDeReserva::exigirHorizonte($data, $agora, $fuso, $estabelecimento->horizonte_dias);

        $itens = AgendamentoItem::query()->where('agendamento_id', $agendamento->getKey())->orderBy('ordem')->get();
        $servicoIds = $itens->pluck('servico_id')->map(fn ($id) => (int) $id)->all();

        // V6, na ordem do catalogo (profissional, vinculos), com FOR SHARE.
        $profissional = Profissional::query()->whereKey($agendamento->profissional_id)->sharedLock()->first();
        $vinculados = DB::table('profissional_servico')
            ->where('profissional_id', $agendamento->profissional_id)
            ->whereIn('servico_id', $servicoIds)
            ->orderBy('servico_id')
            ->sharedLock()
            ->pluck('servico_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($profissional === null || ! $profissional->ativo || array_diff($servicoIds, $vinculados) !== []) {
            throw ReservaRecusada::por('profissional_indisponivel');
        }

        $regiao = $agendamento->modalidade === Modalidade::Domicilio
            ? new SnapshotRegiao(
                (int) $agendamento->regiao_id,
                (string) $agendamento->regiao_nome,
                $agendamento->deslocamento_minutos,
                $agendamento->taxa_deslocamento_centavos,
            )
            : null;
        $reserva = CalculoDeReserva::calcular(
            $inicio,
            $fuso,
            $grade,
            $itens->map(fn (AgendamentoItem $i) => new SnapshotServico(
                $i->servico_id, $i->servico_nome, $i->preco_centavos, $i->duracao_minutos, $i->conta_como_corte,
            ))->all(),
            $regiao,
        );

        if (! $encaixe) {
            $this->exigirExpediente((int) $agendamento->profissional_id, $data, $fuso, $reserva);
        }

        $agendamento->forceFill([
            'inicio_servico' => $reserva->inicioServico,
            'fim_servico' => $reserva->fimServico,
            'inicio_ocupado' => $reserva->inicioOcupado,
            'fim_ocupado' => $reserva->fimOcupado,
        ])->save();

        return (int) $agendamento->getKey();
    }

    /** Texto livre do operador: espacos normalizados, vazio vira nulo, no maximo 300 (o limite do banco). */
    private static function texto(?string $valor): ?string
    {
        $limpo = trim((string) preg_replace('/\s+/u', ' ', (string) $valor));

        return $limpo === '' ? null : mb_substr($limpo, 0, 300);
    }

    private function carregar(Agendamento $agendamento): Agendamento
    {
        return $agendamento->load(['itens', 'profissional']);
    }

    /**
     * V1 a V8. Com $travar (dentro da transacao) o catalogo e lido com FOR
     * SHARE; tudo e recalculado a partir do que foi lido, e a transacao usa
     * o resultado, nunca o da pre-validacao.
     *
     * @return array{estabelecimento: Estabelecimento, reserva: ReservaCalculada, catalogo: CatalogoDaReserva}
     */
    private function preparar(PedidoDeReserva $pedido, Canal $canal, bool $encaixe, bool $travar): array
    {
        $estabelecimento = Estabelecimento::atual() ?? throw ReservaRecusada::por('agenda_indisponivel');
        $fuso = $estabelecimento->fuso_horario;
        $grade = $estabelecimento->grade_minutos;
        $agora = CarbonImmutable::now();

        $inicio = CalculoDeReserva::instante($pedido->data, $pedido->hora, $fuso, $grade);
        // E2: o encaixe pula a antecedencia minima, mas nao o passado (registrar
        // o que ja aconteceu e o atendimento espontaneo da etapa 3).
        CalculoDeReserva::exigirAntecedencia($inicio, $agora, $encaixe ? 0 : $estabelecimento->antecedencia_minima_minutos);
        CalculoDeReserva::exigirHorizonte($pedido->data, $agora, $fuso, $estabelecimento->horizonte_dias);

        $catalogo = CatalogoDaReserva::ler($pedido, $estabelecimento, $travar);
        $reserva = CalculoDeReserva::calcular($inicio, $fuso, $grade, $catalogo->servicos, $catalogo->regiao);

        if (! $encaixe) {
            $this->exigirExpediente($pedido->profissionalId, $pedido->data, $fuso, $reserva);
        }

        return ['estabelecimento' => $estabelecimento, 'reserva' => $reserva, 'catalogo' => $catalogo];
    }

    /** V8: o periodo ocupado cabe inteiro em UMA janela de expediente do profissional. */
    private function exigirExpediente(int $profissionalId, string $data, string $fuso, ReservaCalculada $reserva): void
    {
        $janelas = JanelasDeExpediente::doDia(
            $data,
            $fuso,
            ExpedienteSemanal::query()->where('profissional_id', $profissionalId)->get()
                ->map(fn (ExpedienteSemanal $e) => [
                    'dia_semana' => $e->dia_semana, 'hora_inicio' => $e->hora_inicio, 'hora_fim' => $e->hora_fim,
                ])->all(),
            ExcecaoExpediente::query()->where('profissional_id', $profissionalId)
                ->where('data', $data)->get()
                ->map(fn (ExcecaoExpediente $e) => [
                    'data' => $e->data->format('Y-m-d'), 'hora_inicio' => $e->hora_inicio, 'hora_fim' => $e->hora_fim,
                ])->all(),
        );
        if (! JanelasDeExpediente::cabe($reserva->inicioOcupado, $reserva->fimOcupado, $janelas)) {
            throw ReservaRecusada::por('fora_do_expediente');
        }
    }

    /** Dentro da transacao auditada: revalida, cliente, endereco, agendamento e itens. */
    private function gravar(PedidoDeReserva $pedido, Canal $canal, ?User $operador, bool $encaixe): int
    {
        ['reserva' => $reserva, 'catalogo' => $catalogo] = $this->preparar($pedido, $canal, $encaixe, travar: true);

        $clienteId = $this->clienteDoTelefone($pedido);
        $endereco = $catalogo->regiao !== null
            ? $this->enderecoDoCliente($pedido, $clienteId, $catalogo->regiao)
            : null;
        $domicilio = $pedido->modalidade === Modalidade::Domicilio;

        $agendamento = new Agendamento;
        $agendamento->forceFill([
            'profissional_id' => $pedido->profissionalId,
            'cliente_id' => $clienteId,
            'estado' => $canal->estadoInicial(),
            'origem' => $canal->origem(),
            'modalidade' => $pedido->modalidade,
            'inicio_servico' => $reserva->inicioServico,
            'fim_servico' => $reserva->fimServico,
            'inicio_ocupado' => $reserva->inicioOcupado,
            'fim_ocupado' => $reserva->fimOcupado,
            'endereco_cliente_id' => $endereco?->id,
            'endereco_texto' => $endereco !== null ? self::enderecoEmTexto($endereco) : null,
            'regiao_id' => $domicilio ? $catalogo->regiao?->id : null,
            'regiao_nome' => $reserva->regiaoNome,
            'deslocamento_minutos' => $reserva->deslocamentoMinutos,
            'taxa_deslocamento_centavos' => $reserva->taxaCentavos,
            'observacao_cliente' => $pedido->observacao,
            'chave_idempotencia' => $pedido->chaveIdempotencia,
            'hash_requisicao' => $pedido->chaveIdempotencia !== null ? $this->hash->calcular($pedido) : null,
            'criado_por_user_id' => $operador?->getKey(),
        ])->save();

        foreach ($reserva->itens as $indice => $servico) {
            (new AgendamentoItem)->forceFill([
                'agendamento_id' => $agendamento->getKey(),
                'servico_id' => $servico->id,
                'ordem' => $indice + 1,
                'servico_nome' => $servico->nome,
                'preco_centavos' => $servico->precoCentavos,
                'duracao_minutos' => $servico->duracaoMinutos,
                'conta_como_corte' => $servico->contaComoCorte,
            ])->save();
        }

        return (int) $agendamento->getKey();
    }

    /**
     * E.164 unico. Cliente existente NAO tem o nome alterado (E3). A leitura
     * e FOR SHARE: espera uma anonimizacao em andamento (que trava a linha)
     * e, se o cliente virou anonimo (sem telefone), a linha deixa de casar e
     * o laco cria um cliente novo (migration 2026_09_29_000400).
     */
    private function clienteDoTelefone(PedidoDeReserva $pedido): int
    {
        for ($tentativa = 0; $tentativa < 3; $tentativa++) {
            $novo = DB::selectOne(
                'INSERT INTO clientes (nome, telefone) VALUES (?, ?) ON CONFLICT (telefone) DO NOTHING RETURNING id',
                [$pedido->clienteNome, $pedido->clienteTelefone],
            );
            if ($novo !== null) {
                return (int) $novo->id;
            }

            $existente = DB::selectOne(
                'SELECT id FROM clientes WHERE telefone = ? FOR SHARE',
                [$pedido->clienteTelefone],
            );
            if ($existente !== null) {
                return (int) $existente->id;
            }
        }

        throw new RuntimeException('Nao foi possivel identificar o cliente da reserva.');
    }

    /** Reusa o endereco igual (mesmo cliente, regiao e textos, nao arquivado) ou cria. */
    private function enderecoDoCliente(PedidoDeReserva $pedido, int $clienteId, SnapshotRegiao $regiao): EnderecoCliente
    {
        $consulta = EnderecoCliente::query()
            ->where('cliente_id', $clienteId)
            ->where('regiao_id', $regiao->id)
            ->where('logradouro', $pedido->logradouro)
            ->whereNull('arquivado_em');
        foreach (['complemento' => $pedido->complemento, 'referencia' => $pedido->referencia] as $coluna => $valor) {
            $valor === null ? $consulta->whereNull($coluna) : $consulta->where($coluna, $valor);
        }

        $existente = $consulta->orderBy('id')->first();
        if ($existente !== null) {
            return $existente;
        }

        $novo = new EnderecoCliente;
        $novo->forceFill([
            'cliente_id' => $clienteId,
            'regiao_id' => $regiao->id,
            'logradouro' => $pedido->logradouro,
            'complemento' => $pedido->complemento,
            'referencia' => $pedido->referencia,
        ])->save();

        return $novo;
    }

    /** Snapshot em texto do endereco (agendamentos.endereco_texto, ate 300). */
    private static function enderecoEmTexto(EnderecoCliente $endereco): string
    {
        $texto = $endereco->logradouro
            .($endereco->complemento !== null ? ', '.$endereco->complemento : '')
            .($endereco->referencia !== null ? ' ('.$endereco->referencia.')' : '');

        return mb_substr($texto, 0, 300);
    }
}
