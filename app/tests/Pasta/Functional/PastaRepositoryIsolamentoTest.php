<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PastaRepository::class)]
final class PastaRepositoryIsolamentoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PastaRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaRepository::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ISO ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarPasta(Tenant $tenant, string $prefixo = 'ISO'): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($prefixo . '-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);

        return $pasta;
    }

    #[TestDox('findByFilters retorna pastas do tenant A e não vaza pastas do tenant B')]
    public function testFindByFiltersIsolaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $pastaA  = $this->criarPasta($tenantA, 'ISO-A');
        $pastaB  = $this->criarPasta($tenantB, 'ISO-B');
        $this->em->flush();

        $resultado = $this->repo->findByFilters([], $tenantA);
        $nups      = array_map(fn(Pasta $p) => $p->getNup(), $resultado);

        self::assertContains($pastaA->getNup(), $nups);
        self::assertNotContains($pastaB->getNup(), $nups);
    }

    #[TestDox('findAllNups retorna NUPs do tenant A e não vaza NUPs do tenant B')]
    public function testFindAllNupsIsolaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $pastaA  = $this->criarPasta($tenantA, 'NUPS-A');
        $pastaB  = $this->criarPasta($tenantB, 'NUPS-B');
        $this->em->flush();

        $nups = $this->repo->findAllNups($tenantA);

        self::assertContains($pastaA->getNup(), $nups);
        self::assertNotContains($pastaB->getNup(), $nups);
    }
}
