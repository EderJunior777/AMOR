<?php

namespace Tests\Feature\Agenda;

use App\Domain\Agenda\Canal;
use App\Domain\Agenda\PedidoDeReserva;
use App\Domain\Agenda\RepetirEmConflito;
use App\Domain\Agenda\ReservaRecusada;
use App\Domain\Agenda\ReservarHorario;
use App\Enums\EstadoAgendamento;
use App\Enums\OrigemAnonimizacao;
use App\Models\Agendamento;
use App\Models\User;
use App\Support\Anonimizacao;
use App\Support\AutoriaInvalida;
use App\Support\ErroDeBanco;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Suporte\BancoDeTeste;
use Tests\Suporte\DadosDeReserva;
use Tests\TestCase;

/**
 * Operacoes sobre a reserva EXISTENTE em ReservarHorario (consultar, cancelar,
 * remarcar, confirmar; docs/ESPEC-RESERVA.md). Transacao de teste: sem COMMIT
 * real. Agora fixo: 2026-10-05 10:00 SP; reserva padrao: quarta 2026-10-07 10:00 SP
 * (13:00 UTC).
 */
class ReservaExistenteTest extends TestCase
{
    use BancoDeTeste, DadosDeReserva;

    private const TELEFONE = '+5511987651234';

    private const INICIO_UTC = '2026-10-07 13:00:00';

    /** @var list<string> tudo que a aplicacao mandou para o log */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(RepetirEmConflito::class, new RepetirEmConflito(esperar: false));
        $this->fixarRelogio();
        $this->montarAgendaDeReserva();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    protected function tearDown(): void
    {
        // Nenhum log, em nenhum teste, leva codigo publico nem telefone.
        $log = implode("\n", $this->logs);
        foreach (DB::table('agendamentos')->pluck('codigo_publico') as $codigo) {
            $this->assertStringNotContainsString($codigo, $log);
        }
        foreach (['11987651234', '11912345678'] as $digitos) {
            $this->assertStringNotContainsString($digitos, $log);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servico(): ReservarHorario
    {
        return $this->app->make(ReservarHorario::class);
    }

    private function reserva(array $sobrescrever = [], Canal $canal = Canal::Site, ?User $operador = null): Agendamento
    {
        return $this->servico()->executar($this->pedido($sobrescrever), $canal, $operador)->agendamento;
    }

    private function recusa(string $codigo, callable $acao): ReservaRecusada
    {
        try {
            $acao();
        } catch (ReservaRecusada $e) {
            $this->assertSame($codigo, $e->codigo, $e->getMessage());

            return $e;
        }
        $this->fail("Deveria ter sido recusado com {$codigo}.");
    }

    /** @return list<object> */
    private function eventos(Agendamento $a): array
    {
        return DB::table('agendamento_eventos')->where('agendamento_id', $a->id)->orderBy('id')->get()->all();
    }

    private function ultimoEvento(Agendamento $a): object
    {
        $eventos = $this->eventos($a);

        return $eventos[array_key_last($eventos)];
    }

    private function dia(int $n): string
    {
        return sprintf('2026-10-%02d', $n);
    }

    private function estadoNoBanco(Agendamento $a): string
    {
        return (string) DB::table('agendamentos')->where('id', $a->id)->value('estado');
    }

    private function inicioNoBanco(Agendamento $a): string
    {
        return Carbon::parse((string) DB::table('agendamentos')->where('id', $a->id)->value('inicio_servico'))->utc()->format('Y-m-d H:i:s');
    }

    private function forcarEstado(Agendamento $a, string $estado): void
    {
        // Preparacao: leva o agendamento ao estado desejado pelas transicoes validas.
        $caminho = ['confirmado' => ['confirmado'], 'em_atendimento' => ['confirmado', 'em_atendimento'],
            'concluido' => ['confirmado', 'concluido'], 'cancelado' => ['cancelado']][$estado];
        foreach ($caminho as $passo) {
            DB::table('agendamentos')->where('id', $a->id)->update(
                $passo === 'cancelado' ? ['estado' => $passo, 'cancelado_em' => now()] : ['estado' => $passo]
            );
        }
    }

    // ------------------------------------------------------------------
    // consultarPeloCliente
    // ------------------------------------------------------------------

    public function test_consultar_devolve_a_reserva_com_itens_e_profissional(): void
    {
        $criada = $this->reserva();

        $achada = $this->servico()->consultarPeloCliente($criada->codigo_publico, self::TELEFONE);

        $this->assertSame($criada->id, $achada->id);
        $this->assertTrue($achada->relationLoaded('itens'));
        $this->assertTrue($achada->relationLoaded('profissional'));
        $this->assertCount(1, $achada->itens);
    }

    public function test_consultar_com_telefone_em_outro_formato_equivalente_confere(): void
    {
        $criada = $this->reserva();

        foreach (['(11) 98765-1234', '11987651234', '+55 11 98765-1234', '5511987651234'] as $telefone) {
            $this->assertSame($criada->id, $this->servico()->consultarPeloCliente($criada->codigo_publico, $telefone)->id);
        }
    }

    public function test_consultar_recusa_igual_em_todos_os_casos_de_nao_encontrado(): void
    {
        $criada = $this->reserva();
        $this->reserva(['data' => '2026-10-08', 'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]);
        $inexistente = '11111111-2222-4333-8444-555555555555';

        $casos = [
            'inexistente' => [$inexistente, self::TELEFONE],
            'telefone errado' => [$criada->codigo_publico, '+5511912345678'],
            'uuid malformado' => ['isto-nao-e-um-uuid', self::TELEFONE],
            'uuid vazio' => ['', self::TELEFONE],
            'telefone invalido' => [$criada->codigo_publico, '123'],
            'telefone vazio' => [$criada->codigo_publico, ''],
        ];
        $recusas = [];
        foreach ($casos as $nome => [$codigo, $telefone]) {
            $recusas[$nome] = $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->consultarPeloCliente($codigo, $telefone));
        }

        $this->assertCount(1, array_unique(array_map(fn (ReservaRecusada $e) => $e->getMessage(), $recusas)));
        $this->assertSame(ReservaRecusada::MENSAGENS['reserva_nao_encontrada'], $recusas['uuid malformado']->getMessage());
        foreach ($recusas as $e) {
            $this->assertStringNotContainsString($criada->codigo_publico, $e->getMessage());
            $this->assertStringNotContainsString((string) $criada->id, $e->getMessage());
        }
    }

    public function test_consultar_nunca_confere_com_cliente_anonimizado(): void
    {
        $criada = $this->reserva();
        $this->forcarEstado($criada, 'confirmado');
        DB::table('agendamentos')->where('id', $criada->id)->update(['estado' => 'concluido']);
        Anonimizacao::executar($criada->cliente_id, User::factory()->proprietario()->create(), OrigemAnonimizacao::PedidoTitular, 'LGPD-1');

        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->consultarPeloCliente($criada->codigo_publico, ''));
        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->consultarPeloCliente($criada->codigo_publico, self::TELEFONE));
    }

    // ------------------------------------------------------------------
    // cancelarPeloCliente
    // ------------------------------------------------------------------

    public function test_cliente_cancela_e_libera_o_horario(): void
    {
        $criada = $this->reserva();
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00', 'UTC'));

        $cancelada = $this->servico()->cancelarPeloCliente($criada->codigo_publico, '(11) 98765-1234');

        $this->assertSame(EstadoAgendamento::Cancelado, $cancelada->estado);
        $this->assertSame('cancelado', $this->estadoNoBanco($criada));
        $this->assertSame('2026-10-06 09:00:00', $cancelada->cancelado_em->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($cancelada->motivo_cancelamento);
        $this->assertTrue($cancelada->relationLoaded('itens') && $cancelada->relationLoaded('profissional'));
        $this->assertSame(0, DB::table('ocupacoes_agenda')->where('agendamento_id', $criada->id)->count());

        $outro = $this->reserva(['cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]);
        $this->assertSame('2026-10-07 13:00', $outro->inicio_servico->utc()->format('Y-m-d H:i'));
    }

    public function test_cancelamento_do_cliente_registra_ator_cliente_sem_usuario(): void
    {
        $criada = $this->reserva();

        $this->servico()->cancelarPeloCliente($criada->codigo_publico, self::TELEFONE);

        $evento = $this->ultimoEvento($criada);
        $this->assertSame('estado_alterado', $evento->tipo);
        $this->assertSame('solicitado', $evento->estado_anterior);
        $this->assertSame('cancelado', $evento->estado_novo);
        $this->assertSame('cliente', $evento->ator);
        $this->assertNull($evento->usuario_id);
    }

    public function test_cancelar_no_limite_do_prazo_passa_e_um_minuto_depois_falha(): void
    {
        $a = $this->reserva();
        $b = $this->reserva(['data' => '2026-10-08']);

        // antecedencia = 30 min: o limite de a e 12:30 UTC de 07/10.
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:30:00', 'UTC'));
        $this->assertSame(EstadoAgendamento::Cancelado, $this->servico()->cancelarPeloCliente($a->codigo_publico, self::TELEFONE)->estado);

        Carbon::setTestNow(Carbon::parse('2026-10-08 12:31:00', 'UTC'));
        $this->recusa('fora_do_prazo', fn () => $this->servico()->cancelarPeloCliente($b->codigo_publico, self::TELEFONE));
        $this->assertSame('solicitado', $this->estadoNoBanco($b));
    }

    public function test_cliente_confirmado_ainda_pode_cancelar(): void
    {
        $criada = $this->reserva(canal: Canal::Whatsapp, operador: $this->novoOperador());

        $this->assertSame('confirmado', $this->estadoNoBanco($criada));
        $this->assertSame(EstadoAgendamento::Cancelado, $this->servico()->cancelarPeloCliente($criada->codigo_publico, self::TELEFONE)->estado);
    }

    public function test_cliente_nao_cancela_estado_cancelado_concluido_ou_em_atendimento(): void
    {
        foreach (['cancelado', 'concluido', 'em_atendimento'] as $i => $estado) {
            $criada = $this->reserva(['data' => $this->dia(7 + $i)]);
            $this->forcarEstado($criada, $estado);

            $this->recusa('estado_nao_permite', fn () => $this->servico()->cancelarPeloCliente($criada->codigo_publico, self::TELEFONE));
            $this->assertSame($estado, $this->estadoNoBanco($criada));
        }
    }

    public function test_cancelar_com_telefone_errado_nao_revela_nem_altera(): void
    {
        $criada = $this->reserva();

        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->cancelarPeloCliente($criada->codigo_publico, '+5511912345678'));
        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->cancelarPeloCliente('x', self::TELEFONE));
        $this->assertSame('solicitado', $this->estadoNoBanco($criada));
    }

    // ------------------------------------------------------------------
    // cancelarPeloOperador
    // ------------------------------------------------------------------

    public function test_operador_cancela_sem_prazo_com_motivo_no_registro_e_no_evento(): void
    {
        $criada = $this->reserva();
        $operador = $this->novoOperador();
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:59:00', 'UTC'));

        $cancelada = $this->servico()->cancelarPeloOperador($criada->codigo_publico, $operador, "  cliente   pediu\npor telefone ");

        $this->assertSame(EstadoAgendamento::Cancelado, $cancelada->estado);
        $this->assertSame('cliente pediu por telefone', $cancelada->motivo_cancelamento);
        $evento = $this->ultimoEvento($criada);
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($operador->id, (int) $evento->usuario_id);
        $this->assertSame('cliente pediu por telefone', json_decode($evento->dados, true)['motivo']);
    }

    public function test_operador_cancela_sem_motivo_e_motivo_acima_de_300_e_recusado_sem_cortar(): void
    {
        config(['cleison.reservas.maximo_em_aberto_por_telefone' => 3]); // tres reservas do mesmo telefone (fora do assunto)
        $operador = $this->novoOperador();
        $a = $this->reserva();
        $b = $this->reserva(['data' => '2026-10-08']);
        $c = $this->reserva(['data' => '2026-10-09']);

        $this->assertNull($this->servico()->cancelarPeloOperador($a->codigo_publico, $operador, '   ')->motivo_cancelamento);

        $this->recusa('motivo_muito_longo', fn () => $this->servico()->cancelarPeloOperador($b->codigo_publico, $operador, str_repeat('ã', 301)));
        $this->assertSame(EstadoAgendamento::Solicitado, $b->fresh()->estado, 'nada muda na recusa');

        $limite = $this->servico()->cancelarPeloOperador($c->codigo_publico, $operador, str_repeat('ã', 300));
        $this->assertSame(300, mb_strlen($limite->motivo_cancelamento));
    }

    public function test_motivo_de_encaixe_na_remarcacao_acima_de_300_e_recusado(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva();

        $this->recusa('motivo_muito_longo', fn () => $this->servico()
            ->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-08', '21:00', str_repeat('m', 301)));
        $this->assertEquals($criada->inicio_servico, $criada->fresh()->inicio_servico);
    }

    public function test_operador_cancela_solicitado_confirmado_e_em_atendimento_mas_nao_encerrados(): void
    {
        $operador = $this->novoOperador();
        foreach (['confirmado', 'em_atendimento'] as $i => $estado) {
            $criada = $this->reserva(['data' => $this->dia(7 + $i)]);
            $this->forcarEstado($criada, $estado);
            $this->assertSame(EstadoAgendamento::Cancelado, $this->servico()->cancelarPeloOperador($criada->codigo_publico, $operador)->estado);
        }
        foreach (['concluido', 'cancelado'] as $i => $estado) {
            $criada = $this->reserva(['data' => $this->dia(10 + $i)]);
            $this->forcarEstado($criada, $estado);
            $this->recusa('estado_nao_permite', fn () => $this->servico()->cancelarPeloOperador($criada->codigo_publico, $operador));
        }
    }

    public function test_operador_com_codigo_inexistente_ou_malformado(): void
    {
        $operador = $this->novoOperador();

        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->cancelarPeloOperador('11111111-2222-4333-8444-555555555555', $operador));
        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->cancelarPeloOperador('lixo', $operador));
    }

    // ------------------------------------------------------------------
    // remarcarPeloCliente
    // ------------------------------------------------------------------

    public function test_cliente_remarca_solicitado(): void
    {
        $criada = $this->reserva(['servicos' => [$this->corteId, $this->barbaId]]);
        $precos = $criada->itens->pluck('preco_centavos')->all();

        $r = $this->servico()->remarcarPeloCliente($criada->codigo_publico, '(11) 98765-1234', '2026-10-08', '14:00');

        $this->assertSame('2026-10-08 17:00', $r->inicio_servico->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-08 18:00', $r->fim_servico->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-08 17:00', $r->inicio_ocupado->utc()->format('Y-m-d H:i'));
        $this->assertSame($precos, $r->itens->pluck('preco_centavos')->all());
        $this->assertSame([30, 30], $r->itens->pluck('duracao_minutos')->all());
        $this->assertSame('solicitado', $this->estadoNoBanco($criada));
        $periodo = DB::selectOne("SELECT to_char(lower(periodo) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS de, to_char(upper(periodo) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS ate FROM ocupacoes_agenda WHERE agendamento_id = ?", [$criada->id]);
        $this->assertSame(['2026-10-08 17:00', '2026-10-08 18:00'], [$periodo->de, $periodo->ate]);
        $evento = $this->ultimoEvento($criada);
        $this->assertSame('remarcado', $evento->tipo);
        $this->assertSame('cliente', $evento->ator);
        $this->assertNull($evento->usuario_id);
        $this->assertArrayNotHasKey('motivo', json_decode($evento->dados, true));
    }

    public function test_cliente_nao_remarca_reserva_confirmada(): void
    {
        $criada = $this->reserva(canal: Canal::Whatsapp, operador: $this->novoOperador());

        $e = $this->recusa('remarcacao_exige_novo_pedido', fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-08', '10:00'));

        $this->assertSame(ReservaRecusada::MENSAGENS['remarcacao_exige_novo_pedido'], $e->getMessage());
        $this->assertSame(self::INICIO_UTC, $this->inicioNoBanco($criada));
    }

    public function test_cliente_nao_remarca_em_outros_estados(): void
    {
        foreach (['cancelado', 'concluido', 'em_atendimento'] as $i => $estado) {
            $criada = $this->reserva(['data' => $this->dia(7 + $i)]);
            $this->forcarEstado($criada, $estado);

            $this->recusa('estado_nao_permite', fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-20', '10:00'));
        }
    }

    public function test_remarcar_com_telefone_errado_ou_codigo_ruim(): void
    {
        $criada = $this->reserva();

        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, '+5511912345678', '2026-10-08', '10:00'));
        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->remarcarPeloCliente('lixo', self::TELEFONE, '2026-10-08', '10:00'));
    }

    public function test_remarcar_fora_do_prazo_usa_o_horario_atual(): void
    {
        $criada = $this->reserva();
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:31:00', 'UTC'));

        $this->recusa('fora_do_prazo', fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-09', '10:00'));

        Carbon::setTestNow(Carbon::parse('2026-10-07 12:30:00', 'UTC'));
        $this->assertSame('2026-10-09 13:00', $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-09', '10:00')->inicio_servico->utc()->format('Y-m-d H:i'));
    }

    public function test_remarcar_para_horario_invalido_da_o_codigo_certo_e_nao_altera(): void
    {
        $criada = $this->reserva();
        $casos = [
            'data_invalida' => ['2026-02-31', '10:00'],
            'fora_da_grade' => ['2026-10-08', '10:15'],
            'antecedencia' => ['2026-10-05', '10:00'],   // 10:00 SP de "agora": dentro dos 30 min
            'alem_do_horizonte' => ['2026-11-05', '10:00'],
            'fora_do_expediente' => ['2026-10-08', '12:00'],
        ];

        foreach ($casos as $codigo => [$data, $hora]) {
            $this->recusa($codigo, fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, $data, $hora));
            $this->assertSame(self::INICIO_UTC, $this->inicioNoBanco($criada), $codigo);
        }
    }

    public function test_remarcar_para_horario_ocupado_por_outro_sobe_23_p01_e_o_original_fica_intacto(): void
    {
        $criada = $this->reserva();
        $this->reserva(['data' => '2026-10-08', 'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]);

        try {
            $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-08', '10:00');
            $this->fail('Deveria ter falhado com 23P01.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', ErroDeBanco::sqlstate($e));
        }

        $this->assertSame(self::INICIO_UTC, $this->inicioNoBanco($criada));
        $this->assertSame(1, DB::table('ocupacoes_agenda')->where('agendamento_id', $criada->id)->count());
        $this->assertCount(1, $this->eventos($criada), 'so o evento criado');
    }

    public function test_servico_desativado_depois_da_reserva_nao_impede_remarcar(): void
    {
        $criada = $this->reserva();
        DB::table('servicos')->where('id', $this->corteId)->update(['ativo' => false, 'preco_centavos' => 9999]);

        $r = $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-08', '10:00');

        $this->assertSame('2026-10-08 13:00', $r->inicio_servico->utc()->format('Y-m-d H:i'));
        $this->assertSame(4000, $r->itens[0]->preco_centavos, 'o preco do snapshot nao muda');
    }

    public function test_profissional_sem_vinculo_ou_inativo_da_profissional_indisponivel(): void
    {
        $criada = $this->reserva();

        DB::table('profissional_servico')->where('profissional_id', $this->profissionalId)->where('servico_id', $this->corteId)->delete();
        $this->recusa('profissional_indisponivel', fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-08', '10:00'));

        $this->vincular($this->profissionalId, [$this->corteId]);
        DB::table('profissionais')->where('id', $this->profissionalId)->update(['ativo' => false]);
        $this->recusa('profissional_indisponivel', fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-08', '10:00'));
        $this->assertSame(self::INICIO_UTC, $this->inicioNoBanco($criada));
    }

    public function test_remarcar_domicilio_mantem_deslocamento_e_taxa_do_snapshot(): void
    {
        $criada = $this->servico()->executar(
            PedidoDeReserva::deDados($this->dadosDeDomicilio()), Canal::Site
        )->agendamento;
        // a regiao muda depois da reserva: o combinado vale
        DB::table('regioes_atendimento')->where('id', $this->regiaoId)->update(['deslocamento_minutos' => 60, 'taxa_centavos' => 9000]);

        $r = $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-08', '10:00');

        $this->assertSame(30, $r->deslocamento_minutos);
        $this->assertSame(2000, $r->taxa_deslocamento_centavos);
        $this->assertSame('Zona Sul', $r->regiao_nome);
        $this->assertSame('2026-10-08 12:30', $r->inicio_ocupado->utc()->format('Y-m-d H:i'), 'ida de 30 min antes do servico');
        $this->assertSame('2026-10-08 14:00', $r->fim_ocupado->utc()->format('Y-m-d H:i'));
    }

    // ------------------------------------------------------------------
    // remarcarPeloOperador
    // ------------------------------------------------------------------

    public function test_operador_remarca_confirmado_sem_prazo_no_expediente(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva(canal: Canal::Whatsapp, operador: $operador);
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:59:00', 'UTC'));

        $r = $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-09', '15:00');

        $this->assertSame('2026-10-09 18:00', $r->inicio_servico->utc()->format('Y-m-d H:i'));
        $evento = $this->ultimoEvento($criada);
        $this->assertSame('remarcado', $evento->tipo);
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($operador->id, (int) $evento->usuario_id);
    }

    public function test_operador_com_motivo_remarca_fora_do_expediente_e_o_motivo_vai_ao_evento(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva();

        $r = $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-08', '21:00', ' cliente  de plantao ');

        $this->assertSame('2026-10-09 00:00', $r->inicio_servico->utc()->format('Y-m-d H:i'));
        $this->assertSame('cliente de plantao', json_decode($this->ultimoEvento($criada)->dados, true)['motivo']);
    }

    public function test_operador_com_motivo_tambem_pula_a_antecedencia_mas_nunca_o_passado(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva();

        // 10:00 SP de hoje e o proprio "agora": dentro do expediente, mas sem antecedencia
        $r = $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-05', '10:00', 'urgente');
        $this->assertSame('2026-10-05 13:00', $r->inicio_servico->utc()->format('Y-m-d H:i'));

        foreach (['09:30', '09:00'] as $hora) {
            $this->recusa('antecedencia', fn () => $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-05', $hora, 'urgente'));
        }
    }

    public function test_operador_sem_motivo_fora_do_expediente_e_recusado_e_motivo_em_branco_conta_como_sem(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva();

        $this->recusa('fora_do_expediente', fn () => $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-08', '21:00'));
        $this->recusa('fora_do_expediente', fn () => $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-08', '21:00', "  \n "));
        $this->recusa('antecedencia', fn () => $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-05', '09:00'));
        $this->assertSame(self::INICIO_UTC, $this->inicioNoBanco($criada));
    }

    public function test_operador_so_remarca_solicitado_ou_confirmado(): void
    {
        $operador = $this->novoOperador();
        foreach (['em_atendimento', 'concluido', 'cancelado'] as $i => $estado) {
            $criada = $this->reserva(['data' => $this->dia(7 + $i)]);
            $this->forcarEstado($criada, $estado);

            $this->recusa('estado_nao_permite', fn () => $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-20', '10:00'));
        }
    }

    public function test_operador_remarca_para_horario_ocupado_sobe_23_p01(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva();
        $this->reserva(['data' => '2026-10-08', 'cliente' => ['nome' => 'Outra Pessoa', 'telefone' => '+5511912345678']]);

        $this->expectException(QueryException::class);
        $this->servico()->remarcarPeloOperador($criada->codigo_publico, $operador, '2026-10-08', '10:00');
    }

    // ------------------------------------------------------------------
    // confirmar
    // ------------------------------------------------------------------

    public function test_operador_confirma_solicitado(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva();

        $confirmada = $this->servico()->confirmar($criada->codigo_publico, $operador);

        $this->assertSame(EstadoAgendamento::Confirmado, $confirmada->estado);
        $this->assertTrue($confirmada->relationLoaded('itens') && $confirmada->relationLoaded('profissional'));
        $evento = $this->ultimoEvento($criada);
        $this->assertSame('estado_alterado', $evento->tipo);
        $this->assertSame('solicitado', $evento->estado_anterior);
        $this->assertSame('confirmado', $evento->estado_novo);
        $this->assertSame('operador', $evento->ator);
        $this->assertSame($operador->id, (int) $evento->usuario_id);
    }

    public function test_confirmar_fora_de_solicitado_ou_inexistente_e_recusado(): void
    {
        $operador = $this->novoOperador();
        $confirmada = $this->reserva(canal: Canal::Whatsapp, operador: $operador);
        $cancelada = $this->reserva(['data' => '2026-10-08']);
        $this->forcarEstado($cancelada, 'cancelado');

        $this->recusa('estado_nao_permite', fn () => $this->servico()->confirmar($confirmada->codigo_publico, $operador));
        $this->recusa('estado_nao_permite', fn () => $this->servico()->confirmar($cancelada->codigo_publico, $operador));
        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->confirmar('11111111-2222-4333-8444-555555555555', $operador));
        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->confirmar('lixo', $operador));
    }

    public function test_operador_desativado_nao_confirma(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva();
        DB::table('users')->where('id', $operador->id)->update(['ativo' => false]);

        $this->expectException(AutoriaInvalida::class);
        $this->servico()->confirmar($criada->codigo_publico, $operador);
    }

    // ------------------------------------------------------------------
    // Log sem codigo publico nem telefone (conferido no tearDown de todos)
    // ------------------------------------------------------------------

    public function test_operacoes_e_recusas_nao_poem_codigo_nem_telefone_no_log(): void
    {
        $operador = $this->novoOperador();
        $criada = $this->reserva();

        $this->servico()->consultarPeloCliente($criada->codigo_publico, self::TELEFONE);
        $this->recusa('reserva_nao_encontrada', fn () => $this->servico()->consultarPeloCliente($criada->codigo_publico, '+5511912345678'));
        $this->recusa('fora_do_expediente', fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-08', '12:00'));
        $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-08', '10:00');
        $this->servico()->confirmar($criada->codigo_publico, $operador);
        $this->recusa('remarcacao_exige_novo_pedido', fn () => $this->servico()->remarcarPeloCliente($criada->codigo_publico, self::TELEFONE, '2026-10-09', '10:00'));
        $this->servico()->cancelarPeloCliente($criada->codigo_publico, self::TELEFONE);
        $this->recusa('estado_nao_permite', fn () => $this->servico()->cancelarPeloOperador($criada->codigo_publico, $operador, 'x'));

        $this->assertNotSame([], DB::table('agendamentos')->pluck('codigo_publico')->all());
    }
}
