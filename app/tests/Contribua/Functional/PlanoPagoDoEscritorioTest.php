<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\PlanoPagoDoEscritorio;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** "Pago" é o plano diferente do Free; sem linha é Free; o limite próprio do Master não conta (DAMA desfaz no fim). */
#[CoversClass(PlanoPagoDoEscritorio::class)]
final class PlanoPagoDoEscritorioTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PlanoPagoDoEscritorio $sut;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->sut = static::getContainer()->get(PlanoPagoDoEscritorio::class);
    }

    private function escritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function planoPago(): Plano
    {
        $plano = new Plano('pago_' . substr(uniqid(), -8), 'Pago de teste', 20, 1990, 'Plano de teste', 99);
        $this->em->persist($plano);

        return $plano;
    }

    #[TestDox('sem linha de espaço é Free; plano Free não é pago; plano pago é pago')]
    public function testFreePagoESemLinha(): void
    {
        $semLinha = $this->escritorio();
        $free = $this->escritorio();
        $pago = $this->escritorio();
        $this->em->persist(new EspacoDoEscritorio($free, static::getContainer()->get(PlanoRepository::class)->free()));
        $this->em->persist(new EspacoDoEscritorio($pago, $this->planoPago()));
        $this->em->flush();

        self::assertFalse($this->sut->temPlanoPago((int) $semLinha->getId()));
        self::assertFalse($this->sut->temPlanoPago((int) $free->getId()));
        self::assertTrue($this->sut->temPlanoPago((int) $pago->getId()));
    }

    #[TestDox('o limite próprio do Master ("sem limite") não faz o escritório Free virar pago')]
    public function testLimiteDoMasterNaoConta(): void
    {
        $tenant = $this->escritorio();
        $espaco = new EspacoDoEscritorio($tenant, static::getContainer()->get(PlanoRepository::class)->free());
        $espaco->definirLimite(null, true, 'teste de limite próprio', null);
        $this->em->persist($espaco);
        $this->em->flush();

        self::assertFalse($this->sut->temPlanoPago((int) $tenant->getId()));
    }

    #[TestDox('semPlanoPago devolve só os ids sem plano pago, na ordem recebida')]
    public function testSemPlanoPagoEmLote(): void
    {
        $a = $this->escritorio();
        $b = $this->escritorio();
        $c = $this->escritorio();
        $this->em->persist(new EspacoDoEscritorio($b, $this->planoPago()));
        $this->em->flush();
        $ids = [(int) $a->getId(), (int) $b->getId(), (int) $c->getId()];

        self::assertSame([$ids[0], $ids[2]], $this->sut->semPlanoPago($ids));
        self::assertSame([], $this->sut->semPlanoPago([]));
    }
}
