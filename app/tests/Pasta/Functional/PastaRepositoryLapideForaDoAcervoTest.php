<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A pasta excluída (lápide) mora só na lixeira do escritório desde 18/11/2026 (pedido do Samuel, no
 * lugar das decisões de 28/08 e 31/08 de mantê-la riscada na lista). Aqui se prova contra o Postgres
 * que ela sai da lista do acervo, das contagens e dos marcadores, que são as consultas da tela do
 * Expediente, do CSV, do Minhas Demandas e do agente de pastas.
 */
#[CoversClass(PastaRepository::class)]
final class PastaRepositoryLapideForaDoAcervoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PastaRepository $repo;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaRepository::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Tenant Lapide Acervo ' . uniqid());
        $this->em->persist($this->tenant);

        $this->user = new User();
        $this->user->setEmail('lapide_acervo_' . uniqid() . '@test.com');
        $this->user->setFullName('Quem excluiu');
        $this->user->setRoles(['ROLE_USER']);
        $this->user->setIsActive(true);
        $this->user->setPassword('dummy_hash');
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function criarPasta(string $nup, ?Marcador $marcador = null, bool $excluida = false): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($this->tenant);
        if ($marcador !== null) {
            $pasta->addMarcador($marcador);
        }
        if ($excluida) {
            $pasta->marcarExcluida($this->user, new \DateTimeImmutable());
        }
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    /** @param Pasta[] $pastas @return list<?string> */
    private static function nups(array $pastas): array
    {
        return array_values(array_map(static fn (Pasta $p): ?string => $p->getNup(), $pastas));
    }

    #[TestDox('lista e contagem do acervo deixam a lápide de fora, com ou sem filtro')]
    public function testListaEContagemSemLapide(): void
    {
        $this->criarPasta('2001');
        $this->criarPasta('2002', excluida: true);

        self::assertSame(1, $this->repo->countByFilters([], $this->tenant));
        self::assertSame(['2001'], self::nups($this->repo->findByFilters([], $this->tenant)));
        self::assertSame([], self::nups($this->repo->findByFilters(['busca' => '2002'], $this->tenant)));
    }

    #[TestDox('marcador: a lápide sai da lista, da contagem do marcador e das contagens do trilho')]
    public function testMarcadorSemLapide(): void
    {
        $topo = new Marcador('TOPO ' . uniqid(), $this->tenant, $this->user);
        $filho = new Marcador('FILHO ' . uniqid(), $this->tenant, $this->user, $topo);
        $this->em->persist($topo);
        $this->em->persist($filho);
        $this->em->flush();

        $this->criarPasta('2001', $topo);
        $this->criarPasta('2002', $topo, excluida: true);
        $this->criarPasta('2003', $filho, excluida: true);

        self::assertSame(1, $this->repo->countPorMarcador($topo, $this->tenant));
        self::assertSame(['2001'], self::nups($this->repo->findPorMarcador($topo, $this->tenant)));
        self::assertSame(0, $this->repo->countByFiltrosEMarcador(['busca' => '2002'], $topo, $this->tenant));

        $porMarcador = $this->repo->countPorMarcadores($this->tenant);
        self::assertSame(1, $porMarcador[$topo->getId()] ?? 0);
        self::assertSame(0, $porMarcador[$filho->getId()] ?? 0);
        self::assertSame(1, $this->repo->countPorMarcadoresDeTopoComFilhos($this->tenant)[$topo->getId()] ?? 0);
    }
}
