<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;
use App\Comercial\UseCase\PurgarConfirmacoesVencidasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A purga das pendências nunca confirmadas, 30 dias depois de vencer (spec `comercial-e-emails.md` §3.3). */
#[CoversClass(PurgarConfirmacoesVencidasUseCase::class)]
final class PurgarConfirmacoesVencidasUseCaseTest extends TestCase
{
    #[TestDox('Em dry-run só conta, com o limite de 30 dias antes de agora')]
    public function testSimulacao(): void
    {
        $agora = new \DateTimeImmutable('2026-10-19 10:00:00');
        $repositorio = $this->createMock(ConfirmacaoDeEnderecoRepository::class);
        $repositorio->expects(self::once())->method('contarPendentesVencidas')->with(new \DateTimeImmutable('2026-09-19 10:00:00'))->willReturn(3);
        $repositorio->expects(self::never())->method('purgarPendentesVencidas');

        self::assertSame(3, (new PurgarConfirmacoesVencidasUseCase($repositorio))->executar($agora, true));
    }

    #[TestDox('Fora do dry-run apaga e devolve quantas pendências saíram')]
    public function testPurga(): void
    {
        $agora = new \DateTimeImmutable('2026-10-19 10:00:00');
        $repositorio = $this->createMock(ConfirmacaoDeEnderecoRepository::class);
        $repositorio->expects(self::once())->method('purgarPendentesVencidas')->with(new \DateTimeImmutable('2026-09-19 10:00:00'))->willReturn(2);
        $repositorio->expects(self::never())->method('contarPendentesVencidas');

        self::assertSame(2, (new PurgarConfirmacoesVencidasUseCase($repositorio))->executar($agora, false));
    }
}
