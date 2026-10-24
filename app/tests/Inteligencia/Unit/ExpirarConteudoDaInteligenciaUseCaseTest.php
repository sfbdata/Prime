<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\UseCase\ExpirarConteudoDaInteligenciaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpirarConteudoDaInteligenciaUseCase::class)]
final class ExpirarConteudoDaInteligenciaUseCaseTest extends TestCase
{
    #[TestDox('o limite é 12 meses antes de agora e as contagens vêm do repositório')]
    public function testLimiteDeDozeMeses(): void
    {
        $agora = new \DateTimeImmutable('2026-10-24 00:20:00');
        $repositorio = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repositorio->expects(self::once())
            ->method('expirarConteudoAntesDe')
            ->with(new \DateTimeImmutable('2025-10-24 00:20:00'), $agora)
            ->willReturn(['analises' => 3, 'perguntas' => 2]);

        $resultado = (new ExpirarConteudoDaInteligenciaUseCase($repositorio))->executar($agora);

        self::assertEquals(new \DateTimeImmutable('2025-10-24 00:20:00'), $resultado['limite']);
        self::assertSame(3, $resultado['analises']);
        self::assertSame(2, $resultado['perguntas']);
    }
}
