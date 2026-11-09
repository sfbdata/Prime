<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\UseCase\AbrirPautaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os minutos da mesa ("Abrir por minutos", "Definir prazo"): de 1 a 1440 viram o prazo; vazio é "sem prazo". A
 * transição da pauta e a trava da linha são provadas no teste funcional (AssembleiaGestorTest).
 */
#[CoversClass(AbrirPautaUseCase::class)]
final class AbrirPautaUseCaseTest extends TestCase
{
    #[TestDox('Minutos viram o prazo a partir de agora; sem minutos não há prazo')]
    public function testPrazo(): void
    {
        $agora = new \DateTimeImmutable('2026-11-10 20:00:00');

        self::assertEquals(new \DateTimeImmutable('2026-11-10 20:05:00'), AbrirPautaUseCase::prazo(5, $agora));
        self::assertEquals(new \DateTimeImmutable('2026-11-11 20:00:00'), AbrirPautaUseCase::prazo(1440, $agora));
        self::assertNull(AbrirPautaUseCase::prazo(null, $agora));
    }

    #[TestWith([0])]
    #[TestWith([-3])]
    #[TestWith([1441])]
    #[TestDox('Minutos fora de 1 a 1440 são recusados com a mensagem da tela')]
    public function testMinutosForaDoLimite(int $minutos): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Informe os minutos, de 1 a 1440.');

        AbrirPautaUseCase::prazo($minutos, new \DateTimeImmutable());
    }
}
