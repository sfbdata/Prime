<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\UseCase\ExcluirPublicacoesUseCase;
use App\Djen\UseCase\MarcarPublicacoesComoLidasUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarPublicacoesComoLidasUseCase::class)]
#[CoversClass(ExcluirPublicacoesUseCase::class)]
final class PublicacoesEmLoteUseCasesTest extends TestCase
{
    #[Test]
    public function marcarLidasRepassaTenantIdsEOcultos(): void
    {
        $tenant = new Tenant();
        $repo = $this->createMock(PublicacaoDjenRepository::class);
        $repo->expects(self::once())->method('atualizarLeituraDoTenant')->with($tenant, [1, 2], true, ['0700'])->willReturn(2);

        self::assertSame(2, (new MarcarPublicacoesComoLidasUseCase($repo))->executar($tenant, [1, 2], true, ['0700']));
    }

    #[Test]
    public function marcarNaoLidasUsaOMesmoCaminhoComFalso(): void
    {
        $tenant = new Tenant();
        $repo = $this->createMock(PublicacaoDjenRepository::class);
        $repo->expects(self::once())->method('atualizarLeituraDoTenant')->with($tenant, [5], false, [])->willReturn(1);

        self::assertSame(1, (new MarcarPublicacoesComoLidasUseCase($repo))->executar($tenant, [5], false));
    }

    #[Test]
    public function excluirEExcluirLidasPassamPeloRepositorio(): void
    {
        $tenant = new Tenant();
        $repo = $this->createMock(PublicacaoDjenRepository::class);
        $repo->expects(self::once())->method('excluirDoTenant')->with($tenant, [3], self::isInstanceOf(\DateTimeImmutable::class), [])->willReturn(1);
        $repo->expects(self::once())->method('excluirLidasDoTenant')->with($tenant, self::isInstanceOf(\DateTimeImmutable::class), ['x'])->willReturn(4);
        $caso = new ExcluirPublicacoesUseCase($repo);

        self::assertSame(1, $caso->executar($tenant, [3]));
        self::assertSame(4, $caso->limparLidas($tenant, ['x']));
    }
}
