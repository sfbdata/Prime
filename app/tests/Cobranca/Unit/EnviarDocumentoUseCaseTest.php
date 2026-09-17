<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Enum\CategoriaDocumentoCobranca;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\SecaoNaoEncontradaException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\UseCase\EnviarDocumentoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Service\CompressaoDeArquivoArmazenado;
use App\Shared\Service\CompressorArquivoInterface;
use App\Shared\Service\ResultadoCompressao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Os uploads são arquivos REAIS em diretório temporário próprio (E2.4A): a ponte HTTP lê MIME e
 * extensão do conteúdo e move o arquivo, então um mock de `UploadedFile` não provaria nada. Desde a
 * E2.6B o use case não conhece caminho nem diretório: comprime pela CHAVE, com o serviço real sobre
 * o dublê em memória.
 */
#[CoversClass(EnviarDocumentoUseCase::class)]
final class EnviarDocumentoUseCaseTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private CobrancaDocumentoRepository&MockObject $documentoRepository;
    private ArmazenamentoEmMemoria $armazenamento;
    private CompressorArquivoInterface&MockObject $compressor;
    private EnviarDocumentoUseCase $sut;
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;
    private string $dirTemp;

    protected function setUp(): void
    {
        $this->dirTemp = sys_get_temp_dir() . '/enviar-doc-caso-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);

        $this->documentoRepository = $this->createMock(CobrancaDocumentoRepository::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->compressor = $this->createMock(CompressorArquivoInterface::class);
        $this->sut = new EnviarDocumentoUseCase(
            $this->documentoRepository,
            $this->armazenamento,
            new CompressaoDeArquivoArmazenado($this->armazenamento, $this->armazenamento, $this->compressor, new NullLogger()),
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
    public function enviaDocumentoNoCasoSemSecao(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $file = $this->upload(self::PDF, 'contrato.pdf');

        // Sem redução de tamanho: o compressor não é acionado.
        $this->compressor->expects($this->never())->method('comprimir');

        $this->documentoRepository->method('proximaOrdem')->with($caso)->willReturn(3);

        $salvo = null;
        $this->documentoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(CobrancaDocumento::class), true)
            ->willReturnCallback(function (CobrancaDocumento $doc) use (&$salvo): void {
                $salvo = $doc;
            });

        $documento = $this->sut->executar(
            $caso,
            null,
            $file,
            CategoriaDocumentoCobranca::TermoAcordo,
            'Contrato assinado',
            $this->tenant,
        );

        $chave = $this->armazenamento->ultimaGravada();

        self::assertSame($salvo, $documento);
        self::assertSame($caso, $documento->getCaso());
        self::assertNull($documento->getSecao());
        self::assertSame($this->tenant, $documento->getTenant());
        self::assertSame('CONTRATO.PDF', $documento->getTitulo());
        self::assertSame(CategoriaDocumentoCobranca::TermoAcordo, $documento->getCategoria());
        self::assertSame('Contrato assinado', $documento->getDescricao());
        // A coluna guarda o nome CUNHADO pelo storage, com a extensão tirada do conteúdo.
        self::assertCount(1, $this->armazenamento->gravadas);
        self::assertSame($chave->nome, $documento->getCaminhoArquivo());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $documento->getCaminhoArquivo());
        self::assertSame(self::PDF, $this->armazenamento->ler($chave));
        self::assertSame('contrato.pdf', $documento->getNomeOriginal());
        self::assertSame('application/pdf', $documento->getMimeType());
        self::assertSame(\strlen(self::PDF), $documento->getTamanhoBytes());
        self::assertSame(3, $documento->getOrdem());
    }

    #[Test]
    public function aChaveGravadaTemOEscopoDoTenantDoCaso(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->documentoRepository->method('proximaOrdem')->willReturn(0);

        $this->sut->executar(
            $caso,
            null,
            $this->upload(self::PDF, 'x.pdf'),
            CategoriaDocumentoCobranca::Outro,
            null,
            $this->tenant,
        );

        // R1: no disco local o escopo seria invisível; aqui ele é conferido contra a entidade dona.
        $chave = $this->armazenamento->ultimaGravada();
        self::assertSame($caso->getTenant()?->getId(), $chave->escopo->tenantIdOuNull());
        self::assertSame(CategoriaDeArquivo::COBRANCA_DOCUMENTO, $chave->categoria);
    }

    #[Test]
    public function enviaDocumentoEmSecaoDoMesmoCaso(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $secao = (new CobrancaSecao())->setTenant($this->tenant)->setCaso($caso);
        $file = $this->upload($this->png(), 'boleto.png');

        $this->documentoRepository->method('proximaOrdem')->willReturn(1);
        $this->documentoRepository->expects($this->once())->method('salvar');

        $documento = $this->sut->executar(
            $caso,
            $secao,
            $file,
            CategoriaDocumentoCobranca::Boleto,
            null,
            $this->tenant,
        );

        self::assertSame($secao, $documento->getSecao());
        // descricao vazia/null vira null.
        self::assertNull($documento->getDescricao());
        self::assertSame('image/png', $documento->getMimeType());
        self::assertStringEndsWith('.png', $documento->getCaminhoArquivo());
    }

    #[Test]
    public function comprimeQuandoReduzirTamanhoSolicitado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $file = $this->upload(self::PDF, 'peticao.pdf');

        // O compressor recebe uma CÓPIA gravável com o conteúdo do arquivo recém-gravado, e o que
        // ele escrever lá volta para a MESMA chave.
        $menor   = 'pdf bem menor';
        $recebeu = null;
        $this->compressor
            ->expects($this->once())
            ->method('comprimir')
            ->willReturnCallback(function (string $caminho, string $mime) use (&$recebeu, $menor): ResultadoCompressao {
                $recebeu = [file_get_contents($caminho), $mime];
                file_put_contents($caminho, $menor);

                return new ResultadoCompressao(\strlen(self::PDF), 40, true);
            });

        $this->documentoRepository->method('proximaOrdem')->willReturn(0);
        $this->documentoRepository->expects($this->once())->method('salvar');

        $documento = $this->sut->executar(
            $caso,
            null,
            $file,
            CategoriaDocumentoCobranca::Outro,
            null,
            $this->tenant,
            reduzirTamanho: true,
        );

        self::assertSame([self::PDF, 'application/pdf'], $recebeu, 'o compressor não recebeu o conteúdo recém-gravado');
        self::assertSame($menor, $this->armazenamento->ler($this->armazenamento->ultimaGravada()));
        // D30: o tamanho é o MEDIDO depois da regravação, não os 40 que o compressor relatou.
        self::assertSame(\strlen($menor), $documento->getTamanhoBytes());
    }

    #[Test]
    public function tamanhoVemDoStorageMesmoSemComprimir(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->armazenamento->tamanhoRelatado = 4242;
        $this->documentoRepository->method('proximaOrdem')->willReturn(0);
        $this->documentoRepository->expects($this->once())->method('salvar');

        $documento = $this->sut->executar(
            $caso,
            null,
            $this->upload(self::PDF, 'contrato.pdf'),
            CategoriaDocumentoCobranca::Outro,
            null,
            $this->tenant,
        );

        self::assertSame(4242, $documento->getTamanhoBytes(), 'D30: o tamanho tem de vir do storage');
    }

    #[Test]
    public function falhaDoArmazenamentoPropagaENaoSalvaODocumento(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->armazenamento->falhaAoGravar = new FalhaDeArmazenamento('disco indisponível');

        // Sem arquivo não há registro: nada de linha apontando para o vazio (INV-6).
        $this->compressor->expects($this->never())->method('comprimir');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(FalhaDeArmazenamento::class);

        $this->sut->executar(
            $caso,
            null,
            $this->upload(self::PDF, 'x.pdf'),
            CategoriaDocumentoCobranca::Outro,
            null,
            $this->tenant,
        );
    }

    #[Test]
    public function rejeitaSecaoDeOutroTenant(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $secao = (new CobrancaSecao())->setTenant($this->tenantComId(99))->setCaso($caso);
        $file = $this->upload(self::PDF, 'x.pdf');

        // Nada toca o disco nem o banco: a guarda é anterior.
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar(
            AccessDeniedException::class,
            fn () => $this->sut->executar($caso, $secao, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant),
        );
    }

    #[Test]
    public function rejeitaSecaoDeOutroCaso(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $outroCaso = (new CasoCobranca())->setTenant($this->tenant);
        $secao = (new CobrancaSecao())->setTenant($this->tenant)->setCaso($outroCaso);
        $file = $this->upload(self::PDF, 'x.pdf');

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar(
            SecaoNaoEncontradaException::class,
            fn () => $this->sut->executar($caso, $secao, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant),
        );
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenantComId(42));
        $file = $this->upload(self::PDF, 'x.pdf');

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar(
            AccessDeniedException::class,
            fn () => $this->sut->executar($caso, null, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant),
        );
    }

    #[Test]
    public function rejeitaMimeForaDaWhitelist(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        // Cabeçalho MZ: executável do Windows, fora da whitelist.
        $file = $this->upload("MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\0", 200), 'virus.exe');

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar(
            TipoArquivoNaoPermitidoException::class,
            fn () => $this->sut->executar($caso, null, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant),
        );
    }

    #[Test]
    public function rejeitaArquivoAcimaDoLimite(): void
    {
        // PNG tem limite de 3 MB; 4 MB estoura.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $file = $this->upload($this->png() . str_repeat("\0", 4 * 1024 * 1024), 'gigante.png');

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->assertRecusaSemGravar(
            ArquivoMuitoGrandeException::class,
            fn () => $this->sut->executar($caso, null, $file, CategoriaDocumentoCobranca::Outro, null, $this->tenant),
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
