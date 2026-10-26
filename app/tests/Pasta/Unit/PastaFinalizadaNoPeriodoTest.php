<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Criterio\PastaFinalizadaNoPeriodo;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O critério compartilhado entre a coluna "Pastas concluídas" do Dashboard e o filtro `concluida` do
 * Expediente (regra B12): finalizada, sem lápide, e as datas sobre `finalizadaEm`.
 */
#[CoversClass(PastaFinalizadaNoPeriodo::class)]
final class PastaFinalizadaNoPeriodoTest extends TestCase
{
    private function qb(): QueryBuilder
    {
        return (new QueryBuilder($this->createStub(EntityManagerInterface::class)))->select('x.id')->from('Pasta', 'x');
    }

    #[TestDox('sem datas: só finalizada e sem lápide, no alias recebido')]
    public function testSemDatas(): void
    {
        $qb = $this->qb();
        PastaFinalizadaNoPeriodo::aplicar($qb, 'x', null, null);

        self::assertStringContainsString('x.finalizadaEm IS NOT NULL', $qb->getDQL());
        self::assertStringContainsString('x.excluidaEm IS NULL', $qb->getDQL());
        self::assertStringNotContainsString('>=', $qb->getDQL());
        self::assertCount(0, $qb->getParameters());
    }

    #[TestDox('início, fim e corte do Zerar entram sobre finalizadaEm, nunca sobre a abertura')]
    public function testDatasECorte(): void
    {
        $qb = $this->qb();
        $de = new \DateTimeImmutable('2026-09-01');
        $ate = new \DateTimeImmutable('2026-09-30 23:59:59');
        $desde = new \DateTimeImmutable('2026-09-10 08:00:00');
        PastaFinalizadaNoPeriodo::aplicar($qb, 'x', $de, $ate, $desde);

        $dql = $qb->getDQL();
        self::assertStringContainsString('x.finalizadaEm >= :pfpDe', $dql);
        self::assertStringContainsString('x.finalizadaEm <= :pfpAte', $dql);
        self::assertStringContainsString('x.finalizadaEm >= :pfpDesde', $dql);
        self::assertStringNotContainsString('dataAbertura', $dql);
        self::assertSame($de, $qb->getParameter('pfpDe')?->getValue());
        self::assertSame($ate, $qb->getParameter('pfpAte')?->getValue());
        self::assertSame($desde, $qb->getParameter('pfpDesde')?->getValue());
    }
}
