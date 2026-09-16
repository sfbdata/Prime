<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\UseCase\ExcluirSecaoUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Excluir a seção exclui os documentos — e os arquivos só saem depois de a seção sair (E2.5, INV-6).
 */
#[CoversClass(ExcluirSecaoUseCase::class)]
final class ExcluirSecaoUseCaseTest extends TestCase
{
    private CobrancaSecaoRepository&MockObject $secaoRepository;
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private ExcluirSecaoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->secaoRepository = $this->createMock(CobrancaSecaoRepository::class);
        $this->armazenamento   = new ArmazenamentoEmMemoria();
        $this->logger          = new LoggerEmMemoria();
        $this->sut             = new ExcluirSecaoUseCase(
            $this->secaoRepository,
            new RemocaoAposTransacao($this->armazenamento, $this->logger),
        );
        $this->tenant = $this->tenantComId(7);
    }

    #[Test]
    public function removeASecaoEDepoisOsArquivos(): void
    {
        $secao  = (new CobrancaSecao())->setTenant($this->tenant);
        $chaves = [
            $this->semear($this->adicionarDocumento($secao, 'hashA')),
            $this->semear($this->adicionarDocumento($secao, 'hashB')),
        ];

        $this->secaoRepository
            ->expects($this->once())
            ->method('remover')
            ->with($secao, true)
            ->willReturnCallback(function () use ($chaves): void {
                foreach ($chaves as $chave) {
                    self::assertTrue($this->armazenamento->existe($chave), 'nenhum arquivo sai antes do COMMIT');
                }
            });

        $this->sut->executar($secao, $this->tenant);

        self::assertSame(
            ['hashA', 'hashB'],
            array_map(static fn (ChaveDeArquivo $c): string => $c->nome, $this->armazenamento->excluidas),
        );
    }

    #[Test]
    public function bancoQueRecusaNaoApagaNenhumArquivo(): void
    {
        $secao  = (new CobrancaSecao())->setTenant($this->tenant);
        $chaveA = $this->semear($this->adicionarDocumento($secao, 'hashA'));
        $chaveB = $this->semear($this->adicionarDocumento($secao, 'hashB'));

        $this->secaoRepository->method('remover')->willThrowException(new \RuntimeException('FK recusou'));

        $capturada = null;
        try {
            $this->sut->executar($secao, $this->tenant);
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertSame('FK recusou', $capturada?->getMessage(), 'a recusa do banco devia subir');

        self::assertTrue($this->armazenamento->existe($chaveA));
        self::assertTrue($this->armazenamento->existe($chaveB));
    }

    /** Laço com falha no meio: os outros saem, a seção continua excluída, e nada é lançado. */
    #[Test]
    public function falhaNoMeioDoLacoNaoInterrompeOsDemais(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenant);
        $a     = $this->semear($this->adicionarDocumento($secao, 'hashA'));
        $b     = $this->semear($this->adicionarDocumento($secao, 'hashB'));
        $c     = $this->semear($this->adicionarDocumento($secao, 'hashC'));
        $this->armazenamento->falhaAoExcluir = static fn (ChaveDeArquivo $chave): ?\Throwable => $chave->nome === 'hashB'
            ? new FalhaDeArmazenamento('disco recusou hashB')
            : null;

        $this->secaoRepository->expects($this->once())->method('remover');

        $this->sut->executar($secao, $this->tenant);

        self::assertFalse($this->armazenamento->existe($a));
        self::assertTrue($this->armazenamento->existe($b));
        self::assertFalse($this->armazenamento->existe($c));
        self::assertCount(1, $this->logger->doNivel('error'));
    }

    #[Test]
    public function naoExcluiArquivoInexistenteMasRemoveASecao(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenant);
        $this->adicionarDocumento($secao, 'hashFantasma');

        $this->secaoRepository->expects($this->once())->method('remover')->with($secao, true);

        $this->sut->executar($secao, $this->tenant);

        self::assertSame([], $this->armazenamento->excluidas);
    }

    #[Test]
    public function naoApagaArquivoDeOutroEscritorioComOMesmoNome(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenant);
        $this->adicionarDocumento($secao, 'hashA');
        $alheio = new ChaveDeArquivo(EscopoDeArquivo::deTenant(99), CategoriaDeArquivo::COBRANCA_DOCUMENTO, 'hashA');
        $this->armazenamento->semear($alheio);

        $this->secaoRepository->expects($this->once())->method('remover');

        $this->sut->executar($secao, $this->tenant);

        self::assertTrue($this->armazenamento->existe($alheio));
    }

    #[Test]
    public function rejeitaSecaoDeOutroTenant(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenantComId(99));
        $chave = $this->semear($this->adicionarDocumento($secao, 'hashA'));

        $this->secaoRepository->expects($this->never())->method('remover');

        try {
            $this->sut->executar($secao, $this->tenant);
            self::fail('devia ter recusado');
        } catch (AccessDeniedException) {
        }

        self::assertTrue($this->armazenamento->existe($chave));
    }

    private function semear(CobrancaDocumento $documento): ChaveDeArquivo
    {
        $chave = ChavesDeCobranca::documentoDeCaso($documento);
        $this->armazenamento->semear($chave);

        return $chave;
    }

    private function tenantComId(int $id): Tenant
    {
        $tenant   = new Tenant();
        $reflexao = new \ReflectionProperty(Tenant::class, 'id');
        $reflexao->setValue($tenant, $id);

        return $tenant;
    }

    /**
     * Injeta um documento na coleção interna da seção (populada pelo Doctrine em runtime).
     * No teste unit não há ORM, então adicionamos direto à ArrayCollection via reflexão. O
     * documento nasce com o tenant da seção, como no banco (coluna NOT NULL).
     */
    private function adicionarDocumento(CobrancaSecao $secao, string $hash): CobrancaDocumento
    {
        $documento = (new CobrancaDocumento())->setTenant($secao->getTenant())->setCaminhoArquivo($hash);

        $reflexao = new \ReflectionProperty(CobrancaSecao::class, 'documentos');
        /** @var Collection<int, CobrancaDocumento> $colecao */
        $colecao = $reflexao->getValue($secao);
        $colecao->add($documento);

        return $documento;
    }
}
