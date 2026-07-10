<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Shared\Service\ArquivoStorageInterface;
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

    // --- Via de ARQUIVOS (Fase 1 — motor de arquivos) ---

    #[TestDox('documento sem vínculo (sem seção) sobe para a raiz da pasta no Drive')]
    public function testEnviaDocumentoSemSecaoParaRaizDoDrive(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '500', 'nomeCliente' => 'A', 'driveFolderId' => 'CASE-1']);
        $this->criarDocumento($pasta->getId(), null, 'peticao.pdf');

        $fake   = new FakeGoogleDriveClient();
        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        // Arquivo criado no Drive, na raiz do caso, com o nome original.
        $encontrado = array_filter($fake->arquivos, static fn (array $a): bool => $a['folder'] === 'CASE-1' && $a['nome'] === 'peticao.pdf');
        self::assertCount(1, $encontrado);

        // drive_file_id gravado no doc.
        $fileId = (string) array_key_first($encontrado);
        $em = $this->em();
        $em->clear();
        $driveFileId = $em->getConnection()->fetchOne('SELECT drive_file_id FROM pasta_documento WHERE nome_original = :n', ['n' => 'peticao.pdf']);
        self::assertSame($fileId, $driveFileId);
    }

    #[TestDox('documento de uma seção cria subpasta-espelho no Drive e sobe o arquivo nela')]
    public function testEnviaDocumentoDeSecaoParaSubpastaEspelho(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '501', 'nomeCliente' => 'B', 'driveFolderId' => 'CASE-2']);
        $secao  = $this->criarSecao($pasta->getId(), 'PETICOES');
        $this->criarDocumento($pasta->getId(), $secao, 'inicial.pdf');

        $fake   = new FakeGoogleDriveClient();
        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        // Subpasta-espelho 'PETICOES' criada sob a pasta de caso.
        $subs = $fake->listarSubpastas('CASE-2');
        self::assertCount(1, $subs);
        self::assertSame('PETICOES', $subs[0]['nome']);

        // Arquivo foi para dentro da subpasta-espelho.
        $arquivosNaSecao = $fake->listarArquivos($subs[0]['id']);
        self::assertCount(1, $arquivosNaSecao);
        self::assertSame('inicial.pdf', $arquivosNaSecao[0]['nome']);
    }

    #[TestDox('arquivo novo na raiz do Drive vira PastaDocumento sem seção')]
    public function testBaixaArquivoDaRaizCriaDocumentoSemSecao(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '502', 'nomeCliente' => 'C', 'driveFolderId' => 'CASE-3']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-NOVO', 'sentenca.pdf', 'CASE-3');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = $this->em();
        $em->clear();
        $row = $em->getConnection()->fetchAssociative('SELECT pasta_id, secao_id, drive_file_id, nome_original FROM pasta_documento WHERE drive_file_id = :id', ['id' => 'F-NOVO']);
        self::assertNotFalse($row);
        self::assertSame($pasta->getId(), (int) $row['pasta_id']);
        self::assertNull($row['secao_id']);
        self::assertSame('sentenca.pdf', $row['nome_original']);
    }

    #[TestDox('arquivo em subpasta do Drive cria a seção e vincula o documento a ela')]
    public function testBaixaArquivoDeSubpastaCriaSecao(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '503', 'nomeCliente' => 'D', 'driveFolderId' => 'CASE-4']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('SUB-CONTRATOS', 'CONTRATOS', 'CASE-4');
        $fake->seedArquivo('F-C1', 'contrato.pdf', 'SUB-CONTRATOS');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = $this->em();
        $em->clear();
        $secaoNome = $em->getConnection()->fetchOne(
            'SELECT s.nome FROM pasta_documento d JOIN pasta_secao s ON s.id = d.secao_id WHERE d.drive_file_id = :id',
            ['id' => 'F-C1'],
        );
        self::assertSame('CONTRATOS', $secaoNome);
    }

    #[TestDox('arquivo em sub-subpasta é achatado para a seção-avó (§11.6)')]
    public function testAchataSubSubpastaParaSecaoAvo(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '504', 'nomeCliente' => 'E', 'driveFolderId' => 'CASE-5']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('SUB-DOCS', 'DOCS', 'CASE-5');
        $fake->seedPasta('SUB-ANEXOS', 'ANEXOS', 'SUB-DOCS'); // sub-subpasta
        $fake->seedArquivo('F-A1', 'anexo.pdf', 'SUB-ANEXOS');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        $em = $this->em();
        $em->clear();
        // Documento cai na seção-avó DOCS, não em ANEXOS.
        $secaoNome = $em->getConnection()->fetchOne(
            'SELECT s.nome FROM pasta_documento d JOIN pasta_secao s ON s.id = d.secao_id WHERE d.drive_file_id = :id',
            ['id' => 'F-A1'],
        );
        self::assertSame('DOCS', $secaoNome);
        // Só uma seção criada (DOCS); ANEXOS não vira seção.
        $totalSecoes = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_secao WHERE pasta_id = :p', ['p' => $pasta->getId()]);
        self::assertSame(1, $totalSecoes);
    }

    #[TestDox('arquivo do Drive já vinculado não é rebaixado nem duplicado')]
    public function testNaoDuplicaArquivoJaVinculado(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '505', 'nomeCliente' => 'F', 'driveFolderId' => 'CASE-6']);
        $this->criarDocumento($pasta->getId(), null, 'ja.pdf', 'F-JA');

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-JA', 'ja.pdf', 'CASE-6');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        self::assertSame(1, $this->contarDocumentos($pasta->getId()));
        // Não subiu cópia nova pro Drive (só o que foi semeado).
        self::assertCount(1, $fake->arquivos);
    }

    #[TestDox('arquivo Google-native é pulado (não baixa nem cria documento)')]
    public function testPulaArquivoGoogleNative(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '506', 'nomeCliente' => 'G', 'driveFolderId' => 'CASE-7']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-GDOC', 'planilha', 'CASE-7', 0, 'application/vnd.google-apps.spreadsheet');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        self::assertSame(0, $this->contarDocumentos($pasta->getId()));
    }

    #[TestDox('dry-run de arquivos não sobe, não baixa e não persiste')]
    public function testDryRunArquivosNaoMuta(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '507', 'nomeCliente' => 'H', 'driveFolderId' => 'CASE-8']);
        $this->criarDocumento($pasta->getId(), null, 'suben.pdf'); // sem vínculo → subiria

        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-DOWN', 'baixaria.pdf', 'CASE-8'); // sem par → baixaria

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId(), '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        // Drive intocado: só o arquivo semeado (nada enviado).
        self::assertCount(1, $fake->arquivos);

        // Sistema intocado: doc sem vínculo continua sem vínculo; nada baixado.
        $em = $this->em();
        $em->clear();
        self::assertSame(1, $this->contarDocumentos($pasta->getId()));
        $driveFileId = $em->getConnection()->fetchOne('SELECT drive_file_id FROM pasta_documento WHERE nome_original = :n', ['n' => 'suben.pdf']);
        self::assertNull($driveFileId);
        self::assertFalse($em->getConnection()->fetchOne('SELECT id FROM pasta_documento WHERE drive_file_id = :id', ['id' => 'F-DOWN']));
    }

    #[TestDox('upload e download na mesma rodada não duplicam o arquivo recém-enviado')]
    public function testRoundTripNoMesmoRunNaoDuplica(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '508', 'nomeCliente' => 'I', 'driveFolderId' => 'CASE-9']);
        $secao  = $this->criarSecao($pasta->getId(), 'X');
        $this->criarDocumento($pasta->getId(), $secao, 'unico.pdf');

        $fake   = new FakeGoogleDriveClient();
        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        // Continua havendo exatamente 1 documento, agora vinculado.
        self::assertSame(1, $this->contarDocumentos($pasta->getId()));
        $em = $this->em();
        $em->clear();
        $driveFileId = $em->getConnection()->fetchOne('SELECT drive_file_id FROM pasta_documento WHERE nome_original = :n', ['n' => 'unico.pdf']);
        self::assertNotFalse($driveFileId);
        self::assertNotNull($driveFileId);
        // E só uma seção X (o download não duplicou a seção espelhada).
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_secao WHERE pasta_id = :p', ['p' => $pasta->getId()]));
    }

    #[TestDox('arquivo com nome > 255 chars é pulado e reportado (não envenena o insert)')]
    public function testIgnoraArquivoComNomeMuitoLongo(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '509', 'nomeCliente' => 'J', 'driveFolderId' => 'CASE-10']);

        $nomeEnorme = str_repeat('a', 300) . '.pdf';
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-LONGO', $nomeEnorme, 'CASE-10');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('255', $tester->getDisplay());
        self::assertSame(0, $this->contarDocumentos($pasta->getId()));
    }

    #[TestDox('subpastas irmãs que colidem em UPPER têm todos os arquivos importados na mesma seção (M1)')]
    public function testImportaSubpastasIrmasQueColidememUpper(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '510', 'nomeCliente' => 'K', 'driveFolderId' => 'CASE-11']);

        // "Docs" e "DOCS" sobem para o mesmo UPPER: nenhuma pode ser silenciosamente perdida.
        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('SUB-DOCS-MIN', 'Docs', 'CASE-11');
        $fake->seedPasta('SUB-DOCS-MAI', 'DOCS', 'CASE-11');
        $fake->seedArquivo('F-D1', 'a.pdf', 'SUB-DOCS-MIN');
        $fake->seedArquivo('F-D2', 'b.pdf', 'SUB-DOCS-MAI');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        // Os dois arquivos importados; uma única seção DOCS.
        self::assertSame(2, $this->contarDocumentos($pasta->getId()));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_secao WHERE pasta_id = :p', ['p' => $pasta->getId()]));
    }

    #[TestDox('subpasta só com Google-native não cria seção fantasma')]
    public function testSubpastaSoComGoogleNativeNaoCriaSecaoFantasma(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '511', 'nomeCliente' => 'L', 'driveFolderId' => 'CASE-12']);

        $fake = new FakeGoogleDriveClient();
        $fake->seedPasta('SUB-RASCUNHOS', 'RASCUNHOS', 'CASE-12');
        $fake->seedArquivo('F-GDOC2', 'rascunho', 'SUB-RASCUNHOS', 0, 'application/vnd.google-apps.document');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        self::assertSame(0, $this->contarDocumentos($pasta->getId()));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_secao WHERE pasta_id = :p', ['p' => $pasta->getId()]));
    }

    #[TestDox('upload sem seção e a varredura da raiz na mesma rodada não duplicam o arquivo')]
    public function testRoundTripNaRaizNoMesmoRunNaoDuplica(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '514', 'nomeCliente' => 'O', 'driveFolderId' => 'CASE-13']);
        $this->criarDocumento($pasta->getId(), null, 'raiz.pdf'); // sem seção → sobe para a raiz do caso

        $fake   = new FakeGoogleDriveClient();
        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        // A Via B varre a raiz (onde o arquivo acabou de subir); o id recém-enviado está em $conhecidos → não duplica.
        self::assertSame(1, $this->contarDocumentos($pasta->getId()));
        $driveFileId = $this->em()->getConnection()->fetchOne('SELECT drive_file_id FROM pasta_documento WHERE nome_original = :n', ['n' => 'raiz.pdf']);
        self::assertNotFalse($driveFileId);
        self::assertNotNull($driveFileId);
    }

    #[TestDox('arquivo já vinculado a OUTRA pasta é pulado (guard do UNIQUE global, M2)')]
    public function testNaoReutilizaDriveFileIdDeOutraPasta(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();
        $pastaA = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '512', 'nomeCliente' => 'M', 'driveFolderId' => 'CASE-A']);
        $pastaB = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '513', 'nomeCliente' => 'N', 'driveFolderId' => 'CASE-B']);
        $this->criarDocumento($pastaA->getId(), null, 'compartilhado.pdf', 'F-SHARED');

        // O mesmo drive_file_id aparece na pasta B (arquivo multi-parent no Drive).
        $fake = new FakeGoogleDriveClient();
        $fake->seedArquivo('F-SHARED', 'compartilhado.pdf', 'CASE-B');

        $tester = $this->tester($fake);
        $tester->execute(['--tenant-id' => (string) $tenant->getId(), '--usuario-id' => (string) $user->getId()]);
        $tester->assertCommandIsSuccessful();

        // Pasta B não recria o doc (evita violar o UNIQUE global); pasta A mantém o seu.
        self::assertSame(0, $this->contarDocumentos($pastaB->getId()));
        self::assertSame(1, $this->contarDocumentos($pastaA->getId()));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarSecao(int $pastaId, string $nome): PastaSecao
    {
        $em    = $this->em();
        $pasta = $em->find(Pasta::class, $pastaId);
        $secao = (new PastaSecao())
            ->setNome($nome)
            ->setPasta($pasta)
            ->setTenant($pasta->getTenant())
            ->setOrdem(0);
        $em->persist($secao);
        $em->flush();

        return $secao;
    }

    private function criarDocumento(int $pastaId, ?PastaSecao $secao, string $nomeOriginal, ?string $driveFileId = null): void
    {
        $em         = $this->em();
        $pasta      = $em->find(Pasta::class, $pastaId);
        $storage    = self::getContainer()->get(ArquivoStorageInterface::class);
        $uploadsDir = (string) self::getContainer()->getParameter('uploads_dir');
        $nomeStorage = $storage->salvarConteudo('conteudo-' . $nomeOriginal, $uploadsDir, 'pdf');

        $doc = (new PastaDocumento())
            ->setTitulo($nomeOriginal)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($nomeStorage)
            ->setNomeOriginal($nomeOriginal)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta)
            ->setSecao($secao)
            ->setTenant($pasta->getTenant());
        if ($driveFileId !== null) {
            $doc->setDriveFileId($driveFileId);
        }
        $em->persist($doc);
        $em->flush();
    }

    private function contarDocumentos(int $pastaId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = :p',
            ['p' => $pastaId],
        );
    }
}
