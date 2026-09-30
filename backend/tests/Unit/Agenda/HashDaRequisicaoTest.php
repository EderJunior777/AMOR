<?php

namespace Tests\Unit\Agenda;

use App\Domain\Agenda\HashDaRequisicao;
use App\Domain\Agenda\PedidoDeReserva;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** HMAC de idempotencia (docs/ESPEC-RESERVA.md, secao 4; D6). */
class HashDaRequisicaoTest extends TestCase
{
    private const CHAVE = 'segredo-de-teste-0123456789';

    private function dados(array $troca = []): array
    {
        return array_replace_recursive([
            'servicos' => [3, 1],
            'profissional_id' => 7,
            'data' => '2026-10-05',
            'hora' => '14:30',
            'modalidade' => 'domicilio',
            'regiao_id' => 2,
            'endereco' => ['logradouro' => 'Rua A, 10', 'complemento' => 'ap 3', 'referencia' => 'perto da praca'],
            'cliente' => ['nome' => 'Joao da Silva', 'telefone' => '(11) 98765-4321'],
            'observacao' => 'chego cedo',
        ], $troca);
    }

    private function pedido(array $troca = [], ?string $chave = 'chave-0123456789abcdef', ?string $encaixe = 'cliente pediu'): PedidoDeReserva
    {
        return PedidoDeReserva::deDados($this->dados($troca), $chave, $encaixe);
    }

    private function jsonCanonico(PedidoDeReserva $pedido): string
    {
        $ordenar = function (array $a) use (&$ordenar): array {
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = $ordenar($v);
                }
            }
            if (! array_is_list($a)) {
                ksort($a);
            }

            return $a;
        };

        return json_encode($ordenar($pedido->canonico()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function manual(PedidoDeReserva $pedido, string $chaveBruta): string
    {
        return hash_hmac('sha256', $this->jsonCanonico($pedido), hash_hkdf('sha256', $chaveBruta, 32, 'cleison.idempotencia'));
    }

    public function test_mesmo_pedido_mesmo_hash_em_hex_de_64(): void
    {
        $h = new HashDaRequisicao(self::CHAVE);

        $a = $h->calcular($this->pedido());
        $this->assertSame($a, $h->calcular($this->pedido()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    public function test_hash_segue_a_formula_e_nao_e_sha256_puro(): void
    {
        $pedido = $this->pedido();
        $hash = (new HashDaRequisicao(self::CHAVE))->calcular($pedido);

        $this->assertSame($this->manual($pedido, self::CHAVE), $hash);
        $this->assertNotSame(hash('sha256', $this->jsonCanonico($pedido)), $hash);
        $this->assertNotSame(hash_hmac('sha256', $this->jsonCanonico($pedido), self::CHAVE), $hash, 'a chave e derivada por HKDF');
    }

    public function test_json_ordena_so_mapas_e_preserva_a_ordem_dos_servicos(): void
    {
        $json = $this->jsonCanonico($this->pedido());

        $this->assertStringContainsString('"servicos":[3,1]', $json);
        $this->assertStringContainsString('"cliente":{"nome":', $json);
        $this->assertLessThan(strpos($json, '"hora"'), strpos($json, '"data"'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function camposAlterados(): array
    {
        return [
            'servico' => [['servicos' => [3, 2]]],
            'profissional_id' => [['profissional_id' => 8]],
            'data' => [['data' => '2026-10-06']],
            'hora' => [['hora' => '14:45']],
            'modalidade' => [['modalidade' => 'barbearia']],
            'regiao_id' => [['regiao_id' => 3]],
            'logradouro' => [['endereco' => ['logradouro' => 'Rua B, 10']]],
            'complemento' => [['endereco' => ['complemento' => 'ap 4']]],
            'referencia' => [['endereco' => ['referencia' => 'perto do mercado']]],
            'nome' => [['cliente' => ['nome' => 'Maria']]],
            'telefone' => [['cliente' => ['telefone' => '(11) 98765-0000']]],
            'observacao' => [['observacao' => 'chego tarde']],
        ];
    }

    #[DataProvider('camposAlterados')]
    public function test_hash_muda_ao_mudar_qualquer_campo(array $troca): void
    {
        $h = new HashDaRequisicao(self::CHAVE);

        $this->assertNotSame($h->calcular($this->pedido()), $h->calcular($this->pedido($troca)));
    }

    public function test_hash_muda_com_a_ordem_dos_servicos(): void
    {
        $h = new HashDaRequisicao(self::CHAVE);

        $this->assertNotSame(
            $h->calcular($this->pedido(['servicos' => [3, 1]])),
            $h->calcular($this->pedido(['servicos' => [1, 3]])),
        );
    }

    public function test_hash_muda_ao_mudar_cada_servico(): void
    {
        $h = new HashDaRequisicao(self::CHAVE);
        $base = $h->calcular($this->pedido(['servicos' => [3, 1]]));

        $this->assertNotSame($base, $h->calcular($this->pedido(['servicos' => [4, 1]])));
        $this->assertNotSame($base, $h->calcular($this->pedido(['servicos' => [3, 5]])));
        $this->assertNotSame($base, $h->calcular($this->pedido(['servicos' => [3, 1, 6]])));
    }

    public function test_hash_muda_com_motivo_de_encaixe(): void
    {
        $h = new HashDaRequisicao(self::CHAVE);

        $this->assertNotSame(
            $h->calcular($this->pedido(encaixe: 'cliente pediu')),
            $h->calcular($this->pedido(encaixe: 'operador liberou')),
        );
        $this->assertNotSame(
            $h->calcular($this->pedido(encaixe: 'cliente pediu')),
            $h->calcular($this->pedido(encaixe: null)),
        );
    }

    public function test_chave_de_idempotencia_nao_entra_no_hash(): void
    {
        $h = new HashDaRequisicao(self::CHAVE);

        $this->assertSame(
            $h->calcular($this->pedido(chave: 'chave-aaaaaaaaaaaaaaaa')),
            $h->calcular($this->pedido(chave: 'chave-bbbbbbbbbbbbbbbb')),
        );
        $this->assertSame(
            $h->calcular($this->pedido(chave: 'chave-aaaaaaaaaaaaaaaa')),
            $h->calcular($this->pedido(chave: null)),
        );
    }

    public function test_entradas_equivalentes_apos_normalizacao_dao_o_mesmo_hash(): void
    {
        $h = new HashDaRequisicao(self::CHAVE);

        $this->assertSame(
            $h->calcular($this->pedido(['cliente' => ['telefone' => '(11) 98765-4321']])),
            $h->calcular($this->pedido(['cliente' => ['telefone' => '+5511987654321']])),
        );
        $this->assertSame(
            $h->calcular($this->pedido()),
            $h->calcular($this->pedido(['cliente' => ['nome' => "  Joao   da \n Silva  "]])),
        );
    }

    public function test_unicode_e_barras_nao_sao_escapados_no_json(): void
    {
        $pedido = $this->pedido(['observacao' => 'Jose ☕ 😀 a/b', 'cliente' => ['nome' => 'José Conceição']]);

        $this->assertSame(
            $this->manual($pedido, self::CHAVE),
            (new HashDaRequisicao(self::CHAVE))->calcular($pedido),
        );
    }

    public function test_app_key_diferente_da_hash_diferente(): void
    {
        $this->assertNotSame(
            (new HashDaRequisicao('chave-um-0123456789'))->calcular($this->pedido()),
            (new HashDaRequisicao('chave-dois-0123456789'))->calcular($this->pedido()),
        );
    }

    public function test_chave_base64_usa_os_bytes_decodificados(): void
    {
        $bytes = random_bytes(32);
        $pedido = $this->pedido();

        $this->assertSame(
            $this->manual($pedido, $bytes),
            (new HashDaRequisicao('base64:'.base64_encode($bytes)))->calcular($pedido),
        );
        $this->assertNotSame(
            $this->manual($pedido, 'base64:'.base64_encode($bytes)),
            (new HashDaRequisicao('base64:'.base64_encode($bytes)))->calcular($pedido),
        );
    }

    public function test_base64_invalido_e_recusado(): void
    {
        $this->expectException(LogicException::class);

        new HashDaRequisicao('base64:@@@nao-e-base64@@@');
    }

    public function test_confere_aceita_chave_atual_e_anteriores_e_recusa_o_resto(): void
    {
        $pedido = $this->pedido();
        $antiga = 'base64:'.base64_encode(random_bytes(32));
        $outra = new HashDaRequisicao('chave-de-outro-sistema-123');
        $h = new HashDaRequisicao(self::CHAVE, [$antiga]);

        $this->assertTrue($h->confere($pedido, (new HashDaRequisicao(self::CHAVE))->calcular($pedido)));
        $this->assertTrue($h->confere($pedido, (new HashDaRequisicao($antiga))->calcular($pedido)));
        $this->assertFalse($h->confere($pedido, $outra->calcular($pedido)));
        $this->assertFalse($h->confere($pedido, 'qualquer coisa'));
        $this->assertFalse($h->confere($pedido, ''));
        $this->assertFalse($h->confere($this->pedido(['hora' => '15:00']), $h->calcular($pedido)));
    }

    public function test_sem_chave_anterior_so_a_atual_vale(): void
    {
        $pedido = $this->pedido();
        $antiga = new HashDaRequisicao('chave-antiga-0123456789');

        $this->assertFalse((new HashDaRequisicao(self::CHAVE))->confere($pedido, $antiga->calcular($pedido)));
    }

    public function test_chave_vazia_e_recusada(): void
    {
        $this->expectException(LogicException::class);

        new HashDaRequisicao('');
    }

    public function test_chave_anterior_vazia_e_recusada(): void
    {
        $this->expectException(LogicException::class);

        new HashDaRequisicao(self::CHAVE, ['']);
    }

    public function test_da_aplicacao_le_app_key_e_chaves_anteriores(): void
    {
        $antiga = 'base64:'.base64_encode(random_bytes(32));
        config(['app.key' => self::CHAVE, 'app.previous_keys' => [$antiga]]);
        $pedido = $this->pedido();

        $h = HashDaRequisicao::daAplicacao();

        $this->assertSame((new HashDaRequisicao(self::CHAVE))->calcular($pedido), $h->calcular($pedido));
        $this->assertTrue($h->confere($pedido, (new HashDaRequisicao($antiga))->calcular($pedido)));

        config(['app.previous_keys' => []]);
        $this->assertFalse(HashDaRequisicao::daAplicacao()->confere($pedido, (new HashDaRequisicao($antiga))->calcular($pedido)));
    }
}
