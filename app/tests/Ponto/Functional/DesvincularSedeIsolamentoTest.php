<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `desvincularSede` é um UPDATE em massa (DQL) que NÃO aplica o TenantFilter — o escopo de tenant
 * é manual (`r.tenant = :sede.tenant`). Este teste prova que esse guard protege uma batida de OUTRO
 * tenant que aponte para a mesma sede (vetor que o filtro de `r.sede` sozinho não fecharia).
 */
#[CoversClass(RegistroPontoRepository::class)]
final class DesvincularSedeIsolamentoTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('desvincularSede não toca batidas de outro tenant, mesmo apontando para a mesma sede')]
    public function testDesvincularSedeNaoCruzaTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $sedeA   = $this->criarSede($tenantA);
        $sedeB   = $this->criarSede($tenantB);
        $user    = $this->criarUser();

        $batidaA     = $this->criarBatida($user, $tenantA, $sedeA); // tenant A, sede A → desvincula
        $batidaB     = $this->criarBatida($user, $tenantB, $sedeB); // tenant B, sede B → intacta (sede não bate)
        $batidaCross = $this->criarBatida($user, $tenantB, $sedeA); // tenant B, sede A → intacta (guard de tenant)

        $idA = (int) $batidaA->getId();
        $idB = (int) $batidaB->getId();
        $idCross = (int) $batidaCross->getId();

        $repo = static::getContainer()->get(RegistroPontoRepository::class);
        $afetadas = $repo->desvincularSede($sedeA);

        self::assertSame(1, $afetadas, 'apenas a batida do tenant da sede deveria ser desvinculada');

        $this->em->clear();
        self::assertNull($this->em->find(RegistroPonto::class, $idA)->getSede(), 'batida do tenant A em sedeA deveria ser desvinculada');
        self::assertNotNull($this->em->find(RegistroPonto::class, $idB)->getSede(), 'batida do tenant B em sedeB não deveria ser tocada');
        self::assertNotNull(
            $this->em->find(RegistroPonto::class, $idCross)->getSede(),
            'batida do tenant B apontando p/ sedeA deveria ser protegida pelo guard de tenant',
        );
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant SEDE ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarSede(Tenant $tenant): Sede
    {
        $sede = new Sede();
        $sede->setNome('Sede ' . uniqid());
        $sede->setLatitude('-23.5');
        $sede->setLongitude('-46.6');
        $sede->setTenant($tenant);
        $this->em->persist($sede);
        $this->em->flush();

        return $sede;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('sede_' . uniqid() . '@test.com');
        $user->setFullName('User Sede');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarBatida(User $user, Tenant $tenant, Sede $sede): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setSede($sede);
        $registro->setDataHora(new \DateTime('2026-03-10 08:00:00'));
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $this->em->persist($registro);
        $this->em->flush();

        return $registro;
    }
}
