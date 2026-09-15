<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Repository\AcordoDocumentoRepository;
use App\Cobranca\UseCase\ExcluirDocumentoAcordoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Service\ArquivoStorageInterface;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Estado misto da E2.2, de propósito: a PRESENÇA do arquivo é perguntada ao armazenamento novo,
 * por chave; a REMOÇÃO ainda passa pela interface antiga, por caminho, até a E2.5. O dublê em
 * memória materializa o escopo na chave — é ele que faz o tenant errado quebrar aqui, onde o
 * disco plano de produção seria cego (R1).
 */
#[CoversClass(ExcluirDocumentoAcordoUseCase::class)]
final class ExcluirDocumentoAcordoUseCaseTest extends TestCase
{
    private const UPLOADS_DIR = '/uploads/cobrancas';

    private AcordoDocumentoRepository&MockObject $documentoRepository;
    private ArquivoStorageInterface&MockObject $storage;
    private ArmazenamentoEmMemoria $armazenamento;
    private ExcluirDocumentoAcordoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->documentoRepository = $this->createMock(AcordoDocumentoRepository::class);
        $this->storage = $this->createMock(ArquivoStorageInterface::class);
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->sut = new ExcluirDocumentoAcordoUseCase(
            $this->documentoRepository,
            $this->storage,
            $this->armazenamento,
            self::UPLOADS_DIR,
        );
        $this->tenant = $this->tenantComId(7);
    }

    #[Test]
    public function excluiArquivoFisicoERegistroQuandoArquivoExiste(): void
    {
        $documento = (new AcordoDocumento())->setTenant($this->tenant)->setCaminhoArquivo('hash-abc');
        $this->armazenamento->gravar(ChavesDeCobranca::documentoDeAcordo($documento), FonteDeConteudo::deTexto('%PDF'));

        // A presença é do armazenamento novo; a interface antiga só remove, pelo caminho de sempre
        // (isolamento por tenant, contrato congelado, padrão M5).
        $this->storage->expects($this->never())->method('existe');
        $this->storage
            ->expects($this->once())
            ->method('caminho')
            ->with(self::UPLOADS_DIR . '/7', 'hash-abc')
            ->willReturn('/fisico/hash-abc');
        $this->storage->expects($this->once())->method('excluir')->with('/fisico/hash-abc');

        $this->documentoRepository
            ->expects($this->once())
            ->method('remover')
            ->with(self::identicalTo($documento), true);

        $this->sut->executar($documento, $this->tenant);
    }

    #[Test]
    public function removeRegistroMesmoQuandoArquivoNaoExisteNoDisco(): void
    {
        $documento = (new AcordoDocumento())->setTenant($this->tenant)->setCaminhoArquivo('hash-sumido');

        // Best-effort: arquivo ausente não impede a remoção da linha, e nada é excluído do disco.
        $this->storage->expects($this->never())->method('excluir');

        $this->documentoRepository
            ->expects($this->once())
            ->method('remover')
            ->with(self::identicalTo($documento), true);

        $this->sut->executar($documento, $this->tenant);
    }

    #[Test]
    public function naoEnxergaArquivoDeOutroEscritorioComOMesmoNome(): void
    {
        $documento = (new AcordoDocumento())->setTenant($this->tenant)->setCaminhoArquivo('hash-abc');

        // Mesmo nome, escopo de OUTRO escritório: para este documento o arquivo não existe.
        $this->armazenamento->gravar(
            new ChaveDeArquivo(EscopoDeArquivo::deTenant(99), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'hash-abc'),
            FonteDeConteudo::deTexto('%PDF'),
        );

        $this->storage->expects($this->never())->method('excluir');
        $this->documentoRepository->expects($this->once())->method('remover');

        $this->sut->executar($documento, $this->tenant);
    }

    #[Test]
    public function rejeitaDocumentoDeOutroTenant(): void
    {
        $documento = (new AcordoDocumento())->setTenant($this->tenantComId(99))->setCaminhoArquivo('hash-x');

        // Guarda anterior: nada toca o disco nem o banco.
        $this->storage->expects($this->never())->method('excluir');
        $this->documentoRepository->expects($this->never())->method('remover');

        $this->expectException(AccessDeniedException::class);

        $this->sut->executar($documento, $this->tenant);
    }

    private function tenantComId(int $id): Tenant
    {
        $tenant = new Tenant();
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);

        return $tenant;
    }
}
