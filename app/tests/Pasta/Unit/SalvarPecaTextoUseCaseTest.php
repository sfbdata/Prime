<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\SalvarPecaTextoUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(SalvarPecaTextoUseCase::class)]
final class SalvarPecaTextoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ArquivoStorageInterface&MockObject $storage;
    private SalvarPecaTextoUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->useCase = new SalvarPecaTextoUseCase($this->em, $this->storage, '/uploads/pastas');
        $this->pasta   = new Pasta();
        $this->tenant  = new Tenant();
    }

    #[TestDox('Salvar peça texto válida retorna PastaDocumento com mimeType text/html')]
    public function testSalvarTextoValidoRetornaPastaDocumento(): void
    {
        $this->storage->expects($this->once())
            ->method('salvarConteudo')
            ->with('<p>Conteúdo</p>', '/uploads/pastas', 'html')
            ->willReturn('abc123.html');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, '<p>Conteúdo</p>', 'Petição Inicial', 'PECA', $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado);
        self::assertSame('PETIÇÃO INICIAL', $resultado->getTitulo());
        self::assertSame('PECA', $resultado->getCategoria());
        self::assertSame('text/html', $resultado->getMimeType());
        self::assertSame('abc123.html', $resultado->getCaminhoArquivo());
        self::assertSame('Petição Inicial.html', $resultado->getNomeOriginal());
        self::assertSame($this->pasta, $resultado->getPasta());
        self::assertNull($resultado->getSecao());
    }

    #[TestDox('Tamanho em bytes é calculado com base no conteúdo HTML')]
    public function testTamanhoEmBytesECalculadoPeloConteudo(): void
    {
        $conteudo = '<p>Teste</p>';

        $this->storage->method('salvarConteudo')->willReturn('file.html');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $conteudo, 'Título', 'PECA', $this->tenant);

        self::assertSame(strlen($conteudo), $resultado->getTamanhoBytes());
    }

    #[TestDox('Título vazio lança InvalidArgumentException sem chamar storage')]
    public function testTituloVazioLancaInvalidArgumentException(): void
    {
        $this->storage->expects($this->never())->method('salvarConteudo');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, null, '<p>HTML</p>', '', 'PECA', $this->tenant);
    }

    #[TestDox('Título apenas espaços é tratado como vazio')]
    public function testTituloApenasEspacosLancaInvalidArgumentException(): void
    {
        $this->storage->expects($this->never())->method('salvarConteudo');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, null, '<p>HTML</p>', '   ', 'PECA', $this->tenant);
    }

    #[TestDox('Categoria diferente é salva corretamente')]
    public function testCategoriaDiferenteESalvaCorretamente(): void
    {
        $this->storage->method('salvarConteudo')->willReturn('doc.html');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, '<p>Contrato</p>', 'Contrato Social', 'CONTRATO', $this->tenant);

        self::assertSame('CONTRATO', $resultado->getCategoria());
    }

    #[TestDox('Conteúdo HTML vazio é salvo (documento vazio permitido)')]
    public function testConteudoHtmlVazioESalvoSemErro(): void
    {
        $this->storage->expects($this->once())
            ->method('salvarConteudo')
            ->with('', '/uploads/pastas', 'html')
            ->willReturn('vazio.html');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, '', 'Rascunho', 'PECA', $this->tenant);

        self::assertSame(0, $resultado->getTamanhoBytes());
    }

    #[TestDox('Seção válida é associada ao documento criado')]
    public function testSalvarTextoComSecaoValidaAssociaDocumentoASecao(): void
    {
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);
        $secao->setNome('Petições');

        $this->storage->method('salvarConteudo')->willReturn('doc.html');
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, $secao, '<p>HTML</p>', 'Título', 'PECA', $this->tenant);

        self::assertSame($secao, $resultado->getSecao());
    }

    #[TestDox('Seção de tenant diferente lança AccessDeniedException')]
    public function testSalvarTextoComSecaoDeTenantErradoLancaAccessDeniedException(): void
    {
        $outroTenant = new Tenant();
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($outroTenant);

        $this->storage->expects($this->never())->method('salvarConteudo');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->pasta, $secao, '<p>HTML</p>', 'Título', 'PECA', $this->tenant);
    }

    #[TestDox('Seção de outra pasta lança InvalidArgumentException')]
    public function testSalvarTextoComSecaoDeOutraPastaLancaInvalidArgumentException(): void
    {
        $outraPasta = new Pasta();
        $secao = new PastaSecao();
        $secao->setPasta($outraPasta);
        $secao->setTenant($this->tenant);

        $this->storage->expects($this->never())->method('salvarConteudo');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $secao, '<p>HTML</p>', 'Título', 'PECA', $this->tenant);
    }
}
