<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Task 4 (spec "taxa por-obrigação"): o cálculo ao vivo (`hidratar`/`exigivelVivo`) passa a aplicar o
 * override da PRÓPRIA obrigação sobre a config-base do caso via `ResolvedorConfigEncargos::aplicarObrigacao`
 * ANTES de chamar `CalculadoraEncargos::calcular` — fecha a divergência em que saldo/FIFO/dashboard/modais
 * ignoravam a taxa própria da obrigação.
 */
#[CoversClass(EncargosVivos::class)]
final class EncargosVivosOverridePorObrigacaoTest extends TestCase
{
    #[Test]
    public function obrigacaoComTaxaPropriaCresceporSuaTaxa(): void
    {
        $hoje = new \DateTimeImmutable('2026-01-01'); // relógio fixo
        $vivos = new EncargosVivos(new MockClock($hoje), new CalculadoraEncargos(), new ResolvedorConfigEncargos());

        // Caso: juros 1% (100 bp). Obrigação com override 2% (200 bp). P=R$170, 240 dias de atraso.
        $baseCaso = new ConfigEncargos(taxaJurosMensalBp: 100);
        $venc = $hoje->modify('-240 days');
        $comProprio = (new Obrigacao())->setValorOriginal(17000)->setVencimentoOriginal($venc)->setTaxaJurosMensalBp(200);
        $herdando = (new Obrigacao())->setValorOriginal(17000)->setVencimentoOriginal($venc);

        $exigivelProprio = $vivos->exigivelVivo($baseCaso, $comProprio, $hoje);
        $exigivelHerda = $vivos->exigivelVivo($baseCaso, $herdando, $hoje);

        // A do override rende o DOBRO de juros → exigível maior. Prova que a taxa própria entrou no cálculo.
        self::assertGreaterThan($exigivelHerda, $exigivelProprio);
        self::assertSame(17000 + 2 * ($exigivelHerda - 17000), $exigivelProprio);
    }

    #[Test]
    public function congeladaNaoRecalculaMesmoComOverride(): void
    {
        $hoje = new \DateTimeImmutable('2026-01-01');
        $vivos = new EncargosVivos(new MockClock($hoje), new CalculadoraEncargos(), new ResolvedorConfigEncargos());

        $congelada = (new Obrigacao())
            ->setValorOriginal(17000)
            ->setVencimentoOriginal($hoje->modify('-240 days'))
            ->setTaxaJurosMensalBp(999);
        $congelada->definirEncargos(500, 0, 0, 0, $hoje);
        $congelada->congelarEncargos($hoje);

        self::assertSame(17500, $vivos->exigivelVivo(new ConfigEncargos(taxaJurosMensalBp: 100), $congelada, $hoje));
    }
}
