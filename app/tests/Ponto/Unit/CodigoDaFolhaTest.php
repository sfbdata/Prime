<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;
use App\Ponto\Service\CodigoDaFolha;
use App\Ponto\Service\CodigoDoComprovante;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O código da folha emitida (E16, desenho 16 L721 a L727): `BJF-XXXX-XXXX-XXXX` com o mesmo alfabeto do comprovante,
 * sorteado no servidor e sem repetir um código que já existe.
 */
#[Group('ponto')]
#[CoversClass(CodigoDaFolha::class)]
final class CodigoDaFolhaTest extends TestCase
{
    #[TestDox('O código novo tem o formato BJF do desenho e só usa o alfabeto do comprovante (sem I, O, 0 e 1)')]
    public function testFormatoEAlfabeto(): void
    {
        $repositorio = $this->createStub(FolhaEmitidaDoPontoRepository::class);
        $repositorio->method('existeCodigo')->willReturn(false);
        $gerador = new CodigoDaFolha($repositorio);

        $vistos = [];
        for ($i = 0; $i < 200; ++$i) {
            $codigo = $gerador->novo();
            self::assertMatchesRegularExpression('/^BJF-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codigo);
            self::assertTrue(CodigoDaFolha::temFormato($codigo));
            self::assertDoesNotMatchRegularExpression('/[IO01]/', substr($codigo, 4));
            self::assertSame('', str_replace(str_split(CodigoDoComprovante::ALFABETO), '', str_replace('-', '', substr($codigo, 4))), 'só letras do alfabeto da E15');
            $vistos[$codigo] = true;
        }
        self::assertCount(200, $vistos, 'sorteado: 200 códigos, 200 diferentes');
    }

    #[TestDox('Código que já existe é sorteado de novo; cinco colisões seguidas param com erro')]
    public function testNaoRepeteCodigoExistente(): void
    {
        $repositorio = $this->createMock(FolhaEmitidaDoPontoRepository::class);
        $repositorio->expects(self::exactly(3))->method('existeCodigo')->willReturnOnConsecutiveCalls(true, true, false);
        self::assertTrue(CodigoDaFolha::temFormato((new CodigoDaFolha($repositorio))->novo()));

        $sempreColide = $this->createMock(FolhaEmitidaDoPontoRepository::class);
        $sempreColide->expects(self::exactly(5))->method('existeCodigo')->willReturn(true);
        $this->expectException(\RuntimeException::class);
        (new CodigoDaFolha($sempreColide))->novo();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function codigosDigitados(): iterable
    {
        yield 'certo' => ['BJF-ABCD-EFGH-JK23', true];
        yield 'minúsculas e espaços' => ['  bjf-abcd-efgh-jk23 ', true];
        yield 'código de comprovante (BJP)' => ['BJP-ABCD-EFGH-JK23', false];
        yield 'letra ambígua (I)' => ['BJF-ABCD-EFGH-JKI3', false];
        yield 'número ambíguo (0)' => ['BJF-ABCD-EFGH-JK03', false];
        yield 'curto' => ['BJF-ABCD-EFGH', false];
        yield 'longo' => ['BJF-ABCD-EFGH-JK23-ABCD', false];
        yield 'injeção' => ["BJF-ABCD-EFGH-JK23'--", false];
        yield 'vazio' => ['', false];
    }

    #[DataProvider('codigosDigitados')]
    #[TestDox('O que foi digitado vira o formato gravado antes da conferência: $digitado')]
    public function testNormalizarEConferirFormato(string $digitado, bool $valido): void
    {
        self::assertSame($valido, CodigoDaFolha::temFormato(CodigoDaFolha::normalizar($digitado)));
    }
}
