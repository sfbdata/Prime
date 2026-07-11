<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Pasta\Entity\Pasta;
use App\Sync\Command\BackfillPastasCommand;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(BackfillPastasCommand::class)]
final class BackfillPastasCommandTest extends KernelTestCase
{
    use Factories;

    #[TestDox('grava drive_folder_id nas pastas do CSV e é idempotente')]
    public function testBackfillVinculaPastas(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $p1     = PastaFactory::createOne(['tenant' => $tenant]);
        $p2     = PastaFactory::createOne(['tenant' => $tenant]);

        $csv = tempnam(sys_get_temp_dir(), 'map') . '.csv';
        file_put_contents($csv, "\xEF\xBB\xBF"
            . '"drive_id";"drive_nome";"nup";"pasta_id";"pasta_nome_cliente"' . "\r\n"
            . sprintf('"DRV-1";"n";"x";"%d";"c"', $p1->getId()) . "\r\n"
            . sprintf('"DRV-2";"n";"x";"%d";"c"', $p2->getId()) . "\r\n");

        $tester = new CommandTester((new Application(self::$kernel))->find('app:sync:backfill-pastas'));
        $tester->execute(['--csv' => $csv, '--tenant-id' => (string) $tenant->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('DRV-1', $em->find(Pasta::class, $p1->getId())->getDriveFolderId());
        self::assertSame('DRV-2', $em->find(Pasta::class, $p2->getId())->getDriveFolderId());

        // idempotência: 2ª rodada não altera nem falha
        $tester->execute(['--csv' => $csv, '--tenant-id' => (string) $tenant->getId()]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Já vinculadas', $tester->getDisplay());

        unlink($csv);
    }

    #[TestDox('dry-run não persiste o vínculo')]
    public function testDryRunNaoPersiste(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $p1     = PastaFactory::createOne(['tenant' => $tenant]);

        $csv = tempnam(sys_get_temp_dir(), 'map') . '.csv';
        file_put_contents($csv, "\xEF\xBB\xBF"
            . '"drive_id";"drive_nome";"nup";"pasta_id";"pasta_nome_cliente"' . "\r\n"
            . sprintf('"DRV-1";"n";"x";"%d";"c"', $p1->getId()) . "\r\n");

        $tester = new CommandTester((new Application(self::$kernel))->find('app:sync:backfill-pastas'));
        $tester->execute(['--csv' => $csv, '--tenant-id' => (string) $tenant->getId(), '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Pasta::class, $p1->getId())->getDriveFolderId());

        unlink($csv);
    }

    #[TestDox('pasta_id de outro tenant no CSV é ignorada')]
    public function testPastaDeOutroTenantEhIgnorada(): void
    {
        self::bootKernel();
        $tenant1    = TenantFactory::createOne();
        $tenant2    = TenantFactory::createOne();
        $pastaOutro = PastaFactory::createOne(['tenant' => $tenant2]);

        $csv = tempnam(sys_get_temp_dir(), 'map') . '.csv';
        file_put_contents($csv, "\xEF\xBB\xBF"
            . '"drive_id";"drive_nome";"nup";"pasta_id";"pasta_nome_cliente"' . "\r\n"
            . sprintf('"DRV-X";"n";"x";"%d";"c"', $pastaOutro->getId()) . "\r\n");

        // Roda para o tenant 1: a pasta do tenant 2 não pode ser vinculada
        $tester = new CommandTester((new Application(self::$kernel))->find('app:sync:backfill-pastas'));
        $tester->execute(['--csv' => $csv, '--tenant-id' => (string) $tenant1->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Pasta::class, $pastaOutro->getId())->getDriveFolderId());

        unlink($csv);
    }
}
