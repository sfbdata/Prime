<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanColunaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O MoverCardUseCase confia no repositório para recusar coluna de outro mural: o unitário dele usa um
 * stub que devolve qualquer coluna. Os dois murais aqui são do MESMO escritório, para o TenantFilter
 * não fazer o trabalho que é do filtro por mural.
 */
#[CoversClass(KanbanColunaRepository::class)]
final class KanbanColunaRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private KanbanColunaRepository $repositorio;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em          = static::getContainer()->get(EntityManagerInterface::class);
        $this->repositorio = static::getContainer()->get(KanbanColunaRepository::class);
    }

    #[TestDox('coluna de outro mural do mesmo escritório não é encontrada pelo mural errado')]
    public function testColunaDeOutroMuralNaoEhEncontrada(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Kanban Coluna ' . uniqid());
        $this->em->persist($tenant);

        $user = new User();
        $user->setEmail('kanban_coluna_' . uniqid() . '@test.com');
        $user->setFullName('Usuario Kanban');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);

        $muralA  = new KanbanBoard('Mural A', $tenant, $user);
        $muralB  = new KanbanBoard('Mural B', $tenant, $user);
        $colunaA = new KanbanColuna('A Fazer', KanbanColuna::TIPO_A_FAZER, 0, $muralA);
        $colunaB = new KanbanColuna('A Fazer', KanbanColuna::TIPO_A_FAZER, 0, $muralB);
        foreach ([$muralA, $muralB, $colunaA, $colunaB] as $entidade) {
            $this->em->persist($entidade);
        }
        $this->em->flush();

        self::assertSame($colunaA, $this->repositorio->findPorBoardEId((int) $colunaA->getId(), $muralA));
        self::assertNull(
            $this->repositorio->findPorBoardEId((int) $colunaB->getId(), $muralA),
            'a coluna do mural B foi aceita como destino no mural A',
        );
    }
}
