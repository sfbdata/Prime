<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\UseCase\ReordenarSecoesCasoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(ReordenarSecoesCasoUseCase::class)]
final class ReordenarSecoesCasoUseCaseTest extends TestCase
{
    private CobrancaSecaoRepository&MockObject $secaoRepository;
    private ReordenarSecoesCasoUseCase $sut;
    private Tenant $tenant;
    private CasoCobranca $caso;

    protected function setUp(): void
    {
        $this->secaoRepository = $this->createMock(CobrancaSecaoRepository::class);
        $this->sut = new ReordenarSecoesCasoUseCase($this->secaoRepository);
        $this->tenant = new Tenant();
        $this->caso = (new CasoCobranca())->setTenant($this->tenant);
    }

    #[Test]
    public function aplicaAOrdemSequencialConformeAListaEfazUmFlush(): void
    {
        $a = $this->secao(10);
        $b = $this->secao(20);

        $this->secaoRepository->method('secoesDoCaso')->willReturn([$a, $b]);
        $this->secaoRepository->expects($this->once())->method('salvar')->with(self::isInstanceOf(CobrancaSecao::class), true);

        $this->sut->executar($this->caso, $this->tenant, [20, 10]);

        self::assertSame(1, $b->getOrdem());
        self::assertSame(2, $a->getOrdem());
    }

    #[Test]
    public function ignoraIdsEstranhosAoCaso(): void
    {
        $a = $this->secao(10);
        $this->secaoRepository->method('secoesDoCaso')->willReturn([$a]);
        $this->secaoRepository->expects($this->once())->method('salvar')->with(self::isInstanceOf(CobrancaSecao::class), true);

        $this->sut->executar($this->caso, $this->tenant, [999, 10]);

        self::assertSame(1, $a->getOrdem());
    }

    #[Test]
    public function naoFlushaQuandoNenhumIdCasa(): void
    {
        $a = $this->secao(10);
        $this->secaoRepository->method('secoesDoCaso')->willReturn([$a]);
        $this->secaoRepository->expects($this->never())->method('salvar');

        $this->sut->executar($this->caso, $this->tenant, [999]);
    }

    #[Test]
    public function listaVaziaEhNoOp(): void
    {
        $this->secaoRepository->expects($this->never())->method('secoesDoCaso');
        $this->secaoRepository->expects($this->never())->method('salvar');

        $this->sut->executar($this->caso, $this->tenant, []);
    }

    #[Test]
    public function negaCasoDeOutroTenant(): void
    {
        $casoOutro = (new CasoCobranca())->setTenant(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->sut->executar($casoOutro, $this->tenant, [10]);
    }

    private function secao(int $id): CobrancaSecao
    {
        $secao = new CobrancaSecao();
        $ref = new \ReflectionProperty(CobrancaSecao::class, 'id');
        $ref->setValue($secao, $id);

        return $secao;
    }
}
