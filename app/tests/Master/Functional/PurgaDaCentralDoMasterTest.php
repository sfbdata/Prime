<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Tenant\Tenant;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A purga do escritório leva o estado e as respostas da Central do Master que são das mensagens dele (spec
 * master-central-de-notificacoes.md, D3), e não toca nas de outro escritório nem nas sem escritório (suporte).
 */
#[CoversClass(PurgarEscritorioUseCase::class)]
final class PurgaDaCentralDoMasterTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
    }

    #[TestDox('Purga apaga estado e respostas do escritório purgado; os de outro escritório e os sem escritório ficam')]
    public function testPurgaApagaSoODoEscritorio(): void
    {
        $master = (int) UserFactory::createOne()->getId();
        $purgado = $this->tenant(ativo: false, excluidoEm: '-400 days');
        $outro = $this->tenant(ativo: true);
        foreach ([[(int) $purgado->getId(), 1], [(int) $outro->getId(), 2], [null, 3]] as [$tenantId, $id]) {
            $this->conn->insert('central_master_estado', ['master_id' => $master, 'origem' => 'sugestao', 'id_origem' => $id, 'tenant_id' => $tenantId, 'estrela' => 'true']);
            $this->conn->insert('central_master_resposta', ['origem' => 'sugestao', 'id_origem' => $id, 'tenant_id' => $tenantId, 'texto' => 'Oi', 'por_id' => $master, 'por_nome' => 'Master', 'em' => '2026-10-21 10:00:00', 'canal' => 'email', 'destino' => 'a@exemplo.com']);
        }

        static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($purgado, dryRun: false);

        foreach (['central_master_estado', 'central_master_resposta'] as $tabela) {
            self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE tenant_id = ?', [$purgado->getId()]), $tabela . ' do purgado');
            self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE tenant_id = ?', [$outro->getId()]), $tabela . ' do outro');
            self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE tenant_id IS NULL AND id_origem = 3'), $tabela . ' sem escritório');
        }
    }

    private function tenant(bool $ativo, ?string $excluidoEm = null): Tenant
    {
        $tenant = (new Tenant())->setName('Tenant ' . uniqid());
        $tenant->setIsActive($ativo);
        if ($excluidoEm !== null) {
            $tenant->setExcluidoEm(new \DateTimeImmutable($excluidoEm));
        }
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }
}
