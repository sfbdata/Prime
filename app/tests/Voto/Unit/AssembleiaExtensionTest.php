<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Controller\AssembleiaController;
use App\Voto\Twig\AssembleiaExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O filtro `voto_fracao` (a fração do banco, ponto e 6 casas, como o desenho mostra: vírgula, sem zeros à direita) e a
 * função `voto_assembleia_aba` (só as três ações que as abas de /voto embutem).
 */
#[CoversClass(AssembleiaExtension::class)]
final class AssembleiaExtensionTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function fracoes(): iterable
    {
        yield 'fração pequena' => ['0.025000', '0,025'];
        yield 'seis casas' => ['0.000001', '0,000001'];
        yield 'inteiro' => ['12.000000', '12'];
        yield 'milhar' => ['1234.500000', '1.234,5'];
        yield 'sem casas' => ['3', '3'];
        yield 'nulo' => [null, ''];
    }

    #[DataProvider('fracoes')]
    #[TestDox('$_dataName')]
    public function testFracao(?string $guardada, string $mostrada): void
    {
        self::assertSame($mostrada, AssembleiaExtension::fracao($guardada));
    }

    #[TestDox('A aba embutida aponta para a ação do AssembleiaController; outra ação é recusada')]
    public function testAba(): void
    {
        self::assertSame(AssembleiaController::class . '::painel', AssembleiaExtension::aba('painel')->controller);

        $this->expectException(\InvalidArgumentException::class);
        AssembleiaExtension::aba('ata');
    }
}
