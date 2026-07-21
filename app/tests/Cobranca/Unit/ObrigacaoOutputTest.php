<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ObrigacaoOutput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Os três flags de acordo do ObrigacaoOutput são VIGENTE-AWARE (ajuste 5): decidem, na UI, botão-editar
 * × cadeado × badge "Acordo desfeito". Este teste fixa o comportamento nos 4 estados possíveis.
 */
#[CoversClass(ObrigacaoOutput::class)]
final class ObrigacaoOutputTest extends TestCase
{
    private function obrigacao(): Obrigacao
    {
        return (new Obrigacao())->setDescricao('X')->setValorOriginal(10000)->setEncargosReconhecidos(500);
    }

    private function obrigacaoCom(int $valorOriginal, int $encargos = 0): Obrigacao
    {
        return (new Obrigacao())->setDescricao('X')->setValorOriginal($valorOriginal)->setEncargosReconhecidos($encargos);
    }

    #[Test]
    public function obrigacaoComumNaoTemNenhumFlagDeAcordo(): void
    {
        $out = ObrigacaoOutput::fromEntity($this->obrigacao());

        self::assertFalse($out->substituidaPorAcordo);
        self::assertFalse($out->ehParcelaAcordo);
        self::assertFalse($out->parcelaDeAcordoDesfeito);
        self::assertSame(10500, $out->valorAtual, 'valorAtual = original + encargos');
    }

    #[Test]
    public function substituidaPorAcordoVigente(): void
    {
        $out = ObrigacaoOutput::fromEntity(
            $this->obrigacao()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Ativo)),
        );

        self::assertTrue($out->substituidaPorAcordo);
        self::assertFalse($out->ehParcelaAcordo);
        self::assertFalse($out->parcelaDeAcordoDesfeito);
    }

    #[Test]
    public function parcelaDeAcordoVigente(): void
    {
        $out = ObrigacaoOutput::fromEntity(
            $this->obrigacao()->setAcordoOrigem((new Acordo())->setStatus(StatusAcordo::Cumprido)),
        );

        self::assertTrue($out->ehParcelaAcordo);
        self::assertFalse($out->parcelaDeAcordoDesfeito);
        self::assertFalse($out->substituidaPorAcordo);
    }

    #[Test]
    public function parcelaDeAcordoRompidoOuCanceladoViraHistorico(): void
    {
        $out = ObrigacaoOutput::fromEntity(
            $this->obrigacao()->setAcordoOrigem((new Acordo())->setStatus(StatusAcordo::Cancelado)),
        );

        self::assertTrue($out->parcelaDeAcordoDesfeito);
        self::assertFalse($out->ehParcelaAcordo, 'parcela de acordo desfeito não é mais "parcela vigente"');
        self::assertFalse($out->substituidaPorAcordo);
    }

    #[Test]
    public function originalSubstituidaPorAcordoCanceladoVoltaAoNormal(): void
    {
        // O acordo que a substituía foi cancelado: nenhum flag de trava → volta a ser editável.
        $out = ObrigacaoOutput::fromEntity(
            $this->obrigacao()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Rompido)),
        );

        self::assertFalse($out->substituidaPorAcordo);
        self::assertFalse($out->ehParcelaAcordo);
        self::assertFalse($out->parcelaDeAcordoDesfeito);
    }

    #[Test]
    public function restanteDescontaOAlocadoDoValorAtual(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 120000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 40000);

        self::assertSame(40000, $output->alocado);
        self::assertSame(80000, $output->restante());
        self::assertFalse($output->quitada());
    }

    #[Test]
    public function restanteTemPisoZeroQuandoSuperAlocada(): void
    {
        // Alocação manual não tem teto por obrigação (beco conhecido, spec §10):
        // o DTO não pode devolver negativo para a tela.
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 130000);

        self::assertSame(0, $output->restante());
        self::assertTrue($output->quitada());
    }

    #[Test]
    public function quitadaQuandoAlocadoCobreExatamenteOValor(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 20000);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 120000);

        self::assertSame(0, $output->restante());
        self::assertTrue($output->quitada());
    }

    #[Test]
    public function alocadoDefaultZeroPreservaOComportamentoAntigo(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao);

        self::assertSame(0, $output->alocado);
        self::assertSame(100000, $output->restante());
    }

    #[Test]
    public function fromEntityCopiaOsQuatroOverridesCrusDeTaxa(): void
    {
        // FIX crítico (Task 9): sem estes 4 campos crus (bp), o modal de Editar não consegue reidratar o
        // override ATUAL da obrigação — reabrir sempre nasceria "herda" e qualquer submissão apagaria a
        // taxa própria em silêncio.
        $obrigacao = $this->obrigacao()
            ->setTaxaJurosMensalBp(150)
            ->setTaxaMultaBp(200)
            ->setTaxaCorrecaoBp(50)
            ->setTaxaHonorariosBp(1000);

        $output = ObrigacaoOutput::fromEntity($obrigacao);

        self::assertSame(150, $output->taxaJurosMensalBp);
        self::assertSame(200, $output->taxaMultaBp);
        self::assertSame(50, $output->taxaCorrecaoBp);
        self::assertSame(1000, $output->taxaHonorariosBp);
    }

    #[Test]
    public function fromEntitySemOverrideDeixaOsQuatroCamposNulos(): void
    {
        // Obrigação que herda tudo do caso (nunca teve override próprio) — os 4 campos crus têm de sair
        // `null`, não 0: 0bp seria uma taxa própria de 0%, algo bem diferente de "herda".
        $output = ObrigacaoOutput::fromEntity($this->obrigacao());

        self::assertNull($output->taxaJurosMensalBp);
        self::assertNull($output->taxaMultaBp);
        self::assertNull($output->taxaCorrecaoBp);
        self::assertNull($output->taxaHonorariosBp);
    }
}
