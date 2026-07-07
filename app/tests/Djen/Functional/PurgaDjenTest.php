<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Tenant\UseCase\PurgarEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regressão: a purga de um escritório em quarentena vencida apaga as publicações e as OABs
 * monitoradas dele (FK a tenant é NO ACTION → sem deleção explícita, o DELETE do tenant falharia).
 * Guarda as entradas de publicacao_djen/oab_monitorada na ORDEM_DELECAO do PurgarEscritorioUseCase.
 */
final class PurgaDjenTest extends KernelTestCase
{
    use CriaFixturesDjenTrait;

    #[Test]
    public function purgarEscritorioApagaPublicacoesEOabsMonitoradas(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $tenant = $this->criarTenant();
        $this->criarOab($tenant, '67228', 'PR');
        $this->criarPublicacao($tenant, '950001', '50636766220224047000');
        $tenantId = (int) $tenant->getId();

        // Torna elegível à purga: inativo + quarentena vencida (carência default = 365 dias).
        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        $em->flush();

        $container->get(PurgarEscritorioUseCase::class)->executar($tenant, false);

        $conn = $em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM publicacao_djen WHERE tenant_id = :t', ['t' => $tenantId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM oab_monitorada WHERE tenant_id = :t', ['t' => $tenantId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = :t', ['t' => $tenantId]));
    }
}
