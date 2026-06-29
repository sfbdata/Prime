<?php

declare(strict_types=1);

namespace App\Tests\Permission\Functional;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Repository\AccessRequestRepository;
use App\Shared\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida o isolamento das solicitações de acesso por escritório (M2) depois de AccessRequest
 * virar TenantAware. Cobre os dois mecanismos:
 *  - `findPendingByTenant` escopa pelo `ar.tenant` EXPLÍCITO (testado com o filtro DESLIGADO,
 *    pior caso — antes vazava via JOIN no vínculo do solicitante);
 *  - `find()` por id é fechado pelo TenantFilter (IDOR do approve/deny), testado com o filtro
 *    ligado + `em->clear()`.
 */
#[CoversClass(TenantFilter::class)]
#[CoversClass(AccessRequestRepository::class)]
final class AccessRequestIsolamentoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AccessRequestRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(AccessRequestRepository::class);
    }

    #[TestDox('findPendingByTenant retorna só as solicitações feitas no escritório (filtro desligado)')]
    public function testFindPendingByTenantEscopaPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();

        // Mesmo usuário multi-escritório: o vetor que o JOIN antigo vazava para os dois painéis.
        $usuario = $this->criarUser();
        $reqA = $this->criarAccessRequest($usuario, $tenantA, 10);
        $reqB = $this->criarAccessRequest($usuario, $tenantB, 20);

        $pendentesA = $this->repo->findPendingByTenant($tenantA);
        self::assertCount(1, $pendentesA);
        self::assertSame($reqA->getId(), $pendentesA[0]->getId());

        $pendentesB = $this->repo->findPendingByTenant($tenantB);
        self::assertCount(1, $pendentesB);
        self::assertSame($reqB->getId(), $pendentesB[0]->getId());
    }

    #[TestDox('find() por id de solicitação de outro tenant retorna null (fecha o IDOR do approve/deny)')]
    public function testFindPorIdFechaIdor(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $reqB = $this->criarAccessRequest($this->criarUser(), $tenantB, 30);
        $idB = (int) $reqB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull(
            $this->em->find(AccessRequest::class, $idB),
            'IDOR aberto: approve/deny carregam a solicitação por id direto',
        );
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant AR ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('ar_' . uniqid() . '@test.com');
        $user->setFullName('User AR');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarAccessRequest(User $user, Tenant $tenant, int $resourceId): AccessRequest
    {
        $req = (new AccessRequest())
            ->setUser($user)
            ->setTenant($tenant)
            ->setResourceType(AccessRequest::RESOURCE_CLIENTE)
            ->setResourceId($resourceId)
            ->setAction(AccessRequest::ACTION_VIEW);
        $this->em->persist($req);
        $this->em->flush();

        return $req;
    }
}
