<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerPastaFerramenta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato de entrada de `ler_pasta` (spec mcp-remoto-oauth 6.4): as partes do PR 6, sem publicações, linha do tempo
 * e controladoria, e o pedido recusado antes da guarda. As regras que dependem do banco estão no teste de integração.
 */
#[CoversClass(LerPastaFerramenta::class)]
final class LerPastaFerramentaTest extends TestCase
{
    #[TestDox('o esquema traz as dez partes do PR 6 e exige a pasta')]
    public function testEsquema(): void
    {
        $esquema = $this->ferramenta()->esquemaDeEntrada();
        $partes = $esquema['properties']['partes']['items']['enum'];

        self::assertSame('ler_pasta', $this->ferramenta()->nome());
        self::assertSame(['pasta_id'], $esquema['required']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame('integer', $esquema['properties']['pasta_id']['type']);
        self::assertEqualsCanonicalizing(
            ['resumo', 'andamento', 'registro', 'equipe', 'cadastro', 'prazos', 'metas', 'detalhes', 'checklist', 'financeiro'],
            $partes,
        );
        foreach (['publicacoes', 'timeline', 'controladoria'] as $foraDoPr) {
            self::assertNotContains($foraDoPr, $partes);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function pedidosInvalidos(): iterable
    {
        yield 'sem pasta' => [['partes' => ['resumo']]];
        yield 'pasta zero' => [['pasta_id' => 0]];
        yield 'pasta negativa' => [['pasta_id' => -3]];
        yield 'pasta texto' => [['pasta_id' => 'abc']];
        yield 'partes vazia' => [['pasta_id' => 1, 'partes' => []]];
        yield 'partes não lista' => [['pasta_id' => 1, 'partes' => 'resumo']];
        yield 'publicações fora do PR' => [['pasta_id' => 1, 'partes' => ['publicacoes']]];
        yield 'linha do tempo fora do PR' => [['pasta_id' => 1, 'partes' => ['timeline']]];
        yield 'controladoria fora do PR' => [['pasta_id' => 1, 'partes' => ['controladoria']]];
        yield 'página sem registro' => [['pasta_id' => 1, 'partes' => ['resumo'], 'pagina' => 2]];
        yield 'página zero' => [['pasta_id' => 1, 'partes' => ['registro'], 'pagina' => 0]];
        yield 'página acima do teto' => [['pasta_id' => 1, 'partes' => ['registro'], 'pagina' => 10_001]];
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

    private function ferramenta(): LerPastaFerramenta
    {
        return (new \ReflectionClass(LerPastaFerramenta::class))->newInstanceWithoutConstructor();
    }

    private function chamada(): ChamadaDoConector
    {
        return (new \ReflectionClass(ChamadaDoConector::class))->newInstanceWithoutConstructor();
    }
}
