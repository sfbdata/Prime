<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\DTO\MetaDePastasDoMesOutput;
use App\Dashboard\Inteligencia\EstadoDoRitmo;
use App\Dashboard\Inteligencia\RitmoDaMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Selo, gauge e ritmo necessário da meta de pastas: porte de `indicadores()` e `classificarRitmo()`
 * do desenho sobre dias ÚTEIS. Mês de 21 dias úteis, meta 210 (necessário "redondo": 10 por dia).
 */
#[CoversClass(RitmoDaMeta::class)]
#[CoversClass(EstadoDoRitmo::class)]
#[Group('dashboard')]
final class RitmoDaMetaTest extends TestCase
{
    private function meta(int $realizado, int $decorridos, ?int $meta = 210, bool $visivel = true): MetaDePastasDoMesOutput
    {
        return new MetaDePastasDoMesOutput($meta, $meta === null ? null : '2026-10', '2026-10', $realizado, 21, $decorridos, $visivel);
    }

    /** @return iterable<string, array{int, int, EstadoDoRitmo}> */
    public static function estados(): iterable
    {
        yield 'meta batida é adequado'                    => [210, 10, EstadoDoRitmo::Adequado];
        yield 'atual igual ao necessário é adequado'      => [100, 10, EstadoDoRitmo::Adequado];
        yield 'razão 0,91 é atenção'                      => [95, 10, EstadoDoRitmo::Atencao];
        yield 'razão 0,83 é abaixo'                       => [90, 10, EstadoDoRitmo::Abaixo];
        yield 'razão 0,34 é crítico'                      => [50, 10, EstadoDoRitmo::Critico];
        yield 'início do mês suaviza crítico para abaixo' => [5, 2, EstadoDoRitmo::Abaixo];
        yield 'reta final agrava atenção para abaixo'     => [168, 17, EstadoDoRitmo::Abaixo];
        yield 'mês acabou sem bater é crítico'            => [100, 21, EstadoDoRitmo::Critico];
    }

    #[DataProvider('estados')]
    #[TestDox('Classifica o ritmo: $realizado pastas no dia útil $decorridos')]
    public function testClassifica(int $realizado, int $decorridos, EstadoDoRitmo $esperado): void
    {
        $ritmo = RitmoDaMeta::de($this->meta($realizado, $decorridos));

        self::assertNotNull($ritmo);
        self::assertSame($esperado, $ritmo->estado);
    }

    #[TestDox('Ritmo atual e necessário são por dia ÚTIL: faltam 110 em 11 dias úteis = 10 por dia')]
    public function testRitmosPorDiaUtil(): void
    {
        $ritmo = RitmoDaMeta::de($this->meta(100, 10));

        self::assertNotNull($ritmo);
        self::assertSame(10.0, $ritmo->ritmoAtual);
        self::assertEqualsWithDelta(10.0, (float) $ritmo->ritmoNecessario, 0.0001);
        self::assertSame(10, $ritmo->diaUtil);
        self::assertSame(21, $ritmo->diasUteisTotal);
        self::assertFalse($ritmo->precisaAcelerar());
    }

    #[TestDox('Mês encerrado sem bater a meta: não há ritmo necessário (nulo), e meta batida pede zero')]
    public function testNecessarioNuloEZero(): void
    {
        $encerrado = RitmoDaMeta::de($this->meta(100, 21));
        $batida    = RitmoDaMeta::de($this->meta(250, 21));

        self::assertNotNull($encerrado);
        self::assertNull($encerrado->ritmoNecessario);
        self::assertFalse($encerrado->precisaAcelerar());
        self::assertNotNull($batida);
        self::assertSame(0.0, $batida->ritmoNecessario);
    }

    #[TestDox('Faltando mais que o atual, o necessário pede acelerar')]
    public function testPrecisaAcelerar(): void
    {
        $ritmo = RitmoDaMeta::de($this->meta(50, 10));

        self::assertNotNull($ritmo);
        self::assertTrue($ritmo->precisaAcelerar());
    }

    #[TestDox('Sem meta, meta zero ou fora do mês inteiro (card não visível): não há ritmo, nada é inventado')]
    public function testSemObjetivoNaoHaRitmo(): void
    {
        self::assertNull(RitmoDaMeta::de($this->meta(100, 10, null)));
        self::assertNull(RitmoDaMeta::de($this->meta(100, 10, 0)));
        self::assertNull(RitmoDaMeta::de($this->meta(100, 10, 210, false)));
    }

    #[TestDox('O marcador do gauge fica no centro de cada quarto, do adequado ao crítico')]
    public function testPosicaoDoMarcador(): void
    {
        self::assertSame(
            [12.5, 37.5, 62.5, 87.5],
            array_map(static fn (EstadoDoRitmo $e): float => $e->posicao(), EstadoDoRitmo::cases()),
        );
        self::assertSame('dashboard.ia.estado.critico', EstadoDoRitmo::Critico->chaveDoRotulo());
    }
}
