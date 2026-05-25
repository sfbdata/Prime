<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\MoverDocumentoParaSecaoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(MoverDocumentoParaSecaoUseCase::class)]
final class MoverDocumentoParaSecaoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MoverDocumentoParaSecaoUseCase $useCase;
    private Tenant $tenant;
    private User $autor;
    private Pasta $pasta;
    private PastaDocumento $documento;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new MoverDocumentoParaSecaoUseCase($this->em);

        $this->tenant   = new Tenant();
        $this->autor    = (new User())->setEmail('autor@test.com');
        $this->pasta    = new Pasta();

        $this->documento = new PastaDocumento();
        $this->documento->setPasta($this->pasta);
        $this->documento->setCategoria('DEMAIS');
        $this->documento->setTitulo('Documento');
        $this->documento->setCaminhoArquivo('arquivo.pdf');
        $this->documento->setNomeOriginal('arquivo.pdf');
        $this->documento->setMimeType('application/pdf');
        $this->documento->setTamanhoBytes(1024);
    }

    public function testMoverParaSecaoAtualizaRelacao(): void
    {
        $secao = new PastaSecao();
        $secao->setTenant($this->tenant);
        $secao->setPasta($this->pasta);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->documento, $secao, $this->autor, $this->tenant);

        self::assertSame($secao, $this->documento->getSecao());
    }

    public function testMoverParaCardGeralDefineSecaoNula(): void
    {
        $secao = new PastaSecao();
        $secao->setTenant($this->tenant);
        $secao->setPasta($this->pasta);
        $this->documento->setSecao($secao);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->documento, null, $this->autor, $this->tenant);

        self::assertNull($this->documento->getSecao());
    }

    public function testSecaoDeOutraTenantLancaAccessDeniedException(): void
    {
        $outraTenant = new Tenant();
        $secaoOutraTenant = new PastaSecao();
        $secaoOutraTenant->setTenant($outraTenant);
        $secaoOutraTenant->setPasta($this->pasta);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->documento, $secaoOutraTenant, $this->autor, $this->tenant);
    }

    public function testSecaoDeOutraPastaLancaInvalidArgumentException(): void
    {
        $outraPasta = new Pasta();
        $secaoOutraPasta = new PastaSecao();
        $secaoOutraPasta->setTenant($this->tenant);
        $secaoOutraPasta->setPasta($outraPasta);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->documento, $secaoOutraPasta, $this->autor, $this->tenant);
    }
}
