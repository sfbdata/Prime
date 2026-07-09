<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\UseCase\CriarSecaoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarSecaoUseCase::class)]
final class CriarSecaoUseCaseTest extends TestCase
{
    private CobrancaSecaoRepository&MockObject $secaoRepository;
    private CriarSecaoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->secaoRepository = $this->createMock(CobrancaSecaoRepository::class);
        $this->sut = new CriarSecaoUseCase($this->secaoRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function criaSecaoNoFimDaListaComNomeNormalizadoEFazUmFlush(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        // Próxima ordem calculada pelo repositório (MAX+1).
        $this->secaoRepository
            ->expects($this->once())
            ->method('proximaOrdem')
            ->with($caso)
            ->willReturn(3);

        // Persistência única com flush: true.
        $this->secaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(CobrancaSecao::class), true);

        $secao = $this->sut->executar($caso, '  Termos de acordo  ', $this->tenant);

        self::assertSame($caso, $secao->getCaso());
        self::assertSame($this->tenant, $secao->getTenant());
        self::assertSame(3, $secao->getOrdem());
        // A entidade normaliza o nome (upper + trim).
        self::assertSame('TERMOS DE ACORDO', $secao->getNome());
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        $caso = (new CasoCobranca())->setTenant(new Tenant());

        $this->secaoRepository->expects($this->never())->method('proximaOrdem');
        $this->secaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);

        $this->sut->executar($caso, 'Documentos', $this->tenant);
    }

    #[Test]
    public function rejeitaNomeVazio(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->secaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);

        $this->sut->executar($caso, '   ', $this->tenant);
    }
}
