<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Entity\Auth\User;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PastaRepository::class)]
final class PastaPorNupDoEscritorioTest extends KernelTestCase
{
    #[TestDox('Acha a pasta pelo número só no próprio escritório e fora da lixeira')]
    public function testFindDoEscritorioPorNup(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $nup = 'NUP-AG-' . uniqid();
        $daA = PastaFactory::createOne(['tenant' => $tenantA, 'nup' => $nup])->_real();
        $naLixeira = PastaFactory::createOne(['tenant' => $tenantA, 'nup' => $nup . '-L'])->_real();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $quem = $em->getRepository(User::class)->findOneBy([]) ?? $this->criarUsuario($em);
        $naLixeira->marcarExcluida($quem, new \DateTimeImmutable());
        $em->flush();
        $repo = static::getContainer()->get(PastaRepository::class);

        self::assertSame($daA->getId(), $repo->findDoEscritorioPorNup($nup, $tenantA)?->getId());
        self::assertSame($daA->getId(), $repo->findDoEscritorioPorNup(' ' . strtolower($nup) . ' ', $tenantA)?->getId(), 'digitado em minúsculas');
        self::assertNull($repo->findDoEscritorioPorNup($nup, $tenantB), 'pasta de outro escritório');
        self::assertNull($repo->findDoEscritorioPorNup($nup . '-L', $tenantA), 'pasta na lixeira');
    }

    private function criarUsuario(EntityManagerInterface $em): User
    {
        $u = new User();
        $u->setEmail('nup_' . uniqid() . '@test.com');
        $u->setFullName('Usuário NUP');
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $em->persist($u);

        return $u;
    }
}
