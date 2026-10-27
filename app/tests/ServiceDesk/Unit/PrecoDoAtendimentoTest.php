<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\ServiceDesk\Entity\PrecoDoAtendimento;
use App\ServiceDesk\Enum\TipoDeChamado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrecoDoAtendimento::class)]
final class PrecoDoAtendimentoTest extends TestCase
{
    #[DataProvider('invalidos')]
    public function testPrecoForaDaFaixaEhRecusado(int $centavos): void
    {
        $this->expectException(\DomainException::class);
        new PrecoDoAtendimento(TipoDeChamado::Suporte, $centavos);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidos(): iterable
    {
        yield 'zero' => [0];
        yield 'negativo' => [-100];
        yield 'acima de R$ 100.000,00' => [10_000_001];
    }

    public function testAlterarValor(): void
    {
        $p = new PrecoDoAtendimento(TipoDeChamado::Agenda, 6_000);
        $p->alterarValor(7_500);

        self::assertSame(7_500, $p->getValorCentavos());
    }
}
