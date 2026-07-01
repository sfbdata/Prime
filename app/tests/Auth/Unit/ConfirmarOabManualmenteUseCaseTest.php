<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Enum\StatusOab;
use App\Auth\UseCase\ConfirmarOabManualmenteUseCase;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfirmarOabManualmenteUseCase::class)]
final class ConfirmarOabManualmenteUseCaseTest extends TestCase
{
    #[TestDox('confirma a OAB, marca verificadaEm e persiste (auditoria é automática via subscriber)')]
    public function testConfirma(): void
    {
        $user = new User();
        $user->setOabStatus(StatusOab::Divergente);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new ConfirmarOabManualmenteUseCase($em))->executar($user);

        self::assertSame(StatusOab::Confirmada, $user->getOabStatus());
        self::assertNotNull($user->getOabVerificadaEm());
    }
}
