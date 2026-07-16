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
    public function restante_desconta_o_alocado_do_valor_atual(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 120000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 40000);

        self::assertSame(40000, $output->alocado);
        self::assertSame(80000, $output->restante());
        self::assertFalse($output->quitada());
    }

    #[Test]
    public function restante_tem_piso_zero_quando_super_alocada(): void
    {
        // Alocação manual não tem teto por obrigação (beco conhecido, spec §10):
        // o DTO não pode devolver negativo para a tela.
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 130000);

        self::assertSame(0, $output->restante());
        self::assertTrue($output->quitada());
    }

    #[Test]
    public function quitada_quando_alocado_cobre_exatamente_o_valor(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 20000);

        $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 120000);

        self::assertSame(0, $output->restante());
        self::assertTrue($output->quitada());
    }

    #[Test]
    public function alocado_default_zero_preserva_o_comportamento_antigo(): void
    {
        $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 0);

        $output = ObrigacaoOutput::fromEntity($obrigacao);

        self::assertSame(0, $output->alocado);
        self::assertSame(100000, $output->restante());
    }
}
