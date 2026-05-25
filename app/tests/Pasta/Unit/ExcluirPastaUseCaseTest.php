<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(ExcluirPastaUseCase::class)]
final class ExcluirPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ArquivoStorageInterface&MockObject $storage;
    private ExcluirPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->useCase = new ExcluirPastaUseCase($this->em, $this->storage, '/uploads/pastas');

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
    }

    public function testTenantDivergeLancaAccessDeniedException(): void
    {
        $outraTenant = new Tenant();

        $pasta = $this->criarPasta($this->tenant, []);

        $this->em->expects($this->never())->method('remove');
        $this->storage->expects($this->never())->method('excluir');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($pasta, $this->autor, $outraTenant);
    }

    public function testPastaSemDocumentosRemoveSemChamarStorage(): void
    {
        $pasta = $this->criarPasta($this->tenant, []);

        $this->storage->expects($this->never())->method('excluir');
        $this->em->expects($this->once())->method('remove')->with($pasta);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $this->autor, $this->tenant);
    }

    public function testPastaComDoisDocumentosExistentesExcluiAmbos(): void
    {
        $doc1 = $this->criarDocumento('arquivo1.pdf');
        $doc2 = $this->criarDocumento('arquivo2.pdf');
        $pasta = $this->criarPasta($this->tenant, [$doc1, $doc2]);

        $this->storage->method('caminho')
            ->willReturnCallback(fn (string $dir, string $nome) => $dir . '/' . $nome);

        $this->storage->method('existe')->willReturn(true);

        $this->storage->expects($this->exactly(2))->method('excluir');
        $this->em->expects($this->once())->method('remove')->with($pasta);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $this->autor, $this->tenant);
    }

    public function testDocumentoInexistenteNaoChamaExcluirMasRemoveDosBanco(): void
    {
        $doc = $this->criarDocumento('ausente.pdf');
        $pasta = $this->criarPasta($this->tenant, [$doc]);

        $this->storage->method('caminho')
            ->willReturn('/uploads/pastas/ausente.pdf');

        $this->storage->method('existe')->willReturn(false);

        $this->storage->expects($this->never())->method('excluir');
        $this->em->expects($this->once())->method('remove')->with($pasta);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $this->autor, $this->tenant);
    }

    private function criarPasta(Tenant $tenant, array $documentos): Pasta
    {
        $pasta = $this->createMock(Pasta::class);
        $pasta->method('getTenant')->willReturn($tenant);
        $pasta->method('getDocumentos')->willReturn(new ArrayCollection($documentos));

        return $pasta;
    }

    private function criarDocumento(string $caminhoArquivo): PastaDocumento
    {
        $doc = $this->createMock(PastaDocumento::class);
        $doc->method('getCaminhoArquivo')->willReturn($caminhoArquivo);

        return $doc;
    }
}
