<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ReconciliadorLiquidacao;
use App\Cobranca\Service\ResolvedorConfigEncargos;
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

    #[TestDox('Liquidada que segue paga NÃO re-liquida: preserva o snapshot original (INV-V2)')]
    public function testNaoReLiquidaJaLiquidadaPreservandoSnapshot(): void
    {
        // Liquidada em 05/07 com valores FIXOS (50/200), distintos do que o motor daria em 20/07 (100/200).
        $o = $this->obrigacao(10000, '2026-06-20')
            ->liquidar(50, 200, 0, 0, new \DateTimeImmutable('2026-07-05'));

        // Reconcilia numa data DIFERENTE (ex.: correção de OUTRO pagamento) — segue totalmente paga.
        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 10300], $this->ref());

        self::assertTrue($o->estaLiquidada());
        self::assertEquals(new \DateTimeImmutable('2026-07-05'), $o->getLiquidadaEm(), 'data do snapshot preservada');
        self::assertSame(50, $o->getJuros(), 'valores do snapshot NÃO recalculados (INV-V2)');
        self::assertSame(200, $o->getMulta());
    }

    #[TestDox('Substituída por acordo vigente (design B: materializada, NÃO congelada) NÃO é tocada')]
    public function testNaoTocaSubstituidaPorAcordoVigente(): void
    {
        // Estado REAL que o CriarAcordo produz: snapshot da dataAcordo via definirEncargos, SEM congelar,
        // com acordoSubstituto vigente (Ativo é o default). Mesmo "totalmente paga", fica intacta.
        $acordoVigente = new Acordo(); // status Ativo = vigente (default da entidade)
        $o = $this->obrigacao(10000, '2026-06-20')
            ->definirEncargos(50, 100, 0, 0, new \DateTimeImmutable('2026-04-20'))
            ->setAcordoSubstituto($acordoVigente);
        self::assertFalse($o->encargosCongelados(), 'design B: substituída não é congelada');

        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 999999], $this->ref());

        self::assertFalse($o->estaLiquidada(), 'substituída por acordo vigente nunca é liquidada por pagamento');
        self::assertFalse($o->encargosCongelados(), 'continua não-congelada (volta a crescer se o acordo romper)');
        self::assertSame(50, $o->getJuros(), 'snapshot da dataAcordo intacto');
        self::assertSame(100, $o->getMulta());
    }

    #[TestDox('Congelada legada que NÃO é liquidada nem substituída NÃO é tocada')]
    public function testNaoTocaCongeladaLegada(): void
    {
        // Encargo legado travado (congelado, sem liquidadaEm, sem acordo): fica intacto.
        $o = $this->obrigacao(10000, '2026-06-20')
            ->definirEncargos(999, 111, 0, 222, new \DateTimeImmutable('2026-02-01'))
            ->congelarEncargos(new \DateTimeImmutable('2026-02-01'));

        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 999999], $this->ref());

        self::assertFalse($o->estaLiquidada());
        self::assertSame(999, $o->getJuros(), 'snapshot legado intacto');
        self::assertSame(111, $o->getMulta());
    }

    #[TestDox('Taxa própria MAIOR que a do caso: alocado cobre o exigível-BASE mas NÃO o exigível-OVERLAY → não liquida')]
    public function testTaxaPropriaMaiorImpedeLiquidacaoPrematura(): void
    {
        // P=100,00, venc 30 dias antes da REF. Caso (TOPLIFE): juros 1% a.m. + multa 2% → exigível-BASE
        // = 100,00 + 1,00 + 2,00 = 103,00. Obrigação com taxa PRÓPRIA de juros 3% a.m. (override) →
        // exigível-OVERLAY = 100,00 + 3,00 + 2,00 = 105,00. Alocado = 103,00 cobre o BASE mas não o
        // OVERLAY: com o overlay aplicado, a obrigação tem de permanecer VIVA (não pode liquidar com a
        // taxa do caso, que é menor que a dela).
        $o = $this->obrigacao(10000, '2026-06-20')->setTaxaJurosMensalBp(300);

        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 10300], $this->ref());

        self::assertFalse($o->estaLiquidada(), 'não pode liquidar com o exigível da taxa do CASO (103,00): a taxa própria (105,00) ainda não foi coberta');
        self::assertFalse($o->encargosCongelados());
    }

    #[TestDox('Taxa própria MAIOR: quando o alocado cobre o exigível-OVERLAY, liquida com o snapshot na taxa própria')]
    public function testTaxaPropriaMaiorLiquidaComSnapshotDaTaxaPropria(): void
    {
        // Mesmo cenário acima, mas alocado = 105,00 (exigível-OVERLAY completo) → liquida, e o snapshot
        // grava os encargos calculados com a taxa PRÓPRIA (juros 3,00), não a do caso (1,00).
        $o = $this->obrigacao(10000, '2026-06-20')->setTaxaJurosMensalBp(300);

        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 10500], $this->ref());

        self::assertTrue($o->estaLiquidada());
        self::assertSame(300, $o->getJuros(), 'snapshot com a taxa PRÓPRIA (3% a.m.), não a do caso (1%)');
        self::assertSame(200, $o->getMulta());
        self::assertSame(10500, $o->valorExigivel());
    }

    #[TestDox('Taxa própria MENOR que a do caso: alocado insuficiente para o exigível-BASE mas suficiente para o exigível-OVERLAY → liquida')]
    public function testTaxaPropriaMenorLiquidaAntesDoExigivelDoCaso(): void
    {
        // Obrigação com taxa PRÓPRIA de juros 0,5% a.m. (menor que o 1% do caso) → exigível-OVERLAY =
        // 100,00 + 0,50 + 2,00 = 102,50. Exigível-BASE (taxa do caso) seria 103,00. Alocado = 102,80:
        // cobre o OVERLAY mas não o BASE — com o overlay aplicado corretamente, tem de liquidar (a taxa
        // que rege esta obrigação é a dela, mais branda).
        $o = $this->obrigacao(10000, '2026-06-20')->setTaxaJurosMensalBp(50);

        $this->sut()->reconciliar($this->config(), [$o], [$this->idDe($o) => 10280], $this->ref());

        self::assertTrue($o->estaLiquidada(), 'exigível-OVERLAY (102,50) já foi coberto pelo alocado (102,80)');
        self::assertSame(50, $o->getJuros(), 'snapshot com a taxa PRÓPRIA (0,5% a.m.)');
        self::assertSame(200, $o->getMulta());
    }

    private function sut(): ReconciliadorLiquidacao
    {
        return new ReconciliadorLiquidacao(new CalculadoraEncargos(), new ResolvedorConfigEncargos());
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
