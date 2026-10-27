<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Repository\AcessoConhecidoRepository;
use App\Auth\UseCase\PurgarAcessosConhecidosUseCase;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurgarAcessosConhecidosUseCase::class)]
final class PurgarAcessosConhecidosUseCaseTest extends TestCase
{
    private function limiteUsado(string $retencao, bool $dryRun): \DateTimeImmutable
    {
        $usado = null;
        $repository = $this->createMock(AcessoConhecidoRepository::class);
        $captura = static function (\DateTimeImmutable $l) use (&$usado): int {
            $usado = $l;

            return 3;
        };
        $repository->method('purgarNaoVistosDesde')->willReturnCallback($captura);
        $repository->method('contarNaoVistosDesde')->willReturnCallback($captura);

        $r = (new PurgarAcessosConhecidosUseCase($repository, new ConfiguracaoDaPlataformaFalsa(['retencao' => $retencao])))
            ->executar(new \DateTimeImmutable('2026-10-27 00:00:00'), $dryRun);

        self::assertSame(3, $r);

        return $usado;
    }

    #[TestDox('apaga os não vistos há `retencao` dias; em dry-run só conta; valor inválido vale 365')]
    public function testLimite(): void
    {
        self::assertSame('2026-09-27', $this->limiteUsado('30', false)->format('Y-m-d'));
        self::assertSame('2026-09-27', $this->limiteUsado('30', true)->format('Y-m-d'));
        self::assertSame('2025-10-27', $this->limiteUsado('', false)->format('Y-m-d'));
        self::assertSame('2025-10-27', $this->limiteUsado('5', false)->format('Y-m-d'));
    }
}
