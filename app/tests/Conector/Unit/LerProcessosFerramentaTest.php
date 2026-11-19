<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerProcessosFerramenta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato de entrada de `ler_processos` (spec mcp-remoto-oauth, `ler_processos`): as quatro ações, o esquema
 * fechado e o pedido recusado antes da guarda. As regras que dependem do banco estão no
 * teste de integração.
 */
#[CoversClass(LerProcessosFerramenta::class)]
final class LerProcessosFerramentaTest extends TestCase
{
    #[TestDox('o esquema lista buscar, detalhar, para_vincular e cnj (com numero_processo de até 30 caracteres), exige a ação e fecha os campos')]
    public function testEsquema(): void
    {
        $esquema = $this->ferramenta()->esquemaDeEntrada();

        self::assertSame('object', $esquema['type']);
        self::assertSame(['acao'], $esquema['required']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['buscar', 'detalhar', 'para_vincular', 'cnj'], $esquema['properties']['acao']['enum']);
        self::assertSame(['type' => 'string', 'maxLength' => 30], array_intersect_key($esquema['properties']['numero_processo'], ['type' => 1, 'maxLength' => 1]));
        self::assertSame('ler_processos', $this->ferramenta()->nome());
        self::assertSame('Ler processos', $this->ferramenta()->titulo());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function pedidosInvalidos(): iterable
    {
        yield 'sem ação' => [[]];
        yield 'ação não texto' => [['acao' => 1]];
        yield 'cnj com o campo errado' => [['acao' => 'cnj', 'numero' => '07011345720258070007', 'pasta_id' => 1]];
        yield 'cnj com termo' => [['acao' => 'cnj', 'numero_processo' => '07011345720258070007', 'pasta_id' => 1, 'termo' => 'x']];
        yield 'ação fora da lista' => [['acao' => 'apagar']];
        yield 'buscar com processo_id' => [['acao' => 'buscar', 'processo_id' => 1]];
        yield 'detalhar com termo' => [['acao' => 'detalhar', 'processo_id' => 1, 'termo' => 'x']];
        yield 'para_vincular com página' => [['acao' => 'para_vincular', 'pasta_id' => 1, 'pagina' => 2]];
        yield 'campo desconhecido' => [['acao' => 'buscar', 'nups' => 'x']];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('pedidosInvalidos')]
    #[TestDox('pedido inválido antes da guarda: $_dataName')]
    public function testPedidoInvalido(array $argumentos): void
    {
        try {
            $this->ferramenta()->executar($argumentos, $this->chamada());
            self::fail('deveria recusar o pedido');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $e->motivo);
        }
    }

    private function ferramenta(): LerProcessosFerramenta
    {
        return (new \ReflectionClass(LerProcessosFerramenta::class))->newInstanceWithoutConstructor();
    }

    private function chamada(): ChamadaDoConector
    {
        return (new \ReflectionClass(ChamadaDoConector::class))->newInstanceWithoutConstructor();
    }
}
