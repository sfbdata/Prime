<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\DTO\EntradaTaxaEncargos;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversorTaxaEncargo::class)]
final class ConversorTaxaEncargoTest extends TestCase
{
    private function conversor(): ConversorTaxaEncargo
    {
        return new ConversorTaxaEncargo(new CalculadoraEncargos());
    }

    #[Test]
    public function herdaDeixaTudoNull(): void
    {
        $e = new EntradaTaxaEncargos(); // tudo modo 'herda' por default
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(taxaJurosMensalBp: 100), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertNull($out['taxaJurosMensalBp']);
        self::assertNull($out['taxaMultaBp']);
        self::assertNull($out['taxaHonorariosBp']);
    }

    #[Test]
    public function percentPassaDireto(): void
    {
        $e = new EntradaTaxaEncargos(modoJuros: 'percent', jurosBp: 150);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(150, $out['taxaJurosMensalBp']);
    }

    #[Test]
    public function reaisDeJurosDerivaATaxaDoDia(): void
    {
        // 240 dias entre venc e ref; P=17000; R$13,60 (1360) => 100 bp.
        $e = new EntradaTaxaEncargos(modoJuros: 'reais', jurosReais: 1360);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(100, $out['taxaJurosMensalBp']);
    }

    #[Test]
    public function reaisDeMultaUsaBasePrincipalPorDefault(): void
    {
        // multa base Principal (default): R$3,40 (340) sobre P=17000 => 200 bp (2%).
        $e = new EntradaTaxaEncargos(modoMulta: 'reais', multaReais: 340);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(200, $out['taxaMultaBp']);
    }
}
