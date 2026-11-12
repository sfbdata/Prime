<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Service\ComprovanteDoVoto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComprovanteDoVoto::class)]
#[Group('voto')]
final class ComprovanteDoVotoTest extends TestCase
{
    private const EM = '2026-11-12 14:30:05';

    #[TestDox('O código tem o formato BJV-XXXX-XXXX-XXXX e é sempre o mesmo para a mesma unidade, pauta e hora')]
    public function testFormatoEDeterminismo(): void
    {
        $servico = new ComprovanteDoVoto('segredo');
        $codigo = $servico->codigo(10, 20, new \DateTimeImmutable(self::EM));

        self::assertMatchesRegularExpression('/^BJV-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $codigo);
        self::assertSame($codigo, $servico->codigo(10, 20, new \DateTimeImmutable(self::EM)));
    }

    #[TestDox('Os microssegundos não mudam o código: o banco guarda a hora em segundos')]
    public function testIgnoraMicrossegundos(): void
    {
        $servico = new ComprovanteDoVoto('segredo');

        self::assertSame(
            $servico->codigo(10, 20, new \DateTimeImmutable(self::EM)),
            $servico->codigo(10, 20, new \DateTimeImmutable(self::EM . '.987654')),
        );
    }

    #[TestDox('Unidade, pauta, hora e segredo diferentes geram códigos diferentes')]
    public function testCadaEntradaMudaOCodigo(): void
    {
        $servico = new ComprovanteDoVoto('segredo');
        $base = $servico->codigo(10, 20, new \DateTimeImmutable(self::EM));

        self::assertNotSame($base, $servico->codigo(11, 20, new \DateTimeImmutable(self::EM)));
        self::assertNotSame($base, $servico->codigo(10, 21, new \DateTimeImmutable(self::EM)));
        self::assertNotSame($base, $servico->codigo(10, 20, new \DateTimeImmutable('2026-11-12 14:30:06')));
        self::assertNotSame($base, (new ComprovanteDoVoto('outro-segredo'))->codigo(10, 20, new \DateTimeImmutable(self::EM)));
    }

    #[TestDox('normalizar aceita minúsculas, espaços e traços a menos; recusa o que não é código')]
    public function testNormalizar(): void
    {
        self::assertSame('BJV-ABCD-0123-4567', ComprovanteDoVoto::normalizar(' bjv-abcd-0123-4567 '));
        self::assertSame('BJV-ABCD-0123-4567', ComprovanteDoVoto::normalizar('BJV ABCD 0123 4567'));
        self::assertSame('BJV-ABCD-0123-4567', ComprovanteDoVoto::normalizar('BJVABCD01234567'));
        self::assertSame('', ComprovanteDoVoto::normalizar('BJV-ABCD-0123'));
        self::assertSame('', ComprovanteDoVoto::normalizar('BJV-ZZZZ-0123-4567'));
        self::assertSame('', ComprovanteDoVoto::normalizar(''));
    }
}
