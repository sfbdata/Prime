<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Enum\StatusOab;
use App\Auth\UseCase\ReverterOabUseCase;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReverterOabUseCase::class)]
final class ReverterOabUseCaseTest extends TestCase
{
    #[TestDox('reverter para NaoVerificada limpa verificadaEm')]
    public function testReverterParaNaoVerificada(): void
    {
        $user = new User();
        $user->setOabStatus(StatusOab::Confirmada);
        $user->setOabVerificadaEm(new \DateTimeImmutable());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new ReverterOabUseCase($em))->executar($user, StatusOab::NaoVerificada);

        self::assertSame(StatusOab::NaoVerificada, $user->getOabStatus());
        self::assertNull($user->getOabVerificadaEm());
    }

    #[TestDox('reverter para Divergente marca verificadaEm')]
    public function testReverterParaDivergente(): void
    {
        $user = new User();
        $user->setOabStatus(StatusOab::Confirmada);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new ReverterOabUseCase($em))->executar($user, StatusOab::Divergente);

        self::assertSame(StatusOab::Divergente, $user->getOabStatus());
        self::assertNotNull($user->getOabVerificadaEm());
    }

    #[TestDox('destino Confirmada é rejeitado (só NaoVerificada/Divergente)')]
    public function testDestinoInvalidoLanca(): void
    {
        $user = new User();
        $user->setOabStatus(StatusOab::Confirmada);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        (new ReverterOabUseCase($em))->executar($user, StatusOab::Confirmada);
    }
}
