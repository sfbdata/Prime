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
}
