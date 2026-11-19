<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerClientesFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato de entrada de `ler_clientes` neste PR (spec mcp-remoto-oauth, 6.4): as quatro ações, o esquema fechado,
 * `buscar` fora do esquema (tem linha na tabela, chega depois) e o pedido recusado antes da
 * guarda. As regras que dependem do banco estão no teste de integração.
 */
#[CoversClass(LerClientesFerramenta::class)]
final class LerClientesFerramentaTest extends TestCase
{
    #[TestDox('o esquema lista só ler, conferir_documento, documentos_de_identidade e documentos_do_cliente, exige a ação e fecha os campos')]
    public function testEsquema(): void
    {
        $esquema = $this->ferramenta()->esquemaDeEntrada();

        self::assertSame('object', $esquema['type']);
        self::assertSame(['acao'], $esquema['required']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['ler', 'conferir_documento', 'documentos_de_identidade', 'documentos_do_cliente'], $esquema['properties']['acao']['enum']);
        self::assertSame(['acao', 'cliente_id', 'documento'], array_keys($esquema['properties']));
        self::assertSame('ler_clientes', $this->ferramenta()->nome());
        self::assertSame('Ler clientes', $this->ferramenta()->titulo());
    }

    #[TestDox('as ações que ficam para depois têm linha na tabela, mas não estão no esquema')]
    public function testAcoesQueChegamDepois(): void
    {
        $doEsquema = $this->ferramenta()->esquemaDeEntrada()['properties']['acao']['enum'];

        self::assertSame([], array_diff($doEsquema, TabelaDeAbaEChave::acoes('ler_clientes')));
        self::assertSame(['buscar'], array_values(array_diff(TabelaDeAbaEChave::acoes('ler_clientes'), $doEsquema)));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function pedidosInvalidos(): iterable
    {
        $invalido = ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA;
        yield 'sem ação' => [[], $invalido];
        yield 'ação não texto' => [['acao' => 1], $invalido];
        yield 'buscar ainda não existe' => [['acao' => 'buscar', 'nome' => 'Maria'], $invalido];
        yield 'documentos_do_cliente sem cliente_id' => [['acao' => 'documentos_do_cliente'], $invalido];
        yield 'documentos_do_cliente com documento' => [['acao' => 'documentos_do_cliente', 'cliente_id' => 1, 'documento' => 'x'], $invalido];
        yield 'ação fora da lista' => [['acao' => 'apagar']];
        yield 'ler sem cliente_id' => [['acao' => 'ler'], $invalido];
        yield 'ler com cliente_id zero' => [['acao' => 'ler', 'cliente_id' => 0], $invalido];
        yield 'ler com cliente_id texto' => [['acao' => 'ler', 'cliente_id' => 'um'], $invalido];
        yield 'ler com documento' => [['acao' => 'ler', 'cliente_id' => 1, 'documento' => '52998224725'], $invalido];
        yield 'conferir com cliente_id' => [['acao' => 'conferir_documento', 'documento' => '52998224725', 'cliente_id' => 1], $invalido];
        yield 'conferir sem documento' => [['acao' => 'conferir_documento'], $invalido];
        yield 'conferir com documento não texto' => [['acao' => 'conferir_documento', 'documento' => 52998224725], $invalido];
        yield 'conferir com documento longo demais' => [['acao' => 'conferir_documento', 'documento' => str_repeat('1', 31)], $invalido];
        yield 'conferir com letras' => [['acao' => 'conferir_documento', 'documento' => 'Maria 529.982.247-25'], ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO];
        yield 'conferir com 10 dígitos' => [['acao' => 'conferir_documento', 'documento' => '5299822472'], ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO];
        yield 'documentos_de_identidade com documento' => [['acao' => 'documentos_de_identidade', 'cliente_id' => 1, 'documento' => 'x'], $invalido];
        yield 'campo desconhecido' => [['acao' => 'ler', 'cliente_id' => 1, 'cpf' => 'x'], $invalido];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('pedidosInvalidos')]
    #[TestDox('pedido inválido antes de qualquer leitura: $_dataName')]
    public function testPedidoInvalido(array $argumentos, string $motivo = ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA): void
    {
        try {
            $this->ferramenta()->executar($argumentos, $this->chamada());
            self::fail('deveria recusar o pedido');
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);
            self::assertStringNotContainsString('529', $e->getMessage(), 'o valor digitado nunca entra na mensagem');
        }
    }

    private function ferramenta(): LerClientesFerramenta
    {
        return (new \ReflectionClass(LerClientesFerramenta::class))->newInstanceWithoutConstructor();
    }

    private function chamada(): ChamadaDoConector
    {
        return (new \ReflectionClass(ChamadaDoConector::class))->newInstanceWithoutConstructor();
    }
}
