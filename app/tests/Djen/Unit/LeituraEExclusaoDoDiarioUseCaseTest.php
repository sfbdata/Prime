<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Djen\UseCase\ApagarPublicacoesDoDiarioUseCase;
use App\Djen\UseCase\AtualizarLeituraDoDiarioUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtualizarLeituraDoDiarioUseCase::class)]
#[CoversClass(ApagarPublicacoesDoDiarioUseCase::class)]
#[Group('djen')]
final class LeituraEExclusaoDoDiarioUseCaseTest extends TestCase
{
    #[Test]
    public function marcarComoLidaGravaOInstante(): void
    {
        $agora = new \DateTimeImmutable('2026-10-27 10:00');
        $repo = $this->createMock(PublicacaoDoDiarioDaOabRepository::class);
        $repo->expects(self::once())->method('atualizarLeitura')->with(self::anything(), self::anything(), [1, 2], $agora)->willReturn(2);

        self::assertSame(2, (new AtualizarLeituraDoDiarioUseCase($repo))->executar(new Tenant(), new User(), [1, 2], true, $agora));
    }

    #[Test]
    public function marcarComoNaoLidaLimpaOInstante(): void
    {
        $repo = $this->createMock(PublicacaoDoDiarioDaOabRepository::class);
        $repo->expects(self::once())->method('atualizarLeitura')->with(self::anything(), self::anything(), [3], null)->willReturn(1);

        self::assertSame(1, (new AtualizarLeituraDoDiarioUseCase($repo))->executar(new Tenant(), new User(), [3], false, new \DateTimeImmutable()));
    }

    #[Test]
    public function apagaPorIdELimpaLidas(): void
    {
        $agora = new \DateTimeImmutable();
        $repo = $this->createMock(PublicacaoDoDiarioDaOabRepository::class);
        $repo->expects(self::once())->method('marcarExcluidas')->willReturn(2);
        $repo->expects(self::once())->method('marcarLidasComoExcluidas')->willReturn(5);
        $uc = new ApagarPublicacoesDoDiarioUseCase($repo);

        self::assertSame(2, $uc->executar(new Tenant(), new User(), [1, 2], $agora));
        self::assertSame(5, $uc->limparLidas(new Tenant(), new User(), $agora));
    }
}
