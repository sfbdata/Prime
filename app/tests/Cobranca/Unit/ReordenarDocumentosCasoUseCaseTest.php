<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\UseCase\ReordenarDocumentosCasoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(ReordenarDocumentosCasoUseCase::class)]
final class ReordenarDocumentosCasoUseCaseTest extends TestCase
{
    private CobrancaDocumentoRepository&MockObject $documentoRepository;
    private ReordenarDocumentosCasoUseCase $sut;
    private Tenant $tenant;
    private CasoCobranca $caso;

    protected function setUp(): void
    {
        $this->documentoRepository = $this->createMock(CobrancaDocumentoRepository::class);
        $this->sut = new ReordenarDocumentosCasoUseCase($this->documentoRepository);
        $this->tenant = new Tenant();
        $this->caso = (new CasoCobranca())->setTenant($this->tenant);
    }

    #[Test]
    public function aplicaAOrdemSequencialConformeAListaEfazUmFlush(): void
    {
        $a = $this->documento(10);
        $b = $this->documento(20);
        $c = $this->documento(30);

        $this->documentoRepository->method('documentosDoCaso')->willReturn([$a, $b, $c]);
        // Flush único: salvar chamado uma vez com o ÚLTIMO reordenado + flush=true.
        $this->documentoRepository->expects($this->once())->method('salvar')->with(self::isInstanceOf(CobrancaDocumento::class), true);

        $this->sut->executar($this->caso, $this->tenant, [30, 10, 20]);

        self::assertSame(1, $c->getOrdem());
        self::assertSame(2, $a->getOrdem());
        self::assertSame(3, $b->getOrdem());
    }

    #[Test]
    public function ignoraIdsEstranhosAoCaso(): void
    {
        $a = $this->documento(10);
        $this->documentoRepository->method('documentosDoCaso')->willReturn([$a]);
        $this->documentoRepository->expects($this->once())->method('salvar')->with(self::isInstanceOf(CobrancaDocumento::class), true);

        // 999 é de outro caso/tenant → ignorado; só o 10 é reordenado.
        $this->sut->executar($this->caso, $this->tenant, [999, 10]);

        self::assertSame(1, $a->getOrdem());
    }

    #[Test]
    public function naoFlushaQuandoNenhumIdCasa(): void
    {
        $a = $this->documento(10);
        $this->documentoRepository->method('documentosDoCaso')->willReturn([$a]);
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->sut->executar($this->caso, $this->tenant, [999]);
    }

    #[Test]
    public function listaVaziaEhNoOp(): void
    {
        $this->documentoRepository->expects($this->never())->method('documentosDoCaso');
        $this->documentoRepository->expects($this->never())->method('salvar');

        $this->sut->executar($this->caso, $this->tenant, []);
    }

    #[Test]
    public function negaCasoDeOutroTenant(): void
    {
        $casoOutro = (new CasoCobranca())->setTenant(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->sut->executar($casoOutro, $this->tenant, [10]);
    }

    private function documento(int $id): CobrancaDocumento
    {
        $doc = new CobrancaDocumento();
        $ref = new \ReflectionProperty(CobrancaDocumento::class, 'id');
        $ref->setValue($doc, $id);

        return $doc;
    }
}
