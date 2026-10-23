<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Integration;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tenant\Repository\EquipeDoEscritorioRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(EquipeDoEscritorioRepository::class)]
final class EquipeDoEscritorioRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('conta só vínculos ativos, agrupa por escritório e ignora escritório não pedido')]
    public function testContaAtivosPorEscritorio(): void
    {
        $a = $this->escritorio('A');
        $b = $this->escritorio('B');
        $c = $this->escritorio('C');
        $this->vinculo($a, true);
        $this->vinculo($a, true);
        $this->vinculo($a, false);
        $this->vinculo($b, true);
        $this->vinculo($c, true);
        $this->em->flush();

        $contagem = static::getContainer()->get(EquipeDoEscritorioRepository::class)
            ->contarAtivosPorTenant([(int) $a->getId(), (int) $b->getId()]);

        ksort($contagem);
        self::assertSame([(int) $a->getId() => 2, (int) $b->getId() => 1], $contagem);
        self::assertArrayNotHasKey((int) $c->getId(), $contagem);
    }

    #[TestDox('sem escritórios pedidos, devolve vazio sem consultar')]
    public function testListaVazia(): void
    {
        self::assertSame([], static::getContainer()->get(EquipeDoEscritorioRepository::class)->contarAtivosPorTenant([]));
    }

    private function escritorio(string $nome): Tenant
    {
        $t = new Tenant();
        $t->setName('Equipe ' . $nome . ' ' . uniqid());
        $this->em->persist($t);

        return $t;
    }

    private function vinculo(Tenant $tenant, bool $ativo): void
    {
        $u = new User();
        $u->setEmail(uniqid('equipe_') . '@test.com');
        $u->setFullName('Pessoa ' . uniqid());
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em->persist($u);

        $v = new UserTenant($u, $tenant);
        if (!$ativo) {
            // UserTenant não expõe desativação (só reativar()): o teste grava o estado direto.
            (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($v, false);
        }
        $this->em->persist($v);
    }
}
