<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Entity\OabMonitorada;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\UseCase\AlternarStatusOabMonitoradaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlternarStatusOabMonitoradaUseCase::class)]
final class AlternarStatusOabMonitoradaUseCaseTest extends TestCase
{
    #[Test]
    public function alternaDeAtivaParaPausadaESalva(): void
    {
        $oab = (new OabMonitorada())->setAtivo(true);
        $repo = $this->createMock(OabMonitoradaRepository::class);
        $repo->expects($this->once())->method('salvar')->with($oab, true);

        $novoStatus = (new AlternarStatusOabMonitoradaUseCase($repo))->executar($oab);

        self::assertFalse($novoStatus);
        self::assertFalse($oab->isAtivo());
    }

    #[Test]
    public function alternaDePausadaParaAtivaESalva(): void
    {
        $oab = (new OabMonitorada())->setAtivo(false);
        $repo = $this->createMock(OabMonitoradaRepository::class);
        $repo->expects($this->once())->method('salvar')->with($oab, true);

        $novoStatus = (new AlternarStatusOabMonitoradaUseCase($repo))->executar($oab);

        self::assertTrue($novoStatus);
        self::assertTrue($oab->isAtivo());
    }
}
