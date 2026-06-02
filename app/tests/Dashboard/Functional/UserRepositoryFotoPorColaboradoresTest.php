<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\UserProfile;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(UserRepository::class)]
final class UserRepositoryFotoPorColaboradoresTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(UserRepository::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Foto ' . uniqid());
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

    #[TestDox('Colaborador com UserProfile e fotoUrl retorna o filename no mapa')]
    public function testColaboradorComFotoRetornaFilename(): void
    {
        $tenant  = $this->criarTenant();
        $user    = $this->criarUser('comfoto');
        $profile = (new UserProfile($user))->setFotoUrl('foto_colab.jpg');

        $ut = new UserTenant($user, $tenant);
        $this->em->persist($profile);
        $this->em->persist($ut);
        $this->em->flush();

        $resultado = $this->repo->findFotoPorColaboradores($tenant);

        self::assertArrayHasKey($user->getId(), $resultado);
        self::assertSame('foto_colab.jpg', $resultado[$user->getId()]);
    }

    #[TestDox('Colaborador sem UserProfile retorna null no mapa')]
    public function testColaboradorSemUserProfileRetornaNull(): void
    {
        $tenant = $this->criarTenant();
        $user   = $this->criarUser('semfoto');

        $ut = new UserTenant($user, $tenant);
        $this->em->persist($ut);
        $this->em->flush();

        $resultado = $this->repo->findFotoPorColaboradores($tenant);

        self::assertArrayHasKey($user->getId(), $resultado);
        self::assertNull($resultado[$user->getId()]);
    }
}
