<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\UseCase\RenomearSecaoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenomearSecaoUseCase::class)]
final class RenomearSecaoUseCaseTest extends TestCase
{
    private CobrancaSecaoRepository&MockObject $secaoRepository;
    private RenomearSecaoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->secaoRepository = $this->createMock(CobrancaSecaoRepository::class);
        $this->sut = new RenomearSecaoUseCase($this->secaoRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function renomeiaComNomeNormalizadoEFazUmFlush(): void
    {
        $secao = (new CobrancaSecao())
            ->setTenant($this->tenant)
            ->setNome('ANTIGO');

        $this->secaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($secao, true);

        $this->sut->executar($secao, '  novos comprovantes  ', $this->tenant);

        // A entidade normaliza o nome (upper + trim).
        self::assertSame('NOVOS COMPROVANTES', $secao->getNome());
    }

    #[Test]
    public function rejeitaSecaoDeOutroTenant(): void
    {
        $secao = (new CobrancaSecao())->setTenant(new Tenant());

        $this->secaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);

        $this->sut->executar($secao, 'Qualquer', $this->tenant);
    }

    #[Test]
    public function rejeitaNomeVazio(): void
    {
        $secao = (new CobrancaSecao())->setTenant($this->tenant);

        $this->secaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);

        $this->sut->executar($secao, '   ', $this->tenant);
    }
}
