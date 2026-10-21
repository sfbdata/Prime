<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Processo\Service\AcentuacaoEstragada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcentuacaoEstragada::class)]
final class AcentuacaoEstragadaTest extends TestCase
{
    /**
     * O caminho do defeito, como gravado em produção: o `fixOrgaoJulgador` antigo leu o UTF-8 como ISO-8859-1 e a
     * entidade passou para maiúsculas.
     */
    private static function estragar(string $original): string
    {
        return mb_strtoupper(mb_convert_encoding($original, 'UTF-8', 'ISO-8859-1'));
    }

    /** @return iterable<string, array{string, string}> nomes de órgão estragados em produção (21/10/2026) */
    public static function nomesReais(): iterable
    {
        yield 'ordinal e í' => ['6ª Vara do Trabalho de Brasília - DF', '6ª VARA DO TRABALHO DE BRASÍLIA - DF'];
        yield 'Ú e Ã maiúsculos (controles invisíveis)' => ['VARA ÚNICA DE SÃO GERALDO DO ARAGUAIA', 'VARA ÚNICA DE SÃO GERALDO DO ARAGUAIA'];
        yield 'â de Luziânia' => ['Vara Única de Luziânia', 'VARA ÚNICA DE LUZIÂNIA'];
        yield 'õ de Sucessões vira o Μ grego' => ['Vara de Família, Sucessões e Infância e Juventude', 'VARA DE FAMÍLIA, SUCESSÕES E INFÂNCIA E JUVENTUDE'];
        yield 'ê, é' => ['Juizado de Violência Doméstica e Familiar contra a Mulher', 'JUIZADO DE VIOLÊNCIA DOMÉSTICA E FAMILIAR CONTRA A MULHER'];
        yield 'ó, ã, á' => ['Central de Custódia Interior - Plantão Judiciário 10', 'CENTRAL DE CUSTÓDIA INTERIOR - PLANTÃO JUDICIÁRIO 10'];
        yield 'ÇÃ maiúsculos' => ['VARA JURISDIÇÃO PLENA', 'VARA JURISDIÇÃO PLENA'];
        yield 'ó maiúsculo' => ['V DOS FEITOS DE REL DE CONS CIV E COMERCIAIS DE SANTA MARIA DA VITÓRIA', 'V DOS FEITOS DE REL DE CONS CIV E COMERCIAIS DE SANTA MARIA DA VITÓRIA'];
    }

    #[DataProvider('nomesReais')]
    #[TestDox('reverte o nome real estragado: $original')]
    public function testReverteNomesReais(string $original, string $esperado): void
    {
        $servico = new AcentuacaoEstragada();
        $estragado = self::estragar($original);

        self::assertTrue($servico->temEstrago($estragado));
        self::assertSame($esperado, $servico->reverter($estragado));
    }

    #[TestDox('o caso do Μ grego aparece como em produção: "SUCESSÃΜES"')]
    public function testMuGregoComoEmProducao(): void
    {
        self::assertStringContainsString('SUCESSÃΜES', self::estragar('Sucessões'));
        self::assertSame('SUCESSÕES', (new AcentuacaoEstragada())->reverter(self::estragar('Sucessões')));
    }

    #[TestDox('o texto visível de produção, copiado da consulta, também reverte: "6Âª VARA DO TRABALHO DE BRASÃ­LIA - DF"')]
    public function testTextoCopiadoDeProducao(): void
    {
        $deProducao = "6\u{C2}\u{AA} VARA DO TRABALHO DE BRAS\u{C3}\u{AD}LIA - DF";

        self::assertSame('6ª VARA DO TRABALHO DE BRASÍLIA - DF', (new AcentuacaoEstragada())->reverter($deProducao));
    }

    /** @return iterable<string, array{string}> */
    public static function semEstrago(): iterable
    {
        yield 'Ã legítimo' => ['VARA DE SÃO PAULO'];
        yield 'rescisão' => ['RESCISÃO DO CONTRATO E DEVOLUÇÃO DO DINHEIRO'];
        yield 'sem acento' => ['2A VARA CIVEL'];
        yield 'já revertido (idempotente)' => ['6ª VARA DO TRABALHO DE BRASÍLIA - DF'];
    }

    #[DataProvider('semEstrago')]
    #[TestDox('texto sem o estrago não é tocado: $texto')]
    public function testSemEstragoNaoMexe(string $texto): void
    {
        $servico = new AcentuacaoEstragada();

        self::assertFalse($servico->temEstrago($texto));
        self::assertNull($servico->reverter($texto));
    }

    #[TestDox('o que não reverte limpo devolve nulo (fica intocado e listado): bytes inválidos ou caractere fora do Latin-1')]
    public function testNaoReverteLimpo(): void
    {
        $servico = new AcentuacaoEstragada();

        self::assertNull($servico->reverter("VARA \u{C3}\u{A9} \u{C3}O"), 'Ã sozinho seguido de O não forma UTF-8');
        self::assertNull($servico->reverter("CÃ\u{AD}VEL → VARA"), 'seta fora do Latin-1');
    }
}
