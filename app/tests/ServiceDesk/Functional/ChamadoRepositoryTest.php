<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Entity\Auth\User;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Tenant;
use App\Repository\ChamadoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Trava o isolamento multi-tenant do dashboard (hotfix): as queries do ChamadoRepository
 * só podem retornar chamados do tenant alvo.
 */
#[CoversClass(ChamadoRepository::class)]
final class ChamadoRepositoryTest extends KernelTestCase
{
    use Factories;

    private ChamadoRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(ChamadoRepository::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('findAllFiltered e contagens só retornam chamados do tenant alvo')]
    public function testQueriesDoDashboardIsolamPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $autorA = $this->criarUsuario();
        $autorB = $this->criarUsuario();

        $this->criarChamado($tenantA, $autorA, Chamado::STATUS_ABERTO);
        $this->criarChamado($tenantA, $autorA, Chamado::STATUS_RESOLVIDO);
        $this->criarChamado($tenantB, $autorB, Chamado::STATUS_ABERTO);

        self::assertCount(2, $this->repo->findAllFiltered($tenantA), 'tenant A enxerga só os 2 dele');
        self::assertCount(1, $this->repo->findAllFiltered($tenantB), 'tenant B enxerga só o 1 dele');

        $countsA = $this->repo->countByStatus($tenantA);
        self::assertSame(1, $countsA[Chamado::STATUS_ABERTO]);
        self::assertSame(1, $countsA[Chamado::STATUS_RESOLVIDO]);

        self::assertCount(1, $this->repo->findAbertosNaoAtribuidos($tenantA));
        self::assertCount(1, $this->repo->findAbertosNaoAtribuidos($tenantB));
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUsuario(): User
    {
        $user = new User();
        $user->setEmail('u_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarChamado(Tenant $tenant, User $solicitante, string $status): Chamado
    {
        $chamado = new Chamado();
        $chamado->setTitulo('Chamado ' . uniqid());
        $chamado->setDescricao('desc');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($solicitante);
        $chamado->setStatus($status);
        $this->em->persist($chamado);
        $this->em->flush();

        return $chamado;
    }
}
