<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Criterio\RegistroDePastaNoPeriodo;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O critério compartilhado entre a coluna Relatório do Dashboard e o modal "Ver relatório" (regra B12):
 * escritório, lápide, cartão do sistema (só mensagem), cadeado e as datas sobre `criadaEm`.
 */
#[CoversClass(RegistroDePastaNoPeriodo::class)]
final class RegistroDePastaNoPeriodoTest extends TestCase
{
    private function qb(): QueryBuilder
    {
        return (new QueryBuilder($this->createStub(EntityManagerInterface::class)))->select('r.id')->from('Registro', 'r');
    }

    #[TestDox('as três abas do desenho, cada uma no seu fragmento da pasta')]
    public function testAbas(): void
    {
        self::assertSame([
            PastaMensagem::class             => 'dados',
            PastaObservacaoFinanceira::class => 'financeiro',
            PastaObservacaoDetalhes::class   => 'detalhes',
        ], RegistroDePastaNoPeriodo::ABAS);
    }

    #[TestDox('sem datas nem cadeado: escritório dos dois lados, sem lápide e sem o cartão do sistema (mensagem)')]
    public function testBaseDaMensagem(): void
    {
        $qb = $this->qb();
        $tenant = new Tenant();
        RegistroDePastaNoPeriodo::aplicar($qb, PastaMensagem::class, 'r', 'p', $tenant, null, null);

        $dql = $qb->getDQL();
        self::assertStringContainsString('r.tenant = :rpTenant', $dql);
        self::assertStringContainsString('p.tenant = :rpTenant', $dql);
        self::assertStringContainsString('p.excluidaEm IS NULL', $dql);
        self::assertStringContainsString('r.sistema IS NULL', $dql);
        self::assertStringNotContainsString('NOT IN', $dql);
        self::assertStringNotContainsString('criadaEm', $dql);
        self::assertSame($tenant, $qb->getParameter('rpTenant')?->getValue());
    }

    #[TestDox('observação financeira e de detalhes não têm o cartão do sistema')]
    public function testObservacoesSemSistema(): void
    {
        foreach ([PastaObservacaoFinanceira::class, PastaObservacaoDetalhes::class] as $classe) {
            $qb = $this->qb();
            RegistroDePastaNoPeriodo::aplicar($qb, $classe, 'r', 'p', new Tenant(), null, null);
            self::assertStringNotContainsString('sistema', $qb->getDQL(), $classe);
        }
    }

    #[TestDox('cadeado, início, fim e corte do Zerar entram sobre criadaEm, nos aliases recebidos')]
    public function testCadeadoDatasECorte(): void
    {
        $qb = $this->qb();
        $de = new \DateTimeImmutable('2026-09-01');
        $ate = new \DateTimeImmutable('2026-09-30 23:59:59');
        $desde = new \DateTimeImmutable('2026-09-10 08:00:00');
        RegistroDePastaNoPeriodo::aplicar($qb, PastaMensagem::class, 'reg', 'pas', new Tenant(), $de, $ate, $desde, [7, 9]);

        $dql = $qb->getDQL();
        self::assertStringContainsString('reg.criadaEm >= :rpDe', $dql);
        self::assertStringContainsString('reg.criadaEm <= :rpAte', $dql);
        self::assertStringContainsString('reg.criadaEm >= :rpDesde', $dql);
        self::assertStringContainsString('pas.id NOT IN (:rpOcultas)', $dql);
        self::assertSame($de, $qb->getParameter('rpDe')?->getValue());
        self::assertSame($ate, $qb->getParameter('rpAte')?->getValue());
        self::assertSame($desde, $qb->getParameter('rpDesde')?->getValue());
        self::assertSame([7, 9], $qb->getParameter('rpOcultas')?->getValue());
    }
}
