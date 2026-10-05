<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\PastaVizinhasOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O rótulo das setas ‹ › passou a dizer o IDENTIFICADOR da pasta vizinha (o
 * `nomeCliente`, decisão do dono de 01/09/2026) além do número — é o que o
 * desenho 1.2.3 mostra no title. Sem identificador, o rótulo antigo continua:
 * o número é o que desambigua "anterior" num acervo ordenado do maior para o menor.
 */
#[CoversClass(PastaVizinhasOutput::class)]
final class PastaVizinhasOutputTest extends TestCase
{
    #[TestDox('com identificador o rótulo diz o nome e, entre parênteses, o número da pasta')]
    public function testComIdentificadorDizNomeENumero(): void
    {
        $out = PastaVizinhasOutput::montar([
            'anterior' => ['id' => 7, 'nup' => '2003', 'nomeCliente' => 'MARIA DAS GRACAS'],
            'proxima'  => ['id' => 5, 'nup' => '2001', 'nomeCliente' => 'CONDOMINIO SOL NASCENTE'],
        ]);

        self::assertSame(7, $out->anteriorId);
        self::assertSame('Pasta anterior: MARIA DAS GRACAS (pasta 2003)', $out->rotuloAnterior);
        self::assertSame(5, $out->proximaId);
        self::assertSame('Próxima pasta: CONDOMINIO SOL NASCENTE (pasta 2001)', $out->rotuloProxima);
    }

    #[TestDox('sem identificador (nulo, vazio ou ausente) o rótulo continua sendo o número')]
    public function testSemIdentificadorMantemORotuloPeloNumero(): void
    {
        $out = PastaVizinhasOutput::montar([
            'anterior' => ['id' => 7, 'nup' => '2003', 'nomeCliente' => null],
            'proxima'  => ['id' => 5, 'nup' => '2001', 'nomeCliente' => '   '],
        ]);
        self::assertSame('Pasta anterior na lista: 2003', $out->rotuloAnterior);
        self::assertSame('Próxima pasta na lista: 2001', $out->rotuloProxima);

        // Chave ausente: a leitura antiga do repositório (só id e nup) continua válida.
        $semChave = PastaVizinhasOutput::montar([
            'anterior' => ['id' => 7, 'nup' => '2003'],
            'proxima'  => null,
        ]);
        self::assertSame('Pasta anterior na lista: 2003', $semChave->rotuloAnterior);
    }

    #[TestDox('na ponta do acervo o rótulo explica por que a seta está inerte')]
    public function testPontaDoAcervo(): void
    {
        $out = PastaVizinhasOutput::montar(['anterior' => null, 'proxima' => null]);

        self::assertNull($out->anteriorId);
        self::assertSame('Esta é a primeira pasta do acervo', $out->rotuloAnterior);
        self::assertNull($out->proximaId);
        self::assertSame('Esta é a última pasta do acervo', $out->rotuloProxima);
    }

    #[TestDox('pasta vizinha sem número é identificada pelo id, com ou sem nome')]
    public function testSemNupUsaOId(): void
    {
        $out = PastaVizinhasOutput::montar([
            'anterior' => ['id' => 7, 'nup' => null, 'nomeCliente' => 'MARIA'],
            'proxima'  => ['id' => 5, 'nup' => ' ', 'nomeCliente' => null],
        ]);

        self::assertSame('Pasta anterior: MARIA (pasta #7)', $out->rotuloAnterior);
        self::assertSame('Próxima pasta na lista: #5', $out->rotuloProxima);
    }
}
