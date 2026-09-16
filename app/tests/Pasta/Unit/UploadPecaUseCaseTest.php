<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\ResultadoUploadPeca;
use App\Pasta\UseCase\UploadPecaUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Service\ArquivoStorageInterface;
use App\Shared\Service\CompressorArquivoInterface;
use App\Shared\Service\ResultadoCompressao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Upload de peça na pasta. Desde a E2.4A a gravação é da ponte HTTP + `ArmazenamentoDeArquivos`;
 * o storage antigo só responde o `caminho()` do compressor (E2.6).
 */
#[CoversClass(UploadPecaUseCase::class)]
final class UploadPecaUseCaseTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private EntityManagerInterface&MockObject $em;
    private ArquivoStorageInterface&MockObject $storage;
    private CompressorArquivoInterface&MockObject $compressor;
    private ArmazenamentoEmMemoria $armazenamento;
    private UploadPecaUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;
    private string $diretorio;

    protected function setUp(): void
    {
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->storage       = $this->createMock(ArquivoStorageInterface::class);
        $this->compressor    = $this->createMock(CompressorArquivoInterface::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->useCase       = new UploadPecaUseCase($this->em, $this->armazenamento, $this->storage, $this->compressor, '/uploads/pastas');
        $this->tenant        = $this->tenant(7);
        $this->pasta         = (new Pasta())->setTenant($this->tenant);

        // A gravação não passa mais pelo storage antigo.
        $this->storage->expects(self::never())->method('salvar');

        $this->diretorio = sys_get_temp_dir() . '/e2-upload-peca-' . bin2hex(random_bytes(6));
        mkdir($this->diretorio, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->diretorio . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }

        @rmdir($this->diretorio);
    }

    public function testUploadValidoRetornaPastaDocumento(): void
    {
        $file = $this->upload('application/pdf', 1024, 'peticao.pdf');

        $this->compressor->expects($this->never())->method('comprimir');
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $file, 'PECA', null, null, $this->tenant);

        self::assertInstanceOf(ResultadoUploadPeca::class, $resultado);
        $doc = $resultado->documento;
        self::assertInstanceOf(PastaDocumento::class, $doc);
        self::assertSame('PETICAO.PDF', $doc->getTitulo());
        self::assertSame('PECA', $doc->getCategoria());
        self::assertSame('application/pdf', $doc->getMimeType());
        self::assertSame(1024, $doc->getTamanhoBytes());
        self::assertSame('peticao.pdf', $doc->getNomeOriginal());
        self::assertSame($this->pasta, $doc->getPasta());
        self::assertNull($doc->getSecao());
        self::assertFalse($resultado->compressao->comprimido);

        // O nome guardado é o que o storage cunhou, e o conteúdo é o enviado.
        $gravada = $this->armazenamento->ultimaGravada();
        self::assertSame($gravada->nome, $doc->getCaminhoArquivo());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $doc->getCaminhoArquivo());
        self::assertSame(self::PDF, $this->armazenamento->ler($gravada));
    }

    #[TestDox('R1: a chave gravada tem a categoria de documento e o escopo da pasta')]
    public function testChaveGravadaTemOEscopoDaPasta(): void
    {
        $this->useCase->executar($this->pasta, null, $this->upload('application/pdf', 1024, 'peticao.pdf'), 'PECA', null, null, $this->tenant);

        $gravada = $this->armazenamento->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $gravada->categoria);
        self::assertSame(7, $gravada->escopo->tenantIdOuNull());
    }

    #[TestDox('falha do storage propaga e nada é persistido')]
    public function testFalhaDoStorageNaoPersiste(): void
    {
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco cheio');

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(FalhaDeArmazenamento::class);

        $this->useCase->executar($this->pasta, null, $this->upload('application/pdf', 1024, 'peticao.pdf'), 'PECA', null, null, $this->tenant);
    }

    public function testUploadComDescricaoENumero(): void
    {
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $this->upload('application/pdf', 512, 'doc.pdf'), 'PECA', 'Descrição da peça', '001/2026', $this->tenant);

        self::assertSame('Descrição da peça', $resultado->documento->getDescricao());
        self::assertSame('001/2026', $resultado->documento->getNumero());
    }

    public function testMimeTypeInvalidoLancaInvalidArgumentException(): void
    {
        $file = $this->upload('application/x-executable', 1024, 'malware.exe');

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($this->pasta, null, $file, 'PECA', null, null, $this->tenant);
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    public function testTamanhoExcedidoLancaInvalidArgumentException(): void
    {
        $file = $this->upload('application/pdf', 20 * 1024 * 1024, 'grande.pdf'); // 20 MB > limite de 10 MB

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($this->pasta, null, $file, 'PECA', null, null, $this->tenant);
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    public function testDescricaoENumeroVaziosViramNull(): void
    {
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $this->upload('application/pdf', 100, 'teste.pdf'), 'DEMAIS', '', '', $this->tenant);

        self::assertNull($resultado->documento->getDescricao());
        self::assertNull($resultado->documento->getNumero());
    }

    public function testUploadComSecaoValidaAssociaDocumentoASecao(): void
    {
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);
        $secao->setNome('Documentos do Cliente');

        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, $secao, $this->upload('application/pdf', 512, 'doc.pdf'), 'PECA', null, null, $this->tenant);

        self::assertSame($secao, $resultado->documento->getSecao());
    }

    public function testUploadComSecaoDeTenantErradoLancaAccessDeniedException(): void
    {
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant(99));

        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        try {
            $this->useCase->executar($this->pasta, $secao, $this->upload('application/pdf', 512, 'doc.pdf'), 'PECA', null, null, $this->tenant);
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    public function testUploadComSecaoDeOutraPastaLancaInvalidArgumentException(): void
    {
        $secao = new PastaSecao();
        $secao->setPasta((new Pasta())->setTenant($this->tenant));
        $secao->setTenant($this->tenant);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($this->pasta, $secao, $this->upload('application/pdf', 512, 'doc.pdf'), 'PECA', null, null, $this->tenant);
        } finally {
            self::assertSame([], $this->armazenamento->gravadas);
        }
    }

    public function testTextPlainEAceito(): void
    {
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $this->upload('text/plain', 1 * 1024 * 1024, 'nota.txt'), 'DEMAIS', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado->documento);
        self::assertSame('text/plain', $resultado->documento->getMimeType());
        self::assertStringEndsWith('.txt', $resultado->documento->getCaminhoArquivo());
    }

    public function testApplicationZipEAceito(): void
    {
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $this->upload('application/zip', 10 * 1024 * 1024, 'acervo.zip'), 'DEMAIS', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado->documento);
        self::assertSame('application/zip', $resultado->documento->getMimeType());
        self::assertStringEndsWith('.zip', $resultado->documento->getCaminhoArquivo());
    }

    public function testPkcs7SignatureEAceito(): void
    {
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $this->upload('application/pkcs7-signature', 50 * 1024, 'assinatura.p7s'), 'DEMAIS', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado->documento);
        self::assertSame('application/pkcs7-signature', $resultado->documento->getMimeType());
        self::assertStringEndsWith('.p7s', $resultado->documento->getCaminhoArquivo());
    }

    /**
     * `audio/opus` não tem extensão no mapa do Symfony: `guessExtension()` devolve null e o nome
     * termina em `.bin` — exatamente como no `salvar()` antigo (`?? 'bin'`).
     */
    public function testAudioOpusEAceito(): void
    {
        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $this->upload('audio/opus', 5 * 1024 * 1024, 'gravacao.opus'), 'DEMAIS', null, null, $this->tenant);

        self::assertInstanceOf(PastaDocumento::class, $resultado->documento);
        self::assertSame('audio/opus', $resultado->documento->getMimeType());
        self::assertStringEndsWith('.bin', $resultado->documento->getCaminhoArquivo());
    }

    public function testReduzirTamanhoComprimeEGravaTamanhoFinal(): void
    {
        $this->storage->method('caminho')
            ->willReturnCallback(static fn (string $dir, string $nome): string => $dir . '/' . $nome);

        $comprimido = null;
        $this->compressor->expects($this->once())
            ->method('comprimir')
            ->willReturnCallback(static function (string $caminho, string $mime) use (&$comprimido): ResultadoCompressao {
                $comprimido = [$caminho, $mime];

                return new ResultadoCompressao(5000, 1500, true, true);
            });

        $this->em->method('persist');
        $this->em->method('flush');

        $resultado = $this->useCase->executar($this->pasta, null, $this->upload('application/pdf', 5000, 'grande.pdf'), 'PECA', null, null, $this->tenant, true);

        self::assertSame(1500, $resultado->documento->getTamanhoBytes());
        self::assertTrue($resultado->compressao->comprimido);
        self::assertTrue($resultado->compressao->eraAssinado);
        // O compressor recebe o caminho do arquivo que acabou de ser gravado — não de outro.
        self::assertSame(['/uploads/pastas/' . $this->armazenamento->ultimaGravada()->nome, 'application/pdf'], $comprimido);
    }

    /**
     * Upload de teste: arquivo real (a ponte o move de verdade), com MIME e tamanho DECLARADOS. O
     * que se testa aqui é a lista de tipos e o limite por tipo, e produzir conteúdo real de
     * `audio/opus` ou de 20 MB não acrescentaria nada — `isValid()`, `move()` e
     * `guessExtension()` continuam os do framework.
     *
     * Ler MIME ou tamanho DEPOIS do `move()` falha aqui como falharia no `UploadedFile` real
     * ("stat failed"): é a classe de defeito que derrubava o Kanban e o ServiceDesk, e a sobrescrita
     * não pode escondê-la.
     */
    private function upload(string $mime, int $tamanho, string $nome): UploadedFile
    {
        $caminho = $this->diretorio . '/' . bin2hex(random_bytes(4));
        file_put_contents($caminho, self::PDF);

        return new class ($caminho, $nome, $mime, $tamanho) extends UploadedFile {
            public function __construct(
                string $caminho,
                string $nome,
                private readonly string $mimeDeclarado,
                private readonly int $tamanhoDeclarado,
            ) {
                parent::__construct($caminho, $nome, $mimeDeclarado, null, true);
            }

            private bool $movido = false;

            public function move(string $directory, ?string $name = null): \Symfony\Component\HttpFoundation\File\File
            {
                $this->movido = true;

                return parent::move($directory, $name);
            }

            public function getMimeType(): ?string
            {
                $this->exigirNaoMovido();

                return $this->mimeDeclarado;
            }

            public function getSize(): int|false
            {
                $this->exigirNaoMovido();

                return $this->tamanhoDeclarado;
            }

            private function exigirNaoMovido(): void
            {
                if ($this->movido) {
                    throw new \RuntimeException('SplFileInfo::getSize(): stat failed — metadado lido depois do move()');
                }
            }
        };
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
