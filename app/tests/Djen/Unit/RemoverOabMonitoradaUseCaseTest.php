<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Entity\OabMonitorada;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\UseCase\RemoverOabMonitoradaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoverOabMonitoradaUseCase::class)]
final class RemoverOabMonitoradaUseCaseTest extends TestCase
{
    #[Test]
    public function removeAOabComFlush(): void
    {
        $oab = new OabMonitorada();
        $repo = $this->createMock(OabMonitoradaRepository::class);
        $repo->expects($this->once())->method('remover')->with($oab, true);

        (new RemoverOabMonitoradaUseCase($repo))->executar($oab);
    }
}
