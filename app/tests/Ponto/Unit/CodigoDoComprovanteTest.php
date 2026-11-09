<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Repository\ComprovanteDoPontoRepository;
use App\Ponto\Service\CodigoDoComprovante;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O código do comprovante (E15, desenho 16 L424 e L525): `BJP-XXXX-XXXX-XXXX` com o alfabeto sem caracteres
 * ambíguos, sorteado no servidor e sem repetir um código que já existe.
 */
#[Group('ponto')]
#[CoversClass(CodigoDoComprovante::class)]
final class CodigoDoComprovanteTest extends TestCase
{
    #[TestDox('O código novo tem o formato do desenho e só usa o alfabeto sem I, O, 0 e 1')]
    public function testFormatoEAlfabeto(): void
    {
        $repositorio = $this->createStub(ComprovanteDoPontoRepository::class);
        $repositorio->method('existeCodigo')->willReturn(false);
        $gerador = new CodigoDoComprovante($repositorio);

        $vistos = [];
        for ($i = 0; $i < 200; ++$i) {
            $codigo = $gerador->novo();
            self::assertMatchesRegularExpression('/^BJP-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codigo);
            self::assertTrue(CodigoDoComprovante::temFormato($codigo));
            self::assertDoesNotMatchRegularExpression('/[IO01]/', substr($codigo, 4));
            $vistos[$codigo] = true;
        }
        self::assertCount(200, $vistos, 'sorteado: 200 códigos, 200 diferentes');
    }

    #[TestDox('Código que já existe é sorteado de novo; cinco colisões seguidas param com erro')]
    public function testNaoRepeteCodigoExistente(): void
    {
        $repositorio = $this->createMock(ComprovanteDoPontoRepository::class);
        $repositorio->expects(self::exactly(3))->method('existeCodigo')->willReturnOnConsecutiveCalls(true, true, false);
        self::assertTrue(CodigoDoComprovante::temFormato((new CodigoDoComprovante($repositorio))->novo()));

        $sempreColide = $this->createStub(ComprovanteDoPontoRepository::class);
        $sempreColide->method('existeCodigo')->willReturn(true);
        $this->expectException(\RuntimeException::class);
        (new CodigoDoComprovante($sempreColide))->novo();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function codigosDigitados(): iterable
    {
        yield 'certo' => ['BJP-ABCD-EFGH-JK23', true];
        yield 'minúsculas e espaços' => ['  bjp-abcd-efgh-jk23 ', true];
        yield 'letra ambígua (O)' => ['BJP-ABCD-EFGH-JKO3', false];
        yield 'número ambíguo (1)' => ['BJP-ABCD-EFGH-JK13', false];
        yield 'curto' => ['BJP-ABCD-EFGH', false];
        yield 'outro prefixo' => ['BJX-ABCD-EFGH-JK23', false];
        yield 'injeção' => ["BJP-ABCD-EFGH-JK23'--", false];
        yield 'vazio' => ['', false];
    }

    #[DataProvider('codigosDigitados')]
    #[TestDox('O que foi digitado vira o formato gravado antes da conferência: $digitado')]
    public function testNormalizarEConferirFormato(string $digitado, bool $valido): void
    {
        self::assertSame($valido, CodigoDoComprovante::temFormato(CodigoDoComprovante::normalizar($digitado)));
    }
}
