<?php

declare(strict_types=1);

namespace App\Tests\Sync\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Service\ArquivoStorageInterface;
use App\Sync\Service\ReconciliadorDePasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Sync\Support\FakeGoogleDriveClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Testa o serviço por-pasta diretamente pelo ponto de entrada `sincronizarPasta` — exatamente como
 * o handler da Fase 2 o chamará (uma pasta, um client, uma raiz).
 */
#[CoversClass(ReconciliadorDePasta::class)]
final class ReconciliadorDePastaTest extends KernelTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarDocumento(int $pastaId, string $nomeOriginal): void
    {
        $em          = $this->em();
        $pasta       = $em->find(Pasta::class, $pastaId);
        $storage     = self::getContainer()->get(ArquivoStorageInterface::class);
        $uploadsDir  = (string) self::getContainer()->getParameter('uploads_dir');
        $nomeStorage = $storage->salvarConteudo('conteudo-' . $nomeOriginal, $uploadsDir, 'pdf');

        $doc = (new PastaDocumento())
            ->setTitulo($nomeOriginal)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($nomeStorage)
            ->setNomeOriginal($nomeOriginal)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta)
            ->setTenant($pasta->getTenant());
        $em->persist($doc);
        $em->flush();
    }

    #[TestDox('sincronizarPasta cria o folder no Drive e sobe o documento pendente')]
    public function testSincronizaUmaPastaPontaAPonta(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '777', 'nomeCliente' => 'FULANO']);
        $this->criarDocumento($pasta->getId(), 'peca.pdf');

        $fake = new FakeGoogleDriveClient();
        $reconciliador = self::getContainer()->get(ReconciliadorDePasta::class);

        $resultado = $reconciliador->sincronizarPasta($pasta->getId(), 'RAIZ', $fake);

        // Folder criado sob a raiz e vínculo gravado.
        self::assertSame(1, $resultado->criadasNoDrive);
        self::assertSame(1, $resultado->arquivosEnviados);
        self::assertSame(0, $resultado->erros);
        self::assertFalse($resultado->fatal);

        $em = $this->em();
        $em->clear();
        $folderId = $em->find(Pasta::class, $pasta->getId())->getDriveFolderId();
        self::assertNotNull($folderId);
        self::assertSame('777 - FULANO', $fake->pastas[$folderId]['nome']);
        self::assertSame('RAIZ', $fake->pastas[$folderId]['parent']);

        // Documento subiu para a raiz do caso e ganhou drive_file_id.
        $driveFileId = $em->getConnection()->fetchOne('SELECT drive_file_id FROM pasta_documento WHERE nome_original = :n', ['n' => 'peca.pdf']);
        self::assertNotFalse($driveFileId);
        self::assertNotNull($driveFileId);
        self::assertSame($folderId, $fake->arquivos[$driveFileId]['folder']);
    }

    #[TestDox('sincronizarPasta é idempotente — a 2ª chamada não recria nem re-sobe nada')]
    public function testIdempotente(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'nup' => '888', 'nomeCliente' => 'X']);
        $this->criarDocumento($pasta->getId(), 'doc.pdf');

        $fake = new FakeGoogleDriveClient();
        $reconciliador = self::getContainer()->get(ReconciliadorDePasta::class);

        $reconciliador->sincronizarPasta($pasta->getId(), 'RAIZ', $fake);
        $segundo = $reconciliador->sincronizarPasta($pasta->getId(), 'RAIZ', $fake);

        self::assertSame(0, $segundo->criadasNoDrive, 'folder já existe → não recria');
        self::assertSame(0, $segundo->arquivosEnviados, 'documento já tem drive_file_id → não re-sobe');
        self::assertCount(1, $fake->arquivos, 'nenhum arquivo duplicado no Drive');
    }
}
