<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Pasta\Entity\Pasta;
use App\Sync\Command\ReconciliarCommand;
use App\Sync\Service\GoogleDriveClientInterface;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(ReconciliarCommand::class)]
final class ReconciliarCommandTest extends KernelTestCase
{
    use Factories;

    private const ROOT = 'ROOT'; // == GOOGLE_DRIVE_SHARED_DRIVE_ID no .env.test (commitado)

    private function tester(FakeGoogleDriveClient $fake): CommandTester
    {
        self::getContainer()->set(GoogleDriveClientInterface::class, $fake);

        return new CommandTester((new Application(self::$kernel))->find('app:sync:reconciliar'));
    }

    #[TestDox('pasta do sistema sem vínculo ganha pasta no Drive e grava o drive_folder_id')]
    public function testCriaPastaNoDriveParaPastaSemVinculo(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '777', 'nomeCliente' => 'FULANO']);

        $fake   = new FakeGoogleDriveClient();
        $tester = $this->tester($fake);
        $tester->execute([
            '--tenant-id'  => (string) $tenant->getId(),
            '--usuario-id' => (string) $user->getId(),
        ]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $folderId = $em->find(Pasta::class, $pasta->getId())->getDriveFolderId();
        self::assertNotNull($folderId);
        self::assertSame('777 - FULANO', $fake->pastas[$folderId]['nome']);
        self::assertSame(self::ROOT, $fake->pastas[$folderId]['parent']);
    }

    #[TestDox('subpasta do Drive sem par cria Pasta no sistema com o NUP extraído')]
    public function testCriaPastaNoSistemaParaSubpastaDoDrive(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-NEW', '456 - CLIENTE NOVO', self::ROOT);

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pasta = $em->getRepository(Pasta::class)->findOneBy(['driveFolderId' => 'DRV-NEW']);
        self::assertNotNull($pasta);
        self::assertSame('456', $pasta->getNup());
        self::assertSame($tenant->getId(), $pasta->getTenant()->getId());
    }

    #[TestDox('subpasta do Drive com NUP alfanumérico (10A) cria Pasta e grava o vínculo')]
    public function testCriaPastaParaSubpastaComNupAlfanumerico(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();

        // Desambiguação de número repetido: o Drive passa a ter "10A"/"10B" (letra maiúscula).
        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-10A', '10A - FULANO DE TAL', self::ROOT);

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pasta = $em->getRepository(Pasta::class)->findOneBy(['driveFolderId' => 'DRV-10A']);
        self::assertNotNull($pasta, 'Pasta com NUP alfanumérico (10A) deveria ter sido criada');
        self::assertSame('10A', $pasta->getNup());
    }

    #[TestDox('subpasta do Drive sem NUP numérico é pulada e reportada (D9)')]
    public function testSubpastaSemNupEhPuladaEReportada(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-X', 'PASTA SEM NUMERO', self::ROOT);

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('sem NUP', $tester->getDisplay());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(Pasta::class)->findOneBy(['driveFolderId' => 'DRV-X']));
    }

    #[TestDox('subpasta já vinculada não cria pasta duplicada')]
    public function testSubpastaJaVinculadaNaoDuplica(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        PastaFactory::createOne(['tenant' => $tenant, 'nup' => '123', 'nomeCliente' => 'X', 'driveFolderId' => 'DRV-9']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-9', '123 - X', self::ROOT);

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->getRepository(Pasta::class)->findBy(['driveFolderId' => 'DRV-9']));
    }

    #[TestDox('divergência de nome entre os lados só reporta, não renomeia (D10)')]
    public function testDivergenciaDeNomeSoReporta(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        PastaFactory::createOne(['tenant' => $tenant, 'nup' => '123', 'nomeCliente' => 'X', 'driveFolderId' => 'DRV-9']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-9', '123 - Y', self::ROOT); // diverge de '123 - X' no sistema

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('divergência', $tester->getDisplay());

        // Não renomeia: o Drive permanece intocado.
        self::assertSame('123 - Y', $fake->pastas['DRV-9']['nome']);
    }

    #[TestDox('dry-run não muta o Drive nem persiste no sistema')]
    public function testDryRunNaoMutaNada(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '111', 'nomeCliente' => 'A']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('DRV-NEW', '222 - CLIENTE', self::ROOT);

        $tester = $this->tester($fake);
        $tester->execute([
            '--tenant-id'  => (string) $tenant->getId(),
            '--usuario-id' => (string) $user->getId(),
            '--dry-run'    => true,
        ]);
        $tester->assertCommandIsSuccessful();

        // Drive intocado: nenhuma pasta criada no fake além da semeada.
        self::assertCount(1, $fake->pastas);

        // Sistema intocado: transação revertida.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(Pasta::class, $pasta->getId())->getDriveFolderId());
        self::assertNull($em->getRepository(Pasta::class)->findOneBy(['driveFolderId' => 'DRV-NEW']));
    }

    #[TestDox('--limit limita a via sistema→Drive (amostra)')]
    public function testLimitLimitaSistemaParaDrive(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $p1 = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '111', 'nomeCliente' => 'A']);
        $p2 = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '222', 'nomeCliente' => 'B']);

        $fake   = new FakeGoogleDriveClient();
        $tester = $this->tester($fake);
        $tester->execute([
            '--tenant-id'  => (string) $tenant->getId(),
            '--usuario-id' => (string) $user->getId(),
            '--limit'      => '1',
        ]);
        $tester->assertCommandIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $comVinculo = array_filter([
            $em->find(Pasta::class, $p1->getId())->getDriveFolderId(),
            $em->find(Pasta::class, $p2->getId())->getDriveFolderId(),
        ], static fn (?string $v): bool => $v !== null);
        self::assertCount(1, $comVinculo); // só uma das duas foi processada
    }
}
