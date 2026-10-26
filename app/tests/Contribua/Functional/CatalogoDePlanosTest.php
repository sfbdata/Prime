<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\CatalogoDePlanos;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(CatalogoDePlanos::class)]
#[CoversClass(PlanoRepository::class)]
final class CatalogoDePlanosTest extends KernelTestCase
{
    #[TestDox('Carga da C1 (decisão do dono, D5): preços do 08 e usuários do 05')]
    public function testCargaDosPlanos(): void
    {
        self::bootKernel();
        $catalogo = static::getContainer()->get(PlanoRepository::class)->findBy([], ['ordem' => 'ASC']);

        self::assertSame(
            [['free', 10, 0, 3], ['essencial', 20, 1990, 8], ['profissional', 50, 3990, 15], ['avancado', 100, 6990, null]],
            array_map(static fn ($p): array => [$p->getCodigo(), $p->getCotaGb(), $p->getPrecoCentavos(), $p->getLimiteUsuarios()], $catalogo),
        );
    }

    #[TestDox('Escritórios por plano: sem linha conta no Free; com linha, no plano dela')]
    public function testEscritoriosPorPlano(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $repositorio = $c->get(PlanoRepository::class);
        $antes = $repositorio->escritoriosPorPlano();

        $novoNoFree = (new Tenant())->setName('Catálogo Free ' . uniqid());
        $noEssencial = (new Tenant())->setName('Catálogo Essencial ' . uniqid());
        $em->persist($novoNoFree);
        $em->persist($noEssencial);
        $em->persist(new EspacoDoEscritorio($noEssencial, $repositorio->findOneBy(['codigo' => 'essencial'])));
        $em->flush();

        $depois = $repositorio->escritoriosPorPlano();
        self::assertSame(($antes['free'] ?? 0) + 1, $depois['free']);
        self::assertSame(($antes['essencial'] ?? 0) + 1, $depois['essencial']);
    }
}
