<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\UseCase\ConfigurarFaixasDoEspacoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurarFaixasDoEspacoUseCase::class)]
#[CoversClass(ConfiguracaoDoEspaco::class)]
final class ConfigurarFaixasDoEspacoUseCaseTest extends TestCase
{
    #[TestDox('Master grava as faixas e liga o bloqueio')]
    public function testGravaFaixasELigaBloqueio(): void
    {
        $configuracao = new ConfiguracaoDoEspaco();
        $repositorio = $this->createMock(ConfiguracaoDoEspacoRepository::class);
        $repositorio->method('atual')->willReturn($configuracao);
        $repositorio->expects(self::once())->method('salvar')->with($configuracao, true);

        (new ConfigurarFaixasDoEspacoUseCase($repositorio))->executar(60, 85, 92, 98, true);

        self::assertSame([60, 85, 92, 98], [$configuracao->getFaixaAtencao(), $configuracao->getFaixaAlerta(), $configuracao->getFaixaCritico(), $configuracao->getFaixaBloqueio()]);
        self::assertTrue($configuracao->isBloqueioAtivo());
    }

    #[TestDox('Faixa de bloqueio acima de 100% é recusada e nada é salvo')]
    public function testBloqueioAcimaDeCemRecusado(): void
    {
        $repositorio = $this->createMock(ConfiguracaoDoEspacoRepository::class);
        $repositorio->method('atual')->willReturn(new ConfiguracaoDoEspaco());
        $repositorio->expects(self::never())->method('salvar');
        $this->expectException(\DomainException::class);

        (new ConfigurarFaixasDoEspacoUseCase($repositorio))->executar(70, 90, 95, 120, true);
    }
}
