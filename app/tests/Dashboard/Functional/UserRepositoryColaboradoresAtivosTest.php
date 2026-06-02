<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(UserRepository::class)]
final class UserRepositoryColaboradoresAtivosTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(UserRepository::class);
    }

    private function criarTenant(string $sufixo = ''): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Colab ' . uniqid() . $sufixo);
        $this->em->persist($tenant);

        return $tenant;
    }

    private function criarUser(string $prefixo = 'user'): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('User ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);

        return $user;
    }

    #[TestDox('Colaborador com isActive=true aparece no resultado')]
    public function testColaboradorAtivoApareceNoResultado(): void
    {
        $tenant = $this->criarTenant();
        $user   = $this->criarUser('ativo');

        $ut = new UserTenant($user, $tenant);
        $this->em->persist($ut);
        $this->em->flush();

        $resultado = $this->repo->findColaboradoresAtivosPorTenant($tenant);

        self::assertCount(1, $resultado);
        self::assertSame($user->getId(), $resultado[0]->getId());
    }

    #[TestDox('Colaborador com isActive=false não aparece no resultado')]
    public function testColaboradorInativoNaoAparece(): void
    {
        $tenant  = $this->criarTenant();
        $ativo   = $this->criarUser('ativo');
        $inativo = $this->criarUser('inativo');

        $ut1 = new UserTenant($ativo, $tenant);
        $this->em->persist($ut1);

        $ut2 = new UserTenant($inativo, $tenant);
        $ut2->demitir();
        $this->em->persist($ut2);

        $this->em->flush();

        $resultado = $this->repo->findColaboradoresAtivosPorTenant($tenant);

        $ids = array_map(static fn (User $u): ?int => $u->getId(), $resultado);

        self::assertContains($ativo->getId(), $ids);
        self::assertNotContains($inativo->getId(), $ids);
    }

    #[TestDox('Colaboradores de outro tenant não aparecem no resultado')]
    public function testIsolamentoMultiTenant(): void
    {
        $tenantA = $this->criarTenant('A');
        $tenantB = $this->criarTenant('B');

        $userA = $this->criarUser('tenantA');
        $userB = $this->criarUser('tenantB');

        $ut1 = new UserTenant($userA, $tenantA);
        $this->em->persist($ut1);

        $ut2 = new UserTenant($userB, $tenantB);
        $this->em->persist($ut2);

        $this->em->flush();

        $resultadoA = $this->repo->findColaboradoresAtivosPorTenant($tenantA);
        $idsA       = array_map(static fn (User $u): ?int => $u->getId(), $resultadoA);

        self::assertContains($userA->getId(), $idsA);
        self::assertNotContains($userB->getId(), $idsA);
    }
}
