<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Vaga no limite de escritórios próprios (RN08) com a decisão Q3 da spec perfis-1.2-contas: a conta
 * SUSPENSA (isActive=false, excluidoEm nulo) continua ocupando a vaga; só a excluída libera.
 */
#[CoversClass(TenantRepository::class)]
final class TenantRepositoryContarPorCriadorTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('conta ativas e suspensas; excluídas e de outro criador ficam de fora')]
    public function testContaAtivasESuspensas(): void
    {
        $criador = UserFactory::createOne()->_real();
        $outro   = UserFactory::createOne()->_real();
        $this->escritorio($criador, ativo: true, excluido: false);
        $this->escritorio($criador, ativo: false, excluido: false);
        $this->escritorio($criador, ativo: false, excluido: true);
        $this->escritorio($outro, ativo: true, excluido: false);
        $this->em->flush();

        self::assertSame(2, static::getContainer()->get(TenantRepository::class)->contarPorCriador($criador));
    }

    private function escritorio(User $criador, bool $ativo, bool $excluido): void
    {
        $tenant = (new Tenant())->setName('Vaga ' . uniqid())->setCriadoPor($criador)->setIsActive($ativo);
        if ($excluido) {
            $tenant->setExcluidoEm(new \DateTimeImmutable());
        }
        $this->em->persist($tenant);
    }
}
