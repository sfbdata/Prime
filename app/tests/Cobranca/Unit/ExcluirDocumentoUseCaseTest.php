<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\UseCase\ExcluirDocumentoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A ordem da E2.5 (INV-6): a linha sai e é confirmada, e só então o arquivo. O dublê em memória
 * materializa o escopo na chave — é ele que faz o tenant errado quebrar aqui, onde o disco plano de
 * produção seria cego (R1).
 */
#[CoversClass(ExcluirDocumentoUseCase::class)]
final class ExcluirDocumentoUseCaseTest extends TestCase
{
    private CobrancaDocumentoRepository&MockObject $documentoRepository;
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private ExcluirDocumentoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->documentoRepository = $this->createMock(CobrancaDocumentoRepository::class);
        $this->armazenamento       = new ArmazenamentoEmMemoria();
        $this->logger              = new LoggerEmMemoria();
        $this->sut                 = new ExcluirDocumentoUseCase(
            $this->documentoRepository,
            new RemocaoAposTransacao($this->armazenamento, $this->logger),
        );
        $this->tenant = $this->tenantComId(7);
    }

    #[Test]
    public function removeORegistroEDepoisOArquivo(): void
    {
        $documento = $this->documento($this->tenant, 'hash-abc');
        $chave     = ChavesDeCobranca::documentoDeCaso($documento);
        $this->armazenamento->semear($chave);

        $this->documentoRepository
            ->expects($this->once())
            ->method('remover')
            ->with(self::identicalTo($documento), true)
            ->willReturnCallback(function () use ($chave): void {
                self::assertTrue(
                    $this->armazenamento->existe($chave),
                    'o arquivo não pode sair antes de a linha ser removida e confirmada',
                );
            });

        $this->sut->executar($documento, $this->tenant);

        self::assertFalse($this->armazenamento->existe($chave));
    }

    #[Test]
    public function bancoQueRecusaNaoApagaOArquivo(): void
    {
        $documento = $this->documento($this->tenant, 'hash-abc');
        $chave     = ChavesDeCobranca::documentoDeCaso($documento);
        $this->armazenamento->semear($chave);

        $recusa = new \RuntimeException('flush recusado');
        $this->documentoRepository->method('remover')->willThrowException($recusa);

        try {
            $this->sut->executar($documento, $this->tenant);
            $capturada = null;
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertSame($recusa, $capturada);
        self::assertTrue($this->armazenamento->existe($chave), 'rollback sem perda física');
        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[Test]
    public function discoQueFalhaDepoisDoCommitNaoDesfazNada(): void
    {
        $documento = $this->documento($this->tenant, 'hash-abc');
        $chave     = ChavesDeCobranca::documentoDeCaso($documento);
        $this->armazenamento->semear($chave);
        $this->armazenamento->falhaAoExcluir = static fn (): \Throwable => new FalhaDeArmazenamento('disco ilegível');

        $this->documentoRepository->expects($this->once())->method('remover');

        $this->sut->executar($documento, $this->tenant);

        self::assertTrue($this->armazenamento->existe($chave), 'órfão recuperável');
        self::assertSame($chave->comoTexto(), $this->logger->doNivel('error')[0]['contexto']['chave']);
    }

    #[Test]
    public function removeRegistroMesmoQuandoArquivoNaoExiste(): void
    {
        $documento = $this->documento($this->tenant, 'hash-sumido');

        $this->documentoRepository
            ->expects($this->once())
            ->method('remover')
            ->with(self::identicalTo($documento), true);

        $this->sut->executar($documento, $this->tenant);

        self::assertSame([], $this->armazenamento->excluidas);
        self::assertSame([], $this->logger->registros);
    }

    #[Test]
    public function naoApagaArquivoDeOutroEscritorioComOMesmoNome(): void
    {
        $documento = $this->documento($this->tenant, 'hash-abc');
        $alheio    = new ChaveDeArquivo(EscopoDeArquivo::deTenant(99), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'hash-abc');
        $this->armazenamento->semear($alheio);

        $this->documentoRepository->expects($this->once())->method('remover');

        $this->sut->executar($documento, $this->tenant);

        self::assertTrue($this->armazenamento->existe($alheio));
    }

    #[Test]
    public function rejeitaDocumentoDeOutroTenant(): void
    {
        $documento = $this->documento($this->tenantComId(99), 'hash-x');
        $chave     = ChavesDeCobranca::documentoDeCaso($documento);
        $this->armazenamento->semear($chave);

        $this->documentoRepository->expects($this->never())->method('remover');

        try {
            $this->sut->executar($documento, $this->tenant);
            self::fail('devia ter recusado');
        } catch (AccessDeniedException) {
        }

        self::assertTrue($this->armazenamento->existe($chave), 'nada toca o disco nem o banco');
    }

    private function documento(Tenant $tenant, string $nome): CobrancaDocumento
    {
        return (new CobrancaDocumento())->setTenant($tenant)->setCaminhoArquivo($nome);
    }

    private function tenantComId(int $id): Tenant
    {
        $tenant = new Tenant();
        $ref    = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);

        return $tenant;
    }
}
