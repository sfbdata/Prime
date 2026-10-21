<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Indicacao\Repository\IndicacaoCodigoRepository;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(CodigoDeIndicacao::class)]
final class CodigoDeIndicacaoTest extends TestCase
{
    #[TestDox('o código sorteado tem 8 símbolos do alfabeto sem 0, O, 1 e I')]
    public function testSorteio(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            self::assertMatchesRegularExpression('/^' . CodigoDeIndicacao::PADRAO . '$/', CodigoDeIndicacao::sortear());
        }
    }

    #[TestDox('sem código: sorteia e grava; se o sorteado já era de outra pessoa, sorteia de novo')]
    public function testGeraNoPrimeiroPedido(): void
    {
        $codigos = $this->createMock(IndicacaoCodigoRepository::class);
        $codigos->method('codigoDoUsuario')->with(7)->willReturn(null);
        $codigos->expects(self::exactly(2))->method('gravarSeNaoTiver')->willReturnOnConsecutiveCalls(null, 'QWERTY23');

        self::assertSame('QWERTY23', (new CodigoDeIndicacao($codigos, $this->createStub(UrlGeneratorInterface::class)))->codigoDe(Pessoas::usuario(7)));
    }

    #[TestDox('com código: devolve o mesmo, sem gravar')]
    public function testReaproveita(): void
    {
        $codigos = $this->createMock(IndicacaoCodigoRepository::class);
        $codigos->method('codigoDoUsuario')->willReturn('ABCD2345');
        $codigos->expects(self::never())->method('gravarSeNaoTiver');

        self::assertSame('ABCD2345', (new CodigoDeIndicacao($codigos, $this->createStub(UrlGeneratorInterface::class)))->codigoDe(Pessoas::usuario(7)));
    }
}
