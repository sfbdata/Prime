<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Exception\SecaoNaoEncontradaException;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\UseCase\MoverDocumentoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(MoverDocumentoUseCase::class)]
final class MoverDocumentoUseCaseTest extends TestCase
{
    private CobrancaDocumentoRepository&MockObject $documentoRepository;
    private MoverDocumentoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->documentoRepository = $this->createMock(CobrancaDocumentoRepository::class);
        $this->sut = new MoverDocumentoUseCase($this->documentoRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function moveDocumentoParaSecaoDoMesmoCaso(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $documento = (new CobrancaDocumento())->setTenant($this->tenant)->setCaso($caso);
        $secaoDestino = (new CobrancaSecao())->setTenant($this->tenant)->setCaso($caso);

        $this->documentoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::identicalTo($documento), true);

        $this->sut->executar($documento, $secaoDestino, $this->tenant);

        self::assertSame($secaoDestino, $documento->getSecao());
    }

    #[Test]
    public function moveDocumentoParaGeralQuandoDestinoNulo(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $secaoAtual = (new CobrancaSecao())->setTenant($this->tenant)->setCaso($caso);
        $documento = (new CobrancaDocumento())->setTenant($this->tenant)->setCaso($caso)->setSecao($secaoAtual);

        $this->documentoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::identicalTo($documento), true);

        $this->sut->executar($documento, null, $this->tenant);

        self::assertNull($documento->getSecao());
    }

    #[Test]
    public function rejeitaDocumentoDeOutroTenant(): void
    {
        $documento = (new CobrancaDocumento())->setTenant(new Tenant());

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);

        $this->sut->executar($documento, null, $this->tenant);
    }

    #[Test]
    public function rejeitaSecaoDestinoDeOutroTenant(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $documento = (new CobrancaDocumento())->setTenant($this->tenant)->setCaso($caso);
        $secaoDestino = (new CobrancaSecao())->setTenant(new Tenant())->setCaso($caso);

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(AccessDeniedException::class);

        $this->sut->executar($documento, $secaoDestino, $this->tenant);
    }

    #[Test]
    public function rejeitaSecaoDestinoDeOutroCaso(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $outroCaso = (new CasoCobranca())->setTenant($this->tenant);
        $documento = (new CobrancaDocumento())->setTenant($this->tenant)->setCaso($caso);
        $secaoDestino = (new CobrancaSecao())->setTenant($this->tenant)->setCaso($outroCaso);

        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->expectException(SecaoNaoEncontradaException::class);

        $this->sut->executar($documento, $secaoDestino, $this->tenant);
    }
}
