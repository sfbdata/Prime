<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Entity\OabMonitorada;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\UseCase\EditarApelidoOabMonitoradaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarApelidoOabMonitoradaUseCase::class)]
final class EditarApelidoOabMonitoradaUseCaseTest extends TestCase
{
    #[Test]
    public function trocaOApelidoSemTocarNumeroNemUf(): void
    {
        $oab = (new OabMonitorada())->setNumero('67228')->setUf('PR')->setApelido('Antigo');
        $repo = $this->createMock(OabMonitoradaRepository::class);
        $repo->expects($this->once())->method('salvar')->with($oab, true);

        (new EditarApelidoOabMonitoradaUseCase($repo))->executar($oab, '  Dra. Fulana  ');

        self::assertSame('Dra. Fulana', $oab->getApelido());
        self::assertSame('67228', $oab->getNumero());
        self::assertSame('PR', $oab->getUf());
    }

    #[Test]
    public function apelidoVazioLimpaOCampo(): void
    {
        $oab = (new OabMonitorada())->setApelido('Antigo');
        $repo = $this->createStub(OabMonitoradaRepository::class);

        (new EditarApelidoOabMonitoradaUseCase($repo))->executar($oab, '   ');

        self::assertNull($oab->getApelido());
    }

    #[Test]
    public function apelidoAcimaDe255CaracteresNaoSalva(): void
    {
        $oab = (new OabMonitorada())->setApelido('Antigo');
        $repo = $this->createMock(OabMonitoradaRepository::class);
        $repo->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new EditarApelidoOabMonitoradaUseCase($repo))->executar($oab, str_repeat('a', 256));
        } finally {
            self::assertSame('Antigo', $oab->getApelido());
        }
    }
}
