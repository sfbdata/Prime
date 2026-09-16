<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Enum\CategoriaDocumentoAcordo;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Repository\AcordoDocumentoRepository;
use App\Cobranca\UseCase\EnviarDocumentoAcordoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Os uploads são arquivos REAIS em diretório temporário próprio (E2.4A): a ponte HTTP lê MIME e
 * extensão do conteúdo e move o arquivo, então um mock de `UploadedFile` não provaria nada.
 */
#[CoversClass(EnviarDocumentoAcordoUseCase::class)]
final class EnviarDocumentoAcordoUseCaseTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private AcordoDocumentoRepository&MockObject $documentoRepository;
    private ArmazenamentoEmMemoria $armazenamento;
    private EnviarDocumentoAcordoUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;
    private string $dirTemp;

    protected function setUp(): void
    {
        $this->dirTemp = sys_get_temp_dir() . '/enviar-doc-acordo-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);

        $this->documentoRepository = $this->createMock(AcordoDocumentoRepository::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->sut = new EnviarDocumentoAcordoUseCase(
            $this->documentoRepository,
            $this->armazenamento,
        );
        $this->tenant = $this->tenantComId(7);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }

        @rmdir($this->dirTemp);
    }

    #[Test]
    public function enviaDocumentoNoAcordo(): void
    {
        $acordo = (new Acordo())->setTenant($this->tenant);
        $file = $this->upload(self::PDF, 'termo.pdf');

        $salvo = null;
        $this->documentoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(AcordoDocumento::class), true)
            ->willReturnCallback(function (AcordoDocumento $doc) use (&$salvo): void {
                $salvo = $doc;
            });

        $documento = $this->sut->executar(
            $acordo,
            $file,
            CategoriaDocumentoAcordo::TermoDeAcordo,
            'Assinado pelas partes',
            $this->tenant,
        );

        $chave = $this->armazenamento->ultimaGravada();

        self::assertSame($salvo, $documento);
        self::assertSame($acordo, $documento->getAcordo());
        self::assertSame($this->tenant, $documento->getTenant());
        self::assertSame('termo.pdf', $documento->getTitulo());
        self::assertSame(CategoriaDocumentoAcordo::TermoDeAcordo, $documento->getCategoria());
        self::assertSame('Assinado pelas partes', $documento->getObservacao());
        // A coluna guarda o nome CUNHADO pelo storage, com a extensão tirada do conteúdo.
        self::assertCount(1, $this->armazenamento->gravadas);
        self::assertSame($chave->nome, $documento->getCaminhoArquivo());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $documento->getCaminhoArquivo());
        self::assertSame(self::PDF, $this->armazenamento->ler($chave));
        self::assertSame('termo.pdf', $documento->getNomeOriginal());
        self::assertSame('application/pdf', $documento->getMimeType());
        self::assertSame(\strlen(self::PDF), $documento->getTamanhoBytes());
    }

    #[Test]
    public function aChaveGravadaTemOEscopoDoTenantDoAcordo(): void
    {
        $acordo = (new Acordo())->setTenant($this->tenant);

        $this->sut->executar($acordo, $this->upload(self::PDF, 'x.pdf'), CategoriaDocumentoAcordo::Outro, null, $this->tenant);

        // R1: no disco local o escopo seria invisível; aqui ele é conferido contra a entidade dona.
        // Os três documentos de Cobrança dividem a mesma categoria (e o mesmo diretório por tenant).
        $chave = $this->armazenamento->ultimaGravada();
        self::assertSame($acordo->getTenant()?->getId(), $chave->escopo->tenantIdOuNull());
        self::assertSame(CategoriaDeArquivo::COBRANCA_DOCUMENTO, $chave->categoria);
    }

    #[Test]
    public function observacaoVaziaOuNulaViraNull(): void
    {
        $acordo = (new Acordo())->setTenant($this->tenant);
        $file = $this->upload($this->png(), 'contrato.png');

        $this->documentoRepository->expects($this->once())->method('salvar');

        $documento = $this->sut->executar(
            $acordo,
            $file,
            CategoriaDocumentoAcordo::Contrato,
            '',
            $this->tenant,
        );

        self::assertNull($documento->getObservacao());
        self::assertStringEndsWith('.png', $documento->getCaminhoArquivo());
    }

    #[Test]
    public function falhaDoArmazenamentoPropagaENaoSalvaODocumento(): void
    {
        $acordo = (new Acordo())->setTenant($this->tenant);
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco indisponível');

        // Sem arquivo não há registro: nada de linha apontando para o vazio (INV-6).
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(FalhaDeArmazenamento::class);

        $this->sut->executar($acordo, $this->upload(self::PDF, 'x.pdf'), CategoriaDocumentoAcordo::Outro, null, $this->tenant);
    }

    #[Test]
    public function rejeitaAcordoDeOutroTenant(): void
    {
        $acordo = (new Acordo())->setTenant($this->tenantComId(42));
        $file = $this->upload(self::PDF, 'x.pdf');

        // Guarda IDOR: nada toca o disco nem o banco.
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar(
            AccessDeniedException::class,
            fn () => $this->sut->executar($acordo, $file, CategoriaDocumentoAcordo::Outro, null, $this->tenant),
        );
    }

    #[Test]
    public function rejeitaMimeForaDaWhitelist(): void
    {
        $acordo = (new Acordo())->setTenant($this->tenant);
        // Cabeçalho MZ: executável do Windows, fora da whitelist.
        $file = $this->upload("MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\0", 200), 'virus.exe');

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar(
            TipoArquivoNaoPermitidoException::class,
            fn () => $this->sut->executar($acordo, $file, CategoriaDocumentoAcordo::Outro, null, $this->tenant),
        );
    }

    #[Test]
    public function rejeitaArquivoAcimaDoLimite(): void
    {
        // PNG tem limite de 3 MB; 4 MB estoura.
        $acordo = (new Acordo())->setTenant($this->tenant);
        $file = $this->upload($this->png() . str_repeat("\0", 4 * 1024 * 1024), 'gigante.png');

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar(
            ArquivoMuitoGrandeException::class,
            fn () => $this->sut->executar($acordo, $file, CategoriaDocumentoAcordo::Outro, null, $this->tenant),
        );
    }

    /**
     * @param class-string<\Throwable> $excecao
     */
    private function assertRecusaSemGravar(string $excecao, \Closure $acao): void
    {
        try {
            $acao();
        } catch (\Throwable $e) {
            self::assertInstanceOf($excecao, $e);
            self::assertSame([], $this->armazenamento->gravadas, 'A recusa tem de acontecer antes de qualquer gravação.');

            return;
        }

        self::fail(sprintf('Esperava %s.', $excecao));
    }

    /** Upload real em modo de teste (pula `is_uploaded_file`), sobre um arquivo com conteúdo real. */
    private function upload(string $conteudo, string $nomeOriginal): UploadedFile
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nomeOriginal, null, null, true);
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(2, 2));

        return (string) ob_get_clean();
    }

    private function tenantComId(int $id): Tenant
    {
        $tenant = new Tenant();
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);

        return $tenant;
    }
}
