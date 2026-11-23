<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Service\TenantBootstrapService;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Ligar o Expediente semeia os marcadores padrão só se o escritório não tem nenhum (spec
 * docs/specs/multiproduto-contratacao-e-voto.md, §9, regra 10). Contra o banco: a contagem é a de verdade.
 */
#[CoversClass(TenantBootstrapService::class)]
final class MarcadoresAoLigarOExpedienteTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function marcadoresDo(Tenant $tenant): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM marcador WHERE tenant_id = ?', [$tenant->getId()]);
    }

    #[TestDox('escritório sem marcador ganha os padrões uma vez só; chamar de novo não duplica')]
    public function testSemeiaUmaVezSo(): void
    {
        $tenant = (new Tenant())->setName('Marcadores ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();
        $dono = UserFactory::createOne(['fullName' => 'Dono dos marcadores'])->_real();
        $bootstrap = static::getContainer()->get(TenantBootstrapService::class);

        $bootstrap->semearMarcadoresSeFaltarem($tenant, $dono);
        $depoisDaPrimeira = $this->marcadoresDo($tenant);
        $bootstrap->semearMarcadoresSeFaltarem($tenant, $dono);

        self::assertSame(13, $depoisDaPrimeira, 'os seis marcadores raiz e os sete filhos');
        self::assertSame(13, $this->marcadoresDo($tenant));
    }

    #[TestDox('escritório que já tem um marcador próprio não ganha os padrões')]
    public function testNaoMexeEmQuemJaTem(): void
    {
        $tenant = (new Tenant())->setName('Marcadores próprios ' . uniqid());
        $this->em->persist($tenant);
        $dono = UserFactory::createOne(['fullName' => 'Dono com marcador'])->_real();
        $this->em->persist(new Marcador('Meu marcador', $tenant, $dono));
        $this->em->flush();

        static::getContainer()->get(TenantBootstrapService::class)->semearMarcadoresSeFaltarem($tenant, $dono);

        self::assertSame(1, $this->marcadoresDo($tenant));
    }
}
