<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Valida a regra de backfill da migration (grandfather): dono de escritório → confirmada;
 * demais com OAB → nao_verificada; sem OAB → null. O teste roda a MESMA SQL da migration
 * sobre dados semeados, provando a regra independente de quando a migration rodou.
 */
final class UserOabBackfillTest extends KernelTestCase
{
    use Factories;

    private const SQL_DONO = 'UPDATE "user" SET oab_status = \'confirmada\' WHERE id IN (SELECT DISTINCT criado_por_id FROM tenant WHERE criado_por_id IS NOT NULL)';
    private const SQL_COM_OAB = 'UPDATE "user" SET oab_status = \'nao_verificada\' WHERE oab_status IS NULL AND oab_numero IS NOT NULL';

    #[TestDox('Backfill: dono → confirmada; com OAB sem escritório → nao_verificada; sem OAB → null')]
    public function testBackfillGrandfather(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Dono de um escritório (tem OAB) — deve virar confirmada.
        $dono = UserFactory::createOne(['oabNumero' => '111', 'oabUf' => 'SP'])->_real();
        $tenant = new Tenant();
        $tenant->setName('Escritório ' . uniqid());
        $tenant->setCriadoPor($dono);
        $em->persist($tenant);

        // Tem OAB mas não é dono de nada — deve virar nao_verificada.
        $comOab = UserFactory::createOne(['oabNumero' => '222', 'oabUf' => 'RJ'])->_real();

        // Sem OAB — deve ficar null.
        $semOab = UserFactory::createOne(['oabNumero' => null, 'oabUf' => null])->_real();

        $em->flush();

        $donoId   = $dono->getId();
        $comOabId = $comOab->getId();
        $semOabId = $semOab->getId();

        // Zera o status (simula o estado pré-backfill dos recém-criados) e roda a regra.
        $conn = $em->getConnection();
        $conn->executeStatement('UPDATE "user" SET oab_status = NULL WHERE id IN (:ids)', ['ids' => [$donoId, $comOabId, $semOabId]], ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]);
        $conn->executeStatement(self::SQL_DONO);
        $conn->executeStatement(self::SQL_COM_OAB);

        $em->clear();

        self::assertSame(StatusOab::Confirmada, $em->find(User::class, $donoId)->getOabStatus(), 'dono grandfathered');
        self::assertSame(StatusOab::NaoVerificada, $em->find(User::class, $comOabId)->getOabStatus(), 'com OAB sem escritório');
        self::assertNull($em->find(User::class, $semOabId)->getOabStatus(), 'sem OAB');
    }
}
