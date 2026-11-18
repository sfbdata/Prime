<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\BuscarPastasFerramenta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O contrato de entrada de `buscar_pastas` (spec mcp-remoto-oauth 6.4): as seis ações, o esquema fechado e o pedido
 * recusado antes da guarda (campo que a ação não usa, ação fora da lista). As regras que dependem do banco estão no
 * teste de integração.
 */
#[CoversClass(BuscarPastasFerramenta::class)]
final class BuscarPastasFerramentaTest extends TestCase
{
    #[TestDox('o esquema lista as seis ações, exige a ação e fecha os campos')]
    public function testEsquema(): void
    {
        $esquema = $this->ferramenta()->esquemaDeEntrada();

        self::assertSame('object', $esquema['type']);
        self::assertSame(['acao'], $esquema['required']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(
            ['buscar', 'contar', 'opcoes', 'lixeira', 'numero', 'minhas_demandas'],
            $esquema['properties']['acao']['enum'],
        );
        self::assertArrayNotHasKey('nups', $esquema['properties'], 'a busca por NUP sem escopo não sai pelo conector');
        self::assertSame('buscar_pastas', $this->ferramenta()->nome());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function pedidosInvalidos(): iterable
    {
        yield 'sem ação' => [[]];
        yield 'ação fora da lista' => [['acao' => 'apagar']];
        yield 'ação não texto' => [['acao' => 1]];
        yield 'opcoes com campo' => [['acao' => 'opcoes', 'busca' => 'x']];
        yield 'contar com página' => [['acao' => 'contar', 'pagina' => 2]];
        yield 'numero com busca' => [['acao' => 'numero', 'busca' => 'x']];
        yield 'lixeira com filtro' => [['acao' => 'lixeira', 'situacao' => 'REGULAR']];
        yield 'minhas_demandas com responsável' => [['acao' => 'minhas_demandas', 'responsavel_id' => 3]];
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

    private function ferramenta(): BuscarPastasFerramenta
    {
        return (new \ReflectionClass(BuscarPastasFerramenta::class))->newInstanceWithoutConstructor();
    }

    private function chamada(): ChamadaDoConector
    {
        return (new \ReflectionClass(ChamadaDoConector::class))->newInstanceWithoutConstructor();
    }
}
