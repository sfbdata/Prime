<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\PastaDocumento;
use App\Pasta\UseCase\EditarPecaTextoUseCase;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarPecaTextoUseCase::class)]
final class EditarPecaTextoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ArquivoStorageInterface&MockObject $storage;
    private EditarPecaTextoUseCase $useCase;
    private string $arquivoTemp;
    private PastaDocumento $doc;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->useCase = new EditarPecaTextoUseCase($this->em, $this->storage, '/uploads/pastas');

        $this->arquivoTemp = tempnam(sys_get_temp_dir(), 'peca_html_');
        file_put_contents($this->arquivoTemp, '<p>Conteúdo original</p>');

        $this->doc = (new PastaDocumento())
            ->setTitulo('Petição Inicial')
            ->setCaminhoArquivo(basename($this->arquivoTemp))
            ->setCategoria('PECA')
            ->setMimeType('text/html')
            ->setNomeOriginal('Petição Inicial.html')
            ->setTamanhoBytes(strlen('<p>Conteúdo original</p>'));

        $this->storage->method('caminho')
            ->with('/uploads/pastas', basename($this->arquivoTemp))
            ->willReturn($this->arquivoTemp);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->arquivoTemp)) {
            unlink($this->arquivoTemp);
        }
    }

    #[TestDox('Editar com novo conteúdo e título atualiza arquivo, título e tamanhoBytes')]
    public function testEditarComConteudoEtituloNovoAtualizaTudo(): void
    {
        $novoConteudo = '<p>Novo conteúdo editado</p>';

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->doc, $novoConteudo, 'Nova Petição');

        self::assertSame($novoConteudo, file_get_contents($this->arquivoTemp));
        self::assertSame('NOVA PETIÇÃO', $this->doc->getTitulo());
        self::assertSame(strlen($novoConteudo), $this->doc->getTamanhoBytes());
    }

    #[TestDox('Editar sem título (null) mantém título existente')]
    public function testEditarSemTituloMantemTituloExistente(): void
    {
        $this->em->method('flush');

        $this->useCase->executar($this->doc, '<p>Novo HTML</p>');

        self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
    }

    #[TestDox('Editar com título vazio (string vazia) não atualiza o título')]
    public function testEditarComTituloVazioNaoAtualizaTitulo(): void
    {
        $this->em->method('flush');

        $this->useCase->executar($this->doc, '<p>HTML</p>', '');

        self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
    }

    #[TestDox('Editar com título apenas espaços não atualiza o título')]
    public function testEditarComTituloApenasEspacosNaoAtualizaTitulo(): void
    {
        $this->em->method('flush');

        $this->useCase->executar($this->doc, '<p>HTML</p>', '   ');

        self::assertSame('PETIÇÃO INICIAL', $this->doc->getTitulo());
    }

    #[TestDox('TamanhoBytes é recalculado com base no novo conteúdo')]
    public function testTamanhoEmBytesRecalculadoComNovoConteudo(): void
    {
        $conteudoMaior = str_repeat('<p>Parágrafo com texto</p>', 10);

        $this->em->method('flush');

        $this->useCase->executar($this->doc, $conteudoMaior);

        self::assertSame(strlen($conteudoMaior), $this->doc->getTamanhoBytes());
    }
}
