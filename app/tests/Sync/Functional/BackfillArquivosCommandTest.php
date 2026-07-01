<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Sync\Command\BackfillArquivosCommand;
use App\Sync\Service\GoogleDriveClientInterface;
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

#[CoversClass(BackfillArquivosCommand::class)]
final class BackfillArquivosCommandTest extends KernelTestCase
{
    use Factories;

    private function criarDoc(EntityManagerInterface $em, Pasta $pasta, Tenant $tenant, string $nomeOriginal): PastaDocumento
    {
        $doc = (new PastaDocumento())
            ->setTitulo($nomeOriginal)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo('x/' . $nomeOriginal)
            ->setNomeOriginal($nomeOriginal)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta)
            ->setTenant($tenant);
        $em->persist($doc);

        return $doc;
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:sync:backfill-arquivos'));
    }

    #[TestDox('casa por nome_original exato, grava drive_file_id e é idempotente')]
    public function testVinculaEIdempotente(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'driveFolderId' => 'FOLDER-1']);

        $em  = self::getContainer()->get(EntityManagerInterface::class);
        $doc = $this->criarDoc($em, $pasta->_real(), $tenant->_real(), 'peca.pdf');
        // Doc do sistema sem par no Drive
        $orfao = $this->criarDoc($em, $pasta->_real(), $tenant->_real(), 'sem_par.pdf');
        $em->flush();
        $docId   = $doc->getId();
        $orfaoId = $orfao->getId();

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('DRV-FILE-9', 'peca.pdf', 'FOLDER-1');
        // Arquivo do Drive sem par no sistema
        $fake->seedArquivo('DRV-ORFAO', 'so_no_drive.pdf', 'FOLDER-1');
        self::getContainer()->set(GoogleDriveClientInterface::class, $fake);

        $tester = $this->tester();
        $tester->execute(['--tenant-id' => (string) $tenant->getId()]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Arquivos do Drive sem par no sistema', $tester->getDisplay());

        $em->clear();
        self::assertSame('DRV-FILE-9', $em->find(PastaDocumento::class, $docId)->getDriveFileId());
        self::assertNull($em->find(PastaDocumento::class, $orfaoId)->getDriveFileId());

        // 2ª rodada: idempotente — não altera e reporta "Já vinculados"
        $tester2 = $this->tester();
        $tester2->execute(['--tenant-id' => (string) $tenant->getId()]);
        $tester2->assertCommandIsSuccessful();
        self::assertStringContainsString('Já vinculados', $tester2->getDisplay());

        $em->clear();
        self::assertSame('DRV-FILE-9', $em->find(PastaDocumento::class, $docId)->getDriveFileId());
    }

    #[TestDox('dry-run não persiste o vínculo')]
    public function testDryRunNaoPersiste(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'driveFolderId' => 'FOLDER-1']);

        $em  = self::getContainer()->get(EntityManagerInterface::class);
        $doc = $this->criarDoc($em, $pasta->_real(), $tenant->_real(), 'peca.pdf');
        $em->flush();
        $docId = $doc->getId();

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('DRV-FILE-9', 'peca.pdf', 'FOLDER-1');
        self::getContainer()->set(GoogleDriveClientInterface::class, $fake);

        $tester = $this->tester();
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        self::assertNull($em->find(PastaDocumento::class, $docId)->getDriveFileId());
    }

    #[TestDox('pasta de outro tenant não é processada')]
    public function testOutroTenantNaoEhProcessado(): void
    {
        self::bootKernel();
        $tenant1 = TenantFactory::createOne();
        $tenant2 = TenantFactory::createOne();
        PastaFactory::createOne(['tenant' => $tenant1, 'driveFolderId' => 'FOLDER-1']);
        $pasta2  = PastaFactory::createOne(['tenant' => $tenant2, 'driveFolderId' => 'FOLDER-2']);

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $doc2 = $this->criarDoc($em, $pasta2->_real(), $tenant2->_real(), 'p2.pdf');
        $em->flush();
        $doc2Id = $doc2->getId();

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('DRV-2', 'p2.pdf', 'FOLDER-2');
        self::getContainer()->set(GoogleDriveClientInterface::class, $fake);

        // Roda para o tenant 1 — o doc do tenant 2 não pode ser tocado
        $tester = $this->tester();
        $tester->execute(['--tenant-id' => (string) $tenant1->getId()]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        self::assertNull($em->find(PastaDocumento::class, $doc2Id)->getDriveFileId());
    }

    #[TestDox('colisão: dois docs de mesmo nome não estouram o UNIQUE (guard)')]
    public function testColisaoDeNomeNaoEstouraUnique(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'driveFolderId' => 'FOLDER-1']);

        // Dois docs com o MESMO nome_original na mesma pasta (não há UNIQUE em (pasta,nome)).
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $docA = $this->criarDoc($em, $pasta->_real(), $tenant->_real(), 'dup.pdf');
        $docB = $this->criarDoc($em, $pasta->_real(), $tenant->_real(), 'dup.pdf');
        $em->flush();
        $aId = $docA->getId();
        $bId = $docB->getId();

        // Um único arquivo no Drive com esse nome → só um drive_file_id disponível.
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('DRV-DUP', 'dup.pdf', 'FOLDER-1');
        self::getContainer()->set(GoogleDriveClientInterface::class, $fake);

        $tester = $this->tester();
        $tester->execute(['--tenant-id' => (string) $tenant->getId()]);
        // Não pode estourar UniqueConstraintViolationException.
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Colisões de nome puladas', $tester->getDisplay());

        // Exatamente um doc vinculado a DRV-DUP, o outro nulo (o guard evitou o 2º UPDATE).
        $em->clear();
        $a = $em->find(PastaDocumento::class, $aId)->getDriveFileId();
        $b = $em->find(PastaDocumento::class, $bId)->getDriveFileId();
        self::assertContains('DRV-DUP', [$a, $b]);
        self::assertContains(null, [$a, $b]);
        self::assertNotSame($a, $b);
    }
}
