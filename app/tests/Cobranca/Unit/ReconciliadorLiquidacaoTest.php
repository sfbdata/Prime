<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ReconciliadorLiquidacao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Reconciliação do estado Liquidada/Viva de uma obrigação após um pagamento (modelo "ao vivo" §6.3):
 * dado o alocado FINAL por obrigação e a data de referência (a data do pagamento), decide, por
 * obrigação tocada, se ela quita (snapshot na data + congela + `liquidadaEm`) ou reabre (volta a
 * Viva). É o ponto ÚNICO da regra de quitação, compartilhado por Registrar e Corrigir Pagamento.
 */
#[CoversClass(ReconciliadorLiquidacao::class)]
final class ReconciliadorLiquidacaoTest extends TestCase
{
    private const REF = '2026-07-20';

    #[TestDox('Obrigação viva cujo alocado cobre o exigível é LIQUIDADA na data (snapshot + congela)')]
    public function testQuitaVivaTotalmentePaga(): void
    {
        // P=100,00, venc 20/06/2026 → 30 dias em 20/07: juros 1,00 + multa 2,00 = exigível 103,00.
        // (30 dias == carência de honorários → honorário 0.)
        $o = $this->obrigacao(10000, '2026-06-20');
        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 10300], $this->ref());

        self::assertTrue($o->estaLiquidada());
        self::assertEquals($this->ref(), $o->getLiquidadaEm());
        self::assertTrue($o->encargosCongelados());
        self::assertSame(100, $o->getJuros());
        self::assertSame(200, $o->getMulta());
        self::assertSame(0, $o->getCorrecao());
        self::assertSame(10300, $o->valorExigivel());
    }

    #[TestDox('Quita guarda também os honorários do snapshot (atraso > carência)')]
    public function testQuitaGuardaHonorarios(): void
    {
        // P=170,00, venc 22/11/2025 → 240 dias: juros 1360 · multa 340 · honorários 3740 (TOPLIFE I 20%).
        $o = $this->obrigacao(17000, '2025-11-22');
        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 18700], $this->ref());

        self::assertTrue($o->estaLiquidada());
        self::assertSame(1360, $o->getJuros());
        self::assertSame(340, $o->getMulta());
        self::assertSame(3740, $o->getHonorarios());
        self::assertSame(18700, $o->valorExigivel(), 'exigível SEM honorários (INV-E2)');
    }

    #[TestDox('Obrigação viva parcialmente paga continua VIVA (não liquida)')]
    public function testPagamentoParcialNaoLiquida(): void
    {
        $o = $this->obrigacao(10000, '2026-06-20');
        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 5000], $this->ref());

        self::assertFalse($o->estaLiquidada());
        self::assertFalse($o->encargosCongelados());
    }

    #[TestDox('Liquidada cujo alocado caiu abaixo do exigível REABRE (volta a Viva)')]
    public function testCorrecaoReabreLiquidada(): void
    {
        $o = $this->obrigacao(10000, '2026-06-20')
            ->liquidar(100, 200, 0, 0, new \DateTimeImmutable('2026-07-15'));
        self::assertTrue($o->estaLiquidada());

        // Correção deixou alocado < exigível (103,00) → reabre.
        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 5000], $this->ref());

        self::assertFalse($o->estaLiquidada());
        self::assertNull($o->getLiquidadaEm());
        self::assertFalse($o->encargosCongelados());
    }

    #[TestDox('Liquidada que segue totalmente paga permanece liquidada')]
    public function testLiquidadaQueSeguePagaPermanece(): void
    {
        $o = $this->obrigacao(10000, '2026-06-20')
            ->liquidar(100, 200, 0, 0, new \DateTimeImmutable('2026-07-15'));

        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 10300], $this->ref());

        self::assertTrue($o->estaLiquidada());
    }

    #[TestDox('Congelada que não é liquidada (substituída/legado) NÃO é tocada')]
    public function testNaoTocaCongeladaNaoLiquidada(): void
    {
        // Substituída: snapshot congelado, sem liquidadaEm. Mesmo "totalmente paga", fica intacta.
        $o = $this->obrigacao(10000, '2026-06-20')
            ->definirEncargos(999, 111, 0, 222, new \DateTimeImmutable('2026-02-01'))
            ->congelarEncargos(new \DateTimeImmutable('2026-02-01'));

        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 999999], $this->ref());

        self::assertFalse($o->estaLiquidada());
        self::assertSame(999, $o->getJuros(), 'snapshot da substituída intacto');
        self::assertSame(111, $o->getMulta());
    }

    private function sut(): ReconciliadorLiquidacao
    {
        return new ReconciliadorLiquidacao(new CalculadoraEncargos());
    }

    private function config(): ConfigEncargos
    {
        return ConfigEncargos::padraoTopLife(2000); // TOPLIFE I: juros 1%, multa 2%, honorários 20%, carência 30.
    }

    private function ref(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::REF);
    }

    private function obrigacao(int $valorOriginal, string $venc): Obrigacao
    {
        static $seq = 0;
        $o = (new Obrigacao())
            ->setDescricao('Obrigação')
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable($venc));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, ++$seq);

        return $o;
    }

    private function idDe(Obrigacao $o): int
    {
        return (int) $o->getId();
    }
}
