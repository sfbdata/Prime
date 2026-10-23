<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Profile\Enum\Genero;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Só Feminino e Masculino são oferecidos e aceitos; `nao_informado` é legado (D1 da spec genero-no-cadastro). */
#[CoversClass(Genero::class)]
final class GeneroTest extends TestCase
{
    #[TestDox('As opções dos formulários são só Feminino e Masculino')]
    public function testOferecidos(): void
    {
        self::assertSame([Genero::Feminino, Genero::Masculino], Genero::oferecidos());
    }

    #[TestDox('informado() aceita as duas opções, mesmo com espaços nas pontas')]
    public function testInformadoAceita(): void
    {
        self::assertSame(Genero::Feminino, Genero::informado('feminino'));
        self::assertSame(Genero::Masculino, Genero::informado(' masculino '));
    }

    /** @return iterable<string, array{?string}> */
    public static function recusados(): iterable
    {
        yield 'nulo' => [null];
        yield 'vazio' => [''];
        yield 'desconhecido' => ['outro'];
        yield 'maiúsculas' => ['FEMININO'];
        yield 'legado' => ['nao_informado'];
    }

    #[DataProvider('recusados')]
    #[TestDox('informado() recusa o que não é uma das duas opções com "Informe o gênero."')]
    public function testInformadoRecusa(?string $valor): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Informe o gênero.');

        Genero::informado($valor);
    }

    #[TestDox('O legado não conta como preenchido; as duas opções contam')]
    public function testPreenchido(): void
    {
        self::assertTrue(Genero::Feminino->preenchido());
        self::assertTrue(Genero::Masculino->preenchido());
        self::assertFalse(Genero::NaoInformado->preenchido());
    }
}
