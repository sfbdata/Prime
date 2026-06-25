<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Expediente\Entity\Marcador;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Shared\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida que, após Pasta e Marcador (Expediente) virarem TenantAware, o filtro global passa a
 * cobrir find()/findBy dessas entidades — fechando os IDORs por id (peticionar, sincronizar
 * marcadores, mover/reordenar documento) automaticamente — e que o NUP passou a ser único por
 * escritório (não mais global).
 */
#[CoversClass(TenantFilter::class)]
final class PastaExpedienteTenantFilterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('find() por id de Pasta de outro tenant retorna null (fecha o IDOR das rotas por id)')]
    public function testFiltroFechaIdorDaPasta(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $pastaB = $this->criarPasta($tenantB);
        $idB = (int) $pastaB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull($this->em->find(Pasta::class, $idB), 'IDOR aberto em Pasta');
    }

    #[TestDox('find() por id de Marcador de outro tenant retorna null')]
    public function testFiltroFechaIdorDoMarcador(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $marcadorB = $this->criarMarcador($tenantB);
        $idB = (int) $marcadorB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull($this->em->find(Marcador::class, $idB), 'IDOR aberto em Marcador');
    }

    #[TestDox('findBy/findAll de Pasta só retornam o tenant ativo')]
    public function testFiltroIsolaListagemDePastas(): void
    {
        [$tenantA, $tenantB] = [$this->criarTenant(), $this->criarTenant()];
        $this->criarPasta($tenantA);
        $this->criarPasta($tenantA);
        $this->criarPasta($tenantB);

        $repo = $this->em->getRepository(Pasta::class);

        self::assertCount(3, $repo->findBy([]));

        $this->ligarFiltro((int) $tenantA->getId());
        $doA = $repo->findBy([]);
        self::assertCount(2, $doA);
        foreach ($doA as $pasta) {
            self::assertSame($tenantA->getId(), $pasta->getTenant()->getId());
        }
    }

    #[TestDox('O mesmo NUP convive em escritórios distintos e findOneBy é escopado por tenant')]
    public function testNupUnicoPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $nup = 'NUP-0001234-' . uniqid();

        // O mesmo NUP em dois escritórios — só possível com o unique composto (tenant_id, nup).
        $this->criarPastaComNup($tenantA, $nup);
        $this->criarPastaComNup($tenantB, $nup);

        $repo = $this->em->getRepository(Pasta::class);

        // setNup faz mb_strtoupper(trim()); o registro guarda o NUP em maiúsculas.
        $nupArmazenado = mb_strtoupper(trim($nup));

        $this->ligarFiltro((int) $tenantA->getId());
        $achado = $repo->findOneBy(['nup' => $nupArmazenado]);
        self::assertNotNull($achado);
        self::assertSame($tenantA->getId(), $achado->getTenant()->getId(), 'findOneBy(nup) devolveu pasta de outro tenant');
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant PE ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant): Pasta
    {
        return $this->criarPastaComNup($tenant, 'NUP-' . (++$this->seq) . '-' . uniqid());
    }

    private function criarPastaComNup(Tenant $tenant, string $nup): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    private function criarMarcador(Tenant $tenant): Marcador
    {
        $marcador = new Marcador('Marcador ' . (++$this->seq), $tenant, $this->criarUser());
        $this->em->persist($marcador);
        $this->em->flush();

        return $marcador;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('pe_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy');
        $this->em->persist($user);

        return $user;
    }
}
