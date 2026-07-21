<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Service\CalculadoraEncargos;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CalculadoraEncargosTaxaJurosInversoTest extends TestCase
{
    #[Test]
    public function derivaAsTaxaQueReproduzOsJurosDoDia(): void
    {
        // P=R$170 (17000), 240 dias, 1% a.m. (100 bp) => juros forward = R$13,60 (1360).
        $bp = CalculadoraEncargos::taxaJurosBpDeValor(17000, 240, 1360);

        self::assertSame(100, $bp);
    }

    #[Test]
    public function valorNaoAtingivelSnapEhAMaisProxima(): void
    {
        // R$14,00 (1400) em P=17000/240d nao existe em bp inteiro: a mais proxima e 103 bp (~R$14,01).
        $bp = CalculadoraEncargos::taxaJurosBpDeValor(17000, 240, 1400);
        self::assertSame(103, $bp);

        // Round-trip REAL: aplica os mesmos 103 bp de volta no motor forward, com os MESMOS 240 dias
        // (via datas de calendário), e prova que o R$ recomposto fica a no máximo ~1 bp do digitado.
        $ref = new \DateTimeImmutable('2026-01-01');
        $venc = $ref->modify('-240 days');

        $jurosForward = (new CalculadoraEncargos())->calcular(
            17000,
            $venc,
            new ConfigEncargos(taxaJurosMensalBp: $bp),
            $ref,
        )['juros'];

        // 1 bp de diferença nesse P/dias vale ~13,6 centavos: tolerância de 15 cobre exatamente 1 passo de bp.
        self::assertLessThanOrEqual(15, abs($jurosForward - 1400), 'round-trip: o R$ recomposto fica a no máximo ~1 bp do digitado');
    }

    #[Test]
    public function degradaParaZeroSemBaseOuSemDias(): void
    {
        self::assertSame(0, CalculadoraEncargos::taxaJurosBpDeValor(0, 240, 1360));
        self::assertSame(0, CalculadoraEncargos::taxaJurosBpDeValor(17000, 0, 1360));
        self::assertSame(0, CalculadoraEncargos::taxaJurosBpDeValor(17000, 240, 0));
    }
}
