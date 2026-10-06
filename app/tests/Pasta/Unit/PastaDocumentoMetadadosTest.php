<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaDocumento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os três metadados da D1 (`enviadoPor`, `modificadoEm`, `paginas`): nascem NULL, e `paginas`
 * só aceita inteiro positivo — zero gravado viraria "0 páginas" num PDF de verdade.
 */
#[CoversClass(PastaDocumento::class)]
final class PastaDocumentoMetadadosTest extends TestCase
{
    #[TestDox('nasce sem autor, sem modificação e sem páginas')]
    public function testNasceNulo(): void
    {
        $doc = new PastaDocumento();

        self::assertNull($doc->getEnviadoPor());
        self::assertNull($doc->getModificadoEm());
        self::assertNull($doc->getPaginas());
    }

    #[TestDox('paginas aceita inteiro positivo e volta a NULL')]
    public function testPaginasAceitaPositivoENull(): void
    {
        $doc = new PastaDocumento();

        $doc->setPaginas(12);
        self::assertSame(12, $doc->getPaginas());

        $doc->setPaginas(null);
        self::assertNull($doc->getPaginas());
    }

    /** @return iterable<string, array{int}> */
    public static function contagensInvalidas(): iterable
    {
        yield 'zero'     => [0];
        yield 'negativo' => [-3];
    }

    #[TestDox('paginas recusa o que não é contagem: $_dataName')]
    #[DataProvider('contagensInvalidas')]
    public function testPaginasRecusaZeroOuNegativo(int $invalido): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PastaDocumento())->setPaginas($invalido);
    }

    #[TestDox('marcarModificadoEm grava o instante recebido — quem decide a hora é quem chama')]
    public function testMarcarModificadoEm(): void
    {
        $doc     = new PastaDocumento();
        $instante = new \DateTimeImmutable('2026-10-06 14:30:00');

        $doc->marcarModificadoEm($instante);

        self::assertSame($instante, $doc->getModificadoEm());
    }

    #[TestDox('enviadoPor guarda o usuário e aceita NULL (acervo anterior à coluna)')]
    public function testEnviadoPor(): void
    {
        $doc  = new PastaDocumento();
        $user = (new User())->setEmail('quem@test.com');

        $doc->setEnviadoPor($user);
        self::assertSame($user, $doc->getEnviadoPor());

        $doc->setEnviadoPor(null);
        self::assertNull($doc->getEnviadoPor());
    }
}
